<?php
declare(strict_types=1);

require_once __DIR__ . '/waitlist-config.php';
require_once __DIR__ . '/stripe-config.php';
require_once __DIR__ . '/stripe-helpers.php';

const REGISTRATION_BASE_PRICE = 285.00;
const STRIPE_REGISTRATION_SUBMISSIONS_CSV = __DIR__ . '/data/stripe_registration_submissions.csv';
const STRIPE_REGISTRATION_ERROR_LOG = __DIR__ . '/data/stripe_registration_errors.log';

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
$confirmCheckoutReady = clean_text((string) ($_POST['confirm_enrollment_closed'] ?? ''));
$confirmInfoAccurate = clean_text((string) ($_POST['confirm_info_accurate'] ?? ''));

if ($guardianName === '' || $email === '') {
    redirect_back_with_status('error', 'Please complete the parent / guardian name and email.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirect_back_with_status('error', 'Please enter a valid email address.');
}

if ($confirmCheckoutReady !== 'yes' || $confirmInfoAccurate !== 'yes') {
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
    'future-champions' => 'Future Champions (5-10)',
    'foundation' => 'Foundation (11-13 Beginner)',
    'development' => 'Development (11-13 Intermediate)',
    'advanced-11-13' => 'Advanced (11-13 Advanced)',
    'elite-competition-team' => 'Elite Wrestler (Ages 14+)',
];

$allowedClassesByAge = [
    '5-10' => ['future-champions'],
    '11-13' => ['foundation', 'development', 'advanced-11-13'],
    '14-plus' => ['elite-competition-team'],
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

$submissionId = 'uwc_' . gmdate('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
$siteBaseUrl = rtrim((defined('WAITLIST_SITE_URL') ? WAITLIST_SITE_URL : 'https://united-wc.com'), '/');
$successUrl = $siteBaseUrl . '/payment-success.php?session_id={CHECKOUT_SESSION_ID}&submission_id=' . rawurlencode($submissionId);
$cancelUrl = $siteBaseUrl . '/payment-cancel.html?submission_id=' . rawurlencode($submissionId);

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
    'metadata[athlete_count]' => (string) $athleteCount,
    'metadata[source]' => $submissionSource !== '' ? $submissionSource : 'stripe-checkout-registration',
    'payment_intent_data[metadata][submission_id]' => $submissionId,
    'payment_intent_data[metadata][guardian_email]' => substr($email, 0, 500),
];

if (stripe_automatic_tax_enabled()) {
    $checkoutParams['automatic_tax[enabled]'] = 'true';
}

try {
    $session = stripe_api_request('POST', '/v1/checkout/sessions', $checkoutParams);
} catch (Throwable $e) {
    log_stripe_registration_error('Stripe checkout session create failed: ' . $e->getMessage());
    redirect_back_with_status('error', 'Checkout could not be started. Please try again in a moment.');
}

$submission = [
    'submitted_at_utc' => gmdate('c'),
    'submission_id' => $submissionId,
    'submission_source' => $submissionSource !== '' ? $submissionSource : 'stripe-checkout-registration',
    'stripe_mode' => stripe_mode_label(),
    'checkout_session_id' => (string) ($session['id'] ?? ''),
    'checkout_session_url' => (string) ($session['url'] ?? ''),
    'checkout_session_status' => (string) ($session['status'] ?? ''),
    'payment_status' => 'checkout_started',
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
];

if (!save_submission_csv(STRIPE_REGISTRATION_SUBMISSIONS_CSV, $submission)) {
    log_stripe_registration_error('Failed to save stripe registration submission ' . $submissionId);
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
