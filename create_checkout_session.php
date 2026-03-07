<?php
declare(strict_types=1);

require_once __DIR__ . '/waitlist-config.php';
require_once __DIR__ . '/stripe-config.php';
require_once __DIR__ . '/stripe-helpers.php';
require_once __DIR__ . '/smtp-mailer.php';
require_once __DIR__ . '/excel-exports.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

const REGISTRATION_BASE_PRICE = 285.00;
const REGISTRATION_PROMO_COUPON_CODE = 'UWCULTRATEAM';
const REGISTRATION_PROMO_COUPON_DISCOUNT = 142.50; // 50% off one athlete only
const REGISTRATION_NOFEE_COUPON_CODE = 'NOFEE'; // 100% off
const CLASS_CAPACITY_MAX = 24;
const CLASS_COUNT_ADJUSTMENTS = [
    'Rising Competitors - Advanced (Ages 12-16)' => 7,
    'Elite Wrestlers (Ages 14+)' => -7,
];
const CLASS_MANUALLY_CLOSED_NAMES = [
    'Rising Competitors - Foundation (Ages 11-13)',
];
const STRIPE_REGISTRATION_SUBMISSIONS_CSV = __DIR__ . '/data/stripe_registration_submissions.csv';
const STRIPE_REGISTRATION_ERROR_LOG = __DIR__ . '/data/stripe_registration_errors.log';
const STRIPE_PAYMENT_SUCCESS_LOG = __DIR__ . '/data/stripe_payment_success.csv';
const STRIPE_CLASS_WAITLIST_CSV = __DIR__ . '/data/stripe_class_waitlist.csv';
const STRIPE_CLASS_WAITLIST_ERROR_LOG = __DIR__ . '/data/stripe_class_waitlist_errors.log';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect_back_with_status('error', 'Invalid request method.');
}

if (!empty($_POST['website'] ?? '')) {
    redirect_back_with_status('error', 'Invalid submission.');
}

$guardianName = clean_text((string) ($_POST['guardian_name'] ?? ''));
$email = clean_email((string) ($_POST['email'] ?? ''));
$phone = clean_phone((string) ($_POST['phone'] ?? ''));
$preferredContactMethod = clean_text((string) ($_POST['preferred_contact_method'] ?? ''));
$notes = clean_text((string) ($_POST['notes'] ?? ''));
$submissionSource = clean_text((string) ($_POST['submission_source'] ?? 'stripe-checkout-registration'));
$confirmUsaMembership = clean_text((string) ($_POST['confirm_usaw_membership'] ?? ''));
$confirmCheckoutReady = clean_text((string) ($_POST['confirm_enrollment_closed'] ?? ''));
$confirmInfoAccurate = clean_text((string) ($_POST['confirm_info_accurate'] ?? ''));

