<?php
declare(strict_types=1);

require_once __DIR__ . '/waitlist-config.php';
require_once __DIR__ . '/stripe-config.php';
require_once __DIR__ . '/stripe-helpers.php';
require_once __DIR__ . '/smtp-mailer.php';

const STRIPE_WEBHOOK_ERROR_LOG = __DIR__ . '/data/stripe_webhook_errors.log';
const STRIPE_PAYMENT_SUCCESS_LOG = __DIR__ . '/data/stripe_payment_success.csv';
const STRIPE_PAYMENT_SUCCESS_ERRORS = __DIR__ . '/data/stripe_payment_success_errors.log';
const STRIPE_PAYMENT_EMAIL_ERRORS = __DIR__ . '/data/stripe_payment_email_errors.log';
const STRIPE_PAYMENT_EMAIL_SENT_DIR = __DIR__ . '/data/payment-email-sent';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    webhook_respond(405, ['ok' => false, 'error' => 'Method not allowed']);
}

$payload = file_get_contents('php://input');
if (!is_string($payload) || $payload === '') {
    webhook_log_error('Empty webhook payload.');
    webhook_respond(400, ['ok' => false, 'error' => 'Empty payload']);
}

$sigHeader = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
if (!defined('STRIPE_WEBHOOK_SECRET') || trim((string) STRIPE_WEBHOOK_SECRET) === '') {
    webhook_log_error('STRIPE_WEBHOOK_SECRET is not configured.');
    webhook_respond(500, ['ok' => false, 'error' => 'Webhook secret not configured']);
}

if (!stripe_verify_webhook_signature($payload, $sigHeader, (string) STRIPE_WEBHOOK_SECRET, 300)) {
    webhook_log_error('Invalid Stripe webhook signature.');
    webhook_respond(400, ['ok' => false, 'error' => 'Invalid signature']);
}

$event = json_decode($payload, true);
if (!is_array($event)) {
    webhook_log_error('Invalid JSON payload.');
    webhook_respond(400, ['ok' => false, 'error' => 'Invalid JSON']);
}

$eventType = (string) ($event['type'] ?? '');
if ($eventType !== 'checkout.session.completed') {
    webhook_respond(200, ['ok' => true, 'ignored' => true, 'type' => $eventType]);
}

$session = $event['data']['object'] ?? null;
if (!is_array($session)) {
    webhook_log_error('Webhook checkout.session.completed missing data.object');
    webhook_respond(400, ['ok' => false, 'error' => 'Missing session object']);
}

$sessionId = trim((string) ($session['id'] ?? ''));
if ($sessionId === '') {
    webhook_log_error('Webhook session missing id');
    webhook_respond(400, ['ok' => false, 'error' => 'Missing session id']);
}

$paymentStatus = (string) ($session['payment_status'] ?? '');
$submissionId = trim((string) ($session['client_reference_id'] ?? ((string) ($session['metadata']['submission_id'] ?? ''))));

if ($paymentStatus !== 'paid') {
    webhook_log_error('Webhook checkout.session.completed but payment_status=' . $paymentStatus . ' for session ' . $sessionId);
    webhook_respond(200, [
        'ok' => true,
        'received' => true,
        'paid' => false,
        'session_id' => $sessionId,
        'payment_status' => $paymentStatus,
    ]);
}

record_paid_session_webhook($session, $submissionId);
$emailStatus = send_payment_confirmation_emails_if_needed_webhook($session, $submissionId);

webhook_respond(200, [
    'ok' => true,
    'type' => $eventType,
    'session_id' => $sessionId,
    'submission_id' => $submissionId,
    'payment_status' => $paymentStatus,
    'email_status' => $emailStatus,
]);

