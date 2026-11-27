<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - The tests must pass before a deploy (#155, decision 0025)
 * ==============================================================================
 *
 * Path: tests/test_deploy_test_gate.php
 *
 * Proves three things:
 *   1. tools/ci/run-tests.sh fails when the tests are missing, when a test
 *      fails, when the library check fails, when deprecation text appears,
 *      and (recheck finding 3 of the 7n Phase 2 review) when no tests ran,
 *      too few test files exist or the runner skipped some; it passes only
 *      on a clean run.
 *   2. ci.yml and deploy.yml both use that script, and the upload job waits
 *      for the deploy's test job.
 *   3. deploy.yml's two gate jobs cannot let a failure through
 *      (deployGateProblems()), and every weakening found so far - job or step
 *      continue-on-error, `|| true`, `set +e`, a dropped PHP version - is
 *      applied to a copy of the real file and shown to be caught.
 *
 * Each script case runs in a throwaway folder holding small stand-in files.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.1.0
 * @since       #155
 * @since       2026-11-27 (7n Phase 2 recheck finding 3) - gate weakenings, empty runs
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
    // 🔢 Recheck finding 3: the script now also fails when no tests ran or when
    //    implausibly few test files exist. The stand-in runner therefore prints
    //    the two lines the real tests/run.php prints, and the stand-in folder
    //    holds the minimum number of test files the script asks for.
    $gateScriptText = (string) file_get_contents($gateScript);
    $gateMinimum    = 0;
    if (preg_match('/^MIN_TEST_FILES=([0-9]+)$/m', $gateScriptText, $gateMinimumFound) === 1) {
        $gateMinimum = (int) $gateMinimumFound[1];
    }
    assertTrue($gateMinimum >= 40, 'run-tests.sh sets a plausible minimum number of test files (MIN_TEST_FILES=' . $gateMinimum . ')');
    $gateRealFiles = glob(__DIR__ . DIRECTORY_SEPARATOR . 'test_*.php');
    assertTrue(is_array($gateRealFiles) && count($gateRealFiles) >= $gateMinimum, 'the real suite has at least that many test files (' . count((array) $gateRealFiles) . ')');

    /**
     * 🧪 A stand-in folder: $count test files, the library check, and a runner
     *    that prints what the real runner prints and exits with $exit.
     */
    $gateFolder = static function (int $count, string $runnerOutput, int $exit, string $libraryCheck = '<?php exit(0);'): array {
        $files = ['tools/check_vendored_libraries.php' => $libraryCheck];
        for ($i = 1; $i <= $count; $i++) {
            $files['tests/test_standin_' . $i . '.php'] = '<?php';
        }
        $files['tests/run.php'] = '<?php echo ' . var_export($runnerOutput, true) . '; exit(' . $exit . ');';
        return $files;
    };
    $gateLines = static function (int $discovered, int $assertions): string {
        return 'Discovered ' . $discovered . ' test file(s).' . "\n" . 'Total: ' . $assertions . ' assertion(s)  |  ' . $assertions . " passed  |  0 failed\n";
    };
    $gateEnough = max(1, $gateMinimum);
    $cases   = [
        'clean run passes'                => [$gateFolder($gateEnough, $gateLines($gateEnough, 9), 0), true],
        'a failing test fails'            => [$gateFolder($gateEnough, $gateLines($gateEnough, 9), 1), false],
        'a missing test runner fails'     => [['tools/check_vendored_libraries.php' => '<?php exit(0);'], false],
        'a failing library check fails'   => [$gateFolder($gateEnough, $gateLines($gateEnough, 9), 0, '<?php exit(1);'), false],
        'deprecation text fails'          => [$gateFolder($gateEnough, 'PHP Deprecated: old' . "\n" . $gateLines($gateEnough, 9), 0), false],
        // 🔢 Recheck finding 3: a run with nothing in it must never pass.
        'no test files fails'             => [$gateFolder(0, "Discovered 0 test file(s).\nNo assertions executed (0 tests).\n", 0), false],
        'too few test files fails'        => [$gateFolder($gateEnough - 1, $gateLines($gateEnough - 1, 9), 0), false],
        'no assertions run fails'         => [$gateFolder($gateEnough, 'Discovered ' . $gateEnough . " test file(s).\nNo assertions executed (0 tests).\n", 0), false],
        'runner skipping files fails'     => [$gateFolder($gateEnough, $gateLines($gateEnough - 1, 9), 0), false],
        'runner printing nothing fails'   => [$gateFolder($gateEnough, '', 0), false],
    ];

    foreach ($cases as $label => [$files, $shouldPass]) {
        $result = deployGateRun($files, $gateScript);
        assertEquals($shouldPass, $result['exit'] === 0, 'run-tests.sh: ' . $label . ' (exit ' . $result['exit'] . '): ' . substr(trim($result['output']), -200));
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

/**
 * 🚧 Every way found so far of letting a failed check through to the upload
 *    (recheck finding 3 of the 7n Phase 2 review). Each gate job (php-checks,
 *    tests) must: let only PHP 8.6 fail without blocking (a job-level
 *    continue-on-error, if there is one, must be exactly the 8.6 expression);
 *    have no step-level continue-on-error; never swallow a failure in a run
 *    line (`|| true`, `|| :`, `|| exit 0`, `set +e`); still test PHP 8.4 and
 *    8.5. The tests job must run the shared script as its whole command.
 *
 * @param string $deployText The text of deploy.yml
 * @return array<int, string> Problems found ([] = the gate holds)
 */
function deployGateProblems(string $deployText): array
{
    $problems = [];

    foreach (['php-checks', 'tests'] as $jobName) {
        // ✂️ The job's own lines: from "  name:" to the next top-level job.
        if (preg_match('/^  ' . preg_quote($jobName, '/') . ':\n((?:(?:    .*|\s*)\n)*)/m', $deployText, $jobFound) !== 1) {
            $problems[] = $jobName . ': job not found';
            continue;
        }
        $job = $jobFound[1];

        preg_match_all('/^    continue-on-error:(.*)$/m', $job, $jobLevel);
        foreach ($jobLevel[1] as $value) {
            if (trim($value) !== "\${{ matrix.php == '8.6' }}") {
                $problems[] = $jobName . ': job-level continue-on-error is not limited to PHP 8.6 (' . trim($value) . ')';
            }
        }
        if (preg_match('/^ {6,}(?:- )?continue-on-error:/m', $job) === 1) {
            $problems[] = $jobName . ': a step has continue-on-error';
        }
        if (preg_match('/\|\|\s*(?:true|:|exit\s+0)\b|\|\|\s*:\s*$|\bset\s+\+e\b/m', $job) === 1) {
            $problems[] = $jobName . ': a run line swallows a failure';
        }
        foreach (["'8.4'", "'8.5'"] as $version) {
            if (preg_match('/^          - ' . preg_quote($version, '/') . '$/m', $job) !== 1) {
                $problems[] = $jobName . ': PHP ' . trim($version, "'") . ' is no longer checked';
            }
        }
        if ($jobName === 'tests' && preg_match('/^        run: bash tools\/ci\/run-tests\.sh$/m', $job) !== 1) {
            $problems[] = 'tests: the step does not run exactly "bash tools/ci/run-tests.sh"';
        }
    }

    return $problems;
}

assertEquals([], deployGateProblems($deployText), 'recheck F3: the real deploy.yml gate holds: ' . implode(' | ', deployGateProblems($deployText)));

// 🧬 Each weakening the recheck found (and close relatives) must be caught.
$gateWeakenings = [
    'tests job continue-on-error: true'        => static function (string $t): string {
        return (string) preg_replace("/(\n  tests:\n(?:    .*\n)*?)    continue-on-error: .*\n/", "\$1    continue-on-error: true\n", $t, 1);
    },
    'php-checks job continue-on-error: true'   => static function (string $t): string {
        return (string) preg_replace("/(\n  php-checks:\n(?:    .*\n)*?)    continue-on-error: .*\n/", "\$1    continue-on-error: true\n", $t, 1);
    },
    'step continue-on-error on the test step'  => static function (string $t): string {
        return str_replace("      - name: Run test suite\n", "      - name: Run test suite\n        continue-on-error: true\n", $t);
    },
    '|| true after the test script'            => static function (string $t): string {
        return str_replace("        run: bash tools/ci/run-tests.sh\n", "        run: bash tools/ci/run-tests.sh || true\n", $t);
    },
    '|| true after the lint script'            => static function (string $t): string {
        return str_replace("        run: bash tools/ci/php-lint.sh\n", "        run: bash tools/ci/php-lint.sh || true\n", $t);
    },
    'set +e in a run block'                    => static function (string $t): string {
        return str_replace("        run: bash tools/ci/run-tests.sh\n", "        run: |\n          set +e\n          bash tools/ci/run-tests.sh\n", $t);
    },
    'PHP 8.5 dropped from the tests job'       => static function (string $t): string {
        return (string) preg_replace("/(\n  tests:\n(?:    .*\n|\s*\n)*?)          - '8\\.5'\n/", "\$1", $t, 1);
    },
];
foreach ($gateWeakenings as $gateLabel => $gateWeaken) {
    $gateWeakened = $gateWeaken($deployText);
    assertTrue($gateWeakened !== $deployText, 'recheck F3 mutation applied: ' . $gateLabel);
    assertTrue(deployGateProblems($gateWeakened) !== [], 'recheck F3: the gate test catches ' . $gateLabel);
}

// 🚦 Recheck finding 8: two pushes to the same branch must never upload at
//    the same time, and an upload must never be stopped part-way. The whole
//    workflow is in one concurrency group per branch that queues runs and
//    never cancels one in progress.
assertEquals(
    1,
    preg_match('/^concurrency:\n  group: deploy-\$\{\{ github\.ref \}\}\n  cancel-in-progress: false$/m', $deployText),
    'recheck F8: deploy.yml queues deploys per branch and never cancels one part-way'
);
