<?php
declare(strict_types=1);

require_once __DIR__ . '/waitlist-config.php';

const REGISTRATION_BASE_PRICE = 285.00;
const REGISTRATION_INTEREST_STORAGE_CSV = __DIR__ . '/data/registration_interest_submissions.csv';
const REGISTRATION_INTEREST_ERROR_LOG = __DIR__ . '/data/registration_interest_errors.log';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect_with_status('error', 'Invalid request method.');
}

if (!empty($_POST['website'] ?? '')) {
    redirect_with_status('success');
}

$guardianName = clean_text((string) ($_POST['guardian_name'] ?? ''));
$email = clean_email((string) ($_POST['email'] ?? ''));
$phone = clean_phone((string) ($_POST['phone'] ?? ''));
$preferredContactMethod = clean_text((string) ($_POST['preferred_contact_method'] ?? ''));
$notes = clean_text((string) ($_POST['notes'] ?? ''));
$submissionSource = clean_text((string) ($_POST['submission_source'] ?? 'registration-application'));
$confirmEnrollmentClosed = clean_text((string) ($_POST['confirm_enrollment_closed'] ?? ''));
$confirmInfoAccurate = clean_text((string) ($_POST['confirm_info_accurate'] ?? ''));

if ($guardianName === '' || $email === '') {
    redirect_with_status('error', 'Please complete the parent / guardian name and email.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirect_with_status('error', 'Please enter a valid email address.');
}

if ($confirmEnrollmentClosed !== 'yes' || $confirmInfoAccurate !== 'yes') {
    redirect_with_status('error', 'Please confirm the required registration notices before submitting.');
}

$allowedContactMethods = ['', 'email', 'phone', 'text'];
if (!in_array($preferredContactMethod, $allowedContactMethods, true)) {
    redirect_with_status('error', 'Invalid contact preference.');
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
    'elite-competition-team' => 'Elite Competition Team (14+)',
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
    redirect_with_status('error', 'Invalid athlete registration data.');
}

if (!is_array($experienceRaw)) {
    $experienceRaw = [];
}

$athleteCount = count($athleteNamesRaw);
if ($athleteCount < 1 || $athleteCount > 10) {
    redirect_with_status('error', 'Please include at least one athlete (maximum 10).');
}

if (count($ageGroupsRaw) !== $athleteCount || count($classInterestsRaw) !== $athleteCount) {
    redirect_with_status('error', 'Athlete form data is incomplete. Please try again.');
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
        redirect_with_status('error', 'Please complete all athlete fields (name, age group, and class type).');
    }

    if (!array_key_exists($ageGroup, $ageLabelMap)) {
        redirect_with_status('error', 'Invalid athlete age group selection.');
    }

    if (!array_key_exists($classInterest, $allowedClasses)) {
        redirect_with_status('error', 'Invalid athlete class selection.');
    }

    if (!in_array($classInterest, $allowedClassesByAge[$ageGroup], true)) {
        redirect_with_status('error', 'One athlete class selection does not match the selected age group.');
    }

    if (!array_key_exists($experienceValue, $experienceLabelMap)) {
        redirect_with_status('error', 'Invalid athlete experience selection.');
    }

    $pricing = calculate_line_pricing($i + 1);
    $baseSubtotal += $pricing['base_price'];
    $discountTotal += $pricing['discount_amount'];
    $estimatedTotal += $pricing['line_total'];

    $athlete = [
        'athlete_index' => $i + 1,
        'athlete_name' => $athleteName,
        'athlete_age_group_value' => $ageGroup,
        'athlete_age_group' => $ageLabelMap[$ageGroup],
        'class_interest_value' => $classInterest,
        'class_interest' => $allowedClasses[$classInterest],
        'experience_value' => $experienceValue,
        'experience_label' => $experienceLabelMap[$experienceValue],
    ];

    $athletes[] = $athlete;
    $pricingLines[] = array_merge($pricing, [
        'athlete_index' => $i + 1,
        'athlete_name' => $athleteName,
        'athlete_age_group' => $ageLabelMap[$ageGroup],
        'class_interest' => $allowedClasses[$classInterest],
    ]);
}

