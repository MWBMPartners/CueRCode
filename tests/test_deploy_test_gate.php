<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - The tests must pass before a deploy (#155, decision 0025)
 * ==============================================================================
 *
 * Path: tests/test_deploy_test_gate.php
 *
 * Proves two things:
 *   1. tools/ci/run-tests.sh fails when the tests are missing, when a test
 *      fails, when the library check fails and when deprecation text appears,
 *      and passes only on a clean run.
 *   2. ci.yml and deploy.yml both use that script, and the upload job waits
 *      for the deploy's test job.
 *
 * Each script case runs in a throwaway folder holding small stand-in files.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       #155
 * @see         tools/ci/run-tests.sh
 */

$gateRoot   = dirname(__DIR__);
$gateScript = $gateRoot . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'ci' . DIRECTORY_SEPARATOR . 'run-tests.sh';

/**
 * 🛰️ Run run-tests.sh in a fresh folder holding the given stand-in files.
 *
 * @param array<string, string> $files  Relative path => file contents.
 * @param string                $script Absolute path to run-tests.sh.
 *
 * @return array{exit: int, output: string} Exit code and combined output.
 */
function deployGateRun(array $files, string $script): array
{
    $base = tempnam(sys_get_temp_dir(), 'cuercode_gate_');
    unlink($base);
    mkdir($base, 0700, true);

    foreach ($files as $rel => $contents) {
        $full = $base . DIRECTORY_SEPARATOR . $rel;
        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0700, true);
        }
        file_put_contents($full, $contents);
    }

    // 🧷 STDERR is joined to STDOUT so one pipe cannot fill while the other waits.
    $process = proc_open(
        ['/bin/bash', $script],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        $base
    );

    $output = '';
    $exit   = -1;

    if (is_resource($process)) {
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
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

if (is_executable('/bin/bash')) {
    $okCheck = '<?php exit(0);';
    $cases   = [
        'clean run passes'                => [['tests/run.php' => '<?php echo "ok"; exit(0);', 'tools/check_vendored_libraries.php' => $okCheck], true],
        'a failing test fails'            => [['tests/run.php' => '<?php echo "bad"; exit(1);', 'tools/check_vendored_libraries.php' => $okCheck], false],
        'a missing test runner fails'     => [['tools/check_vendored_libraries.php' => $okCheck], false],
        'a failing library check fails'   => [['tests/run.php' => '<?php exit(0);', 'tools/check_vendored_libraries.php' => '<?php exit(1);'], false],
        'deprecation text fails'          => [['tests/run.php' => '<?php echo "PHP Deprecated: old"; exit(0);', 'tools/check_vendored_libraries.php' => $okCheck], false],
    ];

    foreach ($cases as $label => [$files, $shouldPass]) {
        $result = deployGateRun($files, $gateScript);
        assertEquals($shouldPass, $result['exit'] === 0, 'run-tests.sh: ' . $label);
    }
} else {
    assertTrue(true, 'run-tests.sh cases skipped: no /bin/bash');
}

$workflowDir = $gateRoot . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'workflows' . DIRECTORY_SEPARATOR;
$ciText      = (string) file_get_contents($workflowDir . 'ci.yml');
$deployText  = (string) file_get_contents($workflowDir . 'deploy.yml');

assertContains('bash tools/ci/run-tests.sh', $ciText, 'ci.yml runs the shared test script');
assertContains('bash tools/ci/run-tests.sh', $deployText, 'deploy.yml runs the shared test script');
assertEquals(
    1,
    preg_match('/^  deploy:\n(?:    .*\n|\s*\n)*?    needs: \[php-checks, tests\]$/m', $deployText),
    'the upload job waits for both the PHP checks and the test suite'
);
// 🚧 `if: always()` (or similar) on the upload job would upload despite a failed need.
$deployJob = (string) strstr($deployText, "\n  deploy:\n");
$deployJob = (string) strstr($deployJob, "\n    steps:", true);
assertEquals(0, preg_match('/always\(\)|failure\(\)|cancelled\(\)/', $deployJob), 'the upload job cannot run after a failed check');
