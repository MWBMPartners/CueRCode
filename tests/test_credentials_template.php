<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Tests for the committed credentials template (#113 B3)
 * ==============================================================================
 *
 * Path: tests/test_credentials_template.php
 *
 * The template must exist, must hold ONLY placeholders (never a real secret),
 * must name every required setting, and must NOT be matched by the .gitignore
 * rules that hide the real auth_creds.php. The template is READ as text, never
 * run.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       #113
 */

$template = dirname(__DIR__) . '/web/CueRCode.net/_auth_keys/auth_creds.example.php.dist';
assertTrue(is_file($template), 'credentials template exists');

$text = (string) file_get_contents($template);

// 🔑 Every required setting is present as a define() call.
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'ENCRYPTION_KEY'] as $constant) {
    assertEquals(
        1,
        preg_match("/^define\\('" . $constant . "',\\s*'REPLACE_ME[A-Z0-9_]*'\\);/m", $text),
        $constant . ' is defined with a REPLACE_ME placeholder'
    );
}

// 🚫 Only placeholders: every live define() in the file holds a REPLACE_ME value.
preg_match_all("/^define\\('([A-Z_]+)',\\s*(.+)\\);/m", $text, $matches, PREG_SET_ORDER);
$realValues = [];
foreach ($matches as $match) {
    if (!str_contains($match[2], 'REPLACE_ME')) {
        $realValues[] = $match[1];
    }
}
assertEquals([], $realValues, 'no live setting holds anything but a placeholder');

// 🙈 The ignore rules must not hide the template, and must still hide the real file.
$root = dirname(__DIR__);
$git  = trim((string) shell_exec('command -v git 2>/dev/null'));
if ($git === '' || !is_dir($root . '/.git')) {
    assertTrue(true, 'ignore-rule check skipped: no git checkout here');
} else {
    $check = static function (string $path) use ($root): int {
        $cmd = 'git -C ' . escapeshellarg($root) . ' check-ignore -q ' . escapeshellarg($path) . ' 2>/dev/null';
        exec($cmd, $out, $code);

        return $code;
    };
    assertEquals(1, $check('web/CueRCode.net/_auth_keys/auth_creds.example.php.dist'), 'template is NOT ignored by git');
    assertEquals(0, $check('web/CueRCode.net/_auth_keys/auth_creds.php'), 'the real auth_creds.php IS still ignored');
}