$estimatedTotal = normalize_money($estimatedTotal);
$baseSubtotal = normalize_money($baseSubtotal);
$discountTotal = normalize_money($discountTotal);

$clientEstimatedTotal = normalize_money((float) ($_POST['estimated_total'] ?? 0));
$clientSubtotal = normalize_money((float) ($_POST['estimated_subtotal'] ?? 0));
$clientDiscount = normalize_money((float) ($_POST['estimated_discount_total'] ?? 0));
$pricingSnapshotPosted = trim((string) ($_POST['pricing_snapshot_json'] ?? ''));
$pricingSnapshotValidJson = $pricingSnapshotPosted !== '' && json_decode($pricingSnapshotPosted, true) !== null;

$submission = [
    'submitted_at_utc' => gmdate('c'),
    'submission_source' => $submissionSource !== '' ? $submissionSource : 'registration-application',
    'registration_status' => 'application_received_payment_pending',
    'payment_status' => 'checkout_coming_soon',
    'payment_type' => 'full_payment_when_open',
    'guardian_name' => $guardianName,
    'email' => $email,
    'phone' => $phone,
    'preferred_contact_method' => $preferredContactMethod,
    'total_athletes' => (string) count($athletes),
    'estimated_subtotal' => number_format($baseSubtotal, 2, '.', ''),
    'estimated_discount_total' => number_format($discountTotal, 2, '.', ''),
    'estimated_total' => number_format($estimatedTotal, 2, '.', ''),
    'estimated_tax_note' => 'Tax will be calculated at checkout when payment opens (if applicable).',
    'client_estimated_subtotal' => number_format($clientSubtotal, 2, '.', ''),
    'client_estimated_discount_total' => number_format($clientDiscount, 2, '.', ''),
    'client_estimated_total' => number_format($clientEstimatedTotal, 2, '.', ''),
    'pricing_snapshot_json_valid' => $pricingSnapshotValidJson ? 'yes' : 'no',
    'pricing_snapshot_json' => $pricingSnapshotPosted,
    'athletes_json' => json_encode($athletes, JSON_UNESCAPED_SLASHES),
    'pricing_lines_json' => json_encode($pricingLines, JSON_UNESCAPED_SLASHES),
    'notes' => $notes,
    'ip_address' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
];

if (!save_submission_csv(REGISTRATION_INTEREST_STORAGE_CSV, $submission)) {
    log_registration_error('Could not save registration interest submission to CSV.');
    redirect_with_status('error', 'We could not save your registration request. Please try again.');
}

$clubMailSent = send_club_notification($submission, $athletes, $pricingLines);
$parentMailSent = send_parent_confirmation($submission, $athletes, $pricingLines);

if (!$clubMailSent) {
    log_registration_error('Club notification email failed for ' . $email);
}

if (!$parentMailSent) {
    log_registration_error('Parent confirmation email failed for ' . $email);
}

if ($clubMailSent && $parentMailSent) {
    redirect_with_status('success');
}

redirect_with_status('partial');

function redirect_with_status(string $status, string $message = ''): void
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

function send_club_notification(array $submission, array $athletes, array $pricingLines): bool
{
    $subject = 'New UWC Registration Application - Payment Pending - ' . $submission['guardian_name'];

    $lines = [
        'A new UWC registration application was submitted.',
        '',
        'Parent / Guardian: ' . $submission['guardian_name'],
        'Email: ' . $submission['email'],
        'Phone: ' . ($submission['phone'] !== '' ? $submission['phone'] : '(not provided)'),
        'Preferred Contact Method: ' . ($submission['preferred_contact_method'] !== '' ? ucfirst((string) $submission['preferred_contact_method']) : '(not specified)'),
        'Athletes: ' . $submission['total_athletes'],
        'Estimated Subtotal: $' . $submission['estimated_subtotal'],
        'Estimated Discounts: $' . $submission['estimated_discount_total'],
        'Estimated Total: $' . $submission['estimated_total'],
        'Payment Status: checkout coming soon (application received)',
        '',
        'Athlete Summary:',
    ];

    foreach ($athletes as $index => $athlete) {
        $pricing = $pricingLines[$index] ?? null;
        $line = sprintf(
            '%d) %s | %s | %s',
            $athlete['athlete_index'],
            $athlete['athlete_name'],
            $athlete['athlete_age_group'],
            $athlete['class_interest']
        );
        if (($athlete['experience_label'] ?? '') !== '') {
            $line .= ' | ' . $athlete['experience_label'];
        }
        if (is_array($pricing)) {
            $line .= sprintf(' | %s (%s)', format_money((float) $pricing['line_total']), $pricing['discount_label']);
        }
        $lines[] = $line;
    }

    $lines[] = '';
    $lines[] = 'Notes: ' . ($submission['notes'] !== '' ? $submission['notes'] : '(none)');
    $lines[] = '';
    $lines[] = 'Submitted (UTC): ' . $submission['submitted_at_utc'];
    $lines[] = 'IP: ' . $submission['ip_address'];
    $lines[] = 'User Agent: ' . $submission['user_agent'];

    return send_mail_message(
        WAITLIST_ADMIN_EMAIL,
        $subject,
        implode("\n", $lines),
        build_club_notification_html($submission, $athletes, $pricingLines),
        ['Reply-To: ' . $submission['email']]
    );
}

