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
assertEquals('cuercode_schema_migrate', CUERCODE_MIGRATION_LOCK_NAME, 'lock name prefix matches plan §5.1');

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
// 📝 Codex round 2 finding R1-3 (2026-11-25): the seed now writes the NEW
//    wording itself, so the result is the same whichever runs first - the
//    installer (seed, then 0002's update finds nothing to change) or the
//    upgrade tool (0002 runs before the row exists, then the seed). 0002's
//    file is NOT edited: it has already been applied on existing databases,
//    and the runner warns about a migration changed after it was applied. It
//    still updates databases seeded with the old wording.
$migSeedForProvider = (string) file_get_contents(CUERCODE_INCLUDES_DIR . 'sql' . DIRECTORY_SEPARATOR . 'seed_data.sql');
assertContains("SET `description` = 'Active bot protection provider (none, turnstile, recaptcha, hcaptcha)'", $migProviderUpdate, 'migration 0002: the new provider wording');
assertContains("'bot_protection_provider', 'none', 'string', 0, 1, 'Active bot protection provider (none, turnstile, recaptcha, hcaptcha)'", $migSeedForProvider, 'seed_data.sql (R1-3): seeds exactly the wording migration 0002 sets, so the order does not matter');
assertFalse(str_contains($migSeedForProvider, "'Active bot protection provider (none, turnstile, recaptcha)'"), 'seed_data.sql (R1-3): the old wording is no longer seeded');
assertEquals('7dc796cd', substr(migrationChecksumForContents((string) file_get_contents(migrationDefaultDirectory() . '0002_admin_audit_log_and_settings.php')), 0, 8), 'R1-3: migration 0002 itself is unchanged (an edit would warn on every database that already applied it)');

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
assertContains('No upgrades were applied', $migReport, 'report: a refusal says "no upgrades were applied" (F5), not "nothing was changed" - the ledger table or starting tables may already have been created before the refusal');
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

// 🏁 F5: "invalid" and "baseline_refused" headlines say "no upgrades were
// applied", never the old "nothing was changed" (which was not reliably
// true - the ledger table, or starting tables from install.sql, may already
// have been created before the refusal happened).
assertContains('No upgrades were applied', implode("\n", migrationFormatReport('apply', ['status' => 'invalid', 'migrations' => []])), 'report: an "invalid" refusal says no upgrades were applied');
assertContains('No upgrades were applied', implode("\n", migrationFormatReport('apply', ['status' => 'baseline_refused'])), 'report: a "baseline_refused" refusal says no upgrades were applied');

// 🌱 F1: --apply creating a missing baseline table from install.sql on an
// otherwise-empty database (no web installer involved) must say plainly that
// the STARTING DATA (QR code types, logos, plans) still needs the installer -
// only for --apply (the only mode that ever actually creates a table).
$migCreatedTablesResult = [
    'status'   => 'ok',
    'pending'  => [],
    'baseline' => ['missingTables' => [], 'createdTables' => ['tblQRScans'], 'missingColumns' => []],
];
assertContains('starting data not loaded', strtolower(implode("\n", migrationFormatReport('apply', $migCreatedTablesResult))), 'report: --apply creating a baseline table says the starting data still needs the web installer (F1)');
assertFalse(str_contains(strtolower(implode("\n", migrationFormatReport('dry-run', $migCreatedTablesResult))), 'starting data not loaded'), 'report: the starting-data note never appears for --dry-run (it never actually creates anything)');

// 🔒 F3: $includeErrorDetail. true (the command-line tool's own default)
// keeps the raw MySQL/PHP error text; false (used by the web installer, a
// page a member of the public can reach) replaces it with one generic line.
// Nothing here loses the detail completely - public_html/install/index.php
// writes the full-detail report to error_log() itself before ever asking for
// the generic one (checked directly, by source, in Section 15 below).
$migSensitiveFailure = [
    'status'     => 'failed',
    'error'      => "MySQL error 1045: Access denied for user 'siteuser'@'10.0.0.5' (using password: YES)",
    'migrations' => [[
        'key'          => '0002_b',
        'outcome'      => 'failed',
        'stepsRun'     => 2,
        'stepsSkipped' => 0,
        'problems'     => ["step 3 (add_column tblX.c) failed: MySQL error 1146: Table 'cuercode.tblX' doesn't exist"],
    ]],
];
$migReportWithDetail = implode("\n", migrationFormatReport('apply', $migSensitiveFailure, true));
assertContains('MySQL error 1045', $migReportWithDetail, 'report ($includeErrorDetail = true, the CLI default): the raw database error is shown');
assertContains('MySQL error 1146', $migReportWithDetail, 'report ($includeErrorDetail = true): a failed step\'s raw database error is shown');

$migReportNoDetail = implode("\n", migrationFormatReport('apply', $migSensitiveFailure, false));
assertFalse(str_contains($migReportNoDetail, 'MySQL error 1045'), 'report ($includeErrorDetail = false, F3): the raw database error text is NEVER shown');
assertFalse(str_contains($migReportNoDetail, 'MySQL error 1146'), 'report ($includeErrorDetail = false, F3): a failed step\'s raw database error text is NEVER shown');
assertFalse(str_contains($migReportNoDetail, 'siteuser'), 'report ($includeErrorDetail = false, F3): no internal detail (for example a database user name) leaks through');
assertContains('server error log', $migReportNoDetail, 'report ($includeErrorDetail = false, F3): a generic note points at the server error log instead');

// Default (no third argument) behaves exactly like true, for every existing
// caller that never knew this parameter existed (the real migrate.php).
assertEquals($migReportWithDetail, implode("\n", migrationFormatReport('apply', $migSensitiveFailure)), 'report: the default (no third argument) keeps full detail, same as true');

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
assertEquals(2, substr_count($migRunnerSource, '->multi_query('), 'fn_migrations.php uses multi_query() exactly twice: re-running install.sql, and loading seed_data.sql as one transaction (Codex C1 finding 1)');
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

// ==============================================================================
// ✅ Section 14: seed-data protection and report-line severity (task 7n Phase
//    1A part 2 - the web installer's known-limitation fix, §9)
// ==============================================================================
// migrationDbSeedDataAlreadyPresent() itself needs a real database (it is
// covered by tests/integration/migrations_real_mysql.php); only the pure
// table list and the pure line classifier are unit-tested here.
// ==============================================================================

