<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Tests for the DATABASE UPGRADE RUNNER (task 7n Phase 1A)
 * ==============================================================================
 *
 * Path: tests/test_migrations.php
 *
 * Covers _functions/fn_migrations.php, _includes/cli/migrate.php and the
 * migration files in _includes/sql/migrations/, WITHOUT a database:
 *
 *   1. Constants (exit codes, lock name).
 *   2. File names, keys and fingerprints (checksums).
 *   3. Blanking quoted text, and step validation - every allowed step type,
 *      every refused SQL word (DROP, TRUNCATE, RENAME, MODIFY, CHANGE,
 *      DELETE FROM) both outside quotes (refused) and inside a COMMENT
 *      (allowed), one statement per step, one change per ALTER, and the
 *      "insert only if missing" ending.
 *   4. Whole-definition validation.
 *   5. The real migration files 0001 and 0002 and the runner's own ledger
 *      table: valid, and with the content plan §5.4 / §5.6 asks for.
 *   6. Finding files in a folder (order, bad names, duplicate numbers).
 *   7. The "what is waiting" plan: order, pending list, changed-file
 *      warning, missing files, out-of-order files.
 *   8. Step-needed decisions with a STAND-IN "exists" checker.
 *   9. The install.sql column-name reader and the baseline comparison.
 *  10. install.sql is safe to run twice (only SET + CREATE TABLE IF NOT
 *      EXISTS), and the seed_data.sql frozen-baseline note.
 *  11. Command-line options, exit-code mapping and report text.
 *  12. Source guards (no site start-up helpers; prepared statements; the
 *      command-line guard is the first statement).
 *  13. The real migrate.php as a child process with --help and bad options
 *      ONLY (those exit before any database code is loaded).
 *
 * ⚠️ NOT covered here (needs a real database): the INFORMATION_SCHEMA
 *    questions, the named lock and the real SQL. Those belong in
 *    tests/integration/migrations_real_mysql.php (Phase 1A part 2).
 *
 * ⚠️ House rules for this file (it runs in the SAME global scope as
 *    tests/run.php and every other test file):
 *    - every variable is prefixed $mig, so nothing clashes;
 *    - it never defines dbQuery(), getDB(), getConfig(), logError() or
 *      logActivity() - other test files assert those do NOT exist;
 *    - it never starts migrate.php except with --help or an invalid option.
 *      A real run on a machine with a real _auth_keys/auth_creds.php could
 *      change a real database.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-23 (task 7n Phase 1A)
 * @see         tests/bootstrap.php  - requireFn() loader (no DB connection)
 * @see         tests/lib/assert.php - the assert* functions used below
 * @see         web/CueRCode.net/_functions/fn_migrations.php
 * @see         web/CueRCode.net/_includes/cli/migrate.php
 * @see         .claude/plans/7n-admin-commerce-plan.md §5.1, §5.6, §9 Phase 1A
 */

requireFn('fn_migrations.php');

// ==============================================================================
// ✅ Section 1: constants
// ==============================================================================

assertEquals(0, CUERCODE_MIGRATE_EXIT_OK, 'exit code: 0 = up to date / applied');
assertEquals(1, CUERCODE_MIGRATE_EXIT_FAILED, 'exit code: 1 = a step failed');
assertEquals(2, CUERCODE_MIGRATE_EXIT_REFUSED, 'exit code: 2 = refused');
assertEquals(3, CUERCODE_MIGRATE_EXIT_PENDING, 'exit code: 3 = --status found pending migrations');
assertEquals('cuercode_schema_migrate', CUERCODE_MIGRATION_LOCK_NAME, 'lock name matches plan §5.1');

// 🚫 Loading the runner must not bring in the site start-up helpers.
foreach (['dbQuery', 'getDB', 'getConfig', 'logError', 'logActivity'] as $migForbiddenFunction) {
    assertFalse(function_exists($migForbiddenFunction), 'loading fn_migrations.php does not define ' . $migForbiddenFunction . '()');
}

// ==============================================================================
// ✅ Section 2: file names, keys, checksums, applied-by labels
// ==============================================================================

assertEquals('0001_subscription_price_snapshot_columns', migrationKeyFromFileName('0001_subscription_price_snapshot_columns.php'), 'key from a good file name');
assertEquals('0042_x', migrationKeyFromFileName('0042_x.php'), 'key from a short good file name');
foreach ([
    '0000_reserved.php'       => 'number 0000 is reserved',
    '001_three_digits.php'    => 'three digits',
    '00001_five_digits.php'   => 'five digits',
    '0003_Upper_case.php'     => 'upper case',
    '0003-dash.php'           => 'dash instead of underscore',
    '0003_double__under.php'  => 'double underscore',
    '0003_trailing_.php'      => 'trailing underscore',
    '0003_name.php.bak'       => 'not ending in .php',
    '0003_.php'               => 'no name',
    '0003_name.PHP'           => 'upper-case extension',
    '0003_' . str_repeat('a', 96) . '.php' => 'longer than the 100-character ledger column',
] as $migBadName => $migWhy) {
    assertEquals(null, migrationKeyFromFileName($migBadName), 'bad file name refused (' . $migWhy . ')');
}

$migChecksumLf = migrationChecksumForContents("<?php\nreturn [];\n");
assertMatches('/^[0-9a-f]{64}$/', $migChecksumLf, 'checksum is 64 lower-case hex characters (SHA-256)');
assertEquals($migChecksumLf, migrationChecksumForContents("<?php\r\nreturn [];\r\n"), 'checksum ignores Windows line endings (no false "changed" warning)');
assertTrue($migChecksumLf !== migrationChecksumForContents("<?php\nreturn [ ];\n"), 'checksum changes when the content changes');

assertEquals('cli:www-data', migrationSafeAppliedBy('cli:www-data'), 'applied-by label kept when safe');
assertEquals('admin:7script', migrationSafeAppliedBy("admin:7<script>\n"), 'applied-by label strips unsafe characters (< > and line breaks)');
assertEquals('unknown', migrationSafeAppliedBy('<>'), 'applied-by label never empty');
assertEquals(100, strlen(migrationSafeAppliedBy(str_repeat('a', 300))), 'applied-by label capped at 100 characters');

// ==============================================================================
// ✅ Section 3: blanking quoted text, and step validation
// ==============================================================================

$migBlank = migrationBlankQuotedText("ALTER TABLE `t` ADD COLUMN `c` INT COMMENT 'DROP it; it''s fine'");
assertTrue($migBlank['ok'], 'blanker: closed quotes are fine');
assertFalse(str_contains($migBlank['text'], 'DROP'), 'blanker: words inside quotes are blanked');
assertFalse(str_contains($migBlank['text'], ';'), 'blanker: a ; inside quotes is blanked');
assertContains('`t`', $migBlank['text'], 'blanker: back-quoted names are kept');
assertEquals(strlen("ALTER TABLE `t` ADD COLUMN `c` INT COMMENT 'DROP it; it''s fine'"), strlen($migBlank['text']), 'blanker: keeps the length (so positions still line up)');
assertFalse(migrationBlankQuotedText("INSERT INTO `t` VALUES ('open")['ok'], 'blanker: an unclosed quote is reported');
assertFalse(migrationBlankQuotedText('ALTER TABLE `t')['ok'], 'blanker: an unclosed back-quote is reported');
assertContains("'`'", migrationBlankQuotedText("SELECT '`'")['text'] . "'`'", 'blanker: a back-quote inside quoted text does not start a name');
assertTrue(migrationBlankQuotedText("SELECT '`' FROM `t`")['ok'], 'blanker: a back-quote inside quoted text is not treated as a name');

