<?php
declare(strict_types=1);

require_once __DIR__ . '/waitlist-config.php';
require_once __DIR__ . '/smtp-mailer.php';

const CONTACT_ADMIN_EMAIL = 'gmunch@united-wc.com';
const CONTACT_STORAGE_CSV = __DIR__ . '/data/contact_messages.csv';
const CONTACT_ERROR_LOG = __DIR__ . '/data/contact_messages_errors.log';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect_with_status('error', 'Invalid request method.');
}

if (!empty($_POST['website'] ?? '')) {
    redirect_with_status('success');
}

$contactName = clean_text((string) ($_POST['contact_name'] ?? ''));
$contactEmail = clean_email((string) ($_POST['contact_email'] ?? ''));
$contactPhone = clean_phone((string) ($_POST['contact_phone'] ?? ''));
$topic = clean_text((string) ($_POST['contact_topic'] ?? ''));
$athleteName = clean_text((string) ($_POST['athlete_name'] ?? ''));
$preferredReply = clean_text((string) ($_POST['preferred_reply_method'] ?? ''));
$message = clean_multiline_text((string) ($_POST['contact_message'] ?? ''));
$submissionSource = clean_text((string) ($_POST['submission_source'] ?? 'contact-support-form'));

if ($contactName === '' || $contactEmail === '' || $topic === '' || $message === '') {
    redirect_with_status('error', 'Please complete all required fields.');
}

if (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
    redirect_with_status('error', 'Please enter a valid email address.');
}

$allowedTopics = [
    'registration' => 'Registration and enrollment',
    'program-levels' => 'Program levels and placement',
    'schedule-location' => 'Schedule, location, parking, and entry',
    'payment-billing' => 'Payment and billing',
    'usaw-membership' => 'USA Wrestling membership',
    'private-lessons' => 'Private lessons',
    'general' => 'General question',
    'other' => 'Other / Not listed',
];

if (!array_key_exists($topic, $allowedTopics)) {
    redirect_with_status('error', 'Please choose a valid topic.');
}

$allowedReplyMethods = ['', 'email', 'phone', 'text'];
if (!in_array($preferredReply, $allowedReplyMethods, true)) {
    redirect_with_status('error', 'Please choose a valid preferred reply method.');
}

if (strlen($message) < 10) {
    redirect_with_status('error', 'Please provide more detail in your message.');
}

if (strlen($message) > 5000) {
    redirect_with_status('error', 'Your message is too long. Please keep it under 5,000 characters.');
}

$submission = [
    'submitted_at_utc' => gmdate('c'),
    'submission_source' => $submissionSource !== '' ? $submissionSource : 'contact-support-form',
    'contact_name' => $contactName,
    'contact_email' => $contactEmail,
    'contact_phone' => $contactPhone,
    'athlete_name' => $athleteName,
    'topic_value' => $topic,
    'topic_label' => $allowedTopics[$topic],
    'preferred_reply_method' => $preferredReply,
    'message' => $message,
    'ip_address' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
];

if (!save_submission_csv(CONTACT_STORAGE_CSV, $submission)) {
    log_contact_error('Could not save contact form submission for ' . $contactEmail);
    redirect_with_status('error', 'We could not save your message right now. Please try again.');
}

$adminMailSent = send_admin_notification($submission);
$senderMailSent = send_sender_confirmation($submission);

if (!$adminMailSent) {
    log_contact_error('Admin contact email failed for ' . $contactEmail);
}

if (!$senderMailSent) {
    log_contact_error('Sender confirmation email failed for ' . $contactEmail);
}

if ($adminMailSent && $senderMailSent) {
    redirect_with_status('success');
}

redirect_with_status('partial');