assertEquals(
    ['tblQRCodeTypes', 'tblPredefinedLogos', 'tblSubscriptionPlans'],
    migrationSeedProtectionTables(),
    'seed protection (F1): checks tblQRCodeTypes, tblPredefinedLogos and tblSubscriptionPlans, in that order - deliberately NOT tblSettings, which migration 0002 (and any later settings migration) also inserts into'
);

// 🌱 F1 regression guard: if ANY real migration file could ever put a row
// into an EMPTY one of migrationSeedProtectionTables()'s tables, the exact bug
// review finding F1 describes would come back - running `migrate.php --apply`
// on a brand new database would make that table non-empty, so the installer
// would then believe seed_data.sql had already run and would skip it forever.
// Until task 7n Phase 2 the rule was "never write to them at all"; Phase 2
// must add the Enterprise plan and move two placeholder prices, so the rule
// is now the precise property F1 needs (migrationStepCouldFillEmptySeedTable(),
// also enforced by migrationValidateStep()). This checks EVERY migration file
// in the real folder, so a later one that breaks the rule fails a test
// immediately; tests/integration/migrations_real_mysql.php scenario J proves
// it on real servers.
$migSeedGuardWrites = 0;
foreach ($migLoaded['migrations'] as $migSeedGuardKey => $migSeedGuardMigration) {
    foreach ($migSeedGuardMigration['definition']['steps'] as $migSeedGuardStep) {
        if (!in_array($migSeedGuardStep['table'], migrationSeedProtectionTables(), true)) {
            continue;
        }
        $migSeedGuardWrites++;
        assertFalse(
            migrationStepCouldFillEmptySeedTable($migSeedGuardStep),
            'F1 regression guard: migration ' . $migSeedGuardKey . ' (' . migrationDescribeStep($migSeedGuardStep) . ') can never put a row into an EMPTY seed-protection table'
        );
    }
}
assertTrue($migSeedGuardWrites >= 3, 'F1 regression guard: the check really saw the Phase 2 writes to tblSubscriptionPlans (' . $migSeedGuardWrites . ')');