function send_parent_confirmation(array $submission, array $athletes, array $pricingLines): bool
{
    $subject = 'UWC Registration Application Received - Payment Checkout Coming Soon';

    $contactNameLine = '';
    if (defined('WAITLIST_CONTACT_NAME') && WAITLIST_CONTACT_NAME !== '') {
        $contactNameLine = 'Contact: ' . WAITLIST_CONTACT_NAME;
        if (defined('WAITLIST_CONTACT_TITLE') && WAITLIST_CONTACT_TITLE !== '') {
            $contactNameLine .= ' (' . WAITLIST_CONTACT_TITLE . ')';
        }
    }

    $lines = [
        'Thank you for submitting your family registration application for United Wrestling Club Spring Session 2026.',
        '',
        'Your application has been received.',
        'Payment checkout is not open yet, and we will send the payment link as soon as checkout opens.',
        '',
        'Family Registration Summary',
        'Parent / Guardian: ' . $submission['guardian_name'],
        'Athletes: ' . $submission['total_athletes'],
        'Estimated Total (before tax): ' . format_money((float) $submission['estimated_total']),
        '',
        'Athletes:',
    ];

    foreach ($athletes as $index => $athlete) {
        $pricing = $pricingLines[$index] ?? null;
        $detail = sprintf(
            '- %s | %s | %s',
            $athlete['athlete_name'],
            $athlete['athlete_age_group'],
            $athlete['class_interest']
        );
        if (($athlete['experience_label'] ?? '') !== '') {
            $detail .= ' | ' . $athlete['experience_label'];
        }
        if (is_array($pricing)) {
            $detail .= sprintf(' | %s (%s)', format_money((float) $pricing['line_total']), $pricing['discount_label']);
        }
        $lines[] = $detail;
    }

    $lines[] = '';
    $lines[] = 'Tax will be calculated at checkout when payment opens (if applicable).';
    $lines[] = '';
    if ($contactNameLine !== '') {
        $lines[] = $contactNameLine;
    }
    $lines[] = 'Email: ' . WAITLIST_CONTACT_EMAIL;
    if (defined('WAITLIST_CONTACT_PHONE') && WAITLIST_CONTACT_PHONE !== '') {
        $lines[] = 'Phone / Text: ' . WAITLIST_CONTACT_PHONE;
    }
    $lines[] = 'Website: ' . (defined('WAITLIST_SITE_URL') ? WAITLIST_SITE_URL : 'https://united-wc.com');
    $lines[] = '';
    $lines[] = 'Better United.';

    return send_mail_message(
        $submission['email'],
        $subject,
        implode("\n", array_values(array_filter($lines, static fn ($line) => $line !== ''))),
        build_parent_confirmation_html($submission, $athletes, $pricingLines),
        ['Reply-To: ' . WAITLIST_CONTACT_EMAIL]
    );
}

