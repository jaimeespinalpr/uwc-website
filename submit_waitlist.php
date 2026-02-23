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

    return send_mail_message(
        WAITLIST_ADMIN_EMAIL,
        $subject,
        implode("\n", $bodyLines),
        build_club_notification_html($submission),
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
        ((defined('WAITLIST_CONTACT_NAME') && WAITLIST_CONTACT_NAME !== '')
            ? 'Contact: ' . WAITLIST_CONTACT_NAME . (
                (defined('WAITLIST_CONTACT_TITLE') && WAITLIST_CONTACT_TITLE !== '')
                    ? ' (' . WAITLIST_CONTACT_TITLE . ')'
                    : ''
              )
            : ''),
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

    return send_mail_message(
        $submission['email'],
        $subject,
        implode("\n", $bodyLines),
        build_parent_confirmation_html($submission),
        [
            'Reply-To: ' . WAITLIST_CONTACT_EMAIL,
        ]
    );
}

function send_mail_message(
    string $to,
    string $subject,
    string $plainTextBody,
    ?string $htmlBody = null,
    array $extraHeaders = []
): bool
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

function send_plain_text_mail(string $to, string $subject, string $body, array $extraHeaders = []): bool
{
    return send_mail_message($to, $subject, $body, null, $extraHeaders);
}