// 🌱 The rule itself, on made-up steps (task 7n Phase 2).
$migF1Copy = [
    'type'  => 'insert_if_missing',
    'table' => 'tblSubscriptionPlans',
    'sql'   => 'INSERT INTO `tblSubscriptionPlans` (`planKey`, `featuresJSON`) SELECT \'x\', `b`.`featuresJSON` FROM (SELECT `featuresJSON` FROM `tblSubscriptionPlans` WHERE `planKey` = \'business\') AS `b` ON DUPLICATE KEY UPDATE `planKey` = `planKey`',
];
assertFalse(migrationStepCouldFillEmptySeedTable($migF1Copy), 'F1 rule: a copy of a row already in the same table (through an inner query) cannot fill an empty table');
assertEquals([], migrationValidateStep($migF1Copy, 1), 'F1 rule: ...and the validator accepts it');
$migF1Direct = $migF1Copy;
$migF1Direct['sql'] = 'INSERT INTO `tblSubscriptionPlans` (`planKey`, `featuresJSON`) SELECT \'x\', `featuresJSON` FROM `tblSubscriptionPlans` WHERE `planKey` = \'business\' ON DUPLICATE KEY UPDATE `planKey` = `planKey`';
assertFalse(migrationStepCouldFillEmptySeedTable($migF1Direct), 'F1 rule: a direct copy from the same table also cannot fill it (the engines reject it as ambiguous, but it is not an F1 risk)');
$migF1Bad = [
    'VALUES'               => 'INSERT INTO `tblSubscriptionPlans` (`planKey`) VALUES (\'x\') ON DUPLICATE KEY UPDATE `planKey` = `planKey`',
    'VALUE'                => 'INSERT INTO `tblSubscriptionPlans` (`planKey`) VALUE (\'x\') ON DUPLICATE KEY UPDATE `planKey` = `planKey`',
    'a SELECT with no FROM' => 'INSERT INTO `tblSubscriptionPlans` (`planKey`) SELECT \'x\' ON DUPLICATE KEY UPDATE `planKey` = `planKey`',
    'an inner query with no FROM' => 'INSERT INTO `tblSubscriptionPlans` (`planKey`) SELECT `b`.`k` FROM (SELECT \'x\' AS `k`) AS `b` ON DUPLICATE KEY UPDATE `planKey` = `planKey`',
    'another table'        => 'INSERT INTO `tblSubscriptionPlans` (`planKey`) SELECT `settingKey` FROM `tblSettings` ON DUPLICATE KEY UPDATE `planKey` = `planKey`',
    'an inner query on another table' => 'INSERT INTO `tblSubscriptionPlans` (`planKey`) SELECT `b`.`k` FROM (SELECT `settingKey` AS `k` FROM `tblSettings`) AS `b` ON DUPLICATE KEY UPDATE `planKey` = `planKey`',
    'DUAL'                 => 'INSERT INTO `tblSubscriptionPlans` (`planKey`) SELECT \'x\' FROM DUAL ON DUPLICATE KEY UPDATE `planKey` = `planKey`',
    'UNION'                => 'INSERT INTO `tblSubscriptionPlans` (`planKey`) SELECT `planKey` FROM `tblSubscriptionPlans` UNION SELECT \'x\' FROM `tblSubscriptionPlans` ON DUPLICATE KEY UPDATE `planKey` = `planKey`',
    'a JOIN'               => 'INSERT INTO `tblSubscriptionPlans` (`planKey`) SELECT `p`.`planKey` FROM `tblSubscriptionPlans` AS `p` JOIN `tblSettings` AS `s` ON 1 = 1 ON DUPLICATE KEY UPDATE `planKey` = `planKey`',
    'INSERT ... SET'       => 'INSERT INTO `tblSubscriptionPlans` SET `planKey` = \'x\' ON DUPLICATE KEY UPDATE `planKey` = `planKey`',
    'WITH'                 => 'INSERT INTO `tblSubscriptionPlans` (`planKey`) WITH `c` AS (SELECT \'x\' AS `k`) SELECT `k` FROM `tblSubscriptionPlans` ON DUPLICATE KEY UPDATE `planKey` = `planKey`',
    'a sub-query on another table' => 'INSERT INTO `tblSubscriptionPlans` (`planKey`) SELECT \'x\' FROM `tblSubscriptionPlans` WHERE EXISTS (SELECT 1 FROM `tblSettings`) ON DUPLICATE KEY UPDATE `planKey` = `planKey`',
    // 🔎 Review finding F2 (7n Phase 2 review): an aggregate with no GROUP BY
    //    returns ONE row even from an empty table (measured on MySQL 8.0.36 and
    //    MariaDB 10.6.28: rows 0 -> 1). These two are the reviewer's samples.
    'MAX() with no GROUP BY' => 'INSERT INTO `tblSubscriptionPlans` (`planKey`, `planName`, `featuresJSON`) SELECT \'enterprise\', \'Enterprise\', COALESCE(MAX(`featuresJSON`), \'{}\') FROM `tblSubscriptionPlans` WHERE `planKey` = \'business\' ON DUPLICATE KEY UPDATE `planKey` = `planKey`',
    'COUNT() in an inner query' => 'INSERT INTO `tblSubscriptionPlans` (`planKey`) SELECT \'x\' FROM (SELECT COUNT(*) FROM `tblSubscriptionPlans`) AS `x` ON DUPLICATE KEY UPDATE `planKey` = `planKey`',
    'SUM ( with a space' => 'INSERT INTO `tblSubscriptionPlans` (`sortOrder`) SELECT SUM (`sortOrder`) FROM `tblSubscriptionPlans` ON DUPLICATE KEY UPDATE `planKey` = `planKey`',
    'GROUP_CONCAT()'       => 'INSERT INTO `tblSubscriptionPlans` (`planKey`) SELECT GROUP_CONCAT(`planKey`) FROM `tblSubscriptionPlans` ON DUPLICATE KEY UPDATE `planKey` = `planKey`',
    'JSON_ARRAYAGG()'      => 'INSERT INTO `tblSubscriptionPlans` (`featuresJSON`) SELECT JSON_ARRAYAGG(`planKey`) FROM `tblSubscriptionPlans` ON DUPLICATE KEY UPDATE `planKey` = `planKey`',
    // 🔎 Recheck finding 6: MySQL 8.0.24+ also has the spatial aggregate
    //    ST_Collect(), which gives one row from an empty table too (measured
    //    on MySQL 8.0.36 by the recheck).
    'ST_Collect()'         => 'INSERT INTO `tblSubscriptionPlans` (`planKey`) SELECT COALESCE(ST_AsText(ST_Collect(`location`)), \'x\') FROM `tblSubscriptionPlans` ON DUPLICATE KEY UPDATE `planKey` = `planKey`',
    'st_collect ( lower case, with a space' => 'INSERT INTO `tblSubscriptionPlans` (`planKey`) SELECT \'x\' FROM (SELECT st_collect (`location`) AS `c` FROM `tblSubscriptionPlans`) AS `b` ON DUPLICATE KEY UPDATE `planKey` = `planKey`',
    'HAVING'               => 'INSERT INTO `tblSubscriptionPlans` (`planKey`) SELECT \'x\' FROM `tblSubscriptionPlans` HAVING 1 = 1 ON DUPLICATE KEY UPDATE `planKey` = `planKey`',
    'GROUP BY'             => 'INSERT INTO `tblSubscriptionPlans` (`planKey`) SELECT `planKey` FROM `tblSubscriptionPlans` GROUP BY `planKey` ON DUPLICATE KEY UPDATE `planKey` = `planKey`',
];
foreach ($migF1Bad as $migF1Label => $migF1Sql) {
    $migF1Step = ['type' => 'insert_if_missing', 'table' => 'tblSubscriptionPlans', 'sql' => $migF1Sql];
    assertTrue(migrationStepCouldFillEmptySeedTable($migF1Step), 'F1 rule: an insert using ' . $migF1Label . ' could fill an empty table');
}
$migF1Refused = migrationValidateStep(['type' => 'insert_if_missing', 'table' => 'tblQRCodeTypes', 'sql' => 'INSERT INTO `tblQRCodeTypes` (`typeName`) VALUES (\'x\') ON DUPLICATE KEY UPDATE `typeName` = `typeName`'], 1);
assertTrue($migF1Refused !== [] && str_contains(implode(' ', $migF1Refused), 'must only copy rows that are already in it'), 'F1 rule: the validator refuses a VALUES insert into a seed-protection table, before anything runs: ' . implode(' | ', $migF1Refused));
$migF1Other = migrationValidateStep(['type' => 'insert_if_missing', 'table' => 'tblSettings', 'sql' => 'INSERT INTO `tblSettings` (`settingKey`) VALUES (\'x\') ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`'], 1);
assertEquals([], $migF1Other, 'F1 rule: a VALUES insert into tblSettings (not a seed-protection table) is still fine');
assertFalse(migrationStepCouldFillEmptySeedTable(['type' => 'update_if_unchanged', 'table' => 'tblSubscriptionPlans', 'sql' => 'UPDATE `tblSubscriptionPlans` SET `priceMonthly` = 1.00 WHERE `planKey` = \'x\'']), 'F1 rule: an UPDATE only changes rows that exist, so it cannot fill an empty table');
assertFalse(migrationStepCouldFillEmptySeedTable(['type' => 'add_column', 'table' => 'tblSubscriptionPlans', 'name' => 'x', 'sql' => 'ALTER TABLE `tblSubscriptionPlans` ADD COLUMN `x` INT NULL']), 'F1 rule: a structure step adds no row');
assertTrue(migrationStepCouldFillEmptySeedTable(['type' => 'mystery', 'table' => 'tblSubscriptionPlans', 'sql' => 'x']), 'F1 rule: an unknown step type is assumed to be able to fill it');
$migF1Names = ['type' => 'insert_if_missing', 'table' => 'tblSubscriptionPlans', 'sql' => 'INSERT INTO `tblSubscriptionPlans` (`values`, `joinedAt`) SELECT `b`.`values`, `b`.`joinedAt` FROM (SELECT `values`, `joinedAt` FROM `tblSubscriptionPlans`) AS `b` ON DUPLICATE KEY UPDATE `planKey` = `planKey`'];
assertFalse(migrationStepCouldFillEmptySeedTable($migF1Names), 'F1 rule: back-quoted names such as `values` are never read as keywords');
$migF1Quoted = ['type' => 'insert_if_missing', 'table' => 'tblSubscriptionPlans', 'sql' => 'INSERT INTO `tblSubscriptionPlans` (`description`) SELECT \'select from values union\' FROM (SELECT `planKey` FROM `tblSubscriptionPlans`) AS `b` ON DUPLICATE KEY UPDATE `planKey` = `planKey`'];
assertFalse(migrationStepCouldFillEmptySeedTable($migF1Quoted), 'F1 rule: words inside quoted text are never read as keywords');
$migF1MaxNamed = ['type' => 'insert_if_missing', 'table' => 'tblSubscriptionPlans', 'sql' => 'INSERT INTO `tblSubscriptionPlans` (`maxSales`, `count`) SELECT `b`.`maxSales`, `b`.`count` FROM (SELECT `maxSales`, `count` FROM `tblSubscriptionPlans`) AS `b` ON DUPLICATE KEY UPDATE `planKey` = `planKey`'];
assertFalse(migrationStepCouldFillEmptySeedTable($migF1MaxNamed), 'F2: back-quoted names such as `maxSales` or `count` are not mistaken for MAX() or COUNT()');
$migF2Refused = migrationValidateStep(['type' => 'insert_if_missing', 'table' => 'tblSubscriptionPlans', 'sql' => $migF1Bad['MAX() with no GROUP BY']], 1);
assertTrue(str_contains(implode(' ', $migF2Refused), 'must only copy rows that are already in it'), 'F2: the validator refuses the reviewer\'s MAX() sample before anything runs');