function redirect_with_status(string $status, string $message = ''): void
{
    $location = 'contact.html?contact_status=' . rawurlencode($status);
    if ($message !== '') {
        $location .= '&contact_msg=' . rawurlencode($message);
    }
    $location .= '#contact-us';
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

function clean_multiline_text(string $value): string
{
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[^\P{C}\n\t]/u', '', $value) ?? $value;
    $value = trim($value);
    $value = preg_replace("/\n{3,}/", "\n\n", $value) ?? $value;
    return $value;
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

function log_contact_error(string $message): void
{
    $line = '[' . gmdate('c') . '] ' . $message . PHP_EOL;
    @file_put_contents(CONTACT_ERROR_LOG, $line, FILE_APPEND);
}

function send_admin_notification(array $submission): bool
{
    $subject = 'New UWC Contact Message - ' . $submission['topic_label'] . ' - ' . $submission['contact_name'];

    $lines = [
        'A new message was submitted from the UWC Contact Us form.',
        '',
        'Name: ' . $submission['contact_name'],
        'Email: ' . $submission['contact_email'],
        'Phone: ' . ($submission['contact_phone'] !== '' ? $submission['contact_phone'] : '(not provided)'),
        'Athlete Name: ' . ($submission['athlete_name'] !== '' ? $submission['athlete_name'] : '(not provided)'),
        'Topic: ' . $submission['topic_label'],
        'Preferred Reply Method: ' . ($submission['preferred_reply_method'] !== '' ? ucfirst((string) $submission['preferred_reply_method']) : '(not specified)'),
        '',
        'Message:',
        $submission['message'],
        '',
        'Submitted (UTC): ' . $submission['submitted_at_utc'],
        'IP: ' . $submission['ip_address'],
        'User Agent: ' . $submission['user_agent'],
    ];

    return send_mail_message(
        CONTACT_ADMIN_EMAIL,
        $subject,
        implode("\n", $lines),
        build_admin_notification_html($submission),
        ['Reply-To: ' . $submission['contact_email']]
    );
}

function send_sender_confirmation(array $submission): bool
{
    $subject = 'We received your message - United Wrestling Club';
    $siteUrl = defined('WAITLIST_SITE_URL') ? WAITLIST_SITE_URL : 'https://united-wc.com';

    $lines = [
        'Thank you for contacting United Wrestling Club.',
        '',
        'We received your message and our team will follow up soon.',
        '',
        'Message summary',
        '- Name: ' . $submission['contact_name'],
        '- Topic: ' . $submission['topic_label'],
        '- Athlete: ' . ($submission['athlete_name'] !== '' ? $submission['athlete_name'] : '(not provided)'),
        '',
        'Your message:',
        $submission['message'],
        '',
        'For urgent questions, contact gmunch@united-wc.com',
        (defined('WAITLIST_CONTACT_PHONE') && WAITLIST_CONTACT_PHONE !== '' ? 'Phone / Text: ' . WAITLIST_CONTACT_PHONE : ''),
        'Website: ' . $siteUrl,
    ];

    $bodyLines = array_values(array_filter($lines, static fn ($line) => $line !== ''));

    return send_mail_message(
        $submission['contact_email'],
        $subject,
        implode("\n", $bodyLines),
        build_sender_confirmation_html($submission),
        ['Reply-To: gmunch@united-wc.com']
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
    $fromName = defined('WAITLIST_FROM_NAME') ? WAITLIST_FROM_NAME : 'United Wrestling Club';
    $fromEmail = defined('WAITLIST_FROM_EMAIL') ? WAITLIST_FROM_EMAIL : 'noreply@united-wc.com';

    $headers = [
        'MIME-Version: 1.0',
        'From: ' . $fromName . ' <' . $fromEmail . '>',
    ];

    foreach ($extraHeaders as $header) {
        if ($header !== '') {
            $headers[] = $header;
        }
    }

    if ($htmlBody === null || trim($htmlBody) === '') {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        return uwc_transport_mail($to, $subject, $plainTextBody, implode("\r\n", $headers));
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

    return uwc_transport_mail($to, $subject, $message, implode("\r\n", $headers));
}

function build_admin_notification_html(array $submission): string
{
    $siteUrl = defined('WAITLIST_SITE_URL') ? WAITLIST_SITE_URL : 'https://united-wc.com';
    $logoUrl = defined('WAITLIST_LOGO_URL') ? WAITLIST_LOGO_URL : rtrim($siteUrl, '/') . '/assets/uwc-logo.png';

    $name = e($submission['contact_name']);
    $email = e($submission['contact_email']);
    $phone = $submission['contact_phone'] !== '' ? e($submission['contact_phone']) : '<em style="color:#64748b;">(not provided)</em>';
    $athlete = $submission['athlete_name'] !== '' ? e($submission['athlete_name']) : '<em style="color:#64748b;">(not provided)</em>';
    $topic = e($submission['topic_label']);
    $replyMethod = $submission['preferred_reply_method'] !== '' ? e(ucfirst((string) $submission['preferred_reply_method'])) : '(not specified)';
    $message = nl2br(e($submission['message']));
    $submittedAt = e($submission['submitted_at_utc']);
    $ipAddress = e($submission['ip_address']);
    $userAgent = e($submission['user_agent']);
    $logoUrlEsc = e($logoUrl);

    return <<<HTML
<!doctype html>
<html lang="en">
  <body style="margin:0; padding:0; background:#eef2f7; font-family:Arial, Helvetica, sans-serif; color:#0f172a;">
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="padding:20px 12px;">
      <tr>
        <td align="center">
          <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:720px; background:#ffffff; border:1px solid #dbe4f0; border-radius:14px; overflow:hidden;">
            <tr>
              <td style="background:#081a34; padding:20px 24px; border-bottom:3px solid #c8102e;">
                <img src="{$logoUrlEsc}" alt="United Wrestling Club" width="220" style="display:block; width:220px; max-width:100%; height:auto;" />
                <div style="margin-top:10px; color:#dbeafe; font-size:13px; letter-spacing:0.08em; text-transform:uppercase;">New Contact Message</div>
              </td>
            </tr>
            <tr>
              <td style="padding:22px 24px;">
                <h1 style="margin:0 0 14px 0; font-size:22px; color:#0f172a;">Contact form submission</h1>

                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border:1px solid #dbe4f0; border-radius:10px; background:#f8fbff; margin-bottom:14px;">
                  <tr><td style="padding:14px 16px;">
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                      <tr><td style="padding:0 0 8px 0; color:#64748b; font-size:13px; width:36%;">Name</td><td style="padding:0 0 8px 0; color:#0f172a; font-size:14px; font-weight:600;">{$name}</td></tr>
                      <tr><td style="padding:0 0 8px 0; color:#64748b; font-size:13px;">Email</td><td style="padding:0 0 8px 0; font-size:14px;"><a href="mailto:{$email}" style="color:#1d4ed8; text-decoration:none;">{$email}</a></td></tr>
                      <tr><td style="padding:0 0 8px 0; color:#64748b; font-size:13px;">Phone</td><td style="padding:0 0 8px 0; color:#0f172a; font-size:14px;">{$phone}</td></tr>
                      <tr><td style="padding:0 0 8px 0; color:#64748b; font-size:13px;">Athlete</td><td style="padding:0 0 8px 0; color:#0f172a; font-size:14px;">{$athlete}</td></tr>
                      <tr><td style="padding:0 0 8px 0; color:#64748b; font-size:13px;">Topic</td><td style="padding:0 0 8px 0; color:#0f172a; font-size:14px;">{$topic}</td></tr>
                      <tr><td style="padding:0; color:#64748b; font-size:13px;">Preferred reply</td><td style="padding:0; color:#0f172a; font-size:14px;">{$replyMethod}</td></tr>
                    </table>
                  </td></tr>
                </table>

                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border:1px solid #dbe4f0; border-radius:10px; background:#ffffff; margin-bottom:14px;">
                  <tr><td style="padding:14px 16px; color:#0f172a; font-size:14px; font-weight:700;">Message</td></tr>
                  <tr><td style="padding:0 16px 16px; color:#334155; font-size:14px; line-height:1.55;">{$message}</td></tr>
                </table>

                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                  <tr><td style="padding:0 0 6px 0; color:#64748b; font-size:12px;">Submitted (UTC): {$submittedAt}</td></tr>
                  <tr><td style="padding:0 0 6px 0; color:#64748b; font-size:12px;">IP: {$ipAddress}</td></tr>
                  <tr><td style="padding:0; color:#64748b; font-size:12px; word-break:break-word;">User Agent: {$userAgent}</td></tr>
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

function build_sender_confirmation_html(array $submission): string
{
    $siteUrl = defined('WAITLIST_SITE_URL') ? WAITLIST_SITE_URL : 'https://united-wc.com';
    $logoUrl = defined('WAITLIST_LOGO_URL') ? WAITLIST_LOGO_URL : rtrim($siteUrl, '/') . '/assets/uwc-logo.png';
    $contactPhone = defined('WAITLIST_CONTACT_PHONE') ? trim((string) WAITLIST_CONTACT_PHONE) : '';

    $name = e($submission['contact_name']);
    $topic = e($submission['topic_label']);
    $athlete = $submission['athlete_name'] !== '' ? e($submission['athlete_name']) : 'Not provided';
    $message = nl2br(e($submission['message']));
    $siteUrlEsc = e($siteUrl);
    $logoUrlEsc = e($logoUrl);

    $phoneRow = '';
    if ($contactPhone !== '') {
        $phoneRow = '<tr><td style="padding:0 0 6px 0; color:#334155; font-size:14px; line-height:1.4;"><strong style="color:#0f172a;">Phone / Text:</strong> ' . e($contactPhone) . '</td></tr>';
    }

    return <<<HTML
<!doctype html>
<html lang="en">
  <body style="margin:0; padding:0; background:#eef2f7; font-family:Arial, Helvetica, sans-serif; color:#0f172a;">
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="padding:20px 12px;">
      <tr>
        <td align="center">
          <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:700px; background:#ffffff; border:1px solid #dbe4f0; border-radius:14px; overflow:hidden;">
            <tr>
              <td style="background:#081a34; padding:22px 24px; border-bottom:3px solid #c8102e;">
                <img src="{$logoUrlEsc}" alt="United Wrestling Club" width="220" style="display:block; width:220px; max-width:100%; height:auto;" />
                <div style="margin-top:10px; color:#dbeafe; font-size:13px; letter-spacing:0.08em; text-transform:uppercase;">Message Received</div>
              </td>
            </tr>
            <tr>
              <td style="padding:24px;">
                <h1 style="margin:0 0 8px 0; font-size:24px; line-height:1.15; color:#0f172a;">Thank you for contacting us</h1>
                <p style="margin:0 0 14px 0; color:#334155; font-size:15px; line-height:1.55;">
                  Thank you, {$name}. We received your message and our team will follow up soon.
                </p>

                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border:1px solid #dbe4f0; border-radius:10px; background:#f8fbff; margin:0 0 16px 0;">
                  <tr><td style="padding:14px 16px; border-bottom:1px solid #e5edf7; font-weight:700; color:#0f172a;">Your message summary</td></tr>
                  <tr><td style="padding:12px 16px;">
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                      <tr><td style="padding:0 0 8px 0; color:#64748b; font-size:13px; width:32%;">Topic</td><td style="padding:0 0 8px 0; color:#0f172a; font-size:14px; font-weight:600;">{$topic}</td></tr>
                      <tr><td style="padding:0 0 8px 0; color:#64748b; font-size:13px;">Athlete</td><td style="padding:0 0 8px 0; color:#0f172a; font-size:14px;">{$athlete}</td></tr>
                      <tr><td style="padding:0; color:#64748b; font-size:13px; vertical-align:top;">Message</td><td style="padding:0; color:#334155; font-size:14px; line-height:1.5;">{$message}</td></tr>
                    </table>
                  </td></tr>
                </table>

                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 16px 0;">
                  <tr><td style="padding:0 0 8px 0; color:#0f172a; font-size:15px; font-weight:700;">Need immediate help?</td></tr>
                  <tr>
                    <td style="padding:0 0 6px 0; color:#334155; font-size:14px; line-height:1.4;"><strong style="color:#0f172a;">Email:</strong> <a href="mailto:gmunch@united-wc.com" style="color:#1d4ed8; text-decoration:none;">gmunch@united-wc.com</a></td>
                  </tr>
                  {$phoneRow}
                  <tr>
                    <td style="padding:2px 0 0 0; color:#334155; font-size:14px; line-height:1.4;"><strong style="color:#0f172a;">Website:</strong> <a href="{$siteUrlEsc}" style="color:#1d4ed8; text-decoration:none;">{$siteUrlEsc}</a></td>
                  </tr>
                </table>

                <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                  <tr>
                    <td style="border-radius:8px; background:#c8102e;"><a href="{$siteUrlEsc}/contact.html" style="display:inline-block; padding:10px 14px; color:#ffffff; text-decoration:none; font-weight:700; font-size:13px;">Back to Register</a></td>
                    <td style="width:8px;"></td>
                    <td style="border-radius:8px; background:#081a34;"><a href="{$siteUrlEsc}/program.html" style="display:inline-block; padding:10px 14px; color:#ffffff; text-decoration:none; font-weight:700; font-size:13px;">View Program Levels</a></td>
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