// 🧰 Known-good examples of every step type.
$migGoodSteps = [
    'create_table' => [
        'type'  => 'create_table',
        'table' => 'tblExample',
        'sql'   => 'CREATE TABLE IF NOT EXISTS `tblExample` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'Drop-in id; never DELETE FROM here\', `kind` ENUM(\'a\',\'b\') NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB',
    ],
    'add_column' => [
        'type'  => 'add_column',
        'table' => 'tblExample',
        'name'  => 'price',
        'sql'   => "ALTER TABLE `tblExample`\n    ADD COLUMN `price` DECIMAL(10,2) NULL COMMENT 'Change, rename, modify, truncate, drop: all fine in a comment' AFTER `id`;",
    ],
    'add_index' => [
        'type'  => 'add_index',
        'table' => 'tblExample',
        'name'  => 'idx_example_kind',
        'sql'   => 'ALTER TABLE `tblExample` ADD INDEX `idx_example_kind` (`kind`, `price`)',
    ],
    'add_index_unique' => [
        'type'  => 'add_index',
        'table' => 'tblExample',
        'name'  => 'idx_example_unique',
        'sql'   => 'ALTER TABLE `tblExample` ADD UNIQUE KEY `idx_example_unique` (`kind`)',
    ],
    'add_foreign_key' => [
        'type'  => 'add_foreign_key',
        'table' => 'tblExample',
        'name'  => 'fk_example_user',
        'sql'   => 'ALTER TABLE `tblExample` ADD CONSTRAINT `fk_example_user` FOREIGN KEY (`id`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL ON UPDATE CASCADE',
    ],
    'add_foreign_key_cascade' => [
        'type'  => 'add_foreign_key',
        'table' => 'tblExample',
        'name'  => 'fk_example_cascade',
        'sql'   => 'ALTER TABLE `tblExample` ADD CONSTRAINT `fk_example_cascade` FOREIGN KEY (`id`) REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE',
    ],
    'insert_if_missing' => [
        'type'  => 'insert_if_missing',
        'table' => 'tblSettings',
        'label' => 'setting example_key',
        'sql'   => 'INSERT INTO `tblSettings` (`settingKey`, `settingValue`) VALUES (\'example_key\', \'it\'\'s; DROP\') ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`',
    ],
    'insert_select' => [
        'type'  => 'insert_if_missing',
        'table' => 'tblPlanPricePoints',
        'sql'   => 'INSERT INTO `tblPlanPricePoints` (`planID`, `priceKey`) SELECT `planID`, \'monthly_gbp\' FROM `tblSubscriptionPlans` WHERE `planKey` = \'pro\' ON DUPLICATE KEY UPDATE `priceKey`=`priceKey`',
    ],
    'update_if_unchanged' => [
        'type'  => 'update_if_unchanged',
        'table' => 'tblSubscriptionPlans',
        'sql'   => 'UPDATE `tblSubscriptionPlans` SET `priceMonthly` = 6.99 WHERE `planKey` = \'pro\' AND `priceMonthly` = 4.99',
    ],
];
foreach ($migGoodSteps as $migGoodLabel => $migGoodStep) {
    assertEquals([], migrationValidateStep($migGoodStep, 1), 'valid step accepted: ' . $migGoodLabel);
}

// 🧰 Helper: does validating this step give a problem containing $needle?
$migRefusedWith = static function (array $migStep, string $migNeedle): bool {
    foreach (migrationValidateStep($migStep, 1) as $migProblem) {
        if (str_contains($migProblem, $migNeedle)) {
            return true;
        }
    }
    return false;
};

// 🚫 Every refused SQL word, outside quotes - in an ALTER and in an UPDATE.
foreach (['DROP', 'TRUNCATE', 'RENAME', 'MODIFY', 'CHANGE'] as $migWord) {
    assertTrue(
        $migRefusedWith(['type' => 'update_if_unchanged', 'table' => 'tblX', 'sql' => 'UPDATE `tblX` SET `a` = 1 WHERE `b` = 2 ' . $migWord], 'the word ' . $migWord),
        'refused word outside quotes: ' . $migWord
    );
    assertTrue(
        $migRefusedWith(['type' => 'add_column', 'table' => 'tblX', 'name' => 'c', 'sql' => 'ALTER TABLE `tblX` ADD COLUMN `c` INT ' . strtolower($migWord) . ' x'], 'the word ' . $migWord),
        'refused word in lower case: ' . strtolower($migWord)
    );
}
assertTrue(
    $migRefusedWith(['type' => 'update_if_unchanged', 'table' => 'tblX', 'sql' => 'UPDATE `tblX` SET `a` = 1 WHERE `b` IN (SELECT 1) AND 1 = 1 OR DELETE   FROM'], 'DELETE FROM'),
    'refused phrase DELETE FROM (any spacing)'
);
assertTrue(
    $migRefusedWith(['type' => 'add_column', 'table' => 'tblX', 'name' => 'c', 'sql' => 'ALTER TABLE `tblX` ADD COLUMN `c` INT, DROP COLUMN `d`'], 'DROP'),
    'refused: an ALTER that also drops a column'
);
assertFalse(
    $migRefusedWith(['type' => 'add_column', 'table' => 'tblX', 'name' => 'changesJSON', 'sql' => 'ALTER TABLE `tblX` ADD COLUMN `changesJSON` JSON NULL'], 'the word'),
    'a name that merely CONTAINS a refused word (changesJSON) is fine'
);

// ✂️ One statement per step.
assertTrue($migRefusedWith(['type' => 'insert_if_missing', 'table' => 'tblX', 'sql' => 'INSERT INTO `tblX` (`a`) VALUES (1); INSERT INTO `tblX` (`a`) VALUES (2) ON DUPLICATE KEY UPDATE `a` = `a`'], 'only one SQL statement'), 'refused: two statements');
assertEquals([], migrationValidateStep(['type' => 'add_index', 'table' => 'tblX', 'name' => 'i', 'sql' => 'ALTER TABLE `tblX` ADD INDEX `i` (`a`);  '], 1), 'a single trailing ; is fine');
assertTrue($migRefusedWith(['type' => 'add_index', 'table' => 'tblX', 'name' => 'i', 'sql' => 'ALTER TABLE `tblX` ADD INDEX `i` (`a`);;'], 'only one SQL statement'), 'refused: two trailing ;');

// ☝️ One change per ALTER.
assertTrue($migRefusedWith(['type' => 'add_column', 'table' => 'tblX', 'name' => 'a', 'sql' => 'ALTER TABLE `tblX` ADD COLUMN `a` INT NULL, ADD COLUMN `b` INT NULL'], 'only one thing'), 'refused: two columns in one step');
assertTrue($migRefusedWith(['type' => 'add_column', 'table' => 'tblX', 'name' => 'a', 'sql' => 'ALTER TABLE `tblX` ADD COLUMN `a` INT NULL, ADD INDEX `i` (`a`)'], 'only one thing'), 'refused: a column and an index in one step');