// 🔁 migrationReplayPlan() (task 7n Phase 2): which data steps the installer
//    repeats straight after loading seed_data.sql on a database whose upgrades
//    ran first. Pure, so it is tested with made-up loaded migrations.
$migReplayDataStep   = ['type' => 'insert_if_missing', 'table' => 'tblSettings', 'label' => 'r', 'sql' => 'INSERT INTO `tblSettings` (`settingKey`) VALUES (\'r\') ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`'];
$migReplayUpdateStep = ['type' => 'update_if_unchanged', 'table' => 'tblSettings', 'sql' => 'UPDATE `tblSettings` SET `settingValue` = \'2\' WHERE `settingKey` = \'r\' AND `settingValue` = \'1\''];
$migReplayDdlStep    = ['type' => 'add_column', 'table' => 'tblSettings', 'name' => 'zz', 'sql' => 'ALTER TABLE `tblSettings` ADD COLUMN `zz` INT NULL'];
$migReplayMigrations = [
    '0002_b' => ['path' => '', 'checksum' => 'bb', 'loadError' => '', 'definition' => ['key' => '0002_b', 'description' => 'b', 'steps' => [$migReplayDdlStep, $migReplayUpdateStep]]],
    '0001_a' => ['path' => '', 'checksum' => 'aa', 'loadError' => '', 'definition' => ['key' => '0001_a', 'description' => 'a', 'steps' => [$migReplayDdlStep, $migReplayDataStep, $migReplayUpdateStep]]],
    '0003_c' => ['path' => '', 'checksum' => 'cc', 'loadError' => '', 'definition' => ['key' => '0003_c', 'description' => 'c', 'steps' => [$migReplayDataStep]]],
    '0004_d' => ['path' => '', 'checksum' => 'dd', 'loadError' => '', 'definition' => ['key' => '0004_d', 'description' => 'd', 'steps' => [$migReplayDataStep]]],
    '0005_e' => ['path' => '', 'checksum' => 'ee', 'loadError' => 'could not be loaded (ParseError)', 'definition' => null],
];
$migReplay = migrationReplayPlan($migReplayMigrations, ['0001_a' => 'aa', '0002_b' => 'bb', '0003_c' => 'CHANGED', '0005_e' => 'ee', '0009_gone' => 'gg']);
$migReplaySummary = [];
foreach ($migReplay['steps'] as $migReplayEntry) {
    $migReplaySummary[] = $migReplayEntry['key'] . '#' . $migReplayEntry['position'] . ':' . $migReplayEntry['step']['type'];
}
assertEquals(
    ['0001_a#2:insert_if_missing', '0001_a#3:update_if_unchanged', '0002_b#2:update_if_unchanged'],
    $migReplaySummary,
    'replay: only the DATA steps of APPLIED, unchanged migrations, in number order, keeping each step\'s own position (structure steps are not repeated; 0004 is still waiting, so the normal run applies it in full)'
);
assertEquals(['0003_c', '0005_e', '0009_gone'], $migReplay['skipped'], 'replay: a migration changed since it was applied, one that cannot be loaded and one whose file is gone are skipped and reported');
assertEquals(['steps' => [], 'skipped' => []], migrationReplayPlan($migReplayMigrations, []), 'replay: nothing recorded yet (the normal fresh install) means nothing to repeat');
$migReplayInvalid = $migReplayMigrations;
$migReplayInvalid['0001_a']['definition']['steps'][] = ['type' => 'insert_if_missing', 'table' => 'tblSettings', 'sql' => 'DROP TABLE `tblSettings`'];
$migReplayInvalidPlan = migrationReplayPlan($migReplayInvalid, ['0001_a' => 'aa']);
assertEquals([[], ['0001_a']], [$migReplayInvalidPlan['steps'], $migReplayInvalidPlan['skipped']], 'replay: a migration that no longer passes the checks is skipped as a whole, never partly run');
$migReplayReal = migrationReplayPlan($migLoaded['migrations'], ['0004_plans_prices_currencies' => $migLoaded['migrations']['0004_plans_prices_currencies']['checksum']]);
$migReplayRealTypes = [];
foreach ($migReplayReal['steps'] as $migReplayEntry) {
    $migReplayRealTypes[$migReplayEntry['step']['type']] = true;
}
assertEquals(['insert_if_missing', 'update_if_unchanged'], array_keys($migReplayRealTypes), 'replay of the real 0004: only its insert-if-missing and update-if-unchanged steps');
assertEquals(15, count($migReplayReal['steps']), 'replay of the real 0004: its 15 data steps (5 settings, 1 plan, 2 price moves, 5 price points, 2 descriptions)');