function stripe_verify_webhook_signature(string $payload, string $signatureHeader, string $secret, int $toleranceSeconds = 300): bool
{
    if ($signatureHeader === '' || $secret === '') {
        return false;
    }

    $parts = [];
    foreach (explode(',', $signatureHeader) as $piece) {
        $piece = trim($piece);
        if ($piece === '' || strpos($piece, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $piece, 2);
        $parts[$k][] = $v;
    }

    $timestamp = isset($parts['t'][0]) ? (int) $parts['t'][0] : 0;
    $signatures = $parts['v1'] ?? [];
    if ($timestamp <= 0 || empty($signatures)) {
        return false;
    }

    if ($toleranceSeconds > 0 && abs(time() - $timestamp) > $toleranceSeconds) {
        return false;
    }

    $signedPayload = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signedPayload, $secret);
    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) {
            return true;
        }
    }

    return false;
}

function webhook_respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function webhook_log_error(string $message): void
{
    $line = '[' . gmdate('c') . '] ' . $message . PHP_EOL;
    @file_put_contents(STRIPE_WEBHOOK_ERROR_LOG, $line, FILE_APPEND);
}

function record_paid_session_webhook(array $session, string $submissionId): void
{
    $sessionId = (string) ($session['id'] ?? '');
    if ($sessionId === '') {
        return;
    }

    $csvPath = STRIPE_PAYMENT_SUCCESS_LOG;
    $dir = dirname($csvPath);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return;
    }

    if (stripe_session_already_logged_webhook($csvPath, $sessionId)) {
        return;
    }

    $record = [
        'logged_at_utc' => gmdate('c'),
        'stripe_mode' => stripe_mode_label(),
        'session_id' => $sessionId,
        'submission_id' => $submissionId !== '' ? $submissionId : (string) ($session['client_reference_id'] ?? ''),
        'payment_status' => (string) ($session['payment_status'] ?? ''),
        'status' => (string) ($session['status'] ?? ''),
        'amount_total' => (string) ($session['amount_total'] ?? ''),
        'currency' => (string) ($session['currency'] ?? ''),
        'customer_email' => (string) ($session['customer_email'] ?? (($session['customer_details']['email'] ?? ''))),
        'payment_intent_id' => is_array($session['payment_intent'] ?? null)
            ? (string) (($session['payment_intent']['id'] ?? ''))
            : (string) ($session['payment_intent'] ?? ''),
    ];

    $isNewFile = !file_exists($csvPath);
    $handle = fopen($csvPath, 'ab');
    if ($handle === false) {
        return;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return;
        }
        if ($isNewFile) {
            fputcsv($handle, array_keys($record));
        }
        fputcsv($handle, array_values($record));
        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}

function stripe_session_already_logged_webhook(string $csvPath, string $sessionId): bool
{
    if (!file_exists($csvPath)) {
        return false;
    }

    $handle = fopen($csvPath, 'rb');
    if ($handle === false) {
        return false;
    }

    try {
        $header = fgetcsv($handle);
        if (!is_array($header)) {
            return false;
        }
        $sessionIdIndex = array_search('session_id', $header, true);
        if ($sessionIdIndex === false) {
            return false;
        }
        while (($row = fgetcsv($handle)) !== false) {
            if (($row[$sessionIdIndex] ?? null) === $sessionId) {
                return true;
            }
        }
    } finally {
        fclose($handle);
    }

    return false;
}

