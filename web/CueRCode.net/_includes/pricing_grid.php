<?php
/**
 * ==============================================================================
 * 🃏 CueRCode - Pricing plan cards (shared by /pricing and the admin preview)
 * ==============================================================================
 *
 * Path: _includes/pricing_grid.php
 *
 * The note above the prices and one card per plan. Both the public pricing page
 * (public_html/pricing/index.php) and the Global Admin preview
 * (public_html/admin/pricing-preview/index.php) include this file, so the
 * preview cannot drift from the real page.
 *
 * The including page sets, BEFORE including it (after loading fn_pricing.php):
 *   $pricingDisplayPlans    rows from pricingDisplayRowsFromPlans() (not empty)
 *   $pricingShowPrices      bool - the pricing_show_prices setting
 *   $pricingVatNote         string from pricingVatNote() ('' = no wording)
 *   $pricingSiteEmail       the site_email setting (for "Contact us")
 *   $pricingPreviewCurrency '' on the public page; a currency code on the preview
 *   $pricingPromiseDefaults ['mode' => ..., 'months' => ...] - the site-wide price
 *                           promise settings (decision 0020; optional: when it is
 *                           not set, only a plan's own promise is shown)
 *
 * Everything an admin typed (names, descriptions, contact addresses) is
 * escaped here: it is shown as plain text only.
 *
 * Cards are laid out with Bootstrap's flexbox grid, not a table. Whether
 * something is included is never shown by colour alone (icon or badge text,
 * plus a screen-reader prefix).
 *
 * @package     CueRCode
 * @subpackage  Pages / Pricing
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-26 (task 7n Phase 2, slice 2d - #31)
 * @see         _functions/fn_pricing.php - pricingPriceBlock(), pricingContactLink()
 */

// ==============================================================================
// 🚫 Direct access protection
// ==============================================================================
if (isset($_SERVER['SCRIPT_FILENAME'])) {
    $scriptFilename = $_SERVER['SCRIPT_FILENAME'];
} else {
    $scriptFilename = '';
}
if (basename($scriptFilename) === basename(__FILE__)) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied.');
}

// 🤝 Decision 0020 (#159): the site-wide price promise, for plans that set none.
$pricingPromiseMode = null;
$pricingPromiseMonths = null;
if (isset($pricingPromiseDefaults) && is_array($pricingPromiseDefaults)) {
    if (isset($pricingPromiseDefaults['mode'])) {
        $pricingPromiseMode = $pricingPromiseDefaults['mode'];
    }
    if (isset($pricingPromiseDefaults['months'])) {
        $pricingPromiseMonths = $pricingPromiseDefaults['months'];
    }
}

