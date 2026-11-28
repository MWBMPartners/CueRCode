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
 *   4. Decision 0040 (#174): tools/ci/run-integration-tests.sh fails when a
 *      required real-database script is missing, when none exist, when one
 *      fails or prints deprecation text, and when the server guard refuses;
 *      the guard (tools/ci/integration_db_guard.php) refuses a far-away host
 *      and a server holding any non-test database; ci.yml and deploy.yml run
 *      the job on MySQL 8.0, MySQL 8.4 and MariaDB 10.6, the upload waits for
 *      it, and every weakening of that requirement is caught.
 *
 * Each script case runs in a throwaway folder holding small stand-in files.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.2.0
 * @since       #155
 * @since       2026-11-27 (7n Phase 2 recheck finding 3) - gate weakenings, empty runs
 * @since       2026-11-28 (#174, decision 0040) - the real-database tests gate the deploy
 * @see         tools/ci/run-tests.sh
 * @see         tools/ci/run-integration-tests.sh
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

    // =========================================================================
    // 🗄️ Decision 0040 (#174): tools/ci/run-integration-tests.sh, with stand-in
    //    real-database scripts and a stand-in server guard (the real guard's
    //    decisions are proved further down).
    // =========================================================================
    $itScript = $gateRoot . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'ci' . DIRECTORY_SEPARATOR . 'run-integration-tests.sh';
    $itNames  = [];
    if (preg_match('/^REQUIRED_SCRIPTS="([a-z_ ]+)"$/m', (string) @file_get_contents($itScript), $itNamesFound) === 1) {
        $itNames = preg_split('/\s+/', trim($itNamesFound[1]));
    }
    // 📋 Every real script on disk is required by name, and there are eight.
    $itOnDisk = array_map(
        static function (string $path): string {
            return basename($path, '_real_mysql.php');
        },
        (array) glob(__DIR__ . DIRECTORY_SEPARATOR . 'integration' . DIRECTORY_SEPARATOR . '*_real_mysql.php')
    );
    sort($itOnDisk);
    $itSorted = $itNames;
    sort($itSorted);
    assertEquals($itOnDisk, $itSorted, '0040: run-integration-tests.sh requires every real-database script on disk by name');
    assertTrue(count($itNames) >= 8, '0040: at least the eight real-database scripts are required (' . count($itNames) . ')');

    /**
     * 🧪 A stand-in folder: the guard (exit $guardExit), and one script per
     *    required name; $overrides replaces a script's contents (null = leave
     *    it out).
     */
    $itFolder = static function (array $names, array $overrides = [], string $guard = '<?php exit(0);'): array {
        $files = ['tools/ci/integration_db_guard.php' => $guard];
        foreach ($names as $name) {
            $body = '<?php echo "' . $name . ' ok " . implode(" ", array_slice($argv, 1)) . " db=" . getenv("CUERCODE_IT_DB_NAME") . " all=" . getenv("CUERCODE_IT_REQUIRE_ALL") . "\n"; exit(0);';
            if (array_key_exists($name, $overrides)) {
                $body = $overrides[$name];
            }
            if ($body !== null) {
                $files['tests/integration/' . $name . '_real_mysql.php'] = $body;
            }
        }
        return $files;
    };
    $itFirst = (string) ($itNames[0] ?? 'migrations');
    $itCases = [
        'clean run passes'                 => [$itFolder($itNames), true],
        'a missing required script fails' => [$itFolder($itNames, [$itFirst => null]), false],
        'a failing script fails'           => [$itFolder($itNames, [$itFirst => '<?php echo "FAIL\n"; exit(1);']), false],
        'a refused script fails'           => [$itFolder($itNames, [$itFirst => '<?php exit(2);']), false],
        'deprecation text fails'           => [$itFolder($itNames, [$itFirst => '<?php echo "PHP Deprecated: old\n"; exit(0);']), false],
        'a guard refusal fails'            => [$itFolder($itNames, [], '<?php fwrite(STDERR, "Refusing\n"); exit(2);'), false],
        'an unreachable server fails'      => [$itFolder($itNames, [], '<?php exit(1);'), false],
        'a missing guard fails'            => [array_diff_key($itFolder($itNames), ['tools/ci/integration_db_guard.php' => true]), false],
        'no scripts at all fails'          => [['tools/ci/integration_db_guard.php' => '<?php exit(0);'], false],
    ];
    foreach ($itCases as $label => [$files, $shouldPass]) {
        $result = deployGateRun($files, $itScript);
        assertEquals($shouldPass, $result['exit'] === 0, '0040 run-integration-tests.sh: ' . $label . ' (exit ' . $result['exit'] . '): ' . substr(trim($result['output']), -200));
        if ($label === 'clean run passes') {
            // 🧾 Each script gets its own cuercode_it_ci_ database, the "fail on
            //    a part that cannot run" switch, and --load-schema where needed.
            assertContains('Real-database tests ran: ' . count($itNames) . ' script(s), all passed.', $result['output'], '0040: the clean run reports every script');
            assertContains('migrations ok  db=cuercode_it_ci_migrations all=1', $result['output'], '0040: a script that builds its own databases gets no options and its own name');
            assertContains('email_queue ok --load-schema db=cuercode_it_ci_email_queue all=1', $result['output'], '0040: email_queue loads the starting tables');
            assertContains('account_recovery ok --load-schema db=cuercode_it_ci_account_recovery', $result['output'], '0040: account_recovery loads the starting tables');
        }
        if ($label === 'a failing script fails') {
            // 🏃 One failure does not stop the others running.
            assertContains((string) end($itNames) . ': passed.', $result['output'], '0040: the scripts after a failing one still run');
        }
    }
} else {
    assertTrue(true, 'run-tests.sh cases skipped: no /bin/bash');
}

// =============================================================================
// 🛡️ Decision 0040 (#174): the throwaway-server guard's own decisions
// =============================================================================
$itGuardFile = $gateRoot . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'ci' . DIRECTORY_SEPARATOR . 'integration_db_guard.php';
if (!defined('CUERCODE_IT_GUARD_NO_MAIN')) {
    define('CUERCODE_IT_GUARD_NO_MAIN', true);
}
if (is_file($itGuardFile)) {
    require_once $itGuardFile;
}
assertTrue(function_exists('cuercodeItGuardProblems'), '0040: the guard file defines cuercodeItGuardProblems()');
if (function_exists('cuercodeItGuardProblems')) {
    $itSystem = ['information_schema', 'mysql', 'performance_schema', 'sys'];
    assertEquals([], cuercodeItGuardProblems('127.0.0.1', $itSystem), '0040 guard: an empty server on this machine is safe');
    assertEquals([], cuercodeItGuardProblems('localhost', array_merge($itSystem, ['cuercode_it_ci_migrations_a'])), '0040 guard: test databases left from a run are allowed');
    assertEquals([], cuercodeItGuardProblems('::1', $itSystem), '0040 guard: IPv6 loopback is this machine');
    assertTrue(cuercodeItGuardProblems('db.example.com', $itSystem) !== [], '0040 guard: a server somewhere else is refused');
    assertTrue(cuercodeItGuardProblems('10.0.0.5', $itSystem) !== [], '0040 guard: a server on the local network is refused');
    assertTrue(cuercodeItGuardProblems('127.0.0.1', array_merge($itSystem, ['cuercode_live'])) !== [], '0040 guard: a server holding a live database is refused');
    assertTrue(cuercodeItGuardProblems('127.0.0.1', array_merge($itSystem, ['other_project'])) !== [], '0040 guard: a server holding another project is refused');
    assertTrue(cuercodeItGuardProblems('127.0.0.1', array_merge($itSystem, ['CUERCODE_IT_x'])) !== [], '0040 guard: the prefix is matched exactly (capitals are a different name)');
    assertTrue(cuercodeItGuardNameAllowed('cuercode_it_ci_email_queue'), '0040 guard: a test database name is allowed');
    assertFalse(cuercodeItGuardNameAllowed('cuercode_it_x`; DROP DATABASE mysql; --'), '0040 guard: a name with other characters is refused');
    assertFalse(cuercodeItGuardNameAllowed('cuercode'), '0040 guard: a name without the prefix is refused');

    // 🏠 The real guard, run as a command: a far-away host is refused before
    //    any connection is tried, and so is a bad name to create.
    $itGuardCases = [
        'far-away host' => [['CUERCODE_IT_DB_HOST' => 'db.example.com', 'CUERCODE_IT_DB_USER' => 'root'], []],
        'no host'       => [['CUERCODE_IT_DB_HOST' => '', 'CUERCODE_IT_DB_USER' => 'root'], []],
        'bad name'      => [['CUERCODE_IT_DB_HOST' => '127.0.0.1', 'CUERCODE_IT_DB_USER' => 'root'], ['--create=live_site']],
    ];
    foreach ($itGuardCases as $itLabel => [$itEnv, $itArgs]) {
        $itProcess = proc_open(
            array_merge([PHP_BINARY, $itGuardFile], $itArgs),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $itPipes,
            null,
            array_merge(['PATH' => (string) getenv('PATH')], $itEnv)
        );
        $itOutput = '';
        $itExit   = -1;
        if (is_resource($itProcess)) {
            fclose($itPipes[0]);
            $itOutput = (string) stream_get_contents($itPipes[1]);
            fclose($itPipes[1]);
            $itExit = proc_close($itProcess);
        }
        assertEquals(2, $itExit, '0040 guard command: ' . $itLabel . ' is refused (exit ' . $itExit . '): ' . trim($itOutput));
    }
}

$workflowDir = $gateRoot . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'workflows' . DIRECTORY_SEPARATOR;
$ciText      = (string) file_get_contents($workflowDir . 'ci.yml');
$deployText  = (string) file_get_contents($workflowDir . 'deploy.yml');

assertContains('bash tools/ci/run-tests.sh', $ciText, 'ci.yml runs the shared test script');
assertContains('bash tools/ci/run-tests.sh', $deployText, 'deploy.yml runs the shared test script');
assertEquals(
    1,
    preg_match('/^  deploy:\n(?:    .*\n|\s*\n)*?    needs: \[php-checks, tests, integration\]$/m', $deployText),
    '0040: the upload job waits for the PHP checks, the test suite and the real-database tests'
);
// 🗄️ Decision 0040: ci.yml runs the same real-database job on pull requests
//    and pushes, on the same three databases.
assertEquals([], deployGateIntegrationProblems($ciText, false), '0040: ci.yml runs the real-database tests on the three databases: ' . implode(' | ', deployGateIntegrationProblems($ciText, false)));
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

    // 🗄️ Decision 0040: the real-database job, and the upload waiting for it.
    $problems = array_merge($problems, deployGateIntegrationProblems($deployText, true));
    if (preg_match('/^  deploy:\n(?:    .*\n|\s*\n)*?    needs: \[([^\]]*)\]$/m', $deployText, $needsFound) !== 1) {
        $problems[] = 'deploy: no needs list';
    } else {
        $needs = array_map('trim', explode(',', $needsFound[1]));
        foreach (['php-checks', 'tests', 'integration'] as $needed) {
            if (!in_array($needed, $needs, true)) {
                $problems[] = 'deploy: the upload no longer waits for ' . $needed;
            }
        }
    }

    return $problems;
}