function send_payment_confirmation_emails_if_needed_webhook(array $session, string $submissionId): string
{
    $sessionId = trim((string) ($session['id'] ?? ''));
    if ($sessionId === '') {
        return 'skipped';
    }

    if (payment_confirmation_already_sent_webhook($sessionId)) {
        return 'already-sent';
    }

    $guardianEmail = trim((string) ($session['customer_email'] ?? (($session['customer_details']['email'] ?? ''))));
    $guardianName = trim((string) ($session['metadata']['guardian_name'] ?? ''));
    $athleteCount = trim((string) ($session['metadata']['athlete_count'] ?? ''));
    $amount = format_amount_from_cents_webhook((int) ($session['amount_total'] ?? 0), (string) ($session['currency'] ?? 'usd'));
    $paymentIntentId = is_array($session['payment_intent'] ?? null)
        ? (string) (($session['payment_intent']['id'] ?? ''))
        : (string) ($session['payment_intent'] ?? '');
    $siteUrl = rtrim((defined('WAITLIST_SITE_URL') ? WAITLIST_SITE_URL : 'https://united-wc.com'), '/');
    $logoUrl = defined('WAITLIST_LOGO_URL') ? WAITLIST_LOGO_URL : $siteUrl . '/assets/uwc-logo.png';

    $modePrefix = stripe_mode_label() === 'test' ? '[TEST] ' : '';
    $parentSent = true;
    $clubSent = true;

    if ($guardianEmail !== '' && filter_var($guardianEmail, FILTER_VALIDATE_EMAIL)) {
        $parentSubject = $modePrefix . 'UWC Payment Confirmation - Spring Session 2026';
        $parentPlain = implode("\n", array_filter([
            'Thank you. Your United Wrestling Club payment was received.',
            '',
            'Parent / Guardian: ' . ($guardianName !== '' ? $guardianName : '(not provided)'),
            'Email: ' . $guardianEmail,
            'Athletes: ' . ($athleteCount !== '' ? $athleteCount : '(not available)'),
            'Amount paid: ' . $amount,
            'Submission ID: ' . ($submissionId !== '' ? $submissionId : ((string) ($session['client_reference_id'] ?? ''))),
            '',
            'If you need anything, contact ' . WAITLIST_CONTACT_EMAIL
                . (defined('WAITLIST_CONTACT_PHONE') && WAITLIST_CONTACT_PHONE !== '' ? ' | ' . WAITLIST_CONTACT_PHONE : ''),
            '',
            "It's Bigger Than Wrestling.",
        ]));

        $parentHtml = build_parent_payment_confirmation_html_webhook(
            $guardianName,
            $guardianEmail,
            $athleteCount,
            $amount,
            $submissionId !== '' ? $submissionId : ((string) ($session['client_reference_id'] ?? '')),
            $logoUrl,
            $siteUrl
        );

        $parentSent = payment_send_mail_message_webhook(
            $guardianEmail,
            $parentSubject,
            $parentPlain,
            $parentHtml,
            ['Reply-To: ' . WAITLIST_CONTACT_EMAIL]
        );
    }

    $clubSubject = $modePrefix . 'UWC Registration Payment Received' . ($guardianName !== '' ? ' - ' . $guardianName : '');
    $clubPlain = implode("\n", array_filter([
        'Stripe checkout payment completed (webhook).',
        '',
        'Parent / Guardian: ' . ($guardianName !== '' ? $guardianName : '(not provided)'),
        'Parent Email: ' . ($guardianEmail !== '' ? $guardianEmail : '(not provided)'),
        'Athletes: ' . ($athleteCount !== '' ? $athleteCount : '(not available)'),
        'Amount paid: ' . $amount,
        'Submission ID: ' . ($submissionId !== '' ? $submissionId : ((string) ($session['client_reference_id'] ?? ''))),
        'Stripe Session ID: ' . $sessionId,
        'Payment Intent ID: ' . ($paymentIntentId !== '' ? $paymentIntentId : '(not available)'),
        'Stripe Mode: ' . stripe_mode_label(),
    ]));

    $clubHtml = build_club_payment_notification_html_webhook(
        $guardianName,
        $guardianEmail,
        $athleteCount,
        $amount,
        $submissionId !== '' ? $submissionId : ((string) ($session['client_reference_id'] ?? '')),
        $sessionId,
        $paymentIntentId,
        $logoUrl
    );

    $clubHeaders = [];
    if ($guardianEmail !== '' && filter_var($guardianEmail, FILTER_VALIDATE_EMAIL)) {
        $clubHeaders[] = 'Reply-To: ' . $guardianEmail;
    }
    $paymentNotificationEmail = (defined('WAITLIST_PAYMENT_EMAIL') && trim((string) WAITLIST_PAYMENT_EMAIL) !== '')
        ? (string) WAITLIST_PAYMENT_EMAIL
        : WAITLIST_ADMIN_EMAIL;

    $clubSent = payment_send_mail_message_webhook($paymentNotificationEmail, $clubSubject, $clubPlain, $clubHtml, $clubHeaders);

    if ($parentSent && $clubSent) {
        payment_confirmation_mark_sent_webhook($sessionId);
        return 'sent';
    }

    $failureParts = [];
    if (!$parentSent) {
        $failureParts[] = 'parent';
    }
    if (!$clubSent) {
        $failureParts[] = 'club';
    }
    log_payment_email_error_webhook('Webhook payment email send failed for session ' . $sessionId . ' [' . implode(',', $failureParts) . ']');
    return 'send-failed';
}