// 🔤 Ambiguous characters and comments.
assertTrue($migRefusedWith(['type' => 'add_column', 'table' => 'tblX', 'name' => 'a', 'sql' => 'ALTER TABLE `tblX` ADD COLUMN `a` INT COMMENT \'a\\\' DROP\''], 'backslashes'), 'refused: backslash');
assertTrue($migRefusedWith(['type' => 'add_column', 'table' => 'tblX', 'name' => 'a', 'sql' => 'ALTER TABLE `tblX` ADD COLUMN `a` INT COMMENT "x"'], 'double quotes'), 'refused: double-quoted text');
foreach (['-- note', '/* note */', '# note'] as $migComment) {
    assertTrue($migRefusedWith(['type' => 'add_column', 'table' => 'tblX', 'name' => 'a', 'sql' => 'ALTER TABLE `tblX` ADD COLUMN `a` INT ' . $migComment], 'SQL comments'), 'refused: SQL comment ' . $migComment);
}
assertTrue($migRefusedWith(['type' => 'add_column', 'table' => 'tblX', 'name' => 'a', 'sql' => 'ALTER TABLE `tblX` ADD COLUMN `a` INT COMMENT \'open'], 'never closed'), 'refused: unclosed quote');

// 🧾 Fields and types.
assertTrue($migRefusedWith(['type' => 'drop_table', 'table' => 'tblX', 'sql' => 'x'], 'type must be one of'), 'refused: type not on the whitelist');
assertTrue($migRefusedWith(['type' => 'raw_sql', 'table' => 'tblX', 'sql' => 'x'], 'type must be one of'), 'refused: raw_sql type');
assertTrue($migRefusedWith(['table' => 'tblX', 'sql' => 'x'], 'type must be one of'), 'refused: no type');
assertTrue($migRefusedWith(['type' => 'add_index', 'tabel' => 'tblX', 'table' => 'tblX', 'name' => 'i', 'sql' => 'ALTER TABLE `tblX` ADD INDEX `i` (`a`)'], 'unknown field'), 'refused: unknown field (typo)');
assertTrue($migRefusedWith(['type' => 'add_index', 'table' => 'tbl X', 'name' => 'i', 'sql' => 'x'], 'table must be'), 'refused: table name with a space');
assertTrue($migRefusedWith(['type' => 'add_index', 'table' => 'tblX', 'sql' => 'ALTER TABLE `tblX` ADD INDEX `i` (`a`)'], 'name must be'), 'refused: add_index without a name');
assertTrue($migRefusedWith(['type' => 'create_table', 'table' => 'tblX', 'name' => 'tblX', 'sql' => 'CREATE TABLE IF NOT EXISTS `tblX` (`a` INT)'], 'has no name field'), 'refused: create_table with a name');
assertTrue($migRefusedWith(['type' => 'add_index', 'table' => 'tblX', 'name' => 'i', 'sql' => 'ALTER TABLE tblX ADD INDEX `i` (`a`)'], 'must appear back-quoted'), 'refused: table not back-quoted in the SQL');
assertTrue($migRefusedWith(['type' => 'add_index', 'table' => 'tblX', 'name' => 'i', 'sql' => 'ALTER TABLE `tblX` ADD INDEX `j` (`a`)'], 'the name `i`'), 'refused: name not in the SQL');
assertTrue($migRefusedWith(['type' => 'add_index', 'table' => 'tblX', 'name' => 'i', 'sql' => '   '], 'non-empty'), 'refused: empty SQL');
assertTrue($migRefusedWith(['type' => 'insert_if_missing', 'table' => 'tblX', 'label' => "bad\nlabel", 'sql' => 'INSERT INTO `tblX` (`a`) VALUES (1) ON DUPLICATE KEY UPDATE `a` = `a`'], 'label must be'), 'refused: label with a line break');
assertEquals(['step 4: must be an array'], migrationValidateStep('not an array', 4), 'refused: a step that is not an array');

// 🧭 Shape per type.
assertTrue($migRefusedWith(['type' => 'create_table', 'table' => 'tblX', 'sql' => 'CREATE TABLE `tblX` (`a` INT)'], 'IF NOT EXISTS'), 'refused: CREATE TABLE without IF NOT EXISTS');
assertTrue($migRefusedWith(['type' => 'add_column', 'table' => 'tblX', 'name' => 'a', 'sql' => 'ALTER TABLE `tblX` ADD `a` INT'], 'ADD COLUMN'), 'refused: ADD without COLUMN');
assertTrue($migRefusedWith(['type' => 'add_index', 'table' => 'tblX', 'name' => 'i', 'sql' => 'ALTER TABLE `tblX` ADD FULLTEXT `i` (`a`)'], 'ADD INDEX'), 'refused: index type not in the list');
assertTrue($migRefusedWith(['type' => 'add_foreign_key', 'table' => 'tblX', 'name' => 'f', 'sql' => 'ALTER TABLE `tblX` ADD CONSTRAINT `f` CHECK (`a` > 0)'], 'FOREIGN KEY'), 'refused: a constraint that is not a foreign key');
assertTrue($migRefusedWith(['type' => 'insert_if_missing', 'table' => 'tblX', 'sql' => 'INSERT INTO `tblX` (`a`) VALUES (1)'], 'must end with ON DUPLICATE KEY UPDATE'), 'refused: insert without the no-op ending');
assertTrue($migRefusedWith(['type' => 'insert_if_missing', 'table' => 'tblX', 'sql' => 'INSERT INTO `tblX` (`a`) VALUES (1) ON DUPLICATE KEY UPDATE `a` = 5'], 'must end with ON DUPLICATE KEY UPDATE'), 'refused: insert that would OVERWRITE an existing value');
assertTrue($migRefusedWith(['type' => 'insert_if_missing', 'table' => 'tblX', 'sql' => 'INSERT INTO `tblX` (`a`, `b`) VALUES (1, 2) ON DUPLICATE KEY UPDATE `b` = 2, `a` = `a`'], 'must end with ON DUPLICATE KEY UPDATE'), 'refused: insert that overwrites one column before the no-op');
assertTrue($migRefusedWith(['type' => 'insert_if_missing', 'table' => 'tblX', 'sql' => 'INSERT INTO `tblX` (`a`) VALUES (1) ON DUPLICATE KEY UPDATE `a` = `b`'], 'must end with ON DUPLICATE KEY UPDATE'), 'refused: insert whose ending copies another column');
assertTrue($migRefusedWith(['type' => 'insert_if_missing', 'table' => 'tblX', 'sql' => 'REPLACE INTO `tblX` (`a`) VALUES (1) ON DUPLICATE KEY UPDATE `a` = `a`'], 'must start with INSERT INTO'), 'refused: REPLACE INTO');
assertTrue($migRefusedWith(['type' => 'update_if_unchanged', 'table' => 'tblX', 'sql' => 'UPDATE `tblX` SET `a` = 1'], 'needs a WHERE'), 'refused: update without WHERE');
assertTrue($migRefusedWith(['type' => 'update_if_unchanged', 'table' => 'tblX', 'sql' => "UPDATE `tblX` SET `a` = 'WHERE'"], 'needs a WHERE'), 'refused: WHERE only inside quoted text does not count');
assertTrue($migRefusedWith(['type' => 'update_if_unchanged', 'table' => 'tblX', 'sql' => 'UPDATE `tblY` SET `a` = 1 WHERE `a` = 0 AND `x` = `tblX`'], 'must start with UPDATE `tblX`'), 'refused: update of a different table');

// ==============================================================================
// ✅ Section 4: whole-definition validation
// ==============================================================================

