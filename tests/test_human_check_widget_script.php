<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - The human-check widget script moves on to the next provider reliably
 * ==============================================================================
 *
 * Path: tests/test_human_check_widget_script.php
 *
 * WHAT THIS PROVES (plain English), second Codex review: the widget script
 * (public_html/assets/js/cuercode-human-check.js) waits 8 seconds for a
 * provider's script and then tries the next configured provider. All three
 * providers call back through the SAME function name. Before the fix, a
 * provider that answered LATE (after the script had moved on) stopped the
 * next provider's 8-second timer, so if that one never answered, the third
 * was never tried; and a late "could not load" from the first script made it
 * skip a provider.
 *
 * It runs the REAL script under Node.js with a small stand-in page and
 * stand-in timers (no browser, no network), and checks:
 *   1. the first provider times out, the second is loaded;
 *   2. the first provider's late callback does NOT stop the second's timer:
 *      when the second never answers, the third is loaded;
 *   3. a late "could not load" from a script that is no longer current
 *      changes nothing;
 *   4. the current provider's own callback does stop its timer (it is drawn,
 *      nothing more is loaded).
 * Where Node.js is not installed the behaviour part is skipped with a note
 * (GitHub's ubuntu-latest runners have it); the source checks always run.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025-2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (second Codex review)
 * @see         web/CueRCode.net/public_html/assets/js/cuercode-human-check.js
 */

$wjScript = CUERCODE_SITE_ROOT . 'public_html' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'cuercode-human-check.js';
$wjSource = (string) file_get_contents($wjScript);

// ==============================================================================
// 🔎 Source checks (always)
// ==============================================================================
assertContains('loadGeneration', $wjSource, 'Codex 2: each script load is numbered, so an answer from an older load can be told apart');
assertTrue(preg_match('/script\.onerror = function \(\) \{\s*(?:\/\*.*?\*\/\s*)?if \(myGeneration !== loadGeneration\) \{\s*return;/s', $wjSource) === 1, 'Codex 2: a "could not load" from a script that is no longer current is ignored');
assertTrue(preg_match('/window\.cuercodeHumanCheckLoaded = function \(\) \{\s*if \(boxes\.length === 0 \|\| providerApi\(boxes\[0\]\.getAttribute\(\'data-provider\'\)\) === null\) \{\s*return;/', $wjSource) === 1, 'Codex 2: the shared callback stops the timer only when the CURRENT provider has really loaded');

// ==============================================================================
// 🟢 Behaviour under Node.js
// ==============================================================================
$wjNode = '';
foreach (['/opt/homebrew/bin/node', '/usr/local/bin/node', '/usr/bin/node'] as $wjCandidate) {
    if (is_executable($wjCandidate)) {
        $wjNode = $wjCandidate;
        break;
    }
}
if ($wjNode === '' && function_exists('exec')) {
    $wjFound = [];
    exec('command -v node 2>/dev/null', $wjFound);
    if (isset($wjFound[0]) && is_executable(trim($wjFound[0]))) {
        $wjNode = trim($wjFound[0]);
    }
}
if ($wjNode === '' || !function_exists('proc_open')) {
    assertTrue(true, 'NOTE: Node.js was not found, so the widget script behaviour checks were skipped here (they run where Node.js is installed, including GitHub\'s ubuntu-latest runners)');
    return;
}

// 🧪 The stand-in page: three providers, the first drawn, two fallbacks.
$wjHarness = <<<'JS'
'use strict';
const fs = require('fs');
const source = fs.readFileSync(process.argv[2], 'utf8');
let now = 0; let nextId = 1; const timers = new Map(); const appended = [];
function makeBox() {
  const attrs = {
    'data-provider': 'turnstile', 'data-sitekey': 'site-t', 'data-script': 'https://t.invalid/api.js',
    'data-fallbacks': JSON.stringify([
      { provider: 'recaptcha', sitekey: 'site-r', script: 'https://r.invalid/api.js' },
      { provider: 'hcaptcha', sitekey: 'site-h', script: 'https://h.invalid/api.js' }
    ])
  };
  return { innerHTML: '', getAttribute: (n) => (n in attrs ? attrs[n] : null), setAttribute: (n, v) => { attrs[n] = String(v); } };
}
function run(steps) {
  now = 0; timers.clear(); appended.length = 0;
  const box = makeBox();
  const window = {
    setTimeout: (fn, ms) => { const id = nextId++; timers.set(id, { at: now + ms, fn }); return id; },
    clearTimeout: (id) => { timers.delete(id); },
    matchMedia: () => ({ matches: false })
  };
  const document = {
    readyState: 'complete',
    documentElement: { getAttribute: () => 'light' },
    head: { appendChild: (s) => { appended.push(s); } },
    createElement: () => ({}),
    querySelectorAll: () => [box],
    addEventListener: () => {}
  };
  new Function('window', 'document', source)(window, document);
  const advance = (ms) => { const until = now + ms; for (;;) { let due = null; for (const [id, t] of timers) { if (t.at <= until && (due === null || t.at < due[1].at)) { due = [id, t]; } } if (due === null) { break; } timers.delete(due[0]); now = due[1].at; due[1].fn(); } now = until; };
  const loadProvider = (globalName) => { window[globalName] = { render: () => 'w', getResponse: () => '', reset: () => {} }; };
  steps({ window, box, advance, loadProvider, appended });
  return { provider: box.getAttribute('data-provider'), scripts: appended.map((s) => s.src), pendingTimers: timers.size };
}
const out = {};
// 1 + 2: the first times out; it then answers late; the second never answers.
out.late = run(({ window, advance, loadProvider }) => {
  advance(8000);                     // Turnstile timed out -> reCAPTCHA loading
  loadProvider('turnstile');         // ... Turnstile's script arrives late
  window.cuercodeHumanCheckLoaded(); // ... and calls the shared callback
  advance(8000);                     // reCAPTCHA never answers -> hCaptcha must be tried
});
// 3: a late "could not load" from the first script, while the second is loading.
out.lateError = run(({ advance, appended }) => {
  advance(8000);                     // -> reCAPTCHA loading
  appended[0].onerror();             // Turnstile's script reports a failure late
});
// 4: the current provider answers in time: drawn, nothing more loaded.
out.inTime = run(({ window, advance, loadProvider }) => {
  loadProvider('turnstile');
  window.cuercodeHumanCheckLoaded();
  advance(20000);
});
console.log(JSON.stringify(out));
JS;

$wjHarnessFile = tempnam(sys_get_temp_dir(), 'cuercode_wj_') . '.js';
file_put_contents($wjHarnessFile, $wjHarness);
$wjPipes = [];
$wjProcess = proc_open([$wjNode, $wjHarnessFile, $wjScript], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $wjPipes);
fclose($wjPipes[0]);
$wjOut = (string) stream_get_contents($wjPipes[1]);
$wjErr = (string) stream_get_contents($wjPipes[2]);
fclose($wjPipes[1]);
fclose($wjPipes[2]);
$wjExit = proc_close($wjProcess);
@unlink($wjHarnessFile);

$wjResult = json_decode(trim($wjOut), true);
assertTrue($wjExit === 0 && is_array($wjResult), 'the widget script ran under Node.js (' . $wjExit . ' ' . trim($wjErr) . ')');
if (is_array($wjResult)) {
    assertEquals('hcaptcha', $wjResult['late']['provider'] ?? null, 'Codex 2: Turnstile answered LATE, reCAPTCHA never answered: the third provider (hCaptcha) is still tried (' . json_encode($wjResult['late'] ?? null) . ')');
    assertEquals(['https://t.invalid/api.js', 'https://r.invalid/api.js', 'https://h.invalid/api.js'], $wjResult['late']['scripts'] ?? null, 'Codex 2: ... each provider\'s script loaded once, in order');
    assertEquals('recaptcha', $wjResult['lateError']['provider'] ?? null, 'Codex 2: a late "could not load" from Turnstile\'s script does not skip reCAPTCHA (' . json_encode($wjResult['lateError'] ?? null) . ')');
    assertEquals(1, $wjResult['lateError']['pendingTimers'] ?? null, 'Codex 2: ... and reCAPTCHA\'s own timer is still running');
    assertEquals(['turnstile', ['https://t.invalid/api.js'], 0], [$wjResult['inTime']['provider'] ?? null, $wjResult['inTime']['scripts'] ?? null, $wjResult['inTime']['pendingTimers'] ?? null], 'the current provider answering in time stops its timer: drawn, nothing more loaded');
}