function send_mail_message(string $to, string $subject, string $plainTextBody, ?string $htmlBody = null, array $extraHeaders = []): bool
{
    $headers = [
        'MIME-Version: 1.0',
        'From: ' . WAITLIST_FROM_NAME . ' <' . WAITLIST_FROM_EMAIL . '>',
    ];

    foreach ($extraHeaders as $header) {
        if ($header !== '') {
            $headers[] = $header;
        }
    }

    if ($htmlBody === null || trim($htmlBody) === '') {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        return @mail($to, $subject, $plainTextBody, implode("\r\n", $headers));
    }

    $boundary = 'uwc_' . md5(uniqid((string) mt_rand(), true));
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

    $message = '';
    $message .= '--' . $boundary . "\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= $plainTextBody . "\r\n\r\n";
    $message .= '--' . $boundary . "\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= $htmlBody . "\r\n\r\n";
    $message .= '--' . $boundary . "--\r\n";

    return @mail($to, $subject, $message, implode("\r\n", $headers));
}

function build_parent_confirmation_html(array $submission, array $athletes, array $pricingLines): string
{
    $siteUrl = defined('WAITLIST_SITE_URL') ? WAITLIST_SITE_URL : 'https://united-wc.com';
    $logoUrl = defined('WAITLIST_LOGO_URL') ? WAITLIST_LOGO_URL : rtrim($siteUrl, '/') . '/assets/uwc-logo.png';
    $contactPhone = defined('WAITLIST_CONTACT_PHONE') ? trim((string) WAITLIST_CONTACT_PHONE) : '';
    $contactName = defined('WAITLIST_CONTACT_NAME') ? trim((string) WAITLIST_CONTACT_NAME) : '';
    $contactTitle = defined('WAITLIST_CONTACT_TITLE') ? trim((string) WAITLIST_CONTACT_TITLE) : '';

    $guardianName = e($submission['guardian_name']);
    $athleteCount = e($submission['total_athletes']);
    $estimatedSubtotal = e(format_money((float) $submission['estimated_subtotal']));
    $estimatedDiscount = e(format_money((float) $submission['estimated_discount_total']));
    $estimatedTotal = e(format_money((float) $submission['estimated_total']));
    $contactEmail = e(WAITLIST_CONTACT_EMAIL);
    $logoUrlEsc = e($logoUrl);
    $siteUrlEsc = e($siteUrl);

    $contactPersonRow = '';
    if ($contactName !== '') {
        $label = $contactName;
        if ($contactTitle !== '') {
            $label .= ' (' . $contactTitle . ')';
        }
        $contactPersonRow = '<tr><td style="padding:0 0 6px 0; color:#334155; font-size:14px; line-height:1.4;"><strong style="color:#0f172a;">Contact:</strong> ' . e($label) . '</td></tr>';
    }

    $phoneRow = '';
    if ($contactPhone !== '') {
        $phoneRow = '<tr><td style="padding:0 0 6px 0; color:#334155; font-size:14px; line-height:1.4;"><strong style="color:#0f172a;">Phone / Text:</strong> ' . e($contactPhone) . '</td></tr>';
    }

    $athleteRows = '';
    foreach ($athletes as $index => $athlete) {
        $pricing = $pricingLines[$index] ?? null;
        $experience = $athlete['experience_label'] !== '' ? e($athlete['experience_label']) : 'Not specified';
        $lineTotal = $pricing ? e(format_money((float) $pricing['line_total'])) : '$0.00';
        $discountLabel = $pricing ? e((string) $pricing['discount_label']) : 'Full price';

        $athleteRows .= '<tr>'
            . '<td style="padding:12px 14px; border-top:1px solid #e5edf7; color:#0f172a; font-size:14px; font-weight:600;">' . e($athlete['athlete_name']) . '</td>'
            . '<td style="padding:12px 14px; border-top:1px solid #e5edf7; color:#334155; font-size:13px;">' . e($athlete['athlete_age_group']) . '</td>'
            . '<td style="padding:12px 14px; border-top:1px solid #e5edf7; color:#334155; font-size:13px;">' . e($athlete['class_interest']) . '</td>'
            . '<td style="padding:12px 14px; border-top:1px solid #e5edf7; color:#334155; font-size:13px;">' . $experience . '</td>'
            . '<td style="padding:12px 14px; border-top:1px solid #e5edf7; color:#0f172a; font-size:13px; font-weight:700; text-align:right; white-space:nowrap;">' . $lineTotal . '<br><span style="color:#64748b; font-weight:600; font-size:11px;">' . $discountLabel . '</span></td>'
            . '</tr>';
    }

    return <<<HTML
<!doctype html>
<html lang="en">
  <body style="margin:0; padding:0; background:#eef2f7; font-family:Arial, Helvetica, sans-serif; color:#0f172a;">
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#eef2f7; margin:0; padding:24px 12px;">
      <tr>
        <td align="center">
          <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:700px; background:#ffffff; border:1px solid #dbe4f0; border-radius:14px; overflow:hidden;">
            <tr>
              <td style="background:#081a34; padding:22px 24px; border-bottom:3px solid #c8102e;">
                <img src="{$logoUrlEsc}" alt="United Wrestling Club" width="230" style="display:block; width:230px; max-width:100%; height:auto;" />
                <div style="margin-top:10px; color:#dbeafe; font-size:13px; letter-spacing:0.08em; text-transform:uppercase;">Registration Application Received • Spring Session 2026</div>
              </td>
            </tr>
            <tr>
              <td style="padding:24px;">
                <h1 style="margin:0 0 8px 0; font-size:24px; line-height:1.15; color:#0f172a;">Your registration application has been received</h1>
                <p style="margin:0 0 14px 0; color:#334155; font-size:15px; line-height:1.55;">
                  Thank you, {$guardianName}. We received your family registration application and saved your registration details.
                  Payment checkout is not open yet, but we will send the payment link as soon as checkout opens.
                </p>

                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border:1px solid #ecd4da; border-radius:10px; background:#fff7f9; margin:0 0 16px 0;">
                  <tr>
                    <td style="padding:14px 16px;">
                      <div style="color:#7f1d1d; font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; margin-bottom:6px;">Payment status</div>
                      <div style="color:#334155; font-size:14px; line-height:1.55;">Applications are open and your submission has been received. Payment checkout is not open yet, and we will send the payment link as soon as it is available.</div>
                    </td>
                  </tr>
                </table>

                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border:1px solid #dbe4f0; border-radius:10px; background:#f8fbff; margin:0 0 16px 0;">
                  <tr>
                    <td style="padding:14px 16px; border-bottom:1px solid #e5edf7; font-weight:700; color:#0f172a;">Family Registration Summary</td>
                  </tr>
                  <tr>
                    <td style="padding:12px 16px;">
                      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                        <tr>
                          <td style="padding:0 0 8px 0; color:#64748b; font-size:13px; width:42%;">Parent / Guardian</td>
                          <td style="padding:0 0 8px 0; color:#0f172a; font-size:14px; font-weight:600;">{$guardianName}</td>
                        </tr>
                        <tr>
                          <td style="padding:0 0 8px 0; color:#64748b; font-size:13px;">Athletes</td>
                          <td style="padding:0 0 8px 0; color:#0f172a; font-size:14px; font-weight:600;">{$athleteCount}</td>
                        </tr>
                        <tr>
                          <td style="padding:0 0 8px 0; color:#64748b; font-size:13px;">Base subtotal</td>
                          <td style="padding:0 0 8px 0; color:#0f172a; font-size:14px;">{$estimatedSubtotal}</td>
                        </tr>
                        <tr>
                          <td style="padding:0 0 8px 0; color:#64748b; font-size:13px;">Discounts</td>
                          <td style="padding:0 0 8px 0; color:#0f172a; font-size:14px;">-{$estimatedDiscount}</td>
                        </tr>
                        <tr>
                          <td style="padding:0; color:#0f172a; font-size:13px; font-weight:700;">Estimated total (before tax)</td>
                          <td style="padding:0; color:#c8102e; font-size:16px; font-weight:800;">{$estimatedTotal}</td>
                        </tr>
                      </table>
                    </td>
                  </tr>
                </table>

                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border:1px solid #dbe4f0; border-radius:10px; background:#ffffff; margin:0 0 16px 0;">
                  <tr>
                    <td style="padding:14px 16px; border-bottom:1px solid #e5edf7; font-weight:700; color:#0f172a;">Athletes</td>
                  </tr>
                  <tr>
                    <td style="padding:0; overflow:hidden;">
                      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                        <tr>
                          <td style="padding:10px 14px; background:#fbfdff; color:#64748b; font-size:12px; font-weight:700;">Name</td>
                          <td style="padding:10px 14px; background:#fbfdff; color:#64748b; font-size:12px; font-weight:700;">Age Group</td>
                          <td style="padding:10px 14px; background:#fbfdff; color:#64748b; font-size:12px; font-weight:700;">Class</td>
                          <td style="padding:10px 14px; background:#fbfdff; color:#64748b; font-size:12px; font-weight:700;">Experience</td>
                          <td style="padding:10px 14px; background:#fbfdff; color:#64748b; font-size:12px; font-weight:700; text-align:right;">Estimate</td>
                        </tr>
                        {$athleteRows}
                      </table>
                    </td>
                  </tr>
                </table>

                <p style="margin:0 0 14px 0; color:#64748b; font-size:13px; line-height:1.55;">
                  Tax is not included in this estimate. If applicable, tax will be calculated during checkout once payment opens.
                </p>

                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 16px 0;">
                  <tr><td style="padding:0 0 8px 0; color:#0f172a; font-size:15px; font-weight:700;">Questions?</td></tr>
                  {$contactPersonRow}
                  <tr>
                    <td style="padding:0 0 6px 0; color:#334155; font-size:14px; line-height:1.4;"><strong style="color:#0f172a;">Email:</strong> {$contactEmail}</td>
                  </tr>
                  {$phoneRow}
                  <tr>
                    <td style="padding:2px 0 0 0; color:#334155; font-size:14px; line-height:1.4;"><strong style="color:#0f172a;">Website:</strong> <a href="{$siteUrlEsc}" style="color:#1d4ed8; text-decoration:none;">{$siteUrlEsc}</a></td>
                  </tr>
                </table>

                <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                  <tr>
                    <td style="border-radius:8px; background:#c8102e;"><a href="{$siteUrlEsc}/program.html" style="display:inline-block; padding:10px 14px; color:#ffffff; text-decoration:none; font-weight:700; font-size:13px;">View Program Levels</a></td>
                    <td style="width:8px;"></td>
                    <td style="border-radius:8px; background:#081a34;"><a href="{$siteUrlEsc}/team.html" style="display:inline-block; padding:10px 14px; color:#ffffff; text-decoration:none; font-weight:700; font-size:13px;">Meet the Coaches</a></td>
                  </tr>
                </table>
              </td>
            </tr>
            <tr>
              <td style="padding:14px 24px 18px; border-top:1px solid #e2e8f0; background:#fbfdff;">
                <div style="color:#475569; font-size:12px; line-height:1.5;">United Wrestling Club • Spring Session 2026 • Saint Edmond's Academy</div>
                <div style="margin-top:4px; color:#0f172a; font-size:12px; font-weight:700; letter-spacing:0.04em; text-transform:uppercase;">Better United.</div>
              </td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
  </body>
</html>
HTML;
}