$migGoodDefinition = [
    'key'         => '0009_example',
    'description' => 'Example',
    'steps'       => [$migGoodSteps['add_column']],
];
assertEquals([], migrationValidateDefinition($migGoodDefinition, '0009_example'), 'definition: a good one is accepted');
assertTrue(migrationValidateDefinition($migGoodDefinition, '0010_other') !== [], 'definition: key must match the file name');
assertEquals(['the file must return an array'], migrationValidateDefinition(1, '0009_example'), 'definition: a file that returns nothing useful is refused');
$migBadDefinition = $migGoodDefinition;
$migBadDefinition['steps'] = [];
assertTrue(migrationValidateDefinition($migBadDefinition, '0009_example') !== [], 'definition: empty steps refused');
$migBadDefinition['steps'] = ['a' => $migGoodSteps['add_column']];
assertTrue(migrationValidateDefinition($migBadDefinition, '0009_example') !== [], 'definition: steps must be a list, not keyed');
$migBadDefinition = $migGoodDefinition;
$migBadDefinition['description'] = '  ';
assertTrue(migrationValidateDefinition($migBadDefinition, '0009_example') !== [], 'definition: blank description refused');
$migBadDefinition = $migGoodDefinition;
$migBadDefinition['run'] = 'php code';
assertTrue(migrationValidateDefinition($migBadDefinition, '0009_example') !== [], 'definition: unknown top-level field refused');
$migBadDefinition = $migGoodDefinition;
$migBadDefinition['steps'][] = ['type' => 'add_column', 'table' => 'tblX', 'name' => 'a', 'sql' => 'ALTER TABLE `tblX` ADD COLUMN `a` INT; DROP TABLE `tblUsers`'];
$migBadProblems = migrationValidateDefinition($migBadDefinition, '0009_example');
assertTrue($migBadProblems !== [] && str_starts_with($migBadProblems[0], 'step 2'), 'definition: one bad step makes the whole migration invalid (and says which step)');

// ==============================================================================
// ✅ Section 5: the real migration files and the ledger step
// ==============================================================================

assertEquals([], migrationValidateStep(migrationLedgerStep(), 0), 'ledger step (tblSchemaMigrations) passes the same checks as every migration step');
$migLedgerSql = migrationLedgerStep()['sql'];
foreach (['migrationKey', 'checksum', 'appliedAt', 'appliedBy', 'durationMs', 'stepsRun', 'stepsSkipped'] as $migLedgerColumn) {
    assertContains('`' . $migLedgerColumn . '`', $migLedgerSql, 'ledger has column ' . $migLedgerColumn . ' (plan §5.2)');
}
assertContains('PRIMARY KEY (`migrationKey`)', $migLedgerSql, 'ledger primary key is migrationKey');

$migLoaded = migrationLoadAll(migrationDefaultDirectory());
assertEquals([], $migLoaded['errors'], 'migrations folder: no badly named or duplicate-numbered files');
assertEquals(
    ['0001_subscription_price_snapshot_columns', '0002_admin_audit_log_and_settings'],
    array_slice(array_keys($migLoaded['migrations']), 0, 2),
    'migrations folder: 0001 and 0002 come first, in number order'
);

foreach ($migLoaded['migrations'] as $migKey => $migMigration) {
    assertEquals('', $migMigration['loadError'], 'migration ' . $migKey . ' loads');
    assertEquals([], migrationValidateDefinition($migMigration['definition'], $migKey), 'migration ' . $migKey . ' is valid');
    $migFileSource = (string) file_get_contents($migMigration['path']);
    assertContains('@copyright   2025–2026 MWBM Partners Ltd', $migFileSource, 'migration ' . $migKey . ' has the house copyright header');
    assertContains('Path: _includes/sql/migrations/' . $migKey . '.php', $migFileSource, 'migration ' . $migKey . ' header names its own path');
}

// 0001: exactly the nine pieces the old install.sql ALTER blocks added.
$migDefinition0001 = $migLoaded['migrations']['0001_subscription_price_snapshot_columns']['definition'];
$migSummary0001 = [];
foreach ($migDefinition0001['steps'] as $migStep) {
    $migSummary0001[] = $migStep['type'] . ':' . $migStep['table'] . '.' . $migStep['name'];
}
assertEquals(
    [
        'add_column:tblSubscriptions.pricePointID',
        'add_column:tblSubscriptions.discountID',
        'add_index:tblSubscriptions.idx_subscription_pricepoint',
        'add_index:tblSubscriptions.idx_subscription_discount',
        'add_foreign_key:tblSubscriptions.fk_subscription_pricepoint',
        'add_foreign_key:tblSubscriptions.fk_subscription_discount',
        'add_column:tblPayments.pricePointID',
        'add_index:tblPayments.idx_payment_pricepoint',
        'add_foreign_key:tblPayments.fk_payment_pricepoint',
    ],
    $migSummary0001,
    'migration 0001: the nine steps match the two old install.sql ALTER blocks, one piece per step'
);

// 0002: tblAdminAuditLog + the Phase 1A settings rows of plan §5.4.
$migDefinition0002 = $migLoaded['migrations']['0002_admin_audit_log_and_settings']['definition'];
$migSource0002     = (string) file_get_contents($migLoaded['migrations']['0002_admin_audit_log_and_settings']['path']);
assertEquals('create_table', $migDefinition0002['steps'][0]['type'], 'migration 0002: step 1 creates a table');
assertEquals('tblAdminAuditLog', $migDefinition0002['steps'][0]['table'], 'migration 0002: step 1 creates tblAdminAuditLog');
foreach (['auditID', 'actorUserID', 'actorType', 'actorLabel', 'action', 'entityType', 'entityKey', 'outcome', 'changesJSON', 'reason', 'requestIP', 'userAgent', 'createdAt'] as $migAuditColumn) {
    assertContains('`' . $migAuditColumn . '`', $migDefinition0002['steps'][0]['sql'], 'tblAdminAuditLog has column ' . $migAuditColumn);
}
foreach (['idx_admin_audit_created', 'idx_admin_audit_actor', 'idx_admin_audit_entity', 'fk_admin_audit_actor'] as $migAuditKey) {
    assertContains('`' . $migAuditKey . '`', $migDefinition0002['steps'][0]['sql'], 'tblAdminAuditLog has ' . $migAuditKey);
}
assertContains('ON DELETE SET NULL', $migDefinition0002['steps'][0]['sql'], 'tblAdminAuditLog keeps rows when a user is deleted (link becomes NULL)');

// Every settings change is "insert if missing" or "update only if unchanged"
// - owner-edited values are never overwritten.
$migSettingRows = [];
foreach ($migDefinition0002['steps'] as $migStep) {
    if ($migStep['table'] !== 'tblSettings') {
        continue;
    }
    assertTrue(
        $migStep['type'] === 'insert_if_missing' || $migStep['type'] === 'update_if_unchanged',
        'migration 0002: tblSettings is only touched by insert_if_missing / update_if_unchanged'
    );
    $migMatches = [];
    if ($migStep['type'] === 'insert_if_missing'
        && preg_match("/VALUES \\('([a-z_]+)', '([a-z0-9_]+)', '([^']*)', '([a-z]+)', ([01]), ([01]),/", $migStep['sql'], $migMatches) === 1) {
        $migSettingRows[$migMatches[2]] = [
            'group'     => $migMatches[1],
            'value'     => $migMatches[3],
            'type'      => $migMatches[4],
            'sensitive' => (int) $migMatches[5],
            'editable'  => (int) $migMatches[6],
        ];
    }
}