function payment_confirmation_already_sent_webhook(string $sessionId): bool
{
    return is_file(payment_confirmation_marker_path_webhook($sessionId));
}

function payment_confirmation_mark_sent_webhook(string $sessionId): void
{
    $dir = STRIPE_PAYMENT_EMAIL_SENT_DIR;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        log_payment_email_error_webhook('Could not create payment email marker dir.');
        return;
    }
    @file_put_contents(payment_confirmation_marker_path_webhook($sessionId), '[' . gmdate('c') . '] sent by webhook' . PHP_EOL);
}

function payment_confirmation_marker_path_webhook(string $sessionId): string
{
    $safe = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $sessionId) ?? 'unknown';
    return rtrim(STRIPE_PAYMENT_EMAIL_SENT_DIR, '/') . '/' . $safe . '.sent';
}

function payment_send_mail_message_webhook(string $to, string $subject, string $plainTextBody, ?string $htmlBody = null, array $extraHeaders = []): bool
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

function build_parent_payment_confirmation_html_webhook(string $guardianName, string $guardianEmail, string $athleteCount, string $amount, string $submissionId, string $logoUrl, string $siteUrl): string
{
    $guardianNameEsc = e_out_webhook($guardianName !== '' ? $guardianName : 'Parent / Guardian');
    $guardianEmailEsc = e_out_webhook($guardianEmail);
    $athleteCountEsc = e_out_webhook($athleteCount !== '' ? $athleteCount : '(not available)');
    $amountEsc = e_out_webhook($amount);
    $submissionIdEsc = e_out_webhook($submissionId !== '' ? $submissionId : '(not available)');
    $logoUrlEsc = e_out_webhook($logoUrl);
    $siteUrlEsc = e_out_webhook($siteUrl);
    $contactEmailEsc = e_out_webhook(WAITLIST_CONTACT_EMAIL);
    $contactPhone = defined('WAITLIST_CONTACT_PHONE') ? trim((string) WAITLIST_CONTACT_PHONE) : '';
    $contactPhoneRow = $contactPhone !== ''
        ? '<tr><td style="padding:0 0 6px 0; color:#334155; font-size:14px;"><strong style="color:#0f172a;">Phone / Text:</strong> ' . e_out_webhook($contactPhone) . '</td></tr>'
        : '';

    return <<<HTML
<!doctype html>
<html lang="en">
  <body style="margin:0; padding:0; background:#eef2f7; font-family:Arial, Helvetica, sans-serif; color:#0f172a;">
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#eef2f7; margin:0; padding:24px 12px;">
      <tr><td align="center">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:700px; background:#ffffff; border:1px solid #dbe4f0; border-radius:14px; overflow:hidden;">
          <tr><td style="background:#081a34; padding:22px 24px; border-bottom:3px solid #c8102e;"><img src="{$logoUrlEsc}" alt="United Wrestling Club" width="230" style="display:block; width:230px; max-width:100%; height:auto;" /><div style="margin-top:10px; color:#dbeafe; font-size:13px; letter-spacing:0.08em; text-transform:uppercase;">Payment Confirmation • Spring Session 2026</div></td></tr>
          <tr><td style="padding:24px;">
            <h1 style="margin:0 0 10px 0; font-size:24px; line-height:1.15; color:#0f172a;">Thank you. Your payment was received.</h1>
            <p style="margin:0 0 14px 0; color:#334155; font-size:15px; line-height:1.55;">Thank you, {$guardianNameEsc}. This email confirms your registration payment for United Wrestling Club Spring Session 2026.</p>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border:1px solid #dbe4f0; border-radius:10px; background:#f8fbff; margin:0 0 16px 0;">
              <tr><td style="padding:14px 16px; border-bottom:1px solid #e5edf7; font-weight:700; color:#0f172a;">Payment Summary</td></tr>
              <tr><td style="padding:12px 16px;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                  <tr><td style="padding:0 0 8px 0; color:#64748b; font-size:13px; width:42%;">Parent / Guardian</td><td style="padding:0 0 8px 0; color:#0f172a; font-size:14px; font-weight:600;">{$guardianNameEsc}</td></tr>
                  <tr><td style="padding:0 0 8px 0; color:#64748b; font-size:13px;">Email</td><td style="padding:0 0 8px 0; color:#0f172a; font-size:14px;">{$guardianEmailEsc}</td></tr>
                  <tr><td style="padding:0 0 8px 0; color:#64748b; font-size:13px;">Athletes</td><td style="padding:0 0 8px 0; color:#0f172a; font-size:14px;">{$athleteCountEsc}</td></tr>
                  <tr><td style="padding:0 0 8px 0; color:#64748b; font-size:13px;">Amount paid</td><td style="padding:0 0 8px 0; color:#c8102e; font-size:16px; font-weight:800;">{$amountEsc}</td></tr>
                  <tr><td style="padding:0; color:#64748b; font-size:13px;">Submission ID</td><td style="padding:0; color:#0f172a; font-size:13px; font-family:Menlo, Monaco, Consolas, monospace;">{$submissionIdEsc}</td></tr>
                </table>
              </td></tr>
            </table>
            <p style="margin:0 0 14px 0; color:#334155; font-size:14px; line-height:1.55;">Please keep this confirmation email (and your Stripe receipt) for your records. If you have any questions, contact us using the information below.</p>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 16px 0;">
              <tr><td style="padding:0 0 8px 0; color:#0f172a; font-size:15px; font-weight:700;">Questions?</td></tr>
              <tr><td style="padding:0 0 6px 0; color:#334155; font-size:14px;"><strong style="color:#0f172a;">Email:</strong> {$contactEmailEsc}</td></tr>
              {$contactPhoneRow}
              <tr><td style="padding:2px 0 0 0; color:#334155; font-size:14px;"><strong style="color:#0f172a;">Website:</strong> <a href="{$siteUrlEsc}" style="color:#1d4ed8; text-decoration:none;">{$siteUrlEsc}</a></td></tr>
            </table>
          </td></tr>
          <tr><td style="padding:14px 24px 18px; border-top:1px solid #e2e8f0; background:#fbfdff;"><div style="color:#475569; font-size:12px; line-height:1.5;">United Wrestling Club • Spring Session 2026 • Saint Edmond's Academy</div><div style="margin-top:4px; color:#0f172a; font-size:12px; font-weight:700; letter-spacing:0.04em; text-transform:uppercase;">It's Bigger Than Wrestling.</div></td></tr>
        </table>
      </td></tr>
    </table>
  </body>
</html>
HTML;
}