// 🌱 Independent review (Codex C1 finding 1, 2026-11-25): seed_data.sql is
// loaded inside ONE transaction (migrationDbRunSeedSql()), which only makes it
// all-or-nothing if nothing in the file commits on its own. MySQL commits
// silently before CREATE, ALTER, DROP, TRUNCATE, RENAME, LOCK TABLES, START
// TRANSACTION/BEGIN and the like, so the file may hold only SET and INSERT
// statements - checked statement by statement here, so a later edit that
// breaks this fails a test instead of quietly undoing the protection.
$migSeedSqlText = (string) file_get_contents(dirname(migrationDefaultInstallSqlPath()) . DIRECTORY_SEPARATOR . 'seed_data.sql');
$migSeedBlanked = migrationBlankQuotedText((string) preg_replace('/^\s*--.*$/m', '', $migSeedSqlText));
$migSeedStatementWords = [];
assertTrue($migSeedBlanked['ok'], 'seed_data.sql: every quoted value is closed (so the statement check below reads it correctly)');
foreach (explode(';', $migSeedBlanked['text']) as $migSeedStatement) {
    $migSeedStatement = trim($migSeedStatement);
    if ($migSeedStatement === '') {
        continue;
    }
    $migSeedStatementWords[] = strtoupper((string) strtok($migSeedStatement, " \t\r\n("));
}
assertTrue(count($migSeedStatementWords) >= 5, 'seed_data.sql: the statement check found the file\'s statements (' . count($migSeedStatementWords) . ')');
assertEquals([], array_values(array_diff(array_unique($migSeedStatementWords), ['SET', 'INSERT'])), 'seed_data.sql holds only SET and INSERT statements, so loading it in one transaction really is all-or-nothing');
assertMatches('/never by reading tblSettings itself\'\)\s*(--[^\n]*\n\s*)*ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;/', $migSeedSqlText, 'seed_data.sql: the settings insert skips settings that already exist (migration 0002 adds some of the same ones), so the seed still loads after the upgrade tool has run');
assertEquals(2, substr_count($migSeedSqlText, 'ON DUPLICATE KEY UPDATE'), 'seed_data.sql: ONLY the settings insert and the completion marker skip existing rows - the other three tables stay plain INSERTs, guarded by migrationDbSeedDataState()');

// 🏁 Codex round 2 finding R1-1 (2026-11-25): the completion marker is the
//    LAST statement in seed_data.sql, so it can only exist when every insert
//    before it succeeded.
$migSeedStatements = [];
foreach (explode(';', $migSeedBlanked['text']) as $migSeedStatement) {
    if (trim($migSeedStatement) !== '') {
        $migSeedStatements[] = trim($migSeedStatement);
    }
}
$migSeedLastStatement = (string) end($migSeedStatements);
assertTrue(str_starts_with($migSeedLastStatement, 'INSERT INTO `tblSettings`'), 'seed_data.sql (R1-1): the LAST statement is the tblSettings completion-marker insert');
assertEquals('seed_data_loaded', migrationSeedMarkerKey(), 'R1-1: the completion marker setting is called seed_data_loaded');
assertMatches("/\\('system', 'seed_data_loaded', '1', 'boolean', 0, 0, '[^']+'\\)\\s*ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;\\s*(--[^\\n]*\\s*)*$/", $migSeedSqlText, 'seed_data.sql (R1-1): the marker row is not editable, skips an existing marker, and nothing but comments follows it');

// 📋 R1-1: migrationSeedExpectedRows() lists EXACTLY the rows seed_data.sql
//    inserts into each protection table (read from the file itself, so the two
//    can never drift apart), and migrationSeedTables() (R1-2, the storage-type
//    check) names every table the file writes to.
$migSeedByTable = [];
$migSeedNoComments = (string) preg_replace('/^\s*--.*$/m', '', $migSeedSqlText);
$migSeedPieces = preg_split('/INSERT INTO `(\w+)`/', $migSeedNoComments, -1, PREG_SPLIT_DELIM_CAPTURE);
if (!is_array($migSeedPieces)) {
    $migSeedPieces = [];
}
for ($migPieceIndex = 1; $migPieceIndex < count($migSeedPieces); $migPieceIndex = $migPieceIndex + 2) {
    $migPieceTable = $migSeedPieces[$migPieceIndex];
    $migPieceKeys  = [];
    preg_match_all("/^\\s*\\('([a-z0-9_]+)'\\s*,/m", $migSeedPieces[$migPieceIndex + 1], $migPieceKeys);
    if (!isset($migSeedByTable[$migPieceTable])) {
        $migSeedByTable[$migPieceTable] = [];
    }
    $migSeedByTable[$migPieceTable] = array_merge($migSeedByTable[$migPieceTable], $migPieceKeys[1]);
}
$migSeedTableNames = array_keys($migSeedByTable);
sort($migSeedTableNames);
$migSeedTablesListed = migrationSeedTables();
sort($migSeedTablesListed);
assertEquals($migSeedTableNames, $migSeedTablesListed, 'R1-2: migrationSeedTables() names exactly the tables seed_data.sql inserts into');
foreach (migrationSeedProtectionTables() as $migProtectedTable) {
    assertTrue(in_array($migProtectedTable, migrationSeedTables(), true), 'R1-2: protection table ' . $migProtectedTable . ' is also storage-type checked');
}
$migExpectedRows = migrationSeedExpectedRows();
assertEquals(migrationSeedProtectionTables(), array_keys($migExpectedRows), 'R1-1: expected rows are listed for exactly the three protection tables, in the same order');
foreach ($migExpectedRows as $migExpectedTable => $migExpectedSpec) {
    $migFileKeys = array_values(array_unique($migSeedByTable[$migExpectedTable]));
    assertEquals(count($migSeedByTable[$migExpectedTable]), count($migFileKeys), 'R1-1: seed_data.sql has no repeated name in ' . $migExpectedTable);
    $migListKeys = $migExpectedSpec['keys'];
    sort($migFileKeys);
    sort($migListKeys);
    assertEquals($migFileKeys, $migListKeys, 'R1-1: migrationSeedExpectedRows() lists exactly the ' . count($migFileKeys) . ' starting rows seed_data.sql puts in ' . $migExpectedTable);
}
assertEquals(38, count($migExpectedRows['tblQRCodeTypes']['keys']), 'R1-1: 38 QR code / barcode types (37 live + dynamic_url, built but dark)');
assertEquals(26, count($migExpectedRows['tblPredefinedLogos']['keys']), 'R1-1: 26 predefined logos');
assertEquals(4, count($migExpectedRows['tblSubscriptionPlans']['keys']), 'R1-1: 4 subscription plans');
$migInstallSchema = migrationParseInstallSqlSchema((string) file_get_contents(migrationDefaultInstallSqlPath()));
foreach ($migExpectedRows as $migExpectedTable => $migExpectedSpec) {
    assertTrue(isset($migInstallSchema[$migExpectedTable]) && in_array($migExpectedSpec['column'], $migInstallSchema[$migExpectedTable], true), 'R1-1: ' . $migExpectedTable . '.' . $migExpectedSpec['column'] . ' is a real install.sql column');
}

