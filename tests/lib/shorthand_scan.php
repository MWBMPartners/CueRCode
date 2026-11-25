<?php
/**
 * ==============================================================================
 * 🔎 CueRCode - Shorthand scanner for the "full notation" house rule
 * ==============================================================================
 *
 * Path: tests/lib/shorthand_scan.php
 *
 * The project rule (.claude/CLAUDE.md, "Code Standards") is full multi-line
 * PHP: no `??`, `??=`, `?:`, ternary `? :` or short echo `<?=` in production
 * code. This counts them per file with PHP's own tokenizer (not text
 * matching), so a `?` inside a string, a comment or a nullable type such as
 * `?string` is never counted. Used by tests/test_full_notation.php.
 *
 * Added for the independent review (Codex C3 finding 6, 2026-11-25).
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-25 (Codex C3 finding 6, #113)
 */

/**
 * 🔢 Count shorthand in one piece of PHP source.
 *
 * @param string $source PHP source text
 * @return array{coalesce: int, coalesceAssign: int, elvis: int, ternary: int, shortEcho: int, lines: array<int, int>}
 */
function shorthandCount(string $source): array
{
    $counts = ['coalesce' => 0, 'coalesceAssign' => 0, 'elvis' => 0, 'ternary' => 0, 'shortEcho' => 0, 'lines' => []];
    $significant = [];

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $significant[] = $token;
    }

    $lastLine = 1;
    $typeTokens = [T_STRING, T_ARRAY, T_CALLABLE, T_STATIC, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];
    $afterTypeTokens = [T_VARIABLE, T_ELLIPSIS, '&', '{', ';', '=', ')'];

    foreach ($significant as $index => $token) {
        if (is_array($token)) {
            $lastLine = $token[2];
            if ($token[0] === T_COALESCE) {
                $counts['coalesce']++;
                $counts['lines'][] = $lastLine;
            } elseif ($token[0] === T_COALESCE_EQUAL) {
                $counts['coalesceAssign']++;
                $counts['lines'][] = $lastLine;
            } elseif ($token[0] === T_OPEN_TAG_WITH_ECHO) {
                $counts['shortEcho']++;
                $counts['lines'][] = $lastLine;
            }
            continue;
        }

        if ($token !== '?') {
            continue;
        }

        $previous = null;
        if (isset($significant[$index - 1])) {
            $previous = $significant[$index - 1];
        }
        $next = null;
        if (isset($significant[$index + 1])) {
            $next = $significant[$index + 1];
        }
        $afterNext = null;
        if (isset($significant[$index + 2])) {
            $afterNext = $significant[$index + 2];
        }

        if ($next === ':') {
            $counts['elvis']++;
            $counts['lines'][] = $lastLine;
            continue;
        }

        // 🧩 A nullable type, not a ternary: "?Type" after (, a comma, a
        //    return-type colon, a visibility word or "|", or "?Type $name"
        //    (a parameter, for example after an attribute "#[...]").
        $previousKind = $previous;
        if (is_array($previous)) {
            $previousKind = $previous[0];
        }
        if (in_array($previousKind, ['(', ',', ':', '|', T_PUBLIC, T_PRIVATE, T_PROTECTED, T_READONLY, T_VAR, T_STATIC, T_CONST], true)) {
            continue;
        }
        $nextKind = $next;
        if (is_array($next)) {
            $nextKind = $next[0];
        }
        $afterNextKind = $afterNext;
        if (is_array($afterNext)) {
            $afterNextKind = $afterNext[0];
        }
        if (in_array($nextKind, $typeTokens, true) && in_array($afterNextKind, [T_VARIABLE, T_ELLIPSIS, '&'], true)) {
            continue;
        }

        $counts['ternary']++;
        $counts['lines'][] = $lastLine;
    }

    return $counts;
}

/**
 * 📂 Count shorthand in every .php file under a folder (skipping _libraries/,
 *    which holds third-party code).
 *
 * @param string $root Folder to scan (no trailing separator)
 * @return array<string, int> Relative path => total shorthand uses (files with none are left out)
 */
function shorthandScanTree(string $root): array
{
    $found = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        $path = $file->getPathname();
        if (!str_ends_with($path, '.php')) {
            continue;
        }
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
        if (str_starts_with($relative, '_libraries/')) {
            continue;
        }
        $counts = shorthandCount((string) file_get_contents($path));
        $total = $counts['coalesce'] + $counts['coalesceAssign'] + $counts['elvis'] + $counts['ternary'] + $counts['shortEcho'];
        if ($total > 0) {
            $found[$relative] = $total;
        }
    }

    ksort($found);

    return $found;
}