function build_club_payment_notification_html_webhook(string $guardianName, string $guardianEmail, string $athleteCount, string $amount, string $submissionId, string $sessionId, string $paymentIntentId, string $logoUrl): string
{
    $logoUrlEsc = e_out_webhook($logoUrl);
    $guardianNameEsc = e_out_webhook($guardianName !== '' ? $guardianName : '(not provided)');
    $guardianEmailEsc = e_out_webhook($guardianEmail !== '' ? $guardianEmail : '(not provided)');
    $athleteCountEsc = e_out_webhook($athleteCount !== '' ? $athleteCount : '(not available)');
    $amountEsc = e_out_webhook($amount);
    $submissionIdEsc = e_out_webhook($submissionId !== '' ? $submissionId : '(not available)');
    $sessionIdEsc = e_out_webhook($sessionId);
    $paymentIntentIdEsc = e_out_webhook($paymentIntentId !== '' ? $paymentIntentId : '(not available)');
    $modeEsc = e_out_webhook(stripe_mode_label());

    return <<<HTML
<!doctype html><html lang="en"><body style="margin:0; padding:0; background:#eef2f7; font-family:Arial, Helvetica, sans-serif; color:#0f172a;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#eef2f7; margin:0; padding:24px 12px;"><tr><td align="center">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:720px; background:#ffffff; border:1px solid #dbe4f0; border-radius:14px; overflow:hidden;">
<tr><td style="background:#081a34; padding:18px 22px; border-bottom:3px solid #c8102e;"><img src="{$logoUrlEsc}" alt="United Wrestling Club" width="220" style="display:block; width:220px; max-width:100%; height:auto;" /><div style="margin-top:8px; color:#dbeafe; font-size:12px; letter-spacing:0.08em; text-transform:uppercase;">Registration Payment Received (Webhook)</div></td></tr>
<tr><td style="padding:22px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
<tr><td style="padding:0 0 8px 0; color:#64748b; font-size:13px; width:38%;">Parent / Guardian</td><td style="padding:0 0 8px 0; color:#0f172a; font-size:14px; font-weight:600;">{$guardianNameEsc}</td></tr>
<tr><td style="padding:0 0 8px 0; color:#64748b; font-size:13px;">Parent Email</td><td style="padding:0 0 8px 0; color:#0f172a; font-size:14px;">{$guardianEmailEsc}</td></tr>
<tr><td style="padding:0 0 8px 0; color:#64748b; font-size:13px;">Athletes</td><td style="padding:0 0 8px 0; color:#0f172a; font-size:14px;">{$athleteCountEsc}</td></tr>
<tr><td style="padding:0 0 8px 0; color:#64748b; font-size:13px;">Amount Paid</td><td style="padding:0 0 8px 0; color:#c8102e; font-size:16px; font-weight:800;">{$amountEsc}</td></tr>
<tr><td style="padding:0 0 8px 0; color:#64748b; font-size:13px;">Submission ID</td><td style="padding:0 0 8px 0; color:#0f172a; font-size:13px; font-family:Menlo, Monaco, Consolas, monospace;">{$submissionIdEsc}</td></tr>
<tr><td style="padding:0 0 8px 0; color:#64748b; font-size:13px;">Stripe Session ID</td><td style="padding:0 0 8px 0; color:#0f172a; font-size:13px; font-family:Menlo, Monaco, Consolas, monospace;">{$sessionIdEsc}</td></tr>
<tr><td style="padding:0 0 8px 0; color:#64748b; font-size:13px;">Payment Intent ID</td><td style="padding:0 0 8px 0; color:#0f172a; font-size:13px; font-family:Menlo, Monaco, Consolas, monospace;">{$paymentIntentIdEsc}</td></tr>
<tr><td style="padding:0; color:#64748b; font-size:13px;">Stripe Mode</td><td style="padding:0; color:#0f172a; font-size:13px;">{$modeEsc}</td></tr>
</table></td></tr></table></td></tr></table></body></html>
HTML;
}

function format_amount_from_cents_webhook(int $amountCents, string $currency): string
{
    return '$' . number_format($amountCents / 100, 2) . ' ' . strtoupper($currency);
}

function e_out_webhook(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function log_payment_email_error_webhook(string $message): void
{
    $line = '[' . gmdate('c') . '] ' . $message . PHP_EOL;
    @file_put_contents(STRIPE_PAYMENT_EMAIL_ERRORS, $line, FILE_APPEND);
}