// The exact Phase 1A rows of plan §5.4: key => [group, type, secret, seed].
$migExpectedSettings = [
    'email_queue_retention_days'        => ['email', 'integer', 0, '30'],
    'email_queue_failed_retention_days' => ['email', 'integer', 0, '90'],
    'hcaptcha_site_key'                 => ['security', 'string', 1, ''],
    'hcaptcha_secret_key'               => ['security', 'string', 1, ''],
    'paypal_mode'                       => ['paypal', 'string', 0, 'sandbox'],
    'paypal_client_id'                  => ['paypal', 'string', 1, ''],
    'paypal_client_secret'              => ['paypal', 'string', 1, ''],
    'paypal_webhook_id'                 => ['paypal', 'string', 1, ''],
    'billing_vat_mode'                  => ['billing', 'string', 0, 'inclusive'],
    'billing_vat_rate_percent'          => ['billing', 'float', 0, '20.00'],
    'billing_refund_window_days'        => ['billing', 'integer', 0, '30'],
    'billing_trials_enabled'            => ['billing', 'boolean', 0, '0'],
    'billing_trial_plan_key'            => ['billing', 'string', 0, 'pro'],
    'billing_trial_days'                => ['billing', 'integer', 0, '14'],
    'billing_trial_requires_card'       => ['billing', 'boolean', 0, '0'],
    'admin_reauth_idle_minutes'         => ['admin', 'integer', 0, '15'],
    'admin_reauth_max_minutes'          => ['admin', 'integer', 0, '60'],
    'admin_display_timezone'            => ['admin', 'string', 0, 'Europe/London'],
];
assertEquals(array_keys($migExpectedSettings), array_keys($migSettingRows), 'migration 0002: exactly the Phase 1A settings of plan §5.4, in order');
foreach ($migExpectedSettings as $migSettingKey => $migExpected) {
    if (!isset($migSettingRows[$migSettingKey])) {
        continue;
    }
    $migRow = $migSettingRows[$migSettingKey];
    assertEquals(
        [$migExpected[0], $migExpected[1], $migExpected[2], $migExpected[3], 1],
        [$migRow['group'], $migRow['type'], $migRow['sensitive'], $migRow['value'], $migRow['editable']],
        'migration 0002: ' . $migSettingKey . ' has the planned group/type/secret/default and is admin-editable'
    );
    if ($migRow['sensitive'] === 1) {
        assertEquals('', $migRow['value'], 'migration 0002: secret ' . $migSettingKey . ' is seeded EMPTY (never a real value)');
    }
}
assertFalse(str_contains($migSource0002, "'billing_discount_combining'"), 'migration 0002: no billing_discount_combining row (combining is per discount since 2026-11-22, migration 0005)');
foreach (['billing_price_points_enabled', 'billing_discounts_enabled', 'billing_payg_enabled'] as $migKillSwitch) {
    assertFalse(str_contains($migSource0002, "'" . $migKillSwitch . "'"), 'migration 0002: does not touch the ' . $migKillSwitch . ' kill-switch (charging stays off)');
}

// The bot-protection description update only applies while still unchanged.
$migProviderUpdate = '';
foreach ($migDefinition0002['steps'] as $migStep) {
    if ($migStep['type'] === 'update_if_unchanged') {
        $migProviderUpdate = $migStep['sql'];
    }
}
assertContains("AND `description` = 'Active bot protection provider (none, turnstile, recaptcha)'", $migProviderUpdate, 'migration 0002: provider description only changes while it is still the original wording');
assertContains("'Active bot protection provider (none, turnstile, recaptcha)'", (string) file_get_contents(CUERCODE_INCLUDES_DIR . 'sql' . DIRECTORY_SEPARATOR . 'seed_data.sql'), 'seed_data.sql: the original provider wording the update looks for is still exactly the seeded text');

// The two email clean-up rows are identical to the seed_data.sql rows.
$migSeedSource = (string) file_get_contents(CUERCODE_INCLUDES_DIR . 'sql' . DIRECTORY_SEPARATOR . 'seed_data.sql');
foreach (['email_queue_retention_days', 'email_queue_failed_retention_days'] as $migEmailKey) {
    $migSeedMatch = [];
    preg_match("/\\('email', '" . $migEmailKey . "', '([0-9]+)', 'integer', 0, 1, '((?:[^']|'')*)'\\)/", $migSeedSource, $migSeedMatch);
    assertTrue(isset($migSeedMatch[2]) && str_contains($migSource0002, $migSeedMatch[2]), 'migration 0002: ' . $migEmailKey . ' description matches seed_data.sql exactly');
}

// ==============================================================================
// ✅ Section 6: finding files in a folder
// ==============================================================================

$migTempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cuercode_migtest_' . bin2hex(random_bytes(6));
mkdir($migTempDir);
$migTempFiles = [
    '0003_third.php'   => "<?php\nreturn ['key' => '0003_third', 'description' => 'x', 'steps' => [['type' => 'add_index', 'table' => 'tblX', 'name' => 'i', 'sql' => 'ALTER TABLE `tblX` ADD INDEX `i` (`a`)']]];\n",
    '0001_first.php'   => "<?php\nreturn ['key' => '0001_first', 'description' => 'x', 'steps' => [['type' => 'add_index', 'table' => 'tblX', 'name' => 'j', 'sql' => 'ALTER TABLE `tblX` ADD INDEX `j` (`a`)']]];\n",
    'README.md'        => "notes\n",
    '.htaccess'        => "Require all denied\n",
];
foreach ($migTempFiles as $migTempName => $migTempContents) {
    file_put_contents($migTempDir . DIRECTORY_SEPARATOR . $migTempName, $migTempContents);
}
$migFound = migrationDiscoverFiles($migTempDir);
assertEquals([], $migFound['errors'], 'discover: non-PHP files are ignored');
assertEquals(['0001_first', '0003_third'], array_keys($migFound['files']), 'discover: files come back in number order');

file_put_contents($migTempDir . DIRECTORY_SEPARATOR . '0003_clash.php', "<?php\nreturn [];\n");
file_put_contents($migTempDir . DIRECTORY_SEPARATOR . 'helper.php', "<?php\nreturn [];\n");
$migFound = migrationDiscoverFiles($migTempDir);
assertEquals(2, count($migFound['errors']), 'discover: a duplicate number and a badly named .php file are both errors');
$migFoundText = implode(' | ', $migFound['errors']);
assertContains('share the number 0003', $migFoundText, 'discover: duplicate number reported');
assertContains('helper.php', $migFoundText, 'discover: badly named file reported');

file_put_contents($migTempDir . DIRECTORY_SEPARATOR . '0004_broken.php', "<?php\nreturn [ this is not php\n");
$migBroken = migrationLoadFile($migTempDir . DIRECTORY_SEPARATOR . '0004_broken.php');
assertFalse($migBroken['ok'], 'load: a file with a PHP syntax error is reported, not thrown');
assertContains('ParseError', $migBroken['error'], 'load: the error kind is named');
$migFirst = migrationLoadFile($migTempDir . DIRECTORY_SEPARATOR . '0001_first.php');
assertEquals(migrationChecksumForContents($migTempFiles['0001_first.php']), $migFirst['checksum'], 'load: checksum is of the file text');
assertEquals('0001_first', $migFirst['definition']['key'], 'load: returns the array the file returns');
assertEquals(['files' => [], 'errors' => ['the migrations folder was not found']], migrationDiscoverFiles($migTempDir . DIRECTORY_SEPARATOR . 'nope'), 'discover: a missing folder is an error');