if ($guardianName === '' || $email === '') {
    redirect_back_with_status('error', 'Please complete the parent / guardian name and email.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirect_back_with_status('error', 'Please enter a valid email address.');
}

if ($confirmUsaMembership !== 'yes' || $confirmCheckoutReady !== 'yes' || $confirmInfoAccurate !== 'yes') {
    redirect_back_with_status('error', 'Please confirm the required registration notices before continuing to checkout.');
}

$allowedContactMethods = ['', 'email', 'phone', 'text'];
if (!in_array($preferredContactMethod, $allowedContactMethods, true)) {
    redirect_back_with_status('error', 'Invalid contact preference.');
}

$ageLabelMap = [
    '5-10' => 'Ages 5-10',
    '11-13' => 'Ages 11-13',
    '14-plus' => 'Ages 14+',
];

$allowedClasses = [
    'future-champions' => 'Future Champions (Ages 5-10)',
    'foundation' => 'Rising Competitors - Foundation (Ages 11-13)',
    'development' => 'Rising Competitors - Development (Ages 11-13)',
    'advanced-11-13' => 'Rising Competitors - Advanced (Ages 12-16)',
    'elite-competition-team' => 'Elite Wrestlers (Ages 14+)',
];

$allowedClassesByAge = [
    '5-10' => ['future-champions', 'foundation', 'development', 'advanced-11-13', 'elite-competition-team'],
    '11-13' => ['future-champions', 'foundation', 'development', 'advanced-11-13', 'elite-competition-team'],
    '14-plus' => ['future-champions', 'foundation', 'development', 'advanced-11-13', 'elite-competition-team'],
];

$experienceLabelMap = [
    '' => '',
    'new' => 'New to wrestling',
    'developing' => 'Some experience',
    'experienced' => 'Experienced',
    'competition' => 'Competition-focused',
];

$athleteNamesRaw = $_POST['athlete_name'] ?? null;
$ageGroupsRaw = $_POST['athlete_age_group'] ?? null;
$classInterestsRaw = $_POST['class_interest'] ?? null;
$experienceRaw = $_POST['athlete_experience'] ?? [];

if (!is_array($athleteNamesRaw) || !is_array($ageGroupsRaw) || !is_array($classInterestsRaw)) {
    redirect_back_with_status('error', 'Invalid athlete registration data.');
}
if (!is_array($experienceRaw)) {
    $experienceRaw = [];
}

$athleteCount = count($athleteNamesRaw);
if ($athleteCount < 1 || $athleteCount > 10) {
    redirect_back_with_status('error', 'Please include at least one athlete (maximum 10).');
}
if (count($ageGroupsRaw) !== $athleteCount || count($classInterestsRaw) !== $athleteCount) {
    redirect_back_with_status('error', 'Athlete form data is incomplete. Please try again.');
}

$athletes = [];
$pricingLines = [];
$baseSubtotal = 0.0;
$discountTotal = 0.0;
$estimatedTotal = 0.0;

for ($i = 0; $i < $athleteCount; $i++) {
    $athleteName = clean_text((string) ($athleteNamesRaw[$i] ?? ''));
    $ageGroup = clean_text((string) ($ageGroupsRaw[$i] ?? ''));
    $classInterest = clean_text((string) ($classInterestsRaw[$i] ?? ''));
    $experienceValue = clean_text((string) ($experienceRaw[$i] ?? ''));

    if ($athleteName === '' || $ageGroup === '' || $classInterest === '') {
        redirect_back_with_status('error', 'Please complete all athlete fields (name, age group, and class type).');
    }
    if (!array_key_exists($ageGroup, $ageLabelMap)) {
        redirect_back_with_status('error', 'Invalid athlete age group selection.');
    }
    if (!array_key_exists($classInterest, $allowedClasses)) {
        redirect_back_with_status('error', 'Invalid athlete class selection.');
    }
    if (!in_array($classInterest, $allowedClassesByAge[$ageGroup], true)) {
        redirect_back_with_status('error', 'One athlete class selection does not match the selected age group.');
    }
    if (!array_key_exists($experienceValue, $experienceLabelMap)) {
        redirect_back_with_status('error', 'Invalid athlete experience selection.');
    }

    $pricing = calculate_line_pricing($i + 1);
    $baseSubtotal += $pricing['base_price'];
    $discountTotal += $pricing['discount_amount'];
    $estimatedTotal += $pricing['line_total'];

    $athletes[] = [
        'athlete_index' => $i + 1,
        'athlete_name' => $athleteName,
        'athlete_age_group' => $ageLabelMap[$ageGroup],
        'class_interest' => $allowedClasses[$classInterest],
        'experience_label' => $experienceLabelMap[$experienceValue],
    ];
    $pricingLines[] = array_merge($pricing, [
        'athlete_index' => $i + 1,
        'athlete_name' => $athleteName,
        'athlete_age_group' => $ageLabelMap[$ageGroup],
        'class_interest' => $allowedClasses[$classInterest],
    ]);
}

$baseSubtotal = normalize_money($baseSubtotal);
$discountTotal = normalize_money($discountTotal);
$estimatedTotal = normalize_money($estimatedTotal);

// Coupon handling: server-side authoritative check.
$couponCode = clean_text((string) ($_POST['coupon_code'] ?? ''));
$couponApplied = false;
$couponDiscountAmount = 0.0;
if ($couponCode !== '') {
    if (strcasecmp($couponCode, REGISTRATION_PROMO_COUPON_CODE) === 0) {
        $couponApplied = true;
        $couponDiscountAmount = normalize_money(min(REGISTRATION_PROMO_COUPON_DISCOUNT, $estimatedTotal));
    } elseif (strcasecmp($couponCode, REGISTRATION_NOFEE_COUPON_CODE) === 0) {
        $couponApplied = true;
        $couponDiscountAmount = normalize_money(max(0.0, $estimatedTotal));
    }
}
if ($couponApplied && $couponDiscountAmount > 0) {
    $discountTotal = normalize_money($discountTotal + $couponDiscountAmount);
    $estimatedTotal = normalize_money($estimatedTotal - $couponDiscountAmount);
}

// Capacity guard: if any selected class is full, skip checkout and place this submission on waitlist.
$requestedClassCounts = build_requested_class_counts($athletes);
$paidClassCounts = get_paid_class_counts_for_classes(array_values($allowedClasses));
$paidClassCounts = apply_class_count_adjustments($paidClassCounts, CLASS_COUNT_ADJUSTMENTS, array_values($allowedClasses));
$fullClasses = find_full_classes(
    $requestedClassCounts,
    $paidClassCounts,
    CLASS_CAPACITY_MAX,
    CLASS_MANUALLY_CLOSED_NAMES
);
if (!empty($fullClasses)) {
    $waitlistId = 'uwc_wl_' . gmdate('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
    $waitlistRecord = [
        'submitted_at_utc' => gmdate('c'),
        'waitlist_id' => $waitlistId,
        'submission_source' => $submissionSource !== '' ? $submissionSource : 'stripe-checkout-registration',
        'status' => 'waitlisted_class_full',
        'guardian_name' => $guardianName,
        'email' => $email,
        'phone' => $phone,
        'preferred_contact_method' => $preferredContactMethod,
        'total_athletes' => (string) count($athletes),
        'estimated_subtotal' => number_format($baseSubtotal, 2, '.', ''),
        'estimated_discount_total' => number_format($discountTotal, 2, '.', ''),
        'estimated_total' => number_format($estimatedTotal, 2, '.', ''),
        'currency' => stripe_currency_code(),
        'full_classes_summary' => implode(' | ', array_map(
            static fn ($item) => $item['class_name'] . ' (' . $item['remaining'] . ' spots remaining)',
            $fullClasses
        )),
        'full_classes_json' => json_encode($fullClasses, JSON_UNESCAPED_SLASHES),
        'requested_class_counts_json' => json_encode($requestedClassCounts, JSON_UNESCAPED_SLASHES),
        'athletes_json' => json_encode($athletes, JSON_UNESCAPED_SLASHES),
        'notes' => $notes,
        'ip_address' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
    ];

    if (!save_submission_csv(STRIPE_CLASS_WAITLIST_CSV, $waitlistRecord)) {
        log_class_waitlist_error('Failed to save class waitlist submission ' . $waitlistId);
    }
    send_class_waitlist_emails($waitlistRecord, $athletes, $fullClasses);

    $classNames = array_map(static fn ($item) => $item['class_name'], $fullClasses);
    $msg = 'One or more selected classes are currently full (' . implode(', ', $classNames) . '). '
        . 'Your athlete(s) have been added to the waitlist, and we will contact you as soon as a spot opens.';
    redirect_back_with_status('waitlist', $msg);
}

$submissionId = 'uwc_' . gmdate('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
$siteBaseUrl = rtrim((defined('WAITLIST_SITE_URL') ? WAITLIST_SITE_URL : 'https://united-wc.com'), '/');
$successUrl = $siteBaseUrl . '/payment-success.php?session_id={CHECKOUT_SESSION_ID}&submission_id=' . rawurlencode($submissionId);
$cancelUrl = $siteBaseUrl . '/payment-cancel.html?submission_id=' . rawurlencode($submissionId);

$isNoFeeRegistration = $estimatedTotal <= 0.0;
$automaticTaxRequested = false;
$automaticTaxEnabledInSession = false;
$session = [];
$submissionPaymentStatus = 'checkout_started';

if ($isNoFeeRegistration) {
    $session = build_no_fee_session(
        $submissionId,
        $email,
        $guardianName,
        $couponCode,
        $couponApplied,
        $couponDiscountAmount,
        $athleteCount,
        $submissionSource
    );
    $submissionPaymentStatus = 'no_payment_required';
} else {
    $checkoutParams = [
        'mode' => 'payment',
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'customer_email' => $email,
        'client_reference_id' => $submissionId,
        'billing_address_collection' => 'auto',
        'payment_method_types[0]' => 'card',
        'line_items[0][quantity]' => 1,
        'line_items[0][price_data][currency]' => stripe_currency_code(),
        'line_items[0][price_data][unit_amount]' => (string) ((int) round($estimatedTotal * 100)),
        'line_items[0][price_data][product_data][name]' => 'UWC Spring Session 2026 Registration',
        'line_items[0][price_data][product_data][description]' => 'Family registration for ' . $athleteCount . ' athlete' . ($athleteCount === 1 ? '' : 's'),
        'metadata[submission_id]' => $submissionId,
        'metadata[guardian_name]' => substr($guardianName, 0, 500),
        'metadata[guardian_email]' => substr($email, 0, 500),
        'metadata[coupon_code]' => substr($couponCode, 0, 100),
        'metadata[coupon_applied]' => $couponApplied ? 'yes' : 'no',
        'metadata[coupon_discount]' => number_format($couponDiscountAmount, 2, '.', ''),
        'metadata[athlete_count]' => (string) $athleteCount,
        'metadata[source]' => $submissionSource !== '' ? $submissionSource : 'stripe-checkout-registration',
        'payment_intent_data[metadata][submission_id]' => $submissionId,
        'payment_intent_data[metadata][guardian_email]' => substr($email, 0, 500),
    ];

    $automaticTaxRequested = stripe_automatic_tax_enabled();
    if ($automaticTaxRequested) {
        $checkoutParams['automatic_tax[enabled]'] = 'true';
    }

    try {
        $session = stripe_api_request('POST', '/v1/checkout/sessions', $checkoutParams);
        $automaticTaxEnabledInSession = $automaticTaxRequested;
    } catch (Throwable $e) {
        $message = $e->getMessage();

        if ($automaticTaxRequested && should_retry_without_automatic_tax($message)) {
            unset($checkoutParams['automatic_tax[enabled]']);
            try {
                $session = stripe_api_request('POST', '/v1/checkout/sessions', $checkoutParams);
                $automaticTaxEnabledInSession = false;
                log_stripe_registration_error('Stripe checkout created without automatic tax fallback: ' . $message);
            } catch (Throwable $retryError) {
                log_stripe_registration_error('Stripe checkout session retry failed: ' . $retryError->getMessage());
                redirect_back_with_status('error', 'Checkout could not be started. Please try again in a moment.');
            }
        } else {
            log_stripe_registration_error('Stripe checkout session create failed: ' . $message);
            redirect_back_with_status('error', 'Checkout could not be started. Please try again in a moment.');
        }
    }
}

$submission = [
    'submitted_at_utc' => gmdate('c'),
    'submission_id' => $submissionId,
    'submission_source' => $submissionSource !== '' ? $submissionSource : 'stripe-checkout-registration',
    'stripe_mode' => stripe_mode_label(),
    'checkout_session_id' => (string) ($session['id'] ?? ''),
    'checkout_session_url' => (string) ($session['url'] ?? ''),
    'checkout_session_status' => (string) ($session['status'] ?? ''),
    'automatic_tax_requested' => $automaticTaxRequested ? 'yes' : 'no',
    'automatic_tax_enabled_in_session' => $automaticTaxEnabledInSession ? 'yes' : 'no',
    'payment_status' => $submissionPaymentStatus,
    'guardian_name' => $guardianName,
    'email' => $email,
    'phone' => $phone,
    'preferred_contact_method' => $preferredContactMethod,
    'total_athletes' => (string) count($athletes),
    'estimated_subtotal' => number_format($baseSubtotal, 2, '.', ''),
    'estimated_discount_total' => number_format($discountTotal, 2, '.', ''),
    'estimated_total' => number_format($estimatedTotal, 2, '.', ''),
    'currency' => stripe_currency_code(),
    'athletes_json' => json_encode($athletes, JSON_UNESCAPED_SLASHES),
    'pricing_lines_json' => json_encode($pricingLines, JSON_UNESCAPED_SLASHES),
    'notes' => $notes,
    'ip_address' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
    'coupon_code' => $couponCode,
    'coupon_applied' => $couponApplied ? 'yes' : 'no',
    'coupon_discount' => number_format($couponDiscountAmount, 2, '.', ''),
];

if (!save_submission_csv(STRIPE_REGISTRATION_SUBMISSIONS_CSV, $submission)) {
    log_stripe_registration_error('Failed to save stripe registration submission ' . $submissionId);
}
if (!uwc_excel_export_registration($submission, $athletes, $pricingLines)) {
    log_stripe_registration_error('Failed to save Excel-friendly registration exports for ' . $submissionId);
}

$checkoutSessionId = trim((string) ($session['id'] ?? ''));
if ($isNoFeeRegistration) {
    if (!record_no_fee_payment_success($session, $submissionId)) {
        log_stripe_registration_error('Failed to record no-fee payment success for session ' . $checkoutSessionId);
    }
    if (!uwc_excel_export_payment($session, $submissionId, 'no_fee_coupon')) {
        log_stripe_registration_error('Failed to save no-fee payment export for ' . $submissionId);
    }
    send_no_fee_registration_emails($submission, $athletes);
    redirect_back_with_status('success', 'Registration submitted successfully. No payment was required for this coupon.');
}

$checkoutUrl = (string) ($session['url'] ?? '');
if ($checkoutUrl === '') {
    redirect_back_with_status('error', 'Checkout session was created without a redirect URL.');
}

header('Location: ' . $checkoutUrl, true, 303);
exit;

function redirect_back_with_status(string $status, string $message = ''): void
{
    $location = 'contact.html?status=' . rawurlencode($status);
    if ($message !== '') {
        $location .= '&msg=' . rawurlencode($message);
    }
    header('Location: ' . $location, true, 303);
    exit;
}

function clean_text(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return $value;
}

function clean_email(string $value): string
{
    return trim(strtolower($value));
}

function clean_phone(string $value): string
{
    $value = trim($value);
    return preg_replace('/[^0-9+()\-\.\s]/', '', $value) ?? $value;
}

function calculate_line_pricing(int $athletePosition): array
{
    $discountRate = 0.0;
    if ($athletePosition === 2) {
        $discountRate = 0.50;
    } elseif ($athletePosition >= 3) {
        $discountRate = 0.75;
    }

    $basePrice = REGISTRATION_BASE_PRICE;
    $discountAmount = normalize_money($basePrice * $discountRate);
    $lineTotal = normalize_money($basePrice - $discountAmount);

    $discountLabel = 'Full price';
    if ($discountRate === 0.50) {
        $discountLabel = '50% off';
    } elseif ($discountRate === 0.75) {
        $discountLabel = '75% off';
    }

    return [
        'athlete_position' => $athletePosition,
        'base_price' => normalize_money($basePrice),
        'discount_rate' => $discountRate,
        'discount_label' => $discountLabel,
        'discount_amount' => $discountAmount,
        'line_total' => $lineTotal,
    ];
}

function normalize_money(float $amount): float
{
    return round($amount, 2);
}

function save_submission_csv(string $csvPath, array $submission): bool
{
    $directory = dirname($csvPath);
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        return false;
    }

    $isNewFile = !file_exists($csvPath);
    $handle = fopen($csvPath, 'ab');
    if ($handle === false) {
        return false;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return false;
        }

        if ($isNewFile) {
            fputcsv($handle, array_keys($submission));
        }

        fputcsv($handle, array_values($submission));
        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }

    return true;
}

function log_stripe_registration_error(string $message): void
{
    $line = '[' . gmdate('c') . '] ' . $message . PHP_EOL;
    @file_put_contents(STRIPE_REGISTRATION_ERROR_LOG, $line, FILE_APPEND);
}

function should_retry_without_automatic_tax(string $message): bool
{
    $message = strtolower($message);
    return str_contains($message, 'automatic tax')
        || str_contains($message, 'head office address')
        || str_contains($message, 'tax calculation');
}

function build_no_fee_session(
    string $submissionId,
    string $email,
    string $guardianName,
    string $couponCode,
    bool $couponApplied,
    float $couponDiscountAmount,
    int $athleteCount,
    string $submissionSource
): array {
    return [
        'id' => 'nofee_' . gmdate('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8),
        'status' => 'complete',
        'payment_status' => 'paid',
        'amount_total' => 0,
        'currency' => stripe_currency_code(),
        'customer_email' => $email,
        'client_reference_id' => $submissionId,
        'metadata' => [
            'submission_id' => $submissionId,
            'guardian_name' => substr($guardianName, 0, 500),
            'guardian_email' => substr($email, 0, 500),
            'coupon_code' => substr($couponCode, 0, 100),
            'coupon_applied' => $couponApplied ? 'yes' : 'no',
            'coupon_discount' => number_format($couponDiscountAmount, 2, '.', ''),
            'athlete_count' => (string) $athleteCount,
            'source' => $submissionSource !== '' ? $submissionSource : 'stripe-checkout-registration',
        ],
    ];
}

function record_no_fee_payment_success(array $session, string $submissionId): bool
{
    $sessionId = trim((string) ($session['id'] ?? ''));
    if ($sessionId === '') {
        return false;
    }

    $alreadyLogged = get_paid_stripe_session_ids();
    if (isset($alreadyLogged[$sessionId])) {
        return true;
    }

    $record = [
        'logged_at_utc' => gmdate('c'),
        'stripe_mode' => stripe_mode_label(),
        'session_id' => $sessionId,
        'submission_id' => $submissionId,
        'payment_status' => (string) ($session['payment_status'] ?? 'paid'),
        'status' => (string) ($session['status'] ?? 'complete'),
        'amount_total' => (string) ($session['amount_total'] ?? 0),
        'currency' => (string) ($session['currency'] ?? stripe_currency_code()),
        'customer_email' => (string) ($session['customer_email'] ?? ''),
        'payment_intent_id' => '',
    ];

    return save_submission_csv(STRIPE_PAYMENT_SUCCESS_LOG, $record);
}

function send_no_fee_registration_emails(array $submission, array $athletes): void
{
    $guardianEmail = trim((string) ($submission['email'] ?? ''));
    $guardianName = trim((string) ($submission['guardian_name'] ?? 'Parent / Guardian'));
    $submissionId = trim((string) ($submission['submission_id'] ?? ''));

    $athleteSummary = [];
    foreach ($athletes as $athlete) {
        $athleteSummary[] = '- '
            . trim((string) ($athlete['athlete_name'] ?? 'Athlete'))
            . ' | '
            . trim((string) ($athlete['athlete_age_group'] ?? ''))
            . ' | '
            . trim((string) ($athlete['class_interest'] ?? ''));
    }

    $fromName = defined('WAITLIST_FROM_NAME') ? (string) WAITLIST_FROM_NAME : 'United Wrestling Club';
    $fromEmail = defined('WAITLIST_FROM_EMAIL') ? (string) WAITLIST_FROM_EMAIL : 'noreply@united-wc.com';
    $contactEmail = defined('WAITLIST_CONTACT_EMAIL') ? (string) WAITLIST_CONTACT_EMAIL : 'info@united-wc.com';
    $headers = [
        'From: ' . $fromName . ' <' . $fromEmail . '>',
        'Reply-To: ' . $contactEmail,
        'Content-Type: text/plain; charset=UTF-8',
    ];

    if ($guardianEmail !== '' && filter_var($guardianEmail, FILTER_VALIDATE_EMAIL)) {
        $parentSubject = 'UWC Registration Confirmation - Spring Session 2026';
        $parentBody = implode("\n", array_filter([
            'Thank you for registering with United Wrestling Club.',
            '',
            'Your registration has been confirmed with no payment required.',
            'A private family waiver was applied to this registration.',
            'Amount charged: $0.00',
            'Submission ID: ' . ($submissionId !== '' ? $submissionId : '(not available)'),
            '',
            'Athletes:',
            implode("\n", $athleteSummary),
            '',
            'Questions? ' . $contactEmail
                . (defined('WAITLIST_CONTACT_PHONE') && trim((string) WAITLIST_CONTACT_PHONE) !== '' ? ' | ' . WAITLIST_CONTACT_PHONE : ''),
            '',
            "It's Bigger Than Wrestling.",
        ]));

        if (!uwc_transport_mail($guardianEmail, $parentSubject, $parentBody, implode("\r\n", $headers))) {
            log_stripe_registration_error('Failed no-fee parent confirmation email for submission ' . $submissionId);
        }
    }

}

function build_requested_class_counts(array $athletes): array
{
    $counts = [];
    foreach ($athletes as $athlete) {
        $className = canonical_class_name((string) ($athlete['class_interest'] ?? ''));
        if ($className === '') {
            continue;
        }
        if (!isset($counts[$className])) {
            $counts[$className] = 0;
        }
        $counts[$className]++;
    }
    return $counts;
}

function get_paid_class_counts_for_classes(array $knownClassNames): array
{
    $counts = [];
    foreach ($knownClassNames as $className) {
        $counts[(string) $className] = 0;
    }

    $paidSessionIds = get_paid_stripe_session_ids();
    if (empty($paidSessionIds) || !file_exists(STRIPE_REGISTRATION_SUBMISSIONS_CSV)) {
        return $counts;
    }

    $handle = fopen(STRIPE_REGISTRATION_SUBMISSIONS_CSV, 'rb');
    if ($handle === false) {
        return $counts;
    }

    try {
        $header = fgetcsv($handle);
        if (!is_array($header)) {
            return $counts;
        }
        $sessionIdx = array_search('checkout_session_id', $header, true);
        $athletesIdx = array_search('athletes_json', $header, true);
        if ($sessionIdx === false || $athletesIdx === false) {
            return $counts;
        }

        while (($row = fgetcsv($handle)) !== false) {
            $sessionId = trim((string) ($row[$sessionIdx] ?? ''));
            if ($sessionId === '' || !isset($paidSessionIds[$sessionId])) {
                continue;
            }

            $athletesJson = (string) ($row[$athletesIdx] ?? '');
            $athletes = json_decode($athletesJson, true);
            if (!is_array($athletes)) {
                continue;
            }

            foreach ($athletes as $athlete) {
                $className = canonical_class_name((string) ($athlete['class_interest'] ?? ''));
                if ($className === '') {
                    continue;
                }
                if (!isset($counts[$className])) {
                    $counts[$className] = 0;
                }
                $counts[$className]++;
            }
        }
    } finally {
        fclose($handle);
    }

    return $counts;
}

function apply_class_count_adjustments(array $counts, array $adjustments, array $knownClassNames): array
{
    foreach ($knownClassNames as $className) {
        if (!isset($counts[$className])) {
            $counts[$className] = 0;
        }
    }

    foreach ($adjustments as $className => $delta) {
        $canonical = canonical_class_name((string) $className);
        if ($canonical === '' || !in_array($canonical, $knownClassNames, true)) {
            continue;
        }

        $counts[$canonical] = max(0, (int) ($counts[$canonical] ?? 0) + (int) $delta);
    }

    return $counts;
}

function get_paid_stripe_session_ids(): array
{
    if (!file_exists(STRIPE_PAYMENT_SUCCESS_LOG)) {
        return [];
    }

    $handle = fopen(STRIPE_PAYMENT_SUCCESS_LOG, 'rb');
    if ($handle === false) {
        return [];
    }

    $sessionIdSet = [];
    try {
        $header = fgetcsv($handle);
        if (!is_array($header)) {
            return [];
        }
        $sessionIdx = array_search('session_id', $header, true);
        if ($sessionIdx === false) {
            return [];
        }

        while (($row = fgetcsv($handle)) !== false) {
            $sessionId = trim((string) ($row[$sessionIdx] ?? ''));
            if ($sessionId !== '') {
                $sessionIdSet[$sessionId] = true;
            }
        }
    } finally {
        fclose($handle);
    }

    return $sessionIdSet;
}

function find_full_classes(
    array $requestedClassCounts,
    array $paidClassCounts,
    int $capacityPerClass,
    array $manuallyClosedClassNames = []
): array
{
    $full = [];
    $manuallyClosedLookup = [];
    foreach ($manuallyClosedClassNames as $closedClassName) {
        $canonical = canonical_class_name((string) $closedClassName);
        if ($canonical !== '') {
            $manuallyClosedLookup[$canonical] = true;
        }
    }

    foreach ($requestedClassCounts as $className => $requestedCount) {
        $requestedCount = (int) $requestedCount;
        $paidCount = (int) ($paidClassCounts[$className] ?? 0);
        $manuallyClosed = isset($manuallyClosedLookup[$className]);
        $remaining = max(0, $capacityPerClass - $paidCount);
        if ($manuallyClosed) {
            $remaining = 0;
        }

        if ($manuallyClosed || $requestedCount > $remaining) {
            $full[] = [
                'class_name' => $className,
                'capacity' => $capacityPerClass,
                'currently_paid' => $paidCount,
                'remaining' => $remaining,
                'requested_now' => $requestedCount,
                'manually_closed' => $manuallyClosed ? 'yes' : 'no',
            ];
        }
    }

    return $full;
}

function canonical_class_name(string $className): string
{
    $className = trim($className);
    if ($className === '') {
        return '';
    }

    $aliases = [
        'Foundation (11-13 Beginner)' => 'Rising Competitors - Foundation (Ages 11-13)',
        'Development (11-13 Intermediate)' => 'Rising Competitors - Development (Ages 11-13)',
        'Advanced (11-13 Advanced)' => 'Rising Competitors - Advanced (Ages 12-16)',
        'Rising Competitors - Advanced (Ages 11-13)' => 'Rising Competitors - Advanced (Ages 12-16)',
        'Elite Competition Team (14+)' => 'Elite Wrestlers (Ages 14+)',
        'Elite Competition Team (Ages 14+)' => 'Elite Wrestlers (Ages 14+)',
        'Elite Wrestler (Ages 14+)' => 'Elite Wrestlers (Ages 14+)',
        'Future Champions (5-10)' => 'Future Champions (Ages 5-10)',
    ];

    return $aliases[$className] ?? $className;
}

function send_class_waitlist_emails(array $waitlistRecord, array $athletes, array $fullClasses): void
{
    $guardianEmail = trim((string) ($waitlistRecord['email'] ?? ''));
    $guardianName = trim((string) ($waitlistRecord['guardian_name'] ?? 'Parent / Guardian'));
    $waitlistId = trim((string) ($waitlistRecord['waitlist_id'] ?? ''));
    $fullClassNames = implode(', ', array_map(static fn ($item) => (string) ($item['class_name'] ?? ''), $fullClasses));
    $athleteSummary = [];
    foreach ($athletes as $athlete) {
        $name = trim((string) ($athlete['athlete_name'] ?? 'Athlete'));
        $className = trim((string) ($athlete['class_interest'] ?? ''));
        $ageGroup = trim((string) ($athlete['athlete_age_group'] ?? ''));
        $athleteSummary[] = '- ' . $name . ' | ' . $ageGroup . ' | ' . $className;
    }

    $fromName = defined('WAITLIST_FROM_NAME') ? (string) WAITLIST_FROM_NAME : 'United Wrestling Club';
    $fromEmail = defined('WAITLIST_FROM_EMAIL') ? (string) WAITLIST_FROM_EMAIL : 'noreply@united-wc.com';
    $contactEmail = defined('WAITLIST_CONTACT_EMAIL') ? (string) WAITLIST_CONTACT_EMAIL : 'info@united-wc.com';
    $adminEmail = defined('WAITLIST_ADMIN_EMAIL') ? (string) WAITLIST_ADMIN_EMAIL : $contactEmail;
    $headers = [
        'From: ' . $fromName . ' <' . $fromEmail . '>',
        'Reply-To: ' . $contactEmail,
        'Content-Type: text/plain; charset=UTF-8',
    ];

    if ($guardianEmail !== '' && filter_var($guardianEmail, FILTER_VALIDATE_EMAIL)) {
        $parentSubject = 'UWC Waitlist Update - Class Full';
        $parentBody = implode("\n", array_filter([
            'Thank you for registering with United Wrestling Club.',
            '',
            'One or more of your selected classes are currently full: ' . $fullClassNames,
            'Your athletes have been added to the waitlist.',
            '',
            'Waitlist ID: ' . ($waitlistId !== '' ? $waitlistId : '(not available)'),
            '',
            'Athletes:',
            implode("\n", $athleteSummary),
            '',
            'We will contact you as soon as a spot opens.',
            '',
            'Questions? ' . $contactEmail
                . (defined('WAITLIST_CONTACT_PHONE') && trim((string) WAITLIST_CONTACT_PHONE) !== '' ? ' | ' . WAITLIST_CONTACT_PHONE : ''),
            '',
            "It's Bigger Than Wrestling.",
        ]));

        if (!uwc_transport_mail($guardianEmail, $parentSubject, $parentBody, implode("\r\n", $headers))) {
            log_class_waitlist_error('Failed parent waitlist email for ' . $waitlistId . ' to ' . $guardianEmail);
        }
    }

    if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $adminSubject = 'UWC Class Full Waitlist Entry' . ($guardianName !== '' ? ' - ' . $guardianName : '');
        $adminBody = implode("\n", array_filter([
            'A registration was waitlisted because class capacity is full.',
            '',
            'Waitlist ID: ' . ($waitlistId !== '' ? $waitlistId : '(not available)'),
            'Parent/Guardian: ' . ($guardianName !== '' ? $guardianName : '(not provided)'),
            'Email: ' . ($guardianEmail !== '' ? $guardianEmail : '(not provided)'),
            'Phone: ' . ((string) ($waitlistRecord['phone'] ?? '(not provided)')),
            'Full classes: ' . $fullClassNames,
            '',
            'Athletes:',
            implode("\n", $athleteSummary),
        ]));

        if (!uwc_transport_mail($adminEmail, $adminSubject, $adminBody, implode("\r\n", $headers))) {
            log_class_waitlist_error('Failed admin waitlist email for ' . $waitlistId . ' to ' . $adminEmail);
        }
    }
}

function log_class_waitlist_error(string $message): void
{
    $line = '[' . gmdate('c') . '] ' . $message . PHP_EOL;
    @file_put_contents(STRIPE_CLASS_WAITLIST_ERROR_LOG, $line, FILE_APPEND);
}
