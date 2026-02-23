<?php
declare(strict_types=1);

// UWC waitlist form endpoint
// - saves submissions to a CSV file
// - emails the club (notification)
// - emails the parent/guardian (confirmation)

require_once __DIR__ . '/waitlist-config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect_with_status('error', 'Invalid request method.');
}

// Simple honeypot spam trap (hidden field should stay empty)
if (!empty($_POST['website'] ?? '')) {
    redirect_with_status('success');
}

$guardianName = clean_text($_POST['guardian_name'] ?? '');
$email = clean_email($_POST['email'] ?? '');
$ageGroup = clean_text($_POST['athlete_age_group'] ?? '');
$classInterest = clean_text($_POST['class_interest'] ?? '');
$notes = clean_text($_POST['notes'] ?? '');

if ($guardianName === '' || $email === '' || $ageGroup === '' || $classInterest === '') {
    redirect_with_status('error', 'Please complete all required fields.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirect_with_status('error', 'Please enter a valid email address.');
}

$allowedAgeGroups = ['5-10', '11-13', '14-plus'];
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

if (!in_array($ageGroup, $allowedAgeGroups, true)) {
    redirect_with_status('error', 'Invalid age group selection.');
}

if (!array_key_exists($classInterest, $allowedClasses)) {
    redirect_with_status('error', 'Invalid class selection.');
}

if (!in_array($classInterest, $allowedClassesByAge[$ageGroup], true)) {
    redirect_with_status('error', 'Selected class does not match the selected age group.');
}

$ageLabelMap = [
    '5-10' => 'Ages 5-10',
    '11-13' => 'Ages 11-13',
    '14-plus' => 'Ages 14+',
];

$submission = [
    'submitted_at_utc' => gmdate('c'),
    'guardian_name' => $guardianName,
    'email' => $email,
    'athlete_age_group' => $ageLabelMap[$ageGroup] ?? $ageGroup,
    'class_interest' => $allowedClasses[$classInterest],
    'notes' => $notes,
    'ip_address' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
];

$saved = save_submission_csv(WAITLIST_STORAGE_CSV, $submission);
if (!$saved) {
    log_waitlist_error('Could not save waitlist submission to CSV.');
    redirect_with_status('error', 'We could not save your request. Please try again.');
}

$clubMailSent = send_club_notification($submission);
$parentMailSent = send_parent_confirmation($submission);

if (!$clubMailSent) {
    log_waitlist_error('Club notification email failed for ' . $email);
}

if (!$parentMailSent) {
    log_waitlist_error('Parent confirmation email failed for ' . $email);
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
            fclose($handle);
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

function send_club_notification(array $submission): bool
{
    $subject = 'New UWC Waitlist Submission - ' . $submission['guardian_name'];
    $bodyLines = [
        'A new UWC waitlist request was submitted.',
        '',
        'Parent / Guardian: ' . $submission['guardian_name'],
        'Email: ' . $submission['email'],
        'Athlete Age Group: ' . $submission['athlete_age_group'],
        'Class Interest: ' . $submission['class_interest'],
        'Notes: ' . ($submission['notes'] !== '' ? $submission['notes'] : '(none)'),
        '',
        'Submitted (UTC): ' . $submission['submitted_at_utc'],
        'IP: ' . $submission['ip_address'],
        'User Agent: ' . $submission['user_agent'],
    ];

    return send_plain_text_mail(
        WAITLIST_ADMIN_EMAIL,
        $subject,
        implode("\n", $bodyLines),
        [
            'Reply-To: ' . $submission['email'],
        ]
    );
}

function send_parent_confirmation(array $submission): bool
{
    $subject = 'UWC Waitlist Confirmation - Spring Session 2026';

    $bodyLines = [
        'Thank you for joining the United Wrestling Club waitlist for Spring Session 2026.',
        '',
        'We received your request and will contact families when registration opens.',
        '',
        'Your submission:',
        '- Parent / Guardian: ' . $submission['guardian_name'],
        '- Athlete Age Group: ' . $submission['athlete_age_group'],
        '- Class Interest: ' . $submission['class_interest'],
        '',
        'Questions?',
        'Contact us at: ' . WAITLIST_CONTACT_EMAIL,
        (WAITLIST_CONTACT_PHONE !== '' ? 'Phone / Text: ' . WAITLIST_CONTACT_PHONE : ''),
        '',
        'United Wrestling Club',
        'Spring Session 2026',
        'Saint Edmond\'s Academy',
        '',
        'Better United.',
    ];

    $bodyLines = array_values(array_filter($bodyLines, static fn ($line) => $line !== ''));

    return send_plain_text_mail(
        $submission['email'],
        $subject,
        implode("\n", $bodyLines),
        [
            'Reply-To: ' . WAITLIST_CONTACT_EMAIL,
        ]
    );
}

function send_plain_text_mail(string $to, string $subject, string $body, array $extraHeaders = []): bool
{
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . WAITLIST_FROM_NAME . ' <' . WAITLIST_FROM_EMAIL . '>',
    ];

    foreach ($extraHeaders as $header) {
        if ($header !== '') {
            $headers[] = $header;
        }
    }

    return @mail($to, $subject, $body, implode("\r\n", $headers));
}

function log_waitlist_error(string $message): void
{
    $line = '[' . gmdate('c') . '] ' . $message . PHP_EOL;
    @file_put_contents(WAITLIST_ERROR_LOG, $line, FILE_APPEND | LOCK_EX);
}