// 🧱 Four cards sit in one row on wide screens; otherwise up to three.
$pricingRowClass = 'row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4 justify-content-center';
if (count($pricingDisplayPlans) === 4) {
    $pricingRowClass .= ' row-cols-xl-4';
}
?>
            <?php /* ============================================================
                 💷 Note above the grid - only while price figures are shown
                 ============================================================ */ ?>
            <?php if ($pricingShowPrices) { ?>
            <div class="row justify-content-center">
                <div class="col-lg-8 text-center mb-4">
                    <?php /* 🤝 Decision 0020 (#159): no site-wide promise here any more -
                         each card below says what its own plan promises. */ ?>
                    <p class="text-body-secondary small mb-0">
                        <i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i>
                        Prices shown are introductory pricing and may change. Each plan says
                        how long you keep the price you sign up at.
                    </p>
                    <?php if ($pricingVatNote !== '') { ?>
                    <p class="text-body-secondary small mt-2 mb-0" id="pricing-vat-note">
                        <?php echo htmlspecialchars($pricingVatNote, ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <?php } ?>
                </div>
            </div>
            <?php } ?>

            <?php /* ============================================================
                 🃏 Plan comparison card grid
                 ============================================================ */ ?>
            <div class="<?php echo htmlspecialchars($pricingRowClass, ENT_QUOTES, 'UTF-8'); ?>">
                <?php foreach ($pricingDisplayPlans as $pricingPlan) { ?>
                <?php $pricingBlock = pricingPriceBlock($pricingPlan, $pricingShowPrices, $pricingPreviewCurrency); ?>
                <div class="col">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body d-flex flex-column">
                            <h2 class="h4 card-title mb-1 text-break">
                                <?php echo htmlspecialchars($pricingPlan['planName'], ENT_QUOTES, 'UTF-8'); ?>
                            </h2>

                            <?php if ($pricingPlan['description'] !== '') { ?>
                            <p class="text-body-secondary small mb-3 text-break">
                                <?php echo htmlspecialchars($pricingPlan['description'], ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                            <?php } ?>

                            <?php if ($pricingBlock['isFigure']) { ?>
                            <?php
                                // 📏 A long figure (£10,000.00 and up) is set
                                // smaller, so a narrow card never splits the
                                // number across two lines.
                                $pricingFigureClass = 'display-6 fw-bold mb-0';
                                if (mb_strlen($pricingBlock['headline'], 'UTF-8') > 8) {
                                    $pricingFigureClass = 'fs-2 fw-bold mb-0';
                                }
                            ?>
                            <p class="<?php echo htmlspecialchars($pricingFigureClass, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php if ($pricingBlock['prefix'] !== '') { ?>
                                <span class="fs-6 fw-normal text-body-secondary"><?php echo htmlspecialchars($pricingBlock['prefix'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php } ?>
                                <?php echo htmlspecialchars($pricingBlock['headline'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php if ($pricingBlock['suffix'] !== '') { ?>
                                <span class="fs-6 fw-normal text-body-secondary"><?php echo htmlspecialchars($pricingBlock['suffix'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php } ?>
                            </p>
                            <?php } else { ?>
                            <p class="fs-4 fw-bold mb-0"><?php echo htmlspecialchars($pricingBlock['headline'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php } ?>
                            <?php
                                // 🤝 Decision 0020 (#159): this plan's own price
                                // promise, only beside a real price figure (never
                                // on a free card, nor when prices are hidden).
                                $pricingPromise = '';
                                if ($pricingShowPrices && $pricingBlock['isFigure'] && !$pricingPlan['isZeroPrice']) {
                                    $pricingPromise = pricingPromiseText($pricingPlan['pricePromiseMode'], $pricingPlan['pricePromiseMonths'], $pricingPromiseMode, $pricingPromiseMonths);
                                }
                                $pricingSublineClass = 'text-body-secondary small mb-3';
                                if ($pricingPromise !== '') {
                                    $pricingSublineClass = 'text-body-secondary small mb-1';
                                }
                            ?>
                            <?php if ($pricingBlock['subline'] !== '') { ?>
                            <p class="<?php echo htmlspecialchars($pricingSublineClass, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($pricingBlock['subline'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php } elseif ($pricingPromise === '') { ?>
                            <div class="mb-3"></div>
                            <?php } ?>
                            <?php if ($pricingPromise !== '') { ?>
                            <p class="text-body-secondary small mb-3">
                                <i class="fa-solid fa-handshake me-1" aria-hidden="true"></i>
                                <?php echo htmlspecialchars($pricingPromise, ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                            <?php } ?>

                            <?php /* ================================================
                                 🚩 Feature rows - included/excluded/coming-soon
                                 is NEVER conveyed by colour alone: a
                                 differently-shaped icon (or the "Coming soon"
                                 badge's own visible TEXT, #125) PLUS a
                                 screen-reader-only prefix both carry the
                                 meaning independently of colour.
                                 ================================================ */ ?>
                            <?php if (!empty($pricingPlan['features'])) { ?>
                            <ul class="list-unstyled mb-3">
                                <?php foreach ($pricingPlan['features'] as $pricingFeature) { ?>
                                <?php
                                    // 🚦 #125: a feature the plan has switched on
                                    // but that is not yet BUILT must never read as
                                    // "Included".
                                    $pricingFeatureIsComingSoon = ($pricingFeature['status'] === 'coming_soon')
                                        && $pricingFeature['enabled'];
                                    $pricingFeatureClass = 'mb-2 d-flex align-items-baseline';
                                    if (!$pricingFeature['enabled'] || $pricingFeatureIsComingSoon) {
                                        $pricingFeatureClass .= ' text-body-secondary';
                                    }
                                ?>
                                <li class="<?php echo htmlspecialchars($pricingFeatureClass, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php if ($pricingFeatureIsComingSoon) { ?>
                                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle me-2 flex-shrink-0">
                                        <i class="fa-solid fa-clock me-1" aria-hidden="true"></i>
                                        Coming soon
                                    </span>
                                    <span class="visually-hidden">- not yet available: </span>
                                    <?php } elseif ($pricingFeature['enabled']) { ?>
                                    <i class="fa-solid fa-check text-success me-2" aria-hidden="true"></i>
                                    <span class="visually-hidden">Included: </span>
                                    <?php } else { ?>
                                    <i class="fa-solid fa-xmark me-2" aria-hidden="true"></i>
                                    <span class="visually-hidden">Not included: </span>
                                    <?php } ?>
                                    <?php /* ↔️ Label in its own flex item so a long name wraps in its own
                                             column instead of under the badge or icon (#137). */ ?>
                                    <span><?php echo htmlspecialchars($pricingFeature['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </li>
                                <?php } ?>
                            </ul>
                            <?php } ?>

                            <?php /* ================================================
                                 🔢 Limit rows - a limit whose feature is not yet
                                 built (#125) shows the "Coming soon" badge
                                 instead of a number nobody can use yet.
                                 ================================================ */ ?>
                            <?php if (!empty($pricingPlan['limits'])) { ?>
                            <ul class="list-unstyled mb-3 small text-body-secondary">
                                <?php foreach ($pricingPlan['limits'] as $pricingLimit) { ?>
                                <li class="mb-1">
                                    <i class="fa-solid fa-gauge-high me-2" aria-hidden="true"></i>
                                    <?php echo htmlspecialchars($pricingLimit['label'], ENT_QUOTES, 'UTF-8'); ?>:
                                    <?php if ($pricingLimit['status'] === 'coming_soon') { ?>
                                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">
                                        <i class="fa-solid fa-clock me-1" aria-hidden="true"></i>
                                        Coming soon
                                    </span>
                                    <?php } else { ?>
                                    <strong><?php echo htmlspecialchars($pricingLimit['value'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <?php } ?>
                                </li>
                                <?php } ?>
                            </ul>
                            <?php } ?>

                            <div class="mt-auto">
                                <?php if ($pricingPlan['ctaMode'] === 'contact') { ?>
                                <?php /* ✉️ Agreed one customer at a time: a real link. */ ?>
                                <a href="<?php echo htmlspecialchars(pricingContactLink($pricingPlan['contactUrl'], $pricingSiteEmail), ENT_QUOTES, 'UTF-8'); ?>"
                                   class="btn btn-outline-primary w-100">
                                    <i class="fa-solid fa-envelope me-1" aria-hidden="true"></i>
                                    Contact us<span class="visually-hidden"> about the <?php echo htmlspecialchars($pricingPlan['planName'], ENT_QUOTES, 'UTF-8'); ?> plan</span>
                                </a>
                                <?php } elseif ($pricingPlan['isZeroPrice']) { ?>
                                <?php /* 🆓 Zero-price plan: a real, working link to
                                     account registration. */ ?>
                                <a href="/register/" class="btn btn-primary w-100">
                                    <i class="fa-solid fa-user-plus me-1" aria-hidden="true"></i>
                                    Create free account
                                </a>
                                <p class="small text-body-secondary mt-2 mb-0">
                                    No account needed to generate a QR code right now -
                                    <a href="/">use the generator</a>. A free account adds
                                    saved codes and an API key.
                                </p>
                                <?php } else { ?>
                                <?php /* 💳 Paid plan: checkout is owner-gated and not
                                     yet built (Epic E4 payments stage) - a
                                     disabled control, never an invented flow. */ ?>
                                <button type="button" class="btn btn-outline-secondary w-100" disabled aria-disabled="true">
                                    Coming soon
                                </button>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php } ?>
            </div>