/**
 * 🗄️ Ways the real-database job (decision 0040, #174) could stop catching a
 *    failure: missing; any continue-on-error (every database blocks, and the
 *    job runs PHP 8.4 only, so the 8.6 exception does not apply); a swallowed
 *    failure; one of MySQL 8.0, MySQL 8.4 or MariaDB 10.6 dropped; the shared
 *    script not run as the whole command; a shallow checkout (the migrations
 *    script reads git history). In deploy.yml ($strict) no step may have an
 *    `if:` and the job's only `if:` is the SFTP switch every job has; ci.yml
 *    may skip on a branch that has no tests yet (its "present" step).
 *
 * @param string $workflowText The text of ci.yml or deploy.yml
 * @param bool   $strict       True for deploy.yml
 * @return array<int, string> Problems found ([] = the job holds)
 */
function deployGateIntegrationProblems(string $workflowText, bool $strict): array
{
    if (preg_match('/^  integration:\n((?:(?:    .*|\s*)\n)*)/m', $workflowText, $jobFound) !== 1) {
        return ['integration: job not found'];
    }
    $job      = $jobFound[1];
    $problems = [];

    if (preg_match('/^ {4,}(?:- )?continue-on-error:/m', $job) === 1) {
        $problems[] = 'integration: continue-on-error is set';
    }
    if (preg_match('/\|\|\s*(?:true|:|exit\s+0)\b|\|\|\s*:\s*$|\bset\s+\+e\b/m', $job) === 1) {
        $problems[] = 'integration: a run line swallows a failure';
    }
    foreach (['mysql:8\.0(?:\.[0-9]+)?' => 'MySQL 8.0', 'mysql:8\.4(?:\.[0-9]+)?' => 'MySQL 8.4', 'mariadb:10\.6(?:\.[0-9]+)?' => 'MariaDB 10.6'] as $pattern => $label) {
        if (preg_match('/^          - \'' . $pattern . '\'$/m', $job) !== 1) {
            $problems[] = 'integration: ' . $label . ' is no longer tested';
        }
    }
    if (preg_match('/^        image: \$\{\{ matrix\.database \}\}$/m', $job) !== 1) {
        $problems[] = 'integration: the database server is not the matrix database';
    }
    if (preg_match('/^        run: bash tools\/ci\/run-integration-tests\.sh$/m', $job) !== 1) {
        $problems[] = 'integration: the step does not run exactly "bash tools/ci/run-integration-tests.sh"';
    }
    if (preg_match('/^          fetch-depth: 0$/m', $job) !== 1) {
        $problems[] = 'integration: the checkout is not full history';
    }
    if ($strict) {
        if (preg_match('/^ {6,}(?:- )?if:/m', $job) === 1) {
            $problems[] = 'integration: a step can be skipped (if:)';
        }
        preg_match_all('/^    if:(.*)$/m', $job, $jobIfs);
        foreach ($jobIfs[1] as $value) {
            if (trim($value) !== "\${{ vars.SFTP_ENABLED == 'true' }}") {
                $problems[] = 'integration: the job runs only on a condition (' . trim($value) . ')';
            }
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
    // 🗄️ Decision 0040: weakenings of the real-database requirement.
    '0040: upload no longer waits for integration' => static function (string $t): string {
        return str_replace('    needs: [php-checks, tests, integration]', '    needs: [php-checks, tests]', $t);
    },
    '0040: integration job continue-on-error'      => static function (string $t): string {
        return str_replace("  integration:\n    name:", "  integration:\n    continue-on-error: true\n    name:", $t);
    },
    '0040: 8.6-style continue-on-error on it'      => static function (string $t): string {
        return str_replace("  integration:\n    name:", "  integration:\n    continue-on-error: \${{ matrix.php == '8.6' }}\n    name:", $t);
    },
    '0040: step continue-on-error'                 => static function (string $t): string {
        return str_replace("      - name: Run the real-database tests\n", "      - name: Run the real-database tests\n        continue-on-error: true\n", $t);
    },
    '0040: || true after the script'               => static function (string $t): string {
        return str_replace("        run: bash tools/ci/run-integration-tests.sh\n", "        run: bash tools/ci/run-integration-tests.sh || true\n", $t);
    },
    '0040: the step skipped by an if:'             => static function (string $t): string {
        return str_replace("      - name: Run the real-database tests\n", "      - name: Run the real-database tests\n        if: false\n", $t);
    },
    '0040: the job limited to main'                => static function (string $t): string {
        return str_replace("  integration:\n    name: Real-database tests (\${{ matrix.database }})\n    runs-on: ubuntu-latest\n    if: \${{ vars.SFTP_ENABLED == 'true' }}\n", "  integration:\n    name: Real-database tests (\${{ matrix.database }})\n    runs-on: ubuntu-latest\n    if: \${{ vars.SFTP_ENABLED == 'true' && github.ref_name == 'main' }}\n", $t);
    },
    '0040: MySQL 8.4 dropped'                      => static function (string $t): string {
        return str_replace("          - 'mysql:8.4'\n", '', $t);
    },
    '0040: MariaDB 10.6 dropped'                   => static function (string $t): string {
        return str_replace("          - 'mariadb:10.6'\n", '', $t);
    },
    '0040: the shared script replaced'             => static function (string $t): string {
        return str_replace("        run: bash tools/ci/run-integration-tests.sh\n", "        run: php tests/integration/migrations_real_mysql.php\n", $t);
    },
    '0040: shallow checkout'                       => static function (string $t): string {
        return (string) preg_replace("/(\n  integration:\n(?:    .*\n|\s*\n)*?)          fetch-depth: 0\n/", "\$1          fetch-depth: 1\n", $t, 1);
    },
    '0040: integration job removed'                => static function (string $t): string {
        return str_replace("\n  integration:\n", "\n  integration_off:\n", $t);
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
