<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Tests for tools/ci/php-lint.sh (#151)
 * ==============================================================================
 *
 * Path: tests/test_php_lint_script.php
 *
 * Proves the shared lint script used by ci.yml and deploy.yml:
 *   1. fails when it finds no PHP files (an empty lint must not pass),
 *   2. fails on a compile-time deprecation even though `php -l` exits 0, and
 *   3. passes on a clean file.
 *
 * Each case runs in a throwaway folder so the real project is never touched.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       #151
 * @see         tools/ci/php-lint.sh
 */

$lintScript = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'ci' . DIRECTORY_SEPARATOR . 'php-lint.sh';
$bashPath   = is_executable('/bin/bash') ? '/bin/bash' : '';

if ($bashPath === '') {
    // 🪟 No bash on this machine (not a supported CI/dev setup): nothing to prove.
    assertTrue(true, 'php-lint.sh tests skipped: no /bin/bash');
    return;
}

/**
 * 🛰️ Run php-lint.sh inside a fresh folder holding the given files.
 *
 * @param array<string, string> $files Relative path => file contents.
 * @param string                $lint  Absolute path to php-lint.sh.
 * @param string                $bash  Absolute path to bash.
 *
 * @return array{exit: int, output: string} Exit code and combined output.
 */
function lintScriptRun(array $files, string $lint, string $bash): array
{
    $base = tempnam(sys_get_temp_dir(), 'cuercode_lint_');
    unlink($base);
    mkdir($base . DIRECTORY_SEPARATOR . 'web', 0700, true);

    foreach ($files as $rel => $contents) {
        $full = $base . DIRECTORY_SEPARATOR . $rel;
        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0700, true);
        }
        file_put_contents($full, $contents);
    }

    $process = proc_open(
        [$bash, $lint],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $base
    );

    $output = '';
    $exit   = -1;

    if (is_resource($process)) {
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
    }

    // 🧹 Remove the throwaway folder (files created above only).
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($base);

    return ['exit' => $exit, 'output' => $output];
}

$empty = lintScriptRun([], $lintScript, $bashPath);
assertEquals(1, $empty['exit'], 'php-lint.sh fails when it finds zero PHP files');
assertContains('nothing was linted', $empty['output'], 'the zero-files failure says why');

$deprecated = lintScriptRun(
    ['web/bad.php' => '<?php function g(?int $a = null, $b) { return 1; }' . PHP_EOL],
    $lintScript,
    $bashPath
);
assertEquals(1, $deprecated['exit'], 'php-lint.sh fails on a compile-time deprecation');
assertContains('deprecation found at compile time', $deprecated['output'], 'the deprecation failure is reported');

$clean = lintScriptRun(['web/ok.php' => '<?php echo 1;' . PHP_EOL], $lintScript, $bashPath);
assertEquals(0, $clean['exit'], 'php-lint.sh passes on a clean file');
assertContains('1 PHP files', $clean['output'], 'the pass message counts the files checked');
