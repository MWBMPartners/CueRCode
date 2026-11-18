<?php
/**
 * ==============================================================================
 * 🔎 CueRCode - PHP 8.6 Compatibility Scanner
 * ==============================================================================
 *
 * Path: tools/php86-compat-scan.php
 *
 * Reads every PHP file in the project with PHP's own tokenizer (token_get_all)
 * and reports code that PHP 8.6 deprecates, removes, or changes in a way that
 * could alter behaviour. It does NOT use text pattern matching on source, so
 * words inside strings and comments are never mistaken for code.
 *
 * Why it exists (#151):
 *   The test suite only runs code that needs no database, so most production
 *   paths never run in CI. This scan covers every line of every file, and it
 *   runs on PHP 8.4 and 8.5 too, so problems are found before 8.6 is installed.
 *
 * Rules (source: php-src PHP-8.6 UPGRADING, checked 2026-11-18):
 *   - calls to functions deprecated in 8.6 (spl_object_hash, is_double, ...)
 *   - method calls ->get_charset() / ->stmt_init() and the SplFileObject CSV
 *     methods, the 10 ArrayIterator methods deprecated in 8.6 (only in a file
 *     that names ArrayIterator or RecursiveArrayIterator), the
 *     SORT_LOCALE_STRING constant
 *   - `return` inside a `finally` block
 *   - returning a value, or `yield`, inside __construct / __destruct
 *   - define() with three arguments
 *   - a plain function (NOT a method) named readonly / let / is; a class,
 *     interface, trait, enum, `use ... as` alias or constant (const or define)
 *     named let / is; a constant named `_`; a class constant named `namespace`
 *     (typed class constants such as `const string NAME` are read correctly)
 *   - a literal pack()/unpack() format with `<` or `>` straight after a letter
 *   - array_walk() / array_walk_recursive() only when the first argument looks
 *     like an object ($this, new ..., or a variable called $obj / $object)
 *   - is_a() / is_subclass_of() only when the first argument is a string
 *     literal and allow_string is not true (is_a) / is false (is_subclass_of)
 *   - __debugInfo() declared with a nullable return type
 *   - `new mysqli_stmt($db)` with no query argument
 *
 * Known gaps (not detected): objects passed to http_build_query() or
 * mb_convert_variables(), session handler methods, and functions passed as
 * strings to callables (e.g. array_map('is_double', ...)).
 *
 * Opt-out: put the comment `// php86-scan-ok: <reason>` on the SAME line as
 * the code to skip that line. Always give a reason.
 *
 * Usage (from the repository root):
 *   php tools/php86-compat-scan.php                    # scan web/ tests/ tools/
 *   php tools/php86-compat-scan.php --path=<dir>       # scan only <dir> (repeatable)
 *   php tools/php86-compat-scan.php --include-vendored # also list _libraries/ hits as warnings
 *
 * Exit codes: 0 = clean, 1 = findings in project code, 2 = usage or file error.
 *
 * Structure: php86ScanSource() is a pure function so tests can call it on
 * strings. The command-line part only runs when this file is run directly.
 *
 * @package     CueRCode
 * @subpackage  Tools
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       #151 (PHP 8.6 readiness)
 * @see         https://www.php.net/manual/en/function.token-get-all.php
 * @see         https://github.com/php/php-src/blob/PHP-8.6/UPGRADING
 */

// 🚫 Command line / tests only; never reachable over HTTP.
if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit('Scanner is CLI-only.');
}

/**
 * 📋 Function names whose CALL is flagged (all lower case).
 *
 * @return array<string, string> Function name => short reason.
 */