function build_parent_confirmation_html(array $submission): string
{
    $siteUrl = defined('WAITLIST_SITE_URL') ? WAITLIST_SITE_URL : 'https://united-wc.com';
    $logoUrl = defined('WAITLIST_LOGO_URL') ? WAITLIST_LOGO_URL : rtrim($siteUrl, '/') . '/assets/uwc-logo.png';
    $contactPhone = defined('WAITLIST_CONTACT_PHONE') ? trim((string) WAITLIST_CONTACT_PHONE) : '';
    $contactName = defined('WAITLIST_CONTACT_NAME') ? trim((string) WAITLIST_CONTACT_NAME) : '';
    $contactTitle = defined('WAITLIST_CONTACT_TITLE') ? trim((string) WAITLIST_CONTACT_TITLE) : '';

    $guardianName = e($submission['guardian_name']);
    $ageGroup = e($submission['athlete_age_group']);
    $classInterest = e($submission['class_interest']);
    $contactEmail = e(WAITLIST_CONTACT_EMAIL);
    $logoUrlEsc = e($logoUrl);
    $siteUrlEsc = e($siteUrl);

    $contactPersonRow = '';
    if ($contactName !== '') {
        $contactPersonLabel = $contactName;
        if ($contactTitle !== '') {
            $contactPersonLabel .= ' (' . $contactTitle . ')';
        }
        $contactPersonEsc = e($contactPersonLabel);
        $contactPersonRow = <<<HTML
          <tr>
            <td style="padding:0 0 6px 0; color:#334155; font-size:14px; line-height:1.4;">
              <strong style="color:#0f172a;">Contact:</strong> {$contactPersonEsc}
            </td>
          </tr>
        HTML;
    }

    $phoneRow = '';
    if ($contactPhone !== '') {
        $phoneEsc = e($contactPhone);
        $phoneRow = <<<HTML
          <tr>
            <td style="padding: 0 0 6px 0; color:#334155; font-size:14px; line-height:1.4;">
              <strong style="color:#0f172a;">Phone / Text:</strong> {$phoneEsc}
            </td>
          </tr>
        HTML;
    }

    return <<<HTML
<!doctype html>
<html lang="en">
  <body style="margin:0; padding:0; background:#eef2f7; font-family:Arial, Helvetica, sans-serif; color:#0f172a;">
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#eef2f7; margin:0; padding:24px 12px;">
      <tr>
        <td align="center">
          <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:640px; background:#ffffff; border:1px solid #dbe4f0; border-radius:14px; overflow:hidden;">
            <tr>
              <td style="background:#081a34; padding:22px 24px; border-bottom:3px solid #c8102e;">
                <img src="{$logoUrlEsc}" alt="United Wrestling Club" width="230" style="display:block; width:230px; max-width:100%; height:auto;" />
                <div style="margin-top:10px; color:#dbeafe; font-size:13px; letter-spacing:0.08em; text-transform:uppercase;">
                  Spring Session 2026 Waitlist
                </div>
              </td>
            </tr>
            <tr>
              <td style="padding:24px;">
                <h1 style="margin:0 0 10px 0; font-size:24px; line-height:1.15; color:#0f172a;">You're on the UWC Waitlist</h1>
                <p style="margin:0 0 14px 0; color:#334155; font-size:15px; line-height:1.55;">
                  Thank you for joining the United Wrestling Club waitlist for <strong>Spring Session 2026</strong>.
                  We received your request and will contact families when registration opens.
                </p>

                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border:1px solid #dbe4f0; border-radius:10px; background:#f8fbff; margin:0 0 16px 0;">
                  <tr>
                    <td style="padding:14px 16px; border-bottom:1px solid #e5edf7; font-weight:700; color:#0f172a;">
                      Submission Summary
                    </td>
                  </tr>
                  <tr>
                    <td style="padding:12px 16px;">
                      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                        <tr>
                          <td style="padding:0 0 8px 0; color:#64748b; font-size:13px; width:38%;">Parent / Guardian</td>
                          <td style="padding:0 0 8px 0; color:#0f172a; font-size:14px; font-weight:600;">{$guardianName}</td>
                        </tr>
                        <tr>
                          <td style="padding:0 0 8px 0; color:#64748b; font-size:13px;">Athlete Age Group</td>
                          <td style="padding:0 0 8px 0; color:#0f172a; font-size:14px; font-weight:600;">{$ageGroup}</td>
                        </tr>
                        <tr>
                          <td style="padding:0; color:#64748b; font-size:13px;">Class Interest</td>
                          <td style="padding:0; color:#0f172a; font-size:14px; font-weight:600;">{$classInterest}</td>
                        </tr>
                      </table>
                    </td>
                  </tr>
                </table>

                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border:1px solid #ecd4da; border-radius:10px; background:#fff7f9; margin:0 0 16px 0;">
                  <tr>
                    <td style="padding:14px 16px;">
                      <div style="color:#7f1d1d; font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; margin-bottom:6px;">What happens next</div>
                      <div style="color:#334155; font-size:14px; line-height:1.55;">
                        Families on the waitlist will receive updates when registration opens, including next steps and enrollment details.
                      </div>
                    </td>
                  </tr>
                </table>

                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 16px 0;">
                  <tr>
                    <td style="padding:0 0 8px 0; color:#0f172a; font-size:15px; font-weight:700;">Questions?</td>
                  </tr>
                  {$contactPersonRow}
                  <tr>
                    <td style="padding:0 0 6px 0; color:#334155; font-size:14px; line-height:1.4;">
                      <strong style="color:#0f172a;">Email:</strong> {$contactEmail}
                    </td>
                  </tr>
                  {$phoneRow}
                  <tr>
                    <td style="padding:2px 0 0 0; color:#334155; font-size:14px; line-height:1.4;">
                      <strong style="color:#0f172a;">Website:</strong> <a href="{$siteUrlEsc}" style="color:#1d4ed8; text-decoration:none;">{$siteUrlEsc}</a>
                    </td>
                  </tr>
                </table>

                <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                  <tr>
                    <td style="border-radius:8px; background:#c8102e;">
                      <a href="{$siteUrlEsc}/program.html" style="display:inline-block; padding:10px 14px; color:#ffffff; text-decoration:none; font-weight:700; font-size:13px; letter-spacing:0.03em;">
                        View Program Levels
                      </a>
                    </td>
                    <td style="width:8px;"></td>
                    <td style="border-radius:8px; background:#081a34;">
                      <a href="{$siteUrlEsc}/team.html" style="display:inline-block; padding:10px 14px; color:#ffffff; text-decoration:none; font-weight:700; font-size:13px; letter-spacing:0.03em;">
                        Meet the Coaches
                      </a>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
            <tr>
              <td style="padding:14px 24px 18px; border-top:1px solid #e2e8f0; background:#fbfdff;">
                <div style="color:#475569; font-size:12px; line-height:1.5;">
                  United Wrestling Club • Spring Session 2026 • Saint Edmond's Academy
                </div>
                <div style="margin-top:4px; color:#0f172a; font-size:12px; font-weight:700; letter-spacing:0.04em; text-transform:uppercase;">
                  Better United.
                </div>
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

function build_club_notification_html(array $submission): string
{
    $siteUrl = defined('WAITLIST_SITE_URL') ? WAITLIST_SITE_URL : 'https://united-wc.com';
    $logoUrl = defined('WAITLIST_LOGO_URL') ? WAITLIST_LOGO_URL : rtrim($siteUrl, '/') . '/assets/uwc-logo.png';

    $guardianName = e($submission['guardian_name']);
    $email = e($submission['email']);
    $ageGroup = e($submission['athlete_age_group']);
    $classInterest = e($submission['class_interest']);
    $notes = $submission['notes'] !== '' ? nl2br(e($submission['notes'])) : '<em style="color:#64748b;">(none)</em>';
    $submittedAt = e($submission['submitted_at_utc']);
    $ipAddress = e($submission['ip_address']);
    $userAgent = e($submission['user_agent']);
    $logoUrlEsc = e($logoUrl);
    $siteUrlEsc = e($siteUrl);

    return <<<HTML
<!doctype html>
<html lang="en">
  <body style="margin:0; padding:0; background:#eef2f7; font-family:Arial, Helvetica, sans-serif; color:#0f172a;">
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#eef2f7; margin:0; padding:20px 12px;">
      <tr>
        <td align="center">
          <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:700px; background:#ffffff; border:1px solid #dbe4f0; border-radius:14px; overflow:hidden;">
            <tr>
              <td style="background:#081a34; padding:20px 24px; border-bottom:3px solid #c8102e;">
                <img src="{$logoUrlEsc}" alt="United Wrestling Club" width="220" style="display:block; width:220px; max-width:100%; height:auto;" />
                <div style="margin-top:10px; color:#dbeafe; font-size:13px; letter-spacing:0.08em; text-transform:uppercase;">
                  New Waitlist Submission
                </div>
              </td>
            </tr>
            <tr>
              <td style="padding:22px 24px;">
                <h1 style="margin:0 0 12px 0; font-size:22px; color:#0f172a;">New UWC Waitlist Request</h1>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border:1px solid #dbe4f0; border-radius:10px; background:#f8fbff;">
                  <tr>
                    <td style="padding:14px 16px;">
                      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                        <tr>
                          <td style="padding:0 0 8px 0; color:#64748b; font-size:13px; width:34%;">Parent / Guardian</td>
                          <td style="padding:0 0 8px 0; color:#0f172a; font-size:14px; font-weight:600;">{$guardianName}</td>
                        </tr>
                        <tr>
                          <td style="padding:0 0 8px 0; color:#64748b; font-size:13px;">Email</td>
                          <td style="padding:0 0 8px 0; color:#0f172a; font-size:14px;">
                            <a href="mailto:{$email}" style="color:#1d4ed8; text-decoration:none;">{$email}</a>
                          </td>
                        </tr>
                        <tr>
                          <td style="padding:0 0 8px 0; color:#64748b; font-size:13px;">Athlete Age Group</td>
                          <td style="padding:0 0 8px 0; color:#0f172a; font-size:14px; font-weight:600;">{$ageGroup}</td>
                        </tr>
                        <tr>
                          <td style="padding:0 0 8px 0; color:#64748b; font-size:13px;">Class Interest</td>
                          <td style="padding:0 0 8px 0; color:#0f172a; font-size:14px; font-weight:600;">{$classInterest}</td>
                        </tr>
                        <tr>
                          <td style="padding:0; color:#64748b; font-size:13px; vertical-align:top;">Notes</td>
                          <td style="padding:0; color:#0f172a; font-size:14px; line-height:1.45;">{$notes}</td>
                        </tr>
                      </table>
                    </td>
                  </tr>
                </table>

                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:14px;">
                  <tr>
                    <td style="padding:0 0 6px 0; color:#64748b; font-size:12px;">Submitted (UTC): {$submittedAt}</td>
                  </tr>
                  <tr>
                    <td style="padding:0 0 6px 0; color:#64748b; font-size:12px;">IP: {$ipAddress}</td>
                  </tr>
                  <tr>
                    <td style="padding:0; color:#64748b; font-size:12px; word-break:break-word;">User Agent: {$userAgent}</td>
                  </tr>
                </table>

                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:16px;">
                  <tr>
                    <td style="border-radius:8px; background:#c8102e;">
                      <a href="mailto:{$email}" style="display:inline-block; padding:10px 14px; color:#ffffff; text-decoration:none; font-weight:700; font-size:13px;">
                        Reply to Parent
                      </a>
                    </td>
                    <td style="width:8px;"></td>
                    <td style="border-radius:8px; background:#081a34;">
                      <a href="{$siteUrlEsc}/contact.html" style="display:inline-block; padding:10px 14px; color:#ffffff; text-decoration:none; font-weight:700; font-size:13px;">
                        Open Registration Page
                      </a>
                    </td>
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

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function log_waitlist_error(string $message): void
{
    $line = '[' . gmdate('c') . '] ' . $message . PHP_EOL;
    @file_put_contents(WAITLIST_ERROR_LOG, $line, FILE_APPEND | LOCK_EX);
}