foreach ((array) scandir($migTempDir) as $migTempEntry) {
    if ($migTempEntry !== '.' && $migTempEntry !== '..') {
        unlink($migTempDir . DIRECTORY_SEPARATOR . $migTempEntry);
    }
}
rmdir($migTempDir);

// ==============================================================================
// ✅ Section 7: the "what is waiting" plan
// ==============================================================================

$migPlan = migrationBuildPlan(
    ['0003_c' => 'c3', '0001_a' => 'a1', '0002_b' => 'b2'],
    []
);
assertEquals(['0001_a', '0002_b', '0003_c'], $migPlan['pending'], 'plan: a new database has every migration waiting, in number order');
assertEquals([], $migPlan['changed'], 'plan: nothing changed on a new database');

$migPlan = migrationBuildPlan(
    ['0001_a' => 'a1', '0002_b' => 'b2', '0003_c' => 'c3'],
    ['0001_a' => 'a1', '0002_b' => 'b2', '0003_c' => 'c3']
);
assertEquals([], $migPlan['pending'], 'plan: an up-to-date database has nothing waiting');
assertEquals(['0001_a', '0002_b', '0003_c'], $migPlan['applied'], 'plan: applied list');

$migPlan = migrationBuildPlan(
    ['0001_a' => 'a1-EDITED', '0002_b' => 'b2', '0003_c' => 'c3', '0004_d' => 'd4'],
    ['0002_b' => 'b2', '0001_a' => 'a1', '0009_gone' => 'z']
);
assertEquals(['0003_c', '0004_d'], $migPlan['pending'], 'plan: only the unapplied ones wait');
assertEquals(['0001_a'], $migPlan['changed'], 'plan: a file edited after it ran is flagged as changed (warning)');
assertFalse(in_array('0001_a', $migPlan['pending'], true), 'plan: a changed file is NEVER re-run');
assertEquals(['0009_gone'], $migPlan['missingFiles'], 'plan: a recorded migration whose file is gone is reported');
assertEquals(['0003_c', '0004_d'], $migPlan['outOfOrder'], 'plan: waiting files numbered below an applied one are flagged (0009 was applied)');

$migPlan = migrationBuildPlan(['0001_a' => 'a1', '0002_b' => 'b2'], ['0002_b' => 'b2']);
assertEquals(['0001_a'], $migPlan['outOfOrder'], 'plan: an older file arriving late is flagged but still waits');
assertEquals(['0001_a'], $migPlan['pending'], 'plan: ... and is still applied');

// ==============================================================================
// ✅ Section 8: step-needed decisions with a STAND-IN "exists" checker
// ==============================================================================

$migAsked = [];
$migStandInExists = static function (array $migQuery) use (&$migAsked): bool {
    $migAsked[] = $migQuery;
    // Pretend only tblExample.price and fk_example_user already exist.
    return in_array('price', $migQuery['params'], true) || in_array('fk_example_user', $migQuery['params'], true);
};

assertEquals('skip', migrationDecideStep($migGoodSteps['add_column'], $migStandInExists), 'decide: column already there -> skip');
assertEquals(['tblExample', 'price'], $migAsked[0]['params'], 'decide: column check asks about the right table and column');
assertContains('INFORMATION_SCHEMA.COLUMNS', $migAsked[0]['sql'], 'decide: column check reads INFORMATION_SCHEMA.COLUMNS');
assertEquals('ss', $migAsked[0]['types'], 'decide: column check binds two strings');

assertEquals('run', migrationDecideStep($migGoodSteps['add_index'], $migStandInExists), 'decide: index missing -> run');
assertContains('INFORMATION_SCHEMA.STATISTICS', $migAsked[1]['sql'], 'decide: index check reads INFORMATION_SCHEMA.STATISTICS');
assertEquals(['tblExample', 'idx_example_kind'], $migAsked[1]['params'], 'decide: index check asks about the right index');

assertEquals('skip', migrationDecideStep($migGoodSteps['add_foreign_key'], $migStandInExists), 'decide: foreign key already there -> skip');
assertContains("CONSTRAINT_TYPE = 'FOREIGN KEY'", $migAsked[2]['sql'], 'decide: foreign-key check only counts foreign keys');

assertEquals('run', migrationDecideStep($migGoodSteps['create_table'], $migStandInExists), 'decide: table missing -> run');
assertContains('INFORMATION_SCHEMA.TABLES', $migAsked[3]['sql'], 'decide: table check reads INFORMATION_SCHEMA.TABLES');
assertEquals(['tblExample'], $migAsked[3]['params'], 'decide: table check asks about the right table');

$migAskedBefore = count($migAsked);
assertEquals('run', migrationDecideStep($migGoodSteps['insert_if_missing'], $migStandInExists), 'decide: insert_if_missing always runs (its SQL is a no-op when the row exists)');
assertEquals('run', migrationDecideStep($migGoodSteps['update_if_unchanged'], $migStandInExists), 'decide: update_if_unchanged always runs (its WHERE matches nothing once edited)');
assertEquals($migAskedBefore, count($migAsked), 'decide: insert/update steps never ask the checker');

foreach ($migAsked as $migQuery) {
    assertContains('TABLE_SCHEMA = DATABASE()', $migQuery['sql'], 'decide: every check is limited to the current database');
    assertEquals(substr_count($migQuery['sql'], '?'), strlen($migQuery['types']), 'decide: placeholders match bound types');
}

// ==============================================================================
// ✅ Section 9: the install.sql column-name reader and the baseline comparison
// ==============================================================================