// 🔑 Codex round 3 finding 2: the marker-free "complete" check also needs the
//    starting SETTINGS. The runtime list is short and explicit, and is checked
//    here against seed_data.sql so it cannot drift.
$migSettingsKeys = [];
$migSettingsPieces = preg_split('/INSERT INTO `tblSettings`/', $migSeedNoComments);
if (!is_array($migSettingsPieces)) {
    $migSettingsPieces = [];
}
// The first piece is the file head; the second is the big settings insert; the
// last is the completion marker, which is NOT one of the 80 starting settings.
$migSettingsBig = isset($migSettingsPieces[1]) ? $migSettingsPieces[1] : '';
$migSettingsEnd = strpos($migSettingsBig, 'INSERT INTO');
if (is_int($migSettingsEnd)) {
    $migSettingsBig = substr($migSettingsBig, 0, $migSettingsEnd);
}
preg_match_all("/^\\s*\\('[a-z_]+',\\s*'([^']+)'/m", $migSettingsBig, $migSettingsKeys);
$migSeededSettingKeys = $migSettingsKeys[1];
assertTrue(!in_array('seed_data_loaded', $migSeededSettingKeys, true), 'round 3 (2): the settings count leaves out the completion marker');
assertEquals(count($migSeededSettingKeys), count(array_unique($migSeededSettingKeys)), 'round 3 (2): seed_data.sql seeds no setting twice');
// Codex round 4 finding 1: the runtime list is EVERY seeded setting (both
// directions: nothing seeded is left off, nothing listed is unseeded).
$migListSettings = migrationSeedRequiredSettingKeys();
$migFileSettings = $migSeededSettingKeys;
sort($migListSettings);
sort($migFileSettings);
assertEquals($migFileSettings, $migListSettings, 'round 4 (1): migrationSeedRequiredSettingKeys() is exactly the ' . count($migSeededSettingKeys) . ' settings seed_data.sql inserts (a key added to or removed from the file without the list fails here)');
assertEquals(80, count(migrationSeedRequiredSettingKeys()), 'round 4 (1): 80 starting settings');
assertEquals(count(migrationSeedRequiredSettingKeys()), count(array_unique(migrationSeedRequiredSettingKeys())), 'round 4 (1): no required setting is listed twice');
assertTrue(!function_exists('migrationSeedMinimumSettingsCount'), 'round 4 (1): the settings row count (which an unrelated row could satisfy) is gone');
// Stand-in: the names a database holds, with one starting setting missing and
// one unrelated extra name added - the extra name must not cover the gap.
$migStandIn = array_values(array_diff(migrationSeedRequiredSettingKeys(), ['copyright_year']));
$migStandIn[] = 'some_unrelated_extra_setting';
assertEquals(['copyright_year'], migrationSeedMissingSettingNames($migStandIn), 'round 4 (1): a missing starting setting is named even when an unrelated extra row is present');
assertEquals([], migrationSeedMissingSettingNames(migrationSeedRequiredSettingKeys()), 'round 4 (1): every starting setting present: nothing missing');
assertEquals(80, count(migrationSeedMissingSettingNames(['some_unrelated_extra_setting'])), 'round 4 (1): no starting settings at all: all 80 missing');
$migShortfallStart  = (int) strpos($migRunnerSource, 'function migrationDbSeedSettingsShortfall(');
$migShortfallEnd    = (int) strpos($migRunnerSource, 'function migrationDbTableExistsState(');
$migShortfallSource = substr($migRunnerSource, $migShortfallStart, $migShortfallEnd - $migShortfallStart);
assertTrue(!str_contains($migShortfallSource, 'COUNT(*)'), 'round 4 (1): the settings check does not count rows');

// 📌 Decision 0018 (Codex round 3 finding 1): the completion marker is checked
//    BEFORE "all three tables empty", in the source, so a present marker can
//    never fall through to "none".
$migStateSource = substr($migRunnerSource, (int) strpos($migRunnerSource, 'function migrationDbSeedDataState('));
$migMarkerCallAt = strpos($migStateSource, 'migrationDbSeedMarkerPresent($db)');
$migNoneAt       = strpos($migStateSource, "'state'] = 'none'");
assertTrue(is_int($migMarkerCallAt) && is_int($migNoneAt) && $migMarkerCallAt < $migNoneAt, 'decision 0018: migrationDbSeedDataState() asks for the marker BEFORE it can answer "none"');

// 🧱 R1-2: the storage-type check runs BEFORE the transaction is opened, and a
//    refusal is reported in plain words the installer shows.
$migRunSeedSource = substr($migRunnerSource, (int) strpos($migRunnerSource, 'function migrationDbRunSeedSql('));
$migEngineCheckAt = strpos($migRunSeedSource, 'migrationDbSeedTablesTransactional($db, $tablesToCheck)');
$migMultiQueryAt  = strpos($migRunSeedSource, '->multi_query(');
assertTrue(is_int($migEngineCheckAt) && is_int($migMultiQueryAt) && $migEngineCheckAt < $migMultiQueryAt, 'R1-2: migrationDbRunSeedSql() checks the storage type of every table before loading anything');
assertContains("strcasecmp(\$engineName, 'InnoDB') !== 0", $migRunnerSource, 'R1-2: only InnoDB tables are accepted');