function php86ScanBannedFunctions(): array
{
    return [
        'spl_object_hash'        => 'deprecated in 8.6',
        'spl_classes'            => 'deprecated in 8.6',
        'is_double'              => 'deprecated alias, use is_float()',
        'is_long'                => 'deprecated alias, use is_int()',
        'is_integer'             => 'deprecated alias, use is_int()',
        'doubleval'              => 'deprecated alias, use floatval()',
        'strcoll'                => 'deprecated in 8.6',
        'metaphone'              => 'deprecated in 8.6',
        'mb_split'               => 'deprecated in 8.6',
        'mb_convert_variables'   => 'deprecated in 8.6',
        'mysqli_get_charset'     => 'deprecated in 8.6',
        'mysqli_stmt_init'       => 'deprecated in 8.6',
    ];
}

/**
 * 🔧 Find the index of the '}' that closes the block opened at $openIdx.
 *
 * @param array<int, mixed> $tokens  Significant tokens (no whitespace/comments).
 * @param int               $openIdx Index of the opening brace token.
 *
 * @return int Index of the matching close brace (last index if unbalanced).
 */
function php86ScanMatchBrace(array $tokens, int $openIdx): int
{
    $count = count($tokens);
    $depth = 0;

    for ($i = $openIdx; $i < $count; $i++) {
        $tok = $tokens[$i];

        if ($tok === '{') {
            $depth++;
        } elseif (is_array($tok) && in_array($tok[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
            $depth++;
        } elseif ($tok === '}') {
            $depth--;
            if ($depth === 0) {
                return $i;
            }
        }
    }

    return $count - 1;
}

/**
 * 🔧 Index of the first '{' at or after $from that starts a function body.
 *
 * Skips the parameter list (balanced round brackets). Returns -1 when the
 * function has no body (abstract / interface method ending in ';').
 *
 * @param array<int, mixed> $tokens Significant tokens.
 * @param int               $from   Index to start looking from.
 *
 * @return int Body-open index, or -1.
 */
function php86ScanFindBody(array $tokens, int $from): int
{
    $count = count($tokens);
    $paren = 0;

    for ($i = $from; $i < $count; $i++) {
        $tok = $tokens[$i];

        if ($tok === '(') {
            $paren++;
        } elseif ($tok === ')') {
            $paren--;
        } elseif ($paren === 0 && $tok === '{') {
            return $i;
        } elseif ($paren === 0 && $tok === ';') {
            return -1;
        }
    }

    return -1;
}

/**
 * 🔧 Split the argument list of a call into one token list per argument.
 *
 * @param array<int, mixed> $tokens  Significant tokens.
 * @param int               $openIdx Index of the opening '(' of the call.
 *
 * @return array<int, array<int, mixed>> One array of tokens per argument ([] for no arguments).
 */
function php86ScanSplitArgs(array $tokens, int $openIdx): array
{
    $count = count($tokens);
    $depth = 0;
    $args  = [];
    $cur   = [];

    for ($j = $openIdx; $j < $count; $j++) {
        $t = $tokens[$j];

        if ($t === '(' || $t === '[' || $t === '{') {
            $depth++;
            if ($depth === 1) {
                continue;
            }
        } elseif ($t === ')' || $t === ']' || $t === '}') {
            $depth--;
            if ($depth === 0) {
                if ($cur !== []) {
                    $args[] = $cur;
                }
                return $args;
            }
        } elseif ($t === ',' && $depth === 1) {
            $args[] = $cur;
            $cur    = [];
            continue;
        }

        $cur[] = $t;
    }

    return $args;
}

/**
 * 🔧 Text of one token (arrays carry text at index 1, single characters are the text).
 *
 * @param mixed $tok A token.
 *
 * @return string The token text.
 */
function php86ScanTokText(mixed $tok): string
{
    return is_array($tok) ? $tok[1] : (string) $tok;
}

/**
 * 🔧 Does a first-argument token list look like an object?
 *
 * "Plausibly an object" means: `$this`, `new ...`, or a lone variable whose
 * name starts with obj / object / instance. Anything else is treated as an
 * array, so ordinary array_walk() calls are never flagged.
 *
 * @param array<int, mixed> $arg Tokens of the first argument.
 *
 * @return bool True when it looks like an object.
 */
function php86ScanLooksLikeObject(array $arg): bool
{
    if ($arg === []) {
        return false;
    }

    if (is_array($arg[0]) && $arg[0][0] === T_NEW) {
        return true;
    }

    if (count($arg) === 1 && is_array($arg[0]) && $arg[0][0] === T_VARIABLE) {
        $name = strtolower($arg[0][1]);

        return $name === '$this' || preg_match('/^\$(obj|object|instance)/', $name) === 1;
    }

    return false;
}

/**
 * 🔎 Scan one chunk of PHP source and return the findings.
 *
 * @param string $code  The PHP source text.
 * @param string $label A name for the source (file path) used in findings.
 *
 * @return array<int, array{file: string, line: int, rule: string, message: string}>
 */
function php86ScanSource(string $code, string $label): array
{
    // 🧱 Tokenise. TOKEN_PARSE makes the tokenizer stricter, but we want to
    //    scan even odd files, so use the plain mode.
    $raw = token_get_all($code);

    // 💬 Lines that carry an opt-out comment, and the significant tokens.
    $optOutLines = [];
    $tokens      = [];

    foreach ($raw as $tok) {
        if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            if (str_contains($tok[1], 'php86-scan-ok:')) {
                // A comment can span lines; mark the line the marker is on.
                $lineOffset = substr_count(
                    substr($tok[1], 0, (int) strpos($tok[1], 'php86-scan-ok:')),
                    "\n"
                );
                $optOutLines[$tok[2] + $lineOffset] = true;
            }
            continue;
        }

        if (is_array($tok) && $tok[0] === T_WHITESPACE) {
            continue;
        }

        $tokens[] = $tok;
    }

    $count    = count($tokens);
    $findings = [];
    $banned   = php86ScanBannedFunctions();

    // 🏛️ Pre-pass 1: which `function` tokens are METHODS (declared directly in
    //    a class / interface / trait / enum body)? Methods named is / let /
    //    readonly are legal, only plain functions are reserved.
    $isMethod = [];
    $braceStack = [];
    $pendingClass = false;
    foreach ($tokens as $idx => $t) {
        if (is_array($t) && in_array($t[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            $before = ($idx > 0) ? $tokens[$idx - 1] : null;
            // `Foo::class` is not a declaration.
            if (!(is_array($before) && $before[0] === T_DOUBLE_COLON)) {
                $pendingClass = true;
            }
        } elseif ($t === '{') {
            $braceStack[] = $pendingClass ? 'class' : 'other';
            $pendingClass = false;
        } elseif (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
            $braceStack[] = 'other';
        } elseif ($t === '}') {
            array_pop($braceStack);
        } elseif (is_array($t) && $t[0] === T_FUNCTION) {
            $isMethod[$idx] = (end($braceStack) === 'class');
        }
    }

    // 🏛️ Pre-pass 2: does this file mention ArrayIterator at all?
    $usesArrayIterator = false;
    foreach ($tokens as $t) {
        if (is_array($t) && in_array($t[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)
            && in_array(strtolower(ltrim($t[1], '\\')), ['arrayiterator', 'recursivearrayiterator'], true)
        ) {
            $usesArrayIterator = true;
            break;
        }
    }

    // 🧰 Local helper to add a finding unless the line opted out.
    $add = static function (int $line, string $rule, string $message) use (&$findings, $optOutLines, $label): void {
        if (isset($optOutLines[$line])) {
            return;
        }
        $findings[] = [
            'file'    => $label,
            'line'    => $line,
            'rule'    => $rule,
            'message' => $message,
        ];
    };

    for ($i = 0; $i < $count; $i++) {
        $tok = $tokens[$i];

        if (!is_array($tok)) {
            continue;
        }

        $id   = $tok[0];
        $text = $tok[1];
        $line = $tok[2];
        $prev = ($i > 0) ? $tokens[$i - 1] : null;
        $next = ($i + 1 < $count) ? $tokens[$i + 1] : null;

        // 🔤 Plain or fully-qualified name tokens (\is_a counts too).
        if ($id === T_STRING || $id === T_NAME_FULLY_QUALIFIED) {
            $name = strtolower(ltrim($text, '\\'));

            // Is this name being CALLED as a global function?
            $isCall = ($next === '(');
            if ($prev !== null && is_array($prev)
                && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)
            ) {
                $isCall = false;
            }

            if ($isCall) {
                if (isset($banned[$name])) {
                    $add($line, 'banned-function', $name . '(): ' . $banned[$name]);
                }

                if (str_starts_with($name, 'mb_ereg')) {
                    $add($line, 'banned-function', $name . '(): mb_ereg* family deprecated in 8.6');
                }

                // 🧮 define() with three arguments (case-insensitive flag removed).
                if ($name === 'define') {
                    $depth  = 0;
                    $commas = 0;
                    for ($j = $i + 1; $j < $count; $j++) {
                        $t = $tokens[$j];
                        if ($t === '(' || $t === '[') {
                            $depth++;
                        } elseif ($t === ')' || $t === ']') {
                            $depth--;
                            if ($depth === 0) {
                                break;
                            }
                        } elseif ($t === ',' && $depth === 1) {
                            $commas++;
                        }
                    }
                    if ($commas >= 2) {
                        $add($line, 'define-three-args', 'define() with 3 arguments is removed');
                    }

                    // 🔣 define('_', ...) names a constant `_`.
                    $arg = $tokens[$i + 2] ?? null;
                    if (is_array($arg) && $arg[0] === T_CONSTANT_ENCAPSED_STRING && trim($arg[1], '\'"') === '_') {
                        $add($line, 'constant-underscore', 'a constant named _ is reserved');
                    }
                    if (is_array($arg) && $arg[0] === T_CONSTANT_ENCAPSED_STRING
                        && in_array(strtolower(trim($arg[1], '\'"')), ['let', 'is'], true)
                    ) {
                        $add($line, 'reserved-constant-name', 'a constant named ' . strtolower(trim($arg[1], '\'"')) . ' is reserved in 8.6');
                    }
                }

                // 🧩 array_walk()/array_walk_recursive() on something that looks like an object.
                if ($name === 'array_walk' || $name === 'array_walk_recursive') {
                    $callArgs = php86ScanSplitArgs($tokens, $i + 1);
                    if (isset($callArgs[0]) && php86ScanLooksLikeObject($callArgs[0])) {
                        $add($line, 'array-walk-object', $name . '() on an object (first argument looks like an object) behaves differently in 8.6');
                    }
                }

                // 🧩 is_a()/is_subclass_of() with a string literal as the first argument.
                if ($name === 'is_a' || $name === 'is_subclass_of') {
                    $callArgs = php86ScanSplitArgs($tokens, $i + 1);
                    $first    = $callArgs[0] ?? [];
                    $third    = $callArgs[2] ?? [];
                    $thirdTxt = strtolower(implode('', array_map('php86ScanTokText', $third)));
                    if (count($first) === 1 && is_array($first[0]) && $first[0][0] === T_CONSTANT_ENCAPSED_STRING) {
                        // is_a() defaults allow_string to false; is_subclass_of() to true.
                        $risky = ($name === 'is_a') ? ($thirdTxt !== 'true') : ($thirdTxt === 'false');
                        if ($risky) {
                            $add($line, 'is-a-string', $name . '() with a string literal first argument and allow_string not true: check the 8.6 behaviour');
                        }
                    }
                }

                // 📦 pack()/unpack() literal format with < or > after a letter.
                if ($name === 'pack' || $name === 'unpack') {
                    $arg = $tokens[$i + 2] ?? null;
                    if (is_array($arg) && $arg[0] === T_CONSTANT_ENCAPSED_STRING
                        && preg_match('/[A-Za-z][<>]/', $arg[1]) === 1
                    ) {
                        $add($line, 'pack-byte-order', $name . '() format has < or > after a letter (byte order in 8.6)');
                    }
                }
            }

            // 🔗 Method calls on any object: ->get_charset( ->stmt_init( and CSV methods.
            if ($next === '(' && is_array($prev)
                && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            ) {
                $methods = ['get_charset', 'stmt_init', 'fgetcsv', 'fputcsv', 'getcsvcontrol', 'setcsvcontrol'];
                if (in_array($name, $methods, true)) {
                    $add($line, 'banned-method', '->' . $name . '(): changed or deprecated in 8.6');
                }
            }

            // 🧱 The 10 ArrayIterator methods deprecated in 8.6, but only in a file
            //    that names ArrayIterator / RecursiveArrayIterator (otherwise
            //    ->serialize() etc. on unrelated classes would be false alarms).
            if ($usesArrayIterator && $next === '(' && is_array($prev)
                && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                && in_array($name, ['getflags', 'setflags', 'asort', 'ksort', 'uasort', 'uksort', 'natsort', 'natcasesort', 'serialize', 'unserialize'], true)
            ) {
                $add($line, 'array-iterator-method', 'ArrayIterator::' . $name . '() is deprecated in 8.6');
            }

            // 🧱 The SORT_LOCALE_STRING constant.
            if ($name === 'sort_locale_string') {
                $add($line, 'sort-locale-string', 'SORT_LOCALE_STRING is deprecated in 8.6');
            }
        }

        // 🔙 return inside finally { ... }
        if ($id === T_FINALLY) {
            $open = $i + 1;
            while ($open < $count && $tokens[$open] !== '{') {
                $open++;
            }
            if ($open < $count) {
                $close = php86ScanMatchBrace($tokens, $open);
                for ($j = $open + 1; $j < $close; $j++) {
                    $t = $tokens[$j];
                    if (!is_array($t)) {
                        continue;
                    }
                    if ($t[0] === T_FUNCTION) {
                        // A closure or function declared inside finally has its
                        // own returns; skip its whole body.
                        $body = php86ScanFindBody($tokens, $j);
                        if ($body !== -1 && $body < $close) {
                            $j = php86ScanMatchBrace($tokens, $body);
                        }
                        continue;
                    }
                    if ($t[0] === T_RETURN) {
                        $add($t[2], 'return-in-finally', '`return` inside finally is deprecated in 8.6');
                    }
                }
            }
        }

        // 🏗️ function declarations: reserved names + constructor/destructor values.
        if ($id === T_FUNCTION) {
            $k = $i + 1;
            if ($k < $count && $tokens[$k] === '&') {
                $k++;
            }
            $nameTok = $tokens[$k] ?? null;

            if (is_array($nameTok)) {
                $fnName = strtolower($nameTok[1]);

                if (in_array($fnName, ['readonly', 'let', 'is'], true) && empty($isMethod[$i])) {
                    $add($nameTok[2], 'reserved-function-name', 'a function named ' . $fnName . ' is reserved in 8.6');
                }

                if ($fnName === '__construct' || $fnName === '__destruct') {
                    $body = php86ScanFindBody($tokens, $k);
                    if ($body !== -1) {
                        $close = php86ScanMatchBrace($tokens, $body);
                        for ($j = $body + 1; $j < $close; $j++) {
                            $t = $tokens[$j];
                            if (!is_array($t)) {
                                continue;
                            }
                            if ($t[0] === T_FUNCTION) {
                                // Skip nested closures / anonymous-class methods.
                                $inner = php86ScanFindBody($tokens, $j);
                                if ($inner !== -1 && $inner < $close) {
                                    $j = php86ScanMatchBrace($tokens, $inner);
                                }
                                continue;
                            }
                            if ($t[0] === T_RETURN && ($tokens[$j + 1] ?? null) !== ';') {
                                $add($t[2], 'ctor-return-value', 'returning a value from ' . $fnName . ' is deprecated in 8.6');
                            }
                            if ($t[0] === T_YIELD || $t[0] === T_YIELD_FROM) {
                                $add($t[2], 'ctor-return-value', 'yield inside ' . $fnName . ' is not allowed in 8.6');
                            }
                        }
                    }
                }
            }
        }

        // 🐞 __debugInfo() declared with a nullable return type (?array or array|null).
        if ($id === T_FUNCTION && is_array($tokens[$i + 1] ?? null) && strtolower($tokens[$i + 1][1]) === '__debuginfo') {
            $open = $i + 2;
            if (($tokens[$open] ?? null) === '(') {
                $depthP = 0;
                $m      = $open;
                for (; $m < $count; $m++) {
                    if ($tokens[$m] === '(') {
                        $depthP++;
                    } elseif ($tokens[$m] === ')') {
                        $depthP--;
                        if ($depthP === 0) {
                            break;
                        }
                    }
                }
                if (($tokens[$m + 1] ?? null) === ':') {
                    for ($r = $m + 2; $r < $count && $tokens[$r] !== '{' && $tokens[$r] !== ';'; $r++) {
                        $rt = $tokens[$r];
                        if ($rt === '?' || (is_array($rt) && strtolower($rt[1]) === 'null')) {
                            $add($tokens[$i + 1][2], 'debuginfo-nullable', '__debugInfo() must return array, not a nullable type, in 8.6');
                            break;
                        }
                    }
                }
            }
        }

        // 🆕 new mysqli_stmt($db) with no query argument.
        if ($id === T_NEW && is_array($next) && in_array($next[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
            && strtolower(ltrim($next[1], '\\')) === 'mysqli_stmt'
            && ($tokens[$i + 2] ?? null) === '('
            && count(php86ScanSplitArgs($tokens, $i + 2)) < 2
        ) {
            $add($next[2], 'mysqli-stmt-no-query', 'new mysqli_stmt() without a query is deprecated in 8.6, use mysqli::prepare()');
        }

        // 🏷️ use ... as let; (import alias named let / is).
        if ($id === T_AS && is_array($next) && $next[0] === T_STRING
            && in_array(strtolower($next[1]), ['let', 'is'], true)
        ) {
            $add($next[2], 'reserved-alias-name', 'an alias named ' . strtolower($next[1]) . ' is reserved in 8.6');
        }

        // 🏷️ class / interface / trait / enum named let or is.
        if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)
            && !(is_array($prev) && in_array($prev[0], [T_DOUBLE_COLON, T_NEW], true))
            && is_array($next) && $next[0] === T_STRING
            && in_array(strtolower($next[1]), ['let', 'is'], true)
        ) {
            $add($next[2], 'reserved-type-name', 'a type named ' . strtolower($next[1]) . ' is reserved in 8.6');
        }

        // 🔣 const NAME = ...; (also typed `const string NAME = ...` and `const A = 1, B = 2;`).
        //    The constant name is the token just before each top-level `=`.
        if ($id === T_CONST) {
            $depthC = 0;
            for ($c = $i + 1; $c < $count; $c++) {
                $ct = $tokens[$c];
                if ($ct === '(' || $ct === '[' || $ct === '{') {
                    $depthC++;
                } elseif ($ct === ')' || $ct === ']' || $ct === '}') {
                    $depthC--;
                } elseif ($ct === ';' && $depthC <= 0) {
                    break;
                } elseif ($ct === '=' && $depthC === 0 && is_array($tokens[$c - 1] ?? null)) {
                    $constTok  = $tokens[$c - 1];
                    $constName = strtolower($constTok[1]);
                    if ($constName === '_') {
                        $add($constTok[2], 'constant-underscore', 'a constant named _ is reserved');
                    }
                    if ($constName === 'namespace') {
                        $add($constTok[2], 'constant-namespace', 'a constant named namespace is reserved in 8.6');
                    }
                    if ($constName === 'let' || $constName === 'is') {
                        $add($constTok[2], 'reserved-constant-name', 'a constant named ' . $constName . ' is reserved in 8.6');
                    }
                }
            }
        }
    }

    return $findings;
}

/**
 * 📂 List every .php file under a directory, sorted for stable output.
 *
 * @param string $dir Directory to walk.
 *
 * @return array<int, string> Absolute file paths.
 */
function php86ScanListFiles(string $dir): array
{
    $files = [];

    if (is_file($dir)) {
        return [$dir];
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isFile() && str_ends_with($fileInfo->getFilename(), '.php')) {
            $files[] = $fileInfo->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * 🧮 Is this path inside a vendored-libraries folder?
 *
 * @param string $path File path.
 *
 * @return bool True for anything under a `_libraries` folder.
 */
function php86ScanIsVendored(string $path): bool
{
    return str_contains($path, DIRECTORY_SEPARATOR . '_libraries' . DIRECTORY_SEPARATOR);
}

/**
 * 🚀 Command-line entry point.
 *
 * @param array<int, string> $argv Command-line arguments.
 *
 * @return int Exit code (0 clean, 1 findings, 2 usage/file error).
 */
function php86ScanMain(array $argv): int
{
    $root            = dirname(__DIR__);
    $paths           = [];
    $includeVendored = false;

    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--path=')) {
            $paths[] = substr($arg, strlen('--path='));
        } elseif ($arg === '--include-vendored') {
            $includeVendored = true;
        } else {
            fwrite(STDERR, 'Unknown argument: ' . $arg . PHP_EOL);
            return 2;
        }
    }

    // 🎯 Default scope: web/ tests/ tools/ under the repo root.
    if ($paths === []) {
        foreach (['web', 'tests', 'tools'] as $dir) {
            $paths[] = $root . DIRECTORY_SEPARATOR . $dir;
        }
    }

    $projectHits  = 0;
    $vendoredHits = 0;
    $fileCount    = 0;

    foreach ($paths as $path) {
        if (!file_exists($path)) {
            fwrite(STDERR, 'Path not found: ' . $path . PHP_EOL);
            return 2;
        }

        foreach (php86ScanListFiles($path) as $file) {
            $vendored = php86ScanIsVendored($file);

            // 📚 Vendored libraries are skipped unless asked for.
            if ($vendored && !$includeVendored) {
                continue;
            }

            $code = file_get_contents($file);
            if ($code === false) {
                fwrite(STDERR, 'Cannot read: ' . $file . PHP_EOL);
                return 2;
            }

            $fileCount++;

            foreach (php86ScanSource($code, $file) as $hit) {
                $kind = $vendored ? 'WARNING (vendored)' : 'FINDING';
                echo $kind . ': ' . $hit['file'] . ':' . $hit['line']
                    . ' [' . $hit['rule'] . '] ' . $hit['message'] . PHP_EOL;

                if ($vendored) {
                    $vendoredHits++;
                } else {
                    $projectHits++;
                }
            }
        }
    }

    echo 'PHP 8.6 scan: ' . $fileCount . ' file(s), ' . $projectHits . ' finding(s)';
    if ($includeVendored) {
        echo ', ' . $vendoredHits . ' vendored warning(s)';
    }
    echo PHP_EOL;

    if ($projectHits > 0) {
        return 1;
    }

    return 0;
}

// 🏁 Run only when this file is the script being executed (not when included).
$php86ScanScript = $_SERVER['argv'][0] ?? '';
if ($php86ScanScript !== '' && realpath($php86ScanScript) === realpath(__FILE__)) {
    exit(php86ScanMain($_SERVER['argv']));
}
