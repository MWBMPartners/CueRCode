<?php
/**
 * ==============================================================================
 * 📣 CueRCode - Report a QR code (public, WP4f #169)
 * ==============================================================================
 *
 * Path: public_html/report/index.php
 *
 * WHAT IT IS (plain English):
 *   Anyone who scanned a dynamic QR code (one printed with a QRmy.Link address)
 *   that led somewhere harmful can tell us here. Linked from the page a
 *   suspended code shows, the site footer and the terms.
 *
 *   - The answer is ALWAYS the same thank-you, after at least the same time,
 *     whether or not the code exists, wherever it leads and whatever happened
 *     to it: this page never reveals a code's existence or its destination.
 *   - Each visitor address may send only a few reports an hour (a Global
 *     Admin setting, 5 by default; an IPv6 address counts as its /64),
 *     counted BEFORE the human check is asked.
 *   - The human check (#163, decision 0024) protects the form. A report that
 *     passed it counts towards the automatic suspension (3 different people in
 *     24 hours, decision 0034). WITHOUT JavaScript the check cannot run, so
 *     the form offers "Send my report without JavaScript": the report is
 *     kept, emailed to the abuse mailbox and read by a person, but it is NOT
 *     counted automatically - a script could otherwise skip the check and
 *     suspend anyone's code. The same applies when the provider cannot be
 *     reached. While the check is switched off site-wide, reports count.
 *   - The reporter's email is optional, stored encrypted, and never emailed
 *     to anyone but the abuse mailbox (nothing is sent TO the reporter, so the
 *     form cannot be used to email somebody else).
 *
 * @package     CueRCode
 * @subpackage  Pages
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (WP4f, #169)
 * @see         _functions/fn_abuse_reports.php - abuseReportCheckInput(), abuseReportSubmit()
 * @see         _functions/fn_human_check.php - humanCheckVerifyRequest(), humanCheckWidgetHtml()
 * @see         docs/decisions/0034, 0038, 0046
 */

// ==============================================================================
// 🚀 Load Application Bootstrap
// ==============================================================================
require_once __DIR__ . '/../../_includes/bootstrap.php';
require_once CUERCODE_FUNCTIONS_DIR . 'fn_dynamic_qr_takedown.php';
require_once CUERCODE_FUNCTIONS_DIR . 'fn_abuse_reports.php';

// ==============================================================================
// 📤 The form, sent the normal way (no JavaScript is needed)
// ==============================================================================
$reportStarted  = hrtime(true);
$reportSent     = false;
$reportErrors   = [];
$reportProblem  = null;
$reportPosted   = ['code' => '', 'category' => '', 'details' => '', 'email' => ''];

// 🔤 A code from the suspended-code page's link (?code=...), shape only.
if (isset($_GET['code']) && is_string($_GET['code'])) {
    $reportPosted['code'] = abuseReportCodeFromText($_GET['code']);
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_submit'])) {
    foreach (['code', 'category', 'details', 'email'] as $reportField) {
        if (isset($_POST[$reportField]) && is_string($_POST[$reportField])) {
            $reportPosted[$reportField] = $_POST[$reportField];
        }
    }

    $reportCsrf = '';
    if (isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])) {
        $reportCsrf = $_POST['csrf_token'];
    }

    $reportInput = abuseReportCheckInput($_POST);

    if (!function_exists('validateCSRFToken') || !validateCSRFToken($reportCsrf)) {
        $reportProblem = 'Your session has expired. Please reload the page and try again.';
    } elseif (!$reportInput['ok']) {
        // ✅ Shapes only - nothing about any code has been looked up.
        $reportErrors  = $reportInput['errors'];
        $reportProblem = 'Please check the highlighted answers.';
    } elseif (!abuseReportRateAllowed(getClientIP(), abuseReportSettings()['ipPerHour'])) {
        $reportProblem = 'Too many reports from your connection in the last hour. Please try again later, or email us instead (the address is below).';
    } else {
        // 🤖 The human check decides whether the report COUNTS automatically.
        $reportCounted  = false;
        $reportAccepted = false;
        if (humanCheckEmailRouteChosen($_POST) && humanCheckIsOn()) {
            // 🚶 No JavaScript: kept and read by a person, never counted.
            $reportAccepted = true;
        } else {
            $reportCheck = humanCheckVerifyRequest($_POST, ['purpose' => 'report']);
            if ($reportCheck['ok'] === true) {
                $reportAccepted = true;
                $reportCounted  = true;
            } elseif (in_array($reportCheck['reason'], ['unavailable', 'misconfigured', 'store_unavailable'], true)) {
                // 🩺 The check itself is not working: kept, never counted.
                $reportAccepted = true;
            } else {
                $reportProblem = $reportCheck['message'];
            }
        }

        if ($reportAccepted) {
            abuseReportSubmit($reportInput, ['ip' => getClientIP(), 'counted' => $reportCounted]);
            // 🤝 The same answer whatever happened (the report was stored or
            //    not, the code exists or not, it was suspended or not).
            $reportSent = true;
        }
    }

    // ⏱️ Every answer takes at least the same time.
    padResponseToMinimumDuration($reportStarted, CUERCODE_ABUSE_REPORT_MIN_MICROSECONDS);
}

