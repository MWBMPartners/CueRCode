<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Tests for the PHP 8.6 Compatibility Scanner (#151)
 * ==============================================================================
 *
 * Path: tests/test_php86_compat_scan.php
 *
 * Proves that tools/php86-compat-scan.php:
 *   1. finds each kind of problem it claims to find (one bad example per rule),
 *   2. reports nothing for clean code,
 *   3. honours the `// php86-scan-ok: <reason>` opt-out comment, and
 *   4. finds ZERO problems in the real project code (web/, tests/, tools/).
 *
 * ⚠️ The bad examples are STRINGS inside this file, never separate .php files.
 *    A real file containing `return` inside `finally` would itself fail the
 *    PHP 8.6 lint, and this very scan, which is the point of the check.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       #151
 * @see         tools/php86-compat-scan.php
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'php86-compat-scan.php';

/**
 * 🔧 Run the scanner on a snippet and return the list of rule names hit.
 *
 * @param string $code PHP source (must start with an opening tag).
 *
 * @return array<int, string> Rule names, in the order found.
 */
function php86TestRules(string $code): array
{
    $rules = [];

    foreach (php86ScanSource($code, 'snippet.php') as $hit) {
        $rules[] = $hit['rule'];
    }

    return $rules;
}

// ==============================================================================
// 🔴 One bad example per rule: each must be found
// ==============================================================================

$badExamples = [
    'banned function spl_object_hash' => [
        'banned-function',
        '<?php $h = spl_object_hash($o);',
    ],
    'banned function is_double (fully qualified)' => [
        'banned-function',
        '<?php $b = \\is_double($x);',
    ],
    'banned function doubleval' => [
        'banned-function',
        '<?php $f = doubleval("1.5");',
    ],
    'banned function mb_ereg_replace (mb_ereg* family)' => [
        'banned-function',
        '<?php $r = mb_ereg_replace("a", "b", $s);',
    ],
    'is_a with a string literal first argument' => [
        'is-a-string',
        '<?php $b = is_a("Bar", "Foo");',
    ],
    'is_subclass_of with allow_string false' => [
        'is-a-string',
        '<?php $b = is_subclass_of("Bar", "Foo", false);',
    ],
    'array_walk on $this' => [
        'array-walk-object',
        '<?php array_walk($this, function (&$v) { $v++; });',
    ],
    'array_walk_recursive on a $obj variable' => [
        'array-walk-object',
        '<?php array_walk_recursive($obj, "f");',
    ],
    'array_walk on new object' => [
        'array-walk-object',
        '<?php array_walk(new Foo(), "f");',
    ],
    'method get_charset' => [
        'banned-method',
        '<?php $c = $db->get_charset();',
    ],
    'method stmt_init' => [
        'banned-method',
        '<?php $s = $db->stmt_init();',
    ],
    'SplFileObject csv method fgetcsv' => [
        'banned-method',
        '<?php $row = $file->fgetcsv();',
    ],
    'ArrayIterator deprecated method ksort' => [
        'array-iterator-method',
        '<?php $it = new ArrayIterator([1, 2]); $it->ksort();',
    ],
    'RecursiveArrayIterator deprecated method serialize' => [
        'array-iterator-method',
        '<?php $it = new RecursiveArrayIterator([1]); $s = $it->serialize();',
    ],
    'constant named let (const)' => [
        'reserved-constant-name',
        '<?php const let = 1;',
    ],
    'constant named is (define)' => [
        'reserved-constant-name',
        '<?php define("is", 1);',
    ],
    'typed class constant named is' => [
        'reserved-constant-name',
        '<?php class A { const string is = "x"; }',
    ],
    'typed class constant named namespace' => [
        'constant-namespace',
        '<?php class A { final public const ?int namespace = 1; }',
    ],
    'second constant in a list named let' => [
        'reserved-constant-name',
        '<?php class A { const ONE = 1, let = 2; }',
    ],
    'use alias named let' => [
        'reserved-alias-name',
        '<?php use Foo\\Bar as let;',
    ],
    '__debugInfo with nullable return type' => [
        'debuginfo-nullable',
        '<?php class A { public function __debugInfo(): ?array { return null; } }',
    ],
    '__debugInfo with array|null return type' => [
        'debuginfo-nullable',
        '<?php class A { public function __debugInfo(): array|null { return []; } }',
    ],
    'new mysqli_stmt without a query' => [
        'mysqli-stmt-no-query',
        '<?php $s = new mysqli_stmt($db);',
    ],
    'SORT_LOCALE_STRING constant' => [
        'sort-locale-string',
        '<?php sort($a, SORT_LOCALE_STRING);',
    ],
    'return inside finally' => [
        'return-in-finally',
        '<?php function f() { try { g(); } finally { return 1; } }',
    ],
    'value returned from __construct' => [
        'ctor-return-value',
        '<?php class A { public function __construct() { return 5; } }',
    ],
    'yield inside __destruct' => [
        'ctor-return-value',
        '<?php class A { public function __destruct() { yield 1; } }',
    ],
    'define with three arguments' => [
        'define-three-args',
        '<?php define("FOO", 1, true);',
    ],
    'function named let' => [
        'reserved-function-name',
        '<?php function let() { return 1; }',
    ],
    'class named is' => [
        'reserved-type-name',
        '<?php class is {}',
    ],
    'constant named underscore' => [
        'constant-underscore',
        '<?php const _ = 1;',
    ],
    'class constant named namespace' => [
        'constant-namespace',
        '<?php class A { const namespace = 1; }',
    ],
    'pack format with byte-order sign' => [
        'pack-byte-order',
        '<?php $b = pack("N>", 1);',
    ],
    'unpack format with byte-order sign' => [
        'pack-byte-order',
        '<?php $a = unpack("V<x", $s);',
    ],
];

foreach ($badExamples as $label => $example) {
    $expectedRule = $example[0];
    $rules        = php86TestRules($example[1]);

    assertTrue(
        in_array($expectedRule, $rules, true),
        'scanner finds: ' . $label
    );
}

// ==============================================================================
// 🟢 Clean code: nothing found
// ==============================================================================

$cleanCode = <<<'PHPCODE'
<?php
class Clean
{
    public function __construct()
    {
        // A bare return (no value) is fine.
        return;
    }

    public function run(): int
    {
        try {
            $x = is_float(1.5) ? 1 : 0;
        } finally {
            // A closure inside finally may return its own value.
            $f = function () { return 2; };
            $f();
        }
        // The words below appear only in strings and comments: not code.
        $s = 'spl_object_hash( finally { return 1; } ArrayIterator';
        // is_double( doubleval(
        return $x + strlen($s) + strlen(pack('N', 1));
    }
}
PHPCODE;

assertEquals([], php86TestRules($cleanCode), 'clean code reports no findings');

// 🧩 A method called get_charset on OUR OWN class declaration is not a call.
assertEquals(
    [],
    php86TestRules('<?php class B { public function is_a() { return 1; } }'),
    'declaring a method named is_a is not a call to the banned function'
);

// 🧩 Things that must NOT be flagged (false-alarm guards).
$notFlagged = [
    'array_walk on a plain array'                     => '<?php array_walk($list, function (&$v) { $v++; });',
    'array_walk on a property array'                  => '<?php array_walk($this->items, "f");',
    'array_walk_recursive on a plain array'           => '<?php array_walk_recursive($data, "f");',
    'is_a with an object variable'                    => '<?php $b = is_a($o, "Foo");',
    'is_a with a string and allow_string true'        => '<?php $b = is_a("Bar", "Foo", true);',
    'is_subclass_of with a string (default allows)'   => '<?php $b = is_subclass_of("Bar", "Foo");',
    'bare ArrayIterator mention'                      => '<?php $it = new ArrayIterator([1, 2]); foreach ($it as $v) {}',
    'ksort on a file that never names ArrayIterator'  => '<?php $o->ksort(); $o->serialize();',
    'method named is'                                 => '<?php class A { public function is() { return 1; } }',
    'method named let (no modifier)'                  => '<?php class A { function let() { return 1; } }',
    'method named readonly in a trait'                => '<?php trait T { public function readonly() { return 1; } }',
    'method named is inside an anonymous class'       => '<?php $x = new class { public function is() { return 1; } };',
    'ordinary typed class constant'                   => '<?php class A { const string NAME = "x"; const ?int MAX = 3; }',
    '__debugInfo returning array'                     => '<?php class A { public function __debugInfo(): array { return []; } }',
    'new mysqli_stmt with a query'                    => '<?php $s = new mysqli_stmt($db, "SELECT 1");',
    'foreach ... as with ordinary variable'           => '<?php foreach ($a as $v) {}',
];

foreach ($notFlagged as $label => $code) {
    assertEquals([], php86TestRules($code), 'scanner does not flag: ' . $label);
}

// 🧩 A plain FUNCTION named let is still flagged even after a class closed.
assertEquals(
    ['reserved-function-name'],
    php86TestRules('<?php class A { public function ok() { return 1; } } function let() { return 1; }'),
    'a plain function named let after a class body is still flagged'
);

// ==============================================================================
// 💬 Opt-out comment
// ==============================================================================

assertEquals(
    [],
    php86TestRules('<?php $f = doubleval("1"); // php86-scan-ok: legacy alias kept for a test'),
    'opt-out comment on the same line suppresses the finding'
);

assertEquals(
    ['banned-function'],
    php86TestRules("<?php // php86-scan-ok: other line\n\$f = doubleval(\"1\");"),
    'opt-out comment on a DIFFERENT line does not suppress the finding'
);

// ==============================================================================
// 🌍 The whole repository is clean
// ==============================================================================

$repoRoot  = dirname(__DIR__);
$totalHits = 0;
$firstHit  = '';
$scanned   = 0;

foreach (['web', 'tests', 'tools'] as $dirName) {
    foreach (php86ScanListFiles($repoRoot . DIRECTORY_SEPARATOR . $dirName) as $file) {
        // 📚 Vendored third-party libraries are warnings only, not failures.
        if (php86ScanIsVendored($file)) {
            continue;
        }

        $scanned++;
        $code = file_get_contents($file);

        if ($code === false) {
            continue;
        }

        foreach (php86ScanSource($code, $file) as $hit) {
            $totalHits++;
            if ($firstHit === '') {
                $firstHit = $hit['file'] . ':' . $hit['line'] . ' [' . $hit['rule'] . ']';
            }
        }
    }
}

assertTrue($scanned > 50, 'repository scan covered a sensible number of files (' . $scanned . ')');
assertEquals(0, $totalHits, 'repository has zero PHP 8.6 scanner findings ' . $firstHit);