function build_club_notification_html(array $submission, array $athletes, array $pricingLines): string
{
    $siteUrl = defined('WAITLIST_SITE_URL') ? WAITLIST_SITE_URL : 'https://united-wc.com';
    $logoUrl = defined('WAITLIST_LOGO_URL') ? WAITLIST_LOGO_URL : rtrim($siteUrl, '/') . '/assets/uwc-logo.png';

    $guardianName = e($submission['guardian_name']);
    $email = e($submission['email']);
    $phone = $submission['phone'] !== '' ? e($submission['phone']) : '<em style="color:#64748b;">(not provided)</em>';
    $preferredContact = $submission['preferred_contact_method'] !== '' ? e(ucfirst((string) $submission['preferred_contact_method'])) : '(not specified)';
    $notes = $submission['notes'] !== '' ? nl2br(e($submission['notes'])) : '<em style="color:#64748b;">(none)</em>';
    $submittedAt = e($submission['submitted_at_utc']);
    $ipAddress = e($submission['ip_address']);
    $userAgent = e($submission['user_agent']);
    $totalAthletes = e($submission['total_athletes']);
    $subtotal = e(format_money((float) $submission['estimated_subtotal']));
    $discount = e(format_money((float) $submission['estimated_discount_total']));
    $total = e(format_money((float) $submission['estimated_total']));
    $logoUrlEsc = e($logoUrl);
    $siteUrlEsc = e($siteUrl);

    $athleteRows = '';
    foreach ($athletes as $index => $athlete) {
        $pricing = $pricingLines[$index] ?? null;
        $experience = $athlete['experience_label'] !== '' ? e($athlete['experience_label']) : 'Not specified';
        $lineTotal = $pricing ? e(format_money((float) $pricing['line_total'])) : '$0.00';
        $discountLabel = $pricing ? e((string) $pricing['discount_label']) : 'Full price';

        $athleteRows .= '<tr>'
            . '<td style="padding:11px 12px; border-top:1px solid #e5edf7; font-size:13px; color:#0f172a; font-weight:600;">' . e($athlete['athlete_name']) . '</td>'
            . '<td style="padding:11px 12px; border-top:1px solid #e5edf7; font-size:13px; color:#334155;">' . e($athlete['athlete_age_group']) . '</td>'
            . '<td style="padding:11px 12px; border-top:1px solid #e5edf7; font-size:13px; color:#334155;">' . e($athlete['class_interest']) . '</td>'
            . '<td style="padding:11px 12px; border-top:1px solid #e5edf7; font-size:13px; color:#334155;">' . $experience . '</td>'
            . '<td style="padding:11px 12px; border-top:1px solid #e5edf7; font-size:12px; color:#64748b;">' . $discountLabel . '</td>'
            . '<td style="padding:11px 12px; border-top:1px solid #e5edf7; font-size:13px; color:#0f172a; font-weight:700; text-align:right; white-space:nowrap;">' . $lineTotal . '</td>'
            . '</tr>';
    }

    return <<<HTML
<!doctype html>
<html lang="en">
  <body style="margin:0; padding:0; background:#eef2f7; font-family:Arial, Helvetica, sans-serif; color:#0f172a;">
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#eef2f7; margin:0; padding:20px 12px;">
      <tr>
        <td align="center">
          <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:760px; background:#ffffff; border:1px solid #dbe4f0; border-radius:14px; overflow:hidden;">
            <tr>
              <td style="background:#081a34; padding:20px 24px; border-bottom:3px solid #c8102e;">
                <img src="{$logoUrlEsc}" alt="United Wrestling Club" width="220" style="display:block; width:220px; max-width:100%; height:auto;" />
                <div style="margin-top:10px; color:#dbeafe; font-size:13px; letter-spacing:0.08em; text-transform:uppercase;">New Registration Application • Payment Pending</div>
              </td>
            </tr>
            <tr>
              <td style="padding:22px 24px;">
                <h1 style="margin:0 0 12px 0; font-size:22px; color:#0f172a;">New Family Registration Application</h1>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border:1px solid #dbe4f0; border-radius:10px; background:#f8fbff; margin-bottom:14px;">
                  <tr>
                    <td style="padding:14px 16px;">
                      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                        <tr><td style="padding:0 0 8px 0; color:#64748b; font-size:13px; width:35%;">Parent / Guardian</td><td style="padding:0 0 8px 0; color:#0f172a; font-size:14px; font-weight:600;">{$guardianName}</td></tr>
                        <tr><td style="padding:0 0 8px 0; color:#64748b; font-size:13px;">Email</td><td style="padding:0 0 8px 0; font-size:14px;"><a href="mailto:{$email}" style="color:#1d4ed8; text-decoration:none;">{$email}</a></td></tr>
                        <tr><td style="padding:0 0 8px 0; color:#64748b; font-size:13px;">Phone</td><td style="padding:0 0 8px 0; color:#0f172a; font-size:14px;">{$phone}</td></tr>
                        <tr><td style="padding:0; color:#64748b; font-size:13px;">Preferred Contact</td><td style="padding:0; color:#0f172a; font-size:14px;">{$preferredContact}</td></tr>
                      </table>
                    </td>
                  </tr>
                </table>

                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border:1px solid #ecd4da; border-radius:10px; background:#fff7f9; margin-bottom:14px;">
                  <tr>
                    <td style="padding:14px 16px;">
                      <div style="color:#7f1d1d; font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; margin-bottom:6px;">Enrollment / Payment</div>
                      <div style="color:#334155; font-size:14px; line-height:1.55;">Application received. Payment checkout is not open yet. Family was sent a confirmation email and payment will be requested when checkout opens.</div>
                    </td>
                  </tr>
                </table>

                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border:1px solid #dbe4f0; border-radius:10px; background:#ffffff; margin-bottom:14px; overflow:hidden;">
                  <tr>
                    <td style="padding:10px 12px; background:#fbfdff; color:#64748b; font-size:12px; font-weight:700;">Name</td>
                    <td style="padding:10px 12px; background:#fbfdff; color:#64748b; font-size:12px; font-weight:700;">Age Group</td>
                    <td style="padding:10px 12px; background:#fbfdff; color:#64748b; font-size:12px; font-weight:700;">Class</td>
                    <td style="padding:10px 12px; background:#fbfdff; color:#64748b; font-size:12px; font-weight:700;">Experience</td>
                    <td style="padding:10px 12px; background:#fbfdff; color:#64748b; font-size:12px; font-weight:700;">Discount</td>
                    <td style="padding:10px 12px; background:#fbfdff; color:#64748b; font-size:12px; font-weight:700; text-align:right;">Estimate</td>
                  </tr>
                  {$athleteRows}
                </table>

                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border:1px solid #dbe4f0; border-radius:10px; background:#f8fbff; margin-bottom:14px;">
                  <tr><td style="padding:12px 14px; color:#0f172a; font-size:14px; font-weight:700;">Estimate Summary</td></tr>
                  <tr><td style="padding:0 14px 14px;">
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                      <tr><td style="padding:0 0 6px 0; color:#334155; font-size:13px;">Athletes</td><td style="padding:0 0 6px 0; color:#0f172a; font-size:13px; font-weight:700; text-align:right;">{$totalAthletes}</td></tr>
                      <tr><td style="padding:0 0 6px 0; color:#334155; font-size:13px;">Base subtotal</td><td style="padding:0 0 6px 0; color:#0f172a; font-size:13px; text-align:right;">{$subtotal}</td></tr>
                      <tr><td style="padding:0 0 6px 0; color:#334155; font-size:13px;">Discounts</td><td style="padding:0 0 6px 0; color:#0f172a; font-size:13px; text-align:right;">-{$discount}</td></tr>
                      <tr><td style="padding:2px 0 0 0; color:#0f172a; font-size:14px; font-weight:800;">Estimated total (before tax)</td><td style="padding:2px 0 0 0; color:#c8102e; font-size:15px; font-weight:800; text-align:right;">{$total}</td></tr>
                    </table>
                  </td></tr>
                </table>

                <div style="color:#334155; font-size:14px; line-height:1.5; margin-bottom:12px;"><strong>Notes:</strong> {$notes}</div>

                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:6px;">
                  <tr><td style="padding:0 0 6px 0; color:#64748b; font-size:12px;">Submitted (UTC): {$submittedAt}</td></tr>
                  <tr><td style="padding:0 0 6px 0; color:#64748b; font-size:12px;">IP: {$ipAddress}</td></tr>
                  <tr><td style="padding:0; color:#64748b; font-size:12px; word-break:break-word;">User Agent: {$userAgent}</td></tr>
                </table>

                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:16px;">
                  <tr>
                    <td style="border-radius:8px; background:#c8102e;"><a href="mailto:{$email}" style="display:inline-block; padding:10px 14px; color:#ffffff; text-decoration:none; font-weight:700; font-size:13px;">Reply to Parent</a></td>
                    <td style="width:8px;"></td>
                    <td style="border-radius:8px; background:#081a34;"><a href="{$siteUrlEsc}/contact.html" style="display:inline-block; padding:10px 14px; color:#ffffff; text-decoration:none; font-weight:700; font-size:13px;">Open Register Page</a></td>
                  </tr>
                </table>
              </td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
  </body>
</html>
HTML;
}

function format_money(float $value): string
{
    return '$' . number_format($value, 2, '.', ',');
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function log_registration_error(string $message): void
{
    $line = '[' . gmdate('c') . '] ' . $message . PHP_EOL;
    @file_put_contents(REGISTRATION_INTEREST_ERROR_LOG, $line, FILE_APPEND | LOCK_EX);
}