// 🔁 Review finding F1 (7n Phase 2 review): the starting data and the repeat of
//    the applied upgrades' data steps land together - the upgrade lock is taken
//    before anything is loaded, and the repeated steps run before the one commit.
//    (Proven on a real database in tests/integration/migrations_real_mysql.php J.)
$migSeedOnly       = substr($migRunSeedSource, 0, (int) strpos($migRunSeedSource, 'function migrationDbRunSeedTransaction('));
$migLockAt         = strpos($migSeedOnly, 'migrationDbAcquireLock($db)');
$migSeedTxAt       = strpos($migSeedOnly, 'migrationDbRunSeedTransaction($db, $seedSql)');
$migReplayStepAt   = strpos($migSeedOnly, "migrationDbRunStepSql(\$db, \$plannedStep['step']['sql'])");
$migCommitAt       = strpos($migSeedOnly, '$db->commit()');
assertTrue(is_int($migLockAt) && is_int($migSeedTxAt) && $migLockAt < $migSeedTxAt, 'F1: migrationDbRunSeedSql() takes the upgrade lock BEFORE loading the starting data');
assertTrue(is_int($migReplayStepAt) && is_int($migCommitAt) && $migSeedTxAt < $migReplayStepAt && $migReplayStepAt < $migCommitAt, 'F1: the repeated upgrade data steps run inside the same transaction, before its only commit');
assertFalse(str_contains($migRunnerSource, 'function migrationDbReplayDataSteps('), 'F1: the separate after-the-commit repeat is gone');
assertFalse(str_contains((string) file_get_contents(dirname(CUERCODE_FUNCTIONS_DIR) . DIRECTORY_SEPARATOR . 'public_html' . DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR . 'index.php'), 'migrationDbReplayDataSteps('), 'F1: the installer no longer repeats the upgrade steps separately');
assertTrue(strlen(CUERCODE_INSTALLER_LOCK_NAME) <= 23, 'installer lock prefix fits the 64-character MySQL lock-name limit (23 + 1 + 30 + 1 + 8 = 63)');
assertTrue(CUERCODE_INSTALLER_LOCK_NAME !== CUERCODE_MIGRATION_LOCK_NAME, 'installer lock and upgrade lock have different names, so one connection can hold both');

// 🔒 F6: the lock name SQL expression includes the live database (not a
// fixed, site-wide string) and has exactly one `?` placeholder (the prefix -
// DATABASE() itself needs none, it is a MySQL built-in).
assertContains('DATABASE()', migrationLockNameSqlExpression(), 'lock name expression: includes the current database (F6)');
assertEquals(1, substr_count(migrationLockNameSqlExpression(), '?'), 'lock name expression: exactly one placeholder (the fixed prefix)');
assertContains('MD5(', migrationLockNameSqlExpression(), 'lock name expression: hashes the full database name too, so truncation can never make two different databases collide into the same lock name');

assertEquals('warning', migrationReportLineSeverity('Warning: 0002_x was changed after it was applied.'), 'severity: a Warning: line is a warning');
assertEquals('error', migrationReportLineSeverity('FAILED 0002_b - not recorded; fix the problem and run --apply again.'), 'severity: a FAILED line is an error');
assertEquals('error', migrationReportLineSeverity('Refused: another upgrade is running right now. Try again in a minute.'), 'severity: a Refused: line is an error');
assertEquals('error', migrationReportLineSeverity('Failed: 0002_b step 3 failed.'), 'severity: a Failed: line is an error');
assertEquals('error', migrationReportLineSeverity('This database is older than the supported starting point. Missing columns:'), 'severity: the baseline-mismatch line is an error');
assertEquals('error', migrationReportLineSeverity('Broken migration file 0003_bad:'), 'severity: a broken-file line is an error');
assertEquals('info', migrationReportLineSeverity('Already up to date.'), 'severity: everything else is info');
assertEquals('info', migrationReportLineSeverity('Applied 0001_a: 2 step(s) run, 7 already done.'), 'severity: an applied-migration line is info');
assertEquals('info', migrationReportLineSeverity(''), 'severity: an empty line is info (never silently hidden as success, never mistaken for an error)');

// ==============================================================================
// ✅ Section 15: the web installer's source (public_html/install/index.php) -
//    review findings F1, F2, F3, F4 (checked by source here; the end-to-end
//    database behaviour is proven in tests/integration/migrations_real_mysql.php)
// ==============================================================================

$migInstallerSource = (string) file_get_contents(
    dirname(CUERCODE_FUNCTIONS_DIR) . DIRECTORY_SEPARATOR . 'public_html' . DIRECTORY_SEPARATOR
    . 'install' . DIRECTORY_SEPARATOR . 'index.php'
);

// F3: the installer must call migrationFormatReport() with $includeErrorDetail
// = false for what it SHOWS, but must still write the full-detail version to
// the server's error log first - the detail must not simply vanish.
assertContains("migrationFormatReport('apply', \$migrationResult, false)", $migInstallerSource, 'installer (F3): shows the report with $includeErrorDetail = false (generic lines only, nothing raw shown to the public)');
assertContains("migrationFormatReport('apply', \$migrationResult, true)", $migInstallerSource, 'installer (F3): still builds the FULL-detail report before logging it');

// The true-detail call must sit INSIDE an error_log(...) call, not be echoed
// to the page - checked by looking at the text immediately BEFORE it for the
// nearest unclosed "error_log(" (a simple, readable window check rather than
// a brittle exact-whitespace string match).
$migTrueDetailCallPosition = strpos($migInstallerSource, "migrationFormatReport('apply', \$migrationResult, true)");
assertTrue(is_int($migTrueDetailCallPosition), 'installer (F3): finds the full-detail migrationFormatReport() call');
$migTextBeforeTrueDetailCall = substr($migInstallerSource, max(0, $migTrueDetailCallPosition - 250), 250);
assertContains('error_log(', $migTextBeforeTrueDetailCall, 'installer (F3): the full-detail report is built as an argument to error_log(), not echoed to the page');

// F2/F4: the connection's character set and strict SQL mode are put back
// after the installer's own install.sql/seed_data.sql multi_query() calls,
// before the upgrade tool (migrationApplyPending()) ever runs a migration
// step on this connection.
// 📍 The ACTUAL call site, not the file header's "@see ... migrationApplyPending()"
// mention (which appears earlier in the file and would otherwise make an
// "is it before the call?" check pass for the wrong reason).
$migApplyPendingCallPosition = strpos($migInstallerSource, '$migrationResult = migrationApplyPending(');
assertTrue(is_int($migApplyPendingCallPosition), 'installer: finds the real migrationApplyPending() call site');

assertEquals(2, substr_count($migInstallerSource, '$mysqli->set_charset($dbCharset)'), 'installer (F2/F4): sets the charset twice - once in Step 3 (first connect) and once again in Step 5a, before the upgrade tool runs');
$migFirstCharsetPosition  = strpos($migInstallerSource, '$mysqli->set_charset($dbCharset)');
$migSecondCharsetPosition = strpos($migInstallerSource, '$mysqli->set_charset($dbCharset)', $migFirstCharsetPosition + 1);
assertTrue(
    $migSecondCharsetPosition !== false && $migSecondCharsetPosition < $migApplyPendingCallPosition,
    'installer (F2/F4): the second charset restore happens BEFORE migrationApplyPending() runs'
);