$migSampleSql = <<<'MIGSQL'
-- CREATE TABLE IF NOT EXISTS `tblCommentedOut` (
CREATE TABLE IF NOT EXISTS `tblOne` (
    `oneID`     INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'it''s the key',
    `kind`      ENUM(
                    'a',
                    'b'
                )            NOT NULL DEFAULT 'a',
    `amount`    DECIMAL(10,2) NOT NULL,

    PRIMARY KEY (`oneID`),
    UNIQUE KEY `idx_one_kind` (`kind`),
    INDEX `idx_one_amount` (`amount`),
    CONSTRAINT `fk_one` FOREIGN KEY (`oneID`)
        REFERENCES `tblTwo` (`twoID`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tblTwo` (
    `twoID` INT NOT NULL,
    PRIMARY KEY (`twoID`)
) ENGINE=InnoDB;
MIGSQL;
assertEquals(
    ['tblOne' => ['oneID', 'kind', 'amount'], 'tblTwo' => ['twoID']],
    migrationParseInstallSqlSchema($migSampleSql),
    'install.sql reader: columns only (not keys, indexes, constraints, ENUM lines or commented-out lines)'
);
assertEquals(['tblX' => ['a']], migrationParseInstallSqlSchema("CREATE TABLE IF NOT EXISTS `tblX` (\r\n  `a` INT\r\n) ENGINE=InnoDB;\r\n"), 'install.sql reader: Windows line endings are fine');

$migRealInstallSql = (string) file_get_contents(CUERCODE_INCLUDES_DIR . 'sql' . DIRECTORY_SEPARATOR . 'install.sql');
$migBaseline = migrationParseInstallSqlSchema($migRealInstallSql);
assertEquals(29, count($migBaseline), 'install.sql reader: finds all 29 baseline tables');
assertEquals(substr_count($migRealInstallSql, 'CREATE TABLE IF NOT EXISTS'), count($migBaseline), 'install.sql reader: one entry per CREATE TABLE IF NOT EXISTS');
assertEquals(
    ['settingID', 'settingGroup', 'settingKey', 'settingValue', 'settingType', 'isSensitive', 'isEditable', 'description', 'encryptionSalt', 'createdAt', 'updatedAt'],
    $migBaseline['tblSettings'],
    'install.sql reader: tblSettings columns exactly (multi-line ENUM handled)'
);
assertTrue(in_array('erasedAt', $migBaseline['tblUsers'], true), 'install.sql reader: tblUsers.erasedAt is a baseline column');
assertFalse(in_array('pricePointID', $migBaseline['tblSubscriptions'], true), 'install.sql reader: tblSubscriptions.pricePointID is NOT baseline (migration 0001 adds it)');
assertFalse(in_array('discountID', $migBaseline['tblSubscriptions'], true), 'install.sql reader: tblSubscriptions.discountID is NOT baseline');
assertFalse(in_array('pricePointID', $migBaseline['tblPayments'], true), 'install.sql reader: tblPayments.pricePointID is NOT baseline');
$migEmptyBaselineTables = [];
foreach ($migBaseline as $migTableName => $migColumns) {
    if (count($migColumns) < 2) {
        $migEmptyBaselineTables[] = $migTableName;
    }
}
assertEquals([], $migEmptyBaselineTables, 'install.sql reader: every baseline table has its columns read');

$migCompared = migrationCompareBaseline(
    ['tblOne' => ['oneID', 'kind'], 'tblTwo' => ['twoID'], 'tblThree' => ['x']],
    ['tblone' => ['ONEID'], 'tblTwo' => ['twoID', 'extra'], 'tblSchemaMigrations' => ['migrationKey']]
);
assertEquals(['tblThree'], $migCompared['missingTables'], 'baseline: a missing table is listed');
assertEquals(['tblOne.kind'], $migCompared['missingColumns'], 'baseline: a missing column is listed as table.column; extra columns and tables are fine; case is ignored');
assertEquals(['missingTables' => [], 'missingColumns' => []], migrationCompareBaseline($migBaseline, $migBaseline), 'baseline: a matching database has nothing missing');

// ==============================================================================
// ✅ Section 10: install.sql is safe to run twice; seed_data.sql note
// ==============================================================================

assertEquals(0, substr_count($migRealInstallSql, 'ALTER TABLE `'), 'install.sql: no table-change statements left (moved to migration 0001)');
assertContains('0001_subscription_price_snapshot_columns.php', $migRealInstallSql, 'install.sql: points to migration 0001 where the columns went');
assertContains('FROZEN BASELINE', $migRealInstallSql, 'install.sql: header says it is the frozen baseline');
assertContains('_includes/sql/migrations/', $migRealInstallSql, 'install.sql: header names the migrations folder');

// Every real statement must be a SET line or CREATE TABLE IF NOT EXISTS.
// Comment lines are removed first (they contain apostrophes such as
// "Table 20's"), then quoted text is blanked so a ";" inside a COMMENT does
// not split a statement.
$migInstallWithoutComments = preg_replace('/^\s*--.*$/m', '', $migRealInstallSql);
$migInstallBlanked = migrationBlankQuotedText((string) $migInstallWithoutComments);
assertTrue($migInstallBlanked['ok'], 'install.sql: quotes are balanced once comment lines are removed');
$migStatementKinds = [];
foreach (explode(';', $migInstallBlanked['text']) as $migStatement) {
    $migStatement = trim((string) preg_replace('/\s+/', ' ', $migStatement));
    if ($migStatement === '') {
        continue;
    }
    if (str_starts_with($migStatement, 'SET ') || str_starts_with($migStatement, 'CREATE TABLE IF NOT EXISTS `')) {
        continue;
    }
    $migStatementKinds[] = substr($migStatement, 0, 60);
}
assertEquals([], $migStatementKinds, 'install.sql: only SET and CREATE TABLE IF NOT EXISTS statements, so it is safe to run twice (#113 item 5)');

$migSeedHeader = substr($migSeedSource, 0, 2500);
assertContains('FROZEN BASELINE', $migSeedHeader, 'seed_data.sql: header says it is the frozen baseline');
assertContains('_includes/sql/migrations/', $migSeedHeader, 'seed_data.sql: header says new starting data lives in migrations');

// ==============================================================================
// ✅ Section 11: command-line options, exit codes, report text
// ==============================================================================

assertEquals(['ok' => true, 'mode' => 'status', 'error' => ''], migrationParseCliArgs(['migrate.php', '--status']), 'cli: --status');
assertEquals(['ok' => true, 'mode' => 'dry-run', 'error' => ''], migrationParseCliArgs(['migrate.php', '--dry-run']), 'cli: --dry-run');
assertEquals(['ok' => true, 'mode' => 'apply', 'error' => ''], migrationParseCliArgs(['migrate.php', '--apply']), 'cli: --apply');
assertEquals(['ok' => true, 'mode' => 'help', 'error' => ''], migrationParseCliArgs(['migrate.php', '--help']), 'cli: --help');
assertEquals(['ok' => true, 'mode' => 'help', 'error' => ''], migrationParseCliArgs(['migrate.php', '-h']), 'cli: -h');
assertEquals(['ok' => true, 'mode' => 'apply', 'error' => ''], migrationParseCliArgs(['migrate.php', '--apply', '--apply']), 'cli: the same option twice is fine');
assertFalse(migrationParseCliArgs(['migrate.php'])['ok'], 'cli: no option is refused');
assertFalse(migrationParseCliArgs(['migrate.php', '--apply', '--status'])['ok'], 'cli: two different modes are refused');
assertFalse(migrationParseCliArgs(['migrate.php', '--force'])['ok'], 'cli: an unknown option is refused');
assertFalse(migrationParseCliArgs(['migrate.php', 'apply'])['ok'], 'cli: a bare word is refused');
assertFalse(migrationParseCliArgs(['migrate.php', '--apply=1'])['ok'], 'cli: --apply=1 is refused');
assertFalse(str_contains(migrationParseCliArgs(['migrate.php', "--x\x1b[2J"])['error'], "\x1b"), 'cli: control characters in a bad option are not echoed back');
assertContains('--apply', implode("\n", migrationCliUsage()), 'cli: usage mentions --apply');

$migOk = ['status' => 'ok', 'pending' => [], 'baseline' => ['missingTables' => [], 'createdTables' => [], 'missingColumns' => []]];
$migWaiting = ['status' => 'ok', 'pending' => ['0002_b'], 'baseline' => ['missingTables' => [], 'createdTables' => [], 'missingColumns' => []]];
$migTablesMissing = ['status' => 'ok', 'pending' => [], 'baseline' => ['missingTables' => ['tblQRScans'], 'createdTables' => [], 'missingColumns' => []]];
assertEquals(0, migrationExitCodeForResult('status', $migOk), 'exit: --status up to date -> 0');
assertEquals(3, migrationExitCodeForResult('status', $migWaiting), 'exit: --status with waiting migrations -> 3');
assertEquals(3, migrationExitCodeForResult('status', $migTablesMissing), 'exit: --status with missing starting tables -> 3 (work waiting)');
assertEquals(0, migrationExitCodeForResult('dry-run', $migWaiting), 'exit: --dry-run with waiting migrations -> 0');
assertEquals(0, migrationExitCodeForResult('apply', $migWaiting), 'exit: --apply done -> 0');
assertEquals(1, migrationExitCodeForResult('apply', ['status' => 'failed']), 'exit: a failed step -> 1');
foreach (['busy', 'baseline_refused', 'invalid'] as $migRefusedStatus) {
    assertEquals(2, migrationExitCodeForResult('apply', ['status' => $migRefusedStatus]), 'exit: ' . $migRefusedStatus . ' -> 2 (refused)');
    assertEquals(2, migrationExitCodeForResult('status', ['status' => $migRefusedStatus]), 'exit: --status ' . $migRefusedStatus . ' -> 2');
}
assertEquals(1, migrationExitCodeForResult('apply', ['status' => 'something new']), 'exit: an unknown status is a failure, never success');
assertEquals(1, migrationExitCodeForResult('apply', []), 'exit: an empty result is a failure, never success');

$migReport = implode("\n", migrationFormatReport('status', $migOk));
assertContains('Already up to date', $migReport, 'report: up to date says so');
$migReport = implode("\n", migrationFormatReport('apply', ['status' => 'busy']));
assertContains('another upgrade is running', $migReport, 'report: busy explained in plain English');
$migReport = implode("\n", migrationFormatReport('apply', [
    'status'   => 'baseline_refused',
    'baseline' => ['missingTables' => [], 'createdTables' => [], 'missingColumns' => ['tblUsers.erasedAt', 'tblQRCodes.qrUUID']],
]));
assertContains('tblUsers.erasedAt', $migReport, 'report: missing baseline columns are listed');
assertContains('tblQRCodes.qrUUID', $migReport, 'report: every missing baseline column is listed');
assertContains('Nothing was changed', $migReport, 'report: says nothing was changed');
$migReport = implode("\n", migrationFormatReport('apply', [
    'status'     => 'ok',
    'pending'    => ['0001_a'],
    'migrations' => [['key' => '0001_a', 'outcome' => 'applied', 'stepsRun' => 2, 'stepsSkipped' => 7, 'problems' => []]],
    'warnings'   => ['0000_x was changed after it was applied.'],
]));
assertContains('Applied 0001_a: 2 step(s) run, 7 already done.', $migReport, 'report: applied counts');
assertContains('Warning: 0000_x was changed after it was applied.', $migReport, 'report: warnings shown');
$migReport = implode("\n", migrationFormatReport('apply', [
    'status'     => 'failed',
    'error'      => '0002_b step 3 failed.',
    'migrations' => [['key' => '0002_b', 'outcome' => 'failed', 'stepsRun' => 2, 'stepsSkipped' => 0, 'problems' => ['step 3 (add_column tblX.c) failed: MySQL error 1146']]],
]));
assertContains('not recorded', $migReport, 'report: a failed migration is not recorded');
assertContains('safe to repeat', $migReport, 'report: says to run again');
assertContains('add_column tblX.c', $migReport, 'report: names the failed step without its SQL');

// ==============================================================================
// ✅ Section 12: source guards
// ==============================================================================

$migRunnerSource = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_migrations.php');
foreach (['dbQuery(', 'getDB(', 'getConfig(', 'logError(', 'logActivity(', 'require', 'include_once'] as $migForbiddenCall) {
    assertFalse(
        (bool) preg_match('/^(?!\s*(\*|\/\/)).*\b' . preg_quote($migForbiddenCall, '/') . '/m', str_replace('return include $migrationFilePath;', '', $migRunnerSource)),
        'fn_migrations.php code never uses ' . $migForbiddenCall . ' (works without the site start-up)'
    );
}
assertFalse(str_contains($migRunnerSource, '->query('), 'fn_migrations.php never uses ->query() - prepared statements only');
assertEquals(1, substr_count($migRunnerSource, '->multi_query('), 'fn_migrations.php uses multi_query() only once (re-running install.sql)');
assertContains('->prepare($sql)', $migRunnerSource, 'fn_migrations.php runs SQL through prepare()');
assertContains('->bind_param($types, ...$params)', $migRunnerSource, 'fn_migrations.php binds values with bind_param()');
assertContains('@copyright   2025–2026 MWBM Partners Ltd', $migRunnerSource, 'fn_migrations.php has the house copyright header');

$migCliPath   = CUERCODE_INCLUDES_DIR . 'cli' . DIRECTORY_SEPARATOR . 'migrate.php';
$migCliSource = (string) file_get_contents($migCliPath);
$migFirstCode = preg_replace('/^<\?php\s*\/\*\*.*?\*\/\s*/s', '', $migCliSource);
$migFirstCode = preg_replace('/^(\s*\/\/[^\n]*\n)+/', '', (string) $migFirstCode);
assertTrue(str_starts_with(ltrim((string) $migFirstCode), "if (PHP_SAPI !== 'cli') {"), 'migrate.php: the first statement refuses anything but the command line');
assertFalse(str_contains($migCliSource, "'bootstrap.php'"), 'migrate.php: does not load the full site start-up (it reads settings from a database that may not be ready)');
assertContains("'db_connect.php'", $migCliSource, 'migrate.php: connects through the site database settings (db_connect.php)');
assertTrue(
    strpos($migCliSource, 'migrationParseCliArgs(') < strpos($migCliSource, "'db_connect.php'"),
    'migrate.php: options are checked before any database code is loaded'
);
assertTrue(is_file(CUERCODE_INCLUDES_DIR . '.htaccess') && str_contains((string) file_get_contents(CUERCODE_INCLUDES_DIR . '.htaccess'), 'Require all denied'), '_includes/.htaccess denies web access (covers cli/ and sql/migrations/)');

// ==============================================================================
// ✅ Section 13: the real migrate.php as a child process (--help / bad options ONLY)
// ==============================================================================
// ⚠️ NEVER add a case here without --help or an invalid option: a real run on
//    a machine with a real _auth_keys/auth_creds.php could change a real
//    database. The command is an ARRAY, so no shell is involved.
// @see https://www.php.net/manual/en/function.proc-open.php
// ==============================================================================

$migRunCli = static function (array $migCliArguments) use ($migCliPath): array {
    $migDescriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $migProcess = proc_open(array_merge([PHP_BINARY, $migCliPath], $migCliArguments), $migDescriptors, $migPipes);

    if (!is_resource($migProcess)) {
        return ['exit' => -1, 'out' => '', 'err' => ''];
    }

    fclose($migPipes[0]);
    $migOut = (string) stream_get_contents($migPipes[1]);
    fclose($migPipes[1]);
    $migErr = (string) stream_get_contents($migPipes[2]);
    fclose($migPipes[2]);

    return ['exit' => proc_close($migProcess), 'out' => $migOut, 'err' => $migErr];
};

$migCli = $migRunCli(['--help']);
assertEquals(0, $migCli['exit'], 'real migrate.php --help: exit 0');
assertContains('--dry-run', $migCli['out'], 'real migrate.php --help: usage on stdout');
assertEquals('', $migCli['err'], 'real migrate.php --help: stderr is empty');

$migCli = $migRunCli(['--force']);
assertEquals(2, $migCli['exit'], 'real migrate.php --force: exit 2 (refused)');
assertContains('Unknown option', $migCli['err'], 'real migrate.php --force: the reason is on stderr');

$migCli = $migRunCli([]);
assertEquals(2, $migCli['exit'], 'real migrate.php with no option: exit 2 (refused, nothing runs)');

$migCli = $migRunCli(['--status', '--apply']);
assertEquals(2, $migCli['exit'], 'real migrate.php with two modes: exit 2');