$reportPromise    = dynamicQrTakedownPromise();
$reportMailbox    = dynamicQrAbuseMailbox();
$reportCategories = abuseReportCategories();

// ==============================================================================
// ⚙️ Page Variables
// ==============================================================================
$pageTitle       = 'Report a QR code';
$pageDescription = 'Tell CueRCode about a QR code that leads to a harmful page, such as phishing, malware or a scam.';
$bodyClass       = 'page-account page-report';
$pageCSS         = [];
$pageJS          = ['/assets/js/cuercode-human-check.js'];

/**
 * ✏️ The "is-invalid" class and description link for one field. PURE.
 *
 * @param array<string, string> $errors
 * @param string                $field
 * @return array{class: string, aria: string}
 */
function reportFieldState(array $errors, string $field): array
{
    if (isset($errors[$field])) {
        return ['class' => ' is-invalid', 'aria' => ' aria-invalid="true" aria-describedby="report-' . $field . '-error"'];
    }

    return ['class' => '', 'aria' => ''];
}

// ==============================================================================
// 📄 Render Page
// ==============================================================================
require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <section class="py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-9 col-lg-7">
                    <div class="card shadow-sm">
                        <div class="card-body p-4">
                            <h1 class="h3 fw-bold mb-3">Report a QR code</h1>

                            <?php if ($reportSent) { ?>
                            <div class="alert alert-success" role="status">
                                <i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i>
                                Thank you. Your report has been received, and a person will look at it.
                                <?php echo htmlspecialchars($reportPromise, ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <p class="mb-0">
                                You do not need to do anything else. If you gave your email address, we may write to you
                                if we need more details. <a href="/">Go to the CueRCode home page</a>.
                            </p>
                            <?php } else { ?>

                            <p class="mb-3">
                                Did a QR code printed with a <strong>QRmy.Link</strong> address take you to a page that tries to
                                trick people, asks for passwords or bank details, installs harmful software, or shows illegal content?
                                Tell us here. <?php echo htmlspecialchars($reportPromise, ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                            <p class="text-body-secondary small mb-4">
                                This form never shows whether a code exists or where it leads. A code that several different people
                                report is paused straight away until we have looked at it.
                            </p>

                            <?php if ($reportProblem !== null) { ?>
                            <div class="alert alert-danger" role="alert" id="report-errors">
                                <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                                <?php echo htmlspecialchars($reportProblem, ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <?php } ?>

                            <form method="POST" action="/report/" id="report-form" novalidate>
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="report_submit" value="1">

                                <?php $reportState = reportFieldState($reportErrors, 'code'); ?>
                                <div class="mb-3">
                                    <label for="report-code" class="form-label">The address in the QR code</label>
                                    <input type="text" class="form-control<?php echo $reportState['class']; ?>" id="report-code" name="code"
                                           value="<?php echo htmlspecialchars($reportPosted['code'], ENT_QUOTES, 'UTF-8'); ?>"
                                           maxlength="300" autocomplete="off" spellcheck="false" required<?php echo $reportState['aria']; ?>>
                                    <div class="form-text">For example QRmy.Link/Ab3dE9x, or just the letters after the slash.</div>
                                    <?php if (isset($reportErrors['code'])) { ?><div class="invalid-feedback" id="report-code-error"><?php echo htmlspecialchars($reportErrors['code'], ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
                                </div>

                                <fieldset class="mb-3"<?php if (isset($reportErrors['category'])) { ?> aria-describedby="report-category-error"<?php } ?>>
                                    <legend class="form-label fs-6">What is wrong with the page it leads to?</legend>
                                    <?php foreach ($reportCategories as $reportKey => $reportLabel) { ?>
                                    <div class="form-check">
                                        <input class="form-check-input<?php if (isset($reportErrors['category'])) { ?> is-invalid<?php } ?>" type="radio" name="category"
                                               id="report-category-<?php echo htmlspecialchars($reportKey, ENT_QUOTES, 'UTF-8'); ?>"
                                               value="<?php echo htmlspecialchars($reportKey, ENT_QUOTES, 'UTF-8'); ?>"<?php if ($reportPosted['category'] === $reportKey) { ?> checked<?php } ?> required>
                                        <label class="form-check-label" for="report-category-<?php echo htmlspecialchars($reportKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($reportLabel, ENT_QUOTES, 'UTF-8'); ?></label>
                                    </div>
                                    <?php } ?>
                                    <?php if (isset($reportErrors['category'])) { ?><div class="text-danger small mt-1" id="report-category-error"><?php echo htmlspecialchars($reportErrors['category'], ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
                                </fieldset>

                                <?php $reportState = reportFieldState($reportErrors, 'details'); ?>
                                <div class="mb-3">
                                    <label for="report-details" class="form-label">What happened? (optional, unless you chose "Something else")</label>
                                    <textarea class="form-control<?php echo $reportState['class']; ?>" id="report-details" name="details" rows="4"
                                              maxlength="<?php echo (int) CUERCODE_ABUSE_DETAILS_MAX; ?>"<?php echo $reportState['aria']; ?>><?php echo htmlspecialchars($reportPosted['details'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                                    <div class="form-text">Please do not include passwords or card numbers. Up to <?php echo number_format(CUERCODE_ABUSE_DETAILS_MAX); ?> characters.</div>
                                    <?php if (isset($reportErrors['details'])) { ?><div class="invalid-feedback" id="report-details-error"><?php echo htmlspecialchars($reportErrors['details'], ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
                                </div>

                                <?php $reportState = reportFieldState($reportErrors, 'email'); ?>
                                <div class="mb-4">
                                    <label for="report-email" class="form-label">Your email address (optional)</label>
                                    <input type="email" class="form-control<?php echo $reportState['class']; ?>" id="report-email" name="email"
                                           value="<?php echo htmlspecialchars($reportPosted['email'], ENT_QUOTES, 'UTF-8'); ?>"
                                           maxlength="254" autocomplete="email"<?php echo $reportState['aria']; ?>>
                                    <div class="form-text">Only if you are happy for us to write to you about this report. It is stored encrypted and only our team sees it.</div>
                                    <?php if (isset($reportErrors['email'])) { ?><div class="invalid-feedback" id="report-email-error"><?php echo htmlspecialchars($reportErrors['email'], ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
                                </div>

                                <?php /* 🤖 Human check (#163) - empty while it is off; its
                                     <noscript> part offers sending without it (not counted) */ ?>
                                <?php echo humanCheckWidgetHtml(null, 'report'); ?>

                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="fa-solid fa-flag me-1" aria-hidden="true"></i>
                                        Send the report
                                    </button>
                                </div>
                            </form>

                            <?php if ($reportMailbox !== '') { ?>
                            <p class="small text-body-secondary mt-4 mb-0">
                                You can also email <a href="mailto:<?php echo htmlspecialchars($reportMailbox, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($reportMailbox, ENT_QUOTES, 'UTF-8'); ?></a>.
                                How we handle reports is described in our <a href="/terms#reporting">terms</a> and <a href="/privacy#abuse-reports">privacy policy</a>.
                            </p>
                            <?php } ?>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

<?php
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