assertEquals(2, substr_count($migInstallerSource, 'NO_ENGINE_SUBSTITUTION'), 'installer (F2/F4): reapplies strict SQL mode twice - once in Step 3, once in Step 5a');
$migFirstStrictModePosition  = strpos($migInstallerSource, 'NO_ENGINE_SUBSTITUTION');
$migSecondStrictModePosition = strpos($migInstallerSource, 'NO_ENGINE_SUBSTITUTION', $migFirstStrictModePosition + 1);
assertTrue(
    $migSecondStrictModePosition !== false && $migSecondStrictModePosition < $migApplyPendingCallPosition,
    'installer (F2/F4): strict SQL mode is reapplied a second time before migrationApplyPending() runs'
);

// F1: a seed_data.sql failure must be tracked and must stop the lock file
// (the "installation is complete, now locked" marker) from ever being written.
assertContains('$seedStepFailed', $migInstallerSource, 'installer (F1): tracks whether seed_data.sql itself failed');
assertTrue(
    strpos($migInstallerSource, 'if (!$seedStepFailed)') < strpos($migInstallerSource, '$overallSuccess = true;', strpos($migInstallerSource, 'if (!$seedStepFailed)')),
    'installer (F1): $overallSuccess is only ever set true when seed_data.sql did NOT fail'
);
assertTrue(
    strpos($migInstallerSource, '$overallSuccess = true;') < strpos($migInstallerSource, 'file_put_contents($lockFile'),
    'installer (F1): the lock file is only ever considered after $overallSuccess is decided'
);

// 🔎 Codex round 2 (2026-11-25), installer side: a "partial" database is
//    described from the per-row check (R1-1), with advice for BOTH a new
//    database and a site already in use; a refused load (R1-2, a table that
//    is not InnoDB) shows its plain-English reason; the final check before the
//    lock file names what is missing.
assertContains("\$seedState['summary']", $migInstallerSource, 'installer (R1-1): the part-loaded message says exactly which starting rows are there');
assertContains('If this site is ALREADY IN USE, do not empty anything', $migInstallerSource, 'installer (R1-1): never advises emptying tables on a site that is already in use');
assertContains('.installed.lock in _auth_keys/', $migInstallerSource, 'installer (R1-1): names the real lock file to put back');
assertContains("\$seedRun['refusal'] !== ''", $migInstallerSource, 'installer (R1-2): a refused starting-data load is told apart from a failed one');
assertContains("'message' => \$seedRun['refusal']", $migInstallerSource, 'installer (R1-2): the refusal reason is shown in plain words');
assertContains("\$finalSeedState['summary']", $migInstallerSource, 'installer (R1-1): the final check before the lock file names what is missing');
assertFalse(str_contains($migInstallerSource, "\$finalSeedState['empty']"), 'installer (R1-1): the final check no longer reports only empty tables (a table can be part-filled)');

// 🛑 Recheck finding 1 (7n Phase 2 recheck, decision 0036): an upgrade file
//    that has CHANGED since this database applied it means the database is
//    not what a normal install gives (its new text never ran here, and the
//    installer's repeat skips it). The installer must stop, change nothing
//    and NOT lock itself, saying plainly what to do. Before this fix it only
//    warned and then locked itself as "successful".
$migChangedMessage = migrationChangedUpgradesRefusal(['0004_plans_prices_currencies', '0002_admin_audit_log_and_settings']);
assertContains('0004_plans_prices_currencies', $migChangedMessage, 'recheck F1: the refusal names each changed upgrade');
assertContains('0002_admin_audit_log_and_settings', $migChangedMessage, 'recheck F1: ...every one of them');
assertContains('nothing was changed', $migChangedMessage, 'recheck F1: the refusal says nothing was changed');
assertContains('will not lock itself', $migChangedMessage, 'recheck F1: the refusal says the installer did not lock itself');
assertContains('empty database', $migChangedMessage, 'recheck F1: the refusal says what to do (rebuild from an empty database)');
assertFalse(str_contains($migChangedMessage, 'checksum') || str_contains($migChangedMessage, 'tblSchemaMigrations'), 'recheck F1: the refusal is plain words (no checksum or table names)');
assertEquals('', migrationChangedUpgradesRefusal([]), 'recheck F1: no changed upgrade, no refusal');

// 🔎 The installer asks BEFORE it changes anything: the check comes after the
//    one-installer lock and before install.sql, seed_data.sql and the upgrades.
$migChangedCheckAt = strpos($migInstallerSource, '= migrationDbChangedAppliedUpgrades($mysqli, migrationDefaultDirectory())');
assertTrue(is_int($migChangedCheckAt), 'recheck F1: the installer checks for upgrade files changed since they were applied');
assertTrue(
    is_int($migChangedCheckAt)
    && $migChangedCheckAt > (int) strpos($migInstallerSource, '$installerLockState = migrationDbAcquireInstallerLock($mysqli);')
    && $migChangedCheckAt < (int) strpos($migInstallerSource, '$installRun = migrationDbRunInstallSql($mysqli, $installSQL);')
    && $migChangedCheckAt < (int) strpos($migInstallerSource, '$seedRun = migrationDbRunSeedSql(')
    && $migChangedCheckAt < $migApplyPendingCallPosition,
    'recheck F1: ...under the installer lock, and before install.sql, the starting data and the upgrades run'
);
assertContains("migrationChangedUpgradesRefusal(\$upgradeFileCheck['changed'])", $migInstallerSource, 'recheck F1: the installer shows the plain refusal');

// 📝 Recheck finding 7: a comment said seed_data.sql sets its own SQL mode.
//    It does not (only the character set); the comments now say so, and this
//    pins the fact they rely on.
assertEquals(0, preg_match('/^\s*SET\s+(SESSION\s+)?SQL_MODE\b/im', $migSeedSqlText), 'recheck F7: seed_data.sql sets no SQL mode (only the character set)');
$migRunnerAndInstaller = (string) file_get_contents(CUERCODE_FUNCTIONS_DIR . 'fn_migrations.php') . $migInstallerSource;
assertFalse(str_contains($migRunnerAndInstaller, 'seed_data.sql sets its own SQL mode') || str_contains($migRunnerAndInstaller, 'changes both for itself'), 'recheck F7: no comment claims seed_data.sql sets its own SQL mode');
