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
header('X-Robots-Tag: noindex, nofollow', true);

const STRIPE_PAYMENT_SUCCESS_LOG = __DIR__ . '/data/stripe_payment_success.csv';
const STRIPE_PAYMENT_SUCCESS_ERRORS = __DIR__ . '/data/stripe_payment_success_errors.log';
const STRIPE_PAYMENT_EMAIL_ERRORS = __DIR__ . '/data/stripe_payment_email_errors.log';
const STRIPE_PAYMENT_EMAIL_SENT_DIR = __DIR__ . '/data/payment-email-sent';

$sessionId = trim((string) ($_GET['session_id'] ?? ''));
$submissionId = trim((string) ($_GET['submission_id'] ?? ''));
$errorMessage = '';
$session = null;

if ($sessionId === '') {
    $errorMessage = 'Missing Stripe session ID.';
} else {
    try {
        $session = stripe_api_request('GET', '/v1/checkout/sessions/' . rawurlencode($sessionId), [
            'expand[0]' => 'line_items',
            'expand[1]' => 'payment_intent',
        ]);
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
        log_payment_success_error('Stripe session lookup failed for ' . $sessionId . ': ' . $e->getMessage());
    }
}

$paymentStatus = (string) ($session['payment_status'] ?? '');
$statusText = 'Unable to verify payment.';
$isPaid = false;
$confirmationEmailStatus = '';

if (is_array($session)) {
    if ($paymentStatus === 'paid') {
        $isPaid = true;
        $statusText = 'Payment completed successfully.';
        record_paid_session($session, $submissionId);
        if (!uwc_excel_export_payment($session, $submissionId, 'payment_success_page')) {
            log_payment_success_error('Failed to save Excel-friendly payment export for session ' . (string) ($session['id'] ?? ''));
        }
        $confirmationEmailStatus = send_payment_confirmation_emails_if_needed($session, $submissionId);
    } elseif ($paymentStatus !== '') {
        $statusText = 'Checkout completed, but payment status is: ' . $paymentStatus . '.';
    } else {
        $statusText = 'Checkout was reached, but payment status could not be confirmed yet.';
    }
}

$amountTotal = format_amount_from_cents((int) ($session['amount_total'] ?? 0), (string) ($session['currency'] ?? 'usd'));
$guardianEmail = (string) ($session['customer_email'] ?? '');
$clientReferenceId = (string) ($session['client_reference_id'] ?? $submissionId);
$lineItems = is_array($session['line_items']['data'] ?? null) ? $session['line_items']['data'] : [];
$siteUrl = rtrim((defined('WAITLIST_SITE_URL') ? WAITLIST_SITE_URL : 'https://united-wc.com'), '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Payment Success | United Wrestling Club</title>
  <meta name="description" content="Stripe checkout success for United Wrestling Club registration." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&amp;family=Teko:wght@500;600;700&amp;display=swap" rel="stylesheet" />
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png?v=2" />
  <link rel="icon" type="image/png" sizes="16x16" href="/assets/favicon-16.png?v=2" />
  <link rel="shortcut icon" href="/favicon.png?v=2" />
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png?v=2" />
  <link rel="stylesheet" href="styles.css?v=usa-red-30" />
</head>
<body class="register-page">
  <header class="site-header">
    <div class="container nav-shell">
      <a class="brand" href="index.html" aria-label="United Wrestling Club home">
        <span class="brand-mark"><img src="assets/uwc-mark.png" alt="" aria-hidden="true" /></span>
        <span class="brand-copy"><strong>United Wrestling Club</strong><span>Spring Session 2026</span></span>
      </a>
      <nav class="site-nav" aria-label="Primary navigation">
        <a class="nav-link" href="index.html">HOME</a>
        <a class="nav-link" href="program.html">PROGRAM LEVELS</a>
        <a class="nav-link" href="team.html">COACHES</a>
        <a class="nav-link is-active" href="contact.html" aria-current="page">REGISTER</a>
        <a class="nav-link" href="contact.html#contact-us">CONTACT US</a>
      </nav>
    </div>
  </header>

  <main class="container page-shell">
    <section class="page-hero" aria-labelledby="payment-success-title">
      <span class="pill"><?php echo $isPaid ? 'Payment Received' : 'Checkout Status'; ?></span>
      <h1 id="payment-success-title"><?php echo $isPaid ? 'Thank you. Your payment was received.' : 'We could not confirm payment yet.'; ?></h1>
      <p><?php echo htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8'); ?></p>
      <?php if ($isPaid): ?>
        <div class="success-spotlight">
          <p class="success-spotlight-kicker">Registration Complete</p>
          <h2>Welcome to United Wrestling Club</h2>
          <p>Your registration is confirmed. Redirecting to Coaches so you can review the coaching team.</p>
          <a class="btn btn-lg btn-primary" href="<?php echo e_out($siteUrl); ?>/team.html#coaches-title">View Coaches Now</a>
        </div>
      <?php endif; ?>
      <?php if ($isPaid && $confirmationEmailStatus === 'sent'): ?>
        <div class="badge-line"><span class="badge-soft">Confirmation email sent to <?php echo e_out($guardianEmail !== '' ? $guardianEmail : 'the payer'); ?></span></div>
      <?php elseif ($isPaid && $confirmationEmailStatus === 'already-sent'): ?>
        <div class="badge-line"><span class="badge-soft">Confirmation email already sent</span></div>
      <?php elseif ($isPaid && $confirmationEmailStatus === 'send-failed'): ?>
        <div class="badge-line"><span class="badge-soft">Payment confirmed. Email delivery could not be confirmed yet.</span></div>
      <?php endif; ?>
    </section>

    <section class="section" aria-labelledby="payment-summary-title">
      <div class="section-head">
        <div>
          <p class="section-kicker">Checkout</p>
          <h2 class="section-title" id="payment-summary-title">Payment summary</h2>
        </div>
      </div>

      <?php if ($errorMessage !== ''): ?>
        <div class="callout" style="border-color:#c8102e; color:#7f1d1d; background:#fff5f7;">
          We could not verify the Stripe checkout session: <?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>
        </div>
      <?php endif; ?>

      <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px,1fr)); gap:14px;">
        <div class="info-card">
          <h3>Transaction details</h3>
          <ul class="check-list compact-check-list">
            <li>Submission ID: <?php echo e_out($clientReferenceId !== '' ? $clientReferenceId : '(not available)'); ?></li>
            <li>Stripe session: <?php echo e_out($sessionId !== '' ? $sessionId : '(not available)'); ?></li>
            <li>Payment status: <?php echo e_out($paymentStatus !== '' ? $paymentStatus : 'unknown'); ?></li>
            <li>Total paid: <?php echo e_out($amountTotal); ?></li>
            <?php if ($guardianEmail !== ''): ?><li>Receipt email: <?php echo e_out($guardianEmail); ?></li><?php endif; ?>
          </ul>
        </div>
        <div class="info-card">
          <h3>Next steps</h3>
          <ul class="check-list compact-check-list">
            <li>Your payment has been recorded in Stripe.</li>
            <li>Keep your Stripe receipt email for your records.</li>
            <li>If you need assistance, contact <?php echo e_out(WAITLIST_CONTACT_EMAIL); ?>.</li>
            <?php if (defined('WAITLIST_CONTACT_PHONE') && WAITLIST_CONTACT_PHONE !== ''): ?><li>Phone / Text: <?php echo e_out((string) WAITLIST_CONTACT_PHONE); ?></li><?php endif; ?>
          </ul>
          <div class="hero-actions" style="margin-top:14px;">
            <a class="btn btn-md btn-primary" href="<?php echo e_out($siteUrl); ?>/program.html">View Program Levels</a>
            <a class="btn btn-md btn-secondary" href="<?php echo e_out($siteUrl); ?>/team.html">View Coaches</a>
          </div>
        </div>
      </div>

      <?php if (!empty($lineItems)): ?>
        <div class="info-card" style="margin-top:16px;">
          <h3>Stripe line item</h3>
          <ul class="check-list compact-check-list">
            <?php foreach ($lineItems as $item): ?>
              <li>
                <?php echo e_out((string) ($item['description'] ?? 'Registration')); ?>
                — <?php echo e_out(format_amount_from_cents((int) ($item['amount_total'] ?? 0), (string) ($item['currency'] ?? 'usd'))); ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </section>
  </main>
  <?php if ($isPaid): ?>
  <script>
    setTimeout(function () {
      window.location.href = <?php echo json_encode($siteUrl . '/team.html#coaches-title'); ?>;
    }, 4500);
  </script>
  <?php endif; ?>
</body>
</html>
<?php

function record_paid_session(array $session, string $submissionId): void
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

    if (stripe_session_already_logged($csvPath, $sessionId)) {
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
        'customer_email' => (string) ($session['customer_email'] ?? ''),
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

function stripe_session_already_logged(string $csvPath, string $sessionId): bool
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

function format_amount_from_cents(int $amountCents, string $currency): string
{
    $value = $amountCents / 100;
    return '$' . number_format($value, 2) . ' ' . strtoupper($currency);
}

function e_out(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function log_payment_success_error(string $message): void
{
    $line = '[' . gmdate('c') . '] ' . $message . PHP_EOL;
    @file_put_contents(STRIPE_PAYMENT_SUCCESS_ERRORS, $line, FILE_APPEND);
}

function send_payment_confirmation_emails_if_needed(array $session, string $submissionId): string
{
    $sessionId = trim((string) ($session['id'] ?? ''));
    if ($sessionId === '') {
        return 'skipped';
    }

    if (payment_confirmation_already_sent($sessionId)) {
        return 'already-sent';
    }

    $guardianEmail = trim((string) ($session['customer_email'] ?? ''));
    $guardianName = trim((string) ($session['metadata']['guardian_name'] ?? ''));
    $athleteCount = trim((string) ($session['metadata']['athlete_count'] ?? ''));
    $amount = format_amount_from_cents((int) ($session['amount_total'] ?? 0), (string) ($session['currency'] ?? 'usd'));
    $paymentIntentId = is_array($session['payment_intent'] ?? null)
        ? (string) (($session['payment_intent']['id'] ?? ''))
        : (string) ($session['payment_intent'] ?? '');
    $siteUrl = rtrim((defined('WAITLIST_SITE_URL') ? WAITLIST_SITE_URL : 'https://united-wc.com'), '/');
    $logoUrl = defined('WAITLIST_LOGO_URL') ? WAITLIST_LOGO_URL : $siteUrl . '/assets/uwc-logo.png';

    $parentSent = true;
    $clubSent = true;

    if ($guardianEmail !== '' && filter_var($guardianEmail, FILTER_VALIDATE_EMAIL)) {
        $parentSubject = 'UWC Payment Confirmation - Spring Session 2026';
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

        $parentHtml = build_parent_payment_confirmation_html(
            $guardianName,
            $guardianEmail,
            $athleteCount,
            $amount,
            $submissionId !== '' ? $submissionId : ((string) ($session['client_reference_id'] ?? '')),
            $logoUrl,
            $siteUrl
        );

        $parentSent = payment_send_mail_message(
            $guardianEmail,
            $parentSubject,
            $parentPlain,
            $parentHtml,
            ['Reply-To: ' . WAITLIST_CONTACT_EMAIL]
        );
    }

    $clubSubject = 'UWC Registration Payment Received' . ($guardianName !== '' ? ' - ' . $guardianName : '');
    $clubPlain = implode("\n", array_filter([
        'Stripe checkout payment completed.',
        '',
        'Parent / Guardian: ' . ($guardianName !== '' ? $guardianName : '(not provided)'),
        'Parent Email: ' . ($guardianEmail !== '' ? $guardianEmail : '(not provided)'),
        'Athletes: ' . ($athleteCount !== '' ? $athleteCount : '(not available)'),
        'Amount paid: ' . $amount,
        'Submission ID: ' . ($submissionId !== '' ? $submissionId : ((string) ($session['client_reference_id'] ?? ''))),
        'Stripe Session ID: ' . $sessionId,
        'Payment Intent ID: ' . ($paymentIntentId !== '' ? $paymentIntentId : '(not available)'),
    ]));

    $clubHtml = build_club_payment_notification_html(
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

    $clubSent = payment_send_mail_message($paymentNotificationEmail, $clubSubject, $clubPlain, $clubHtml, $clubHeaders);

    if ($parentSent && $clubSent) {
        payment_confirmation_mark_sent($sessionId);
        return 'sent';
    }

    $failureParts = [];
    if (!$parentSent) {
        $failureParts[] = 'parent';
    }
    if (!$clubSent) {
        $failureParts[] = 'club';
    }
    log_payment_email_error('Payment email send failed for session ' . $sessionId . ' [' . implode(',', $failureParts) . ']');
    return 'send-failed';
}

function payment_confirmation_already_sent(string $sessionId): bool
{
    $path = payment_confirmation_marker_path($sessionId);
    return is_file($path);
}

function payment_confirmation_mark_sent(string $sessionId): void
{
    $dir = STRIPE_PAYMENT_EMAIL_SENT_DIR;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        log_payment_email_error('Could not create payment email marker dir.');
        return;
    }

    $path = payment_confirmation_marker_path($sessionId);
    @file_put_contents($path, '[' . gmdate('c') . '] sent' . PHP_EOL);
}

function payment_confirmation_marker_path(string $sessionId): string
{
    $safe = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $sessionId) ?? 'unknown';
    return rtrim(STRIPE_PAYMENT_EMAIL_SENT_DIR, '/') . '/' . $safe . '.sent';
}

function payment_send_mail_message(
    string $to,
    string $subject,
    string $plainTextBody,
    ?string $htmlBody = null,
    array $extraHeaders = []
): bool {
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

function build_parent_payment_confirmation_html(
    string $guardianName,
    string $guardianEmail,
    string $athleteCount,
    string $amount,
    string $submissionId,
    string $logoUrl,
    string $siteUrl
): string {
    $guardianNameEsc = e_out($guardianName !== '' ? $guardianName : 'Parent / Guardian');
    $guardianEmailEsc = e_out($guardianEmail);
    $athleteCountEsc = e_out($athleteCount !== '' ? $athleteCount : '(not available)');
    $amountEsc = e_out($amount);
    $submissionIdEsc = e_out($submissionId !== '' ? $submissionId : '(not available)');
    $logoUrlEsc = e_out($logoUrl);
    $siteUrlEsc = e_out($siteUrl);
    $contactEmailEsc = e_out(WAITLIST_CONTACT_EMAIL);
    $contactPhone = defined('WAITLIST_CONTACT_PHONE') ? trim((string) WAITLIST_CONTACT_PHONE) : '';
    $contactPhoneRow = $contactPhone !== ''
        ? '<tr><td style="padding:0 0 6px 0; color:#334155; font-size:14px;"><strong style="color:#0f172a;">Phone / Text:</strong> ' . e_out($contactPhone) . '</td></tr>'
        : '';

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
                <div style="margin-top:10px; color:#dbeafe; font-size:13px; letter-spacing:0.08em; text-transform:uppercase;">Payment Confirmation • Spring Session 2026</div>
              </td>
            </tr>
            <tr>
              <td style="padding:24px;">
                <h1 style="margin:0 0 10px 0; font-size:24px; line-height:1.15; color:#0f172a;">Thank you. Your payment was received.</h1>
                <p style="margin:0 0 14px 0; color:#334155; font-size:15px; line-height:1.55;">
                  Thank you, {$guardianNameEsc}. This email confirms your registration payment for United Wrestling Club Spring Session 2026.
                </p>

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

                <p style="margin:0 0 14px 0; color:#334155; font-size:14px; line-height:1.55;">
                  Please keep this confirmation email (and your Stripe receipt) for your records. If you have any questions, contact us using the information below.
                </p>

                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 16px 0;">
                  <tr><td style="padding:0 0 8px 0; color:#0f172a; font-size:15px; font-weight:700;">Questions?</td></tr>
                  <tr><td style="padding:0 0 6px 0; color:#334155; font-size:14px;"><strong style="color:#0f172a;">Email:</strong> {$contactEmailEsc}</td></tr>
                  {$contactPhoneRow}
                  <tr><td style="padding:2px 0 0 0; color:#334155; font-size:14px;"><strong style="color:#0f172a;">Website:</strong> <a href="{$siteUrlEsc}" style="color:#1d4ed8; text-decoration:none;">{$siteUrlEsc}</a></td></tr>
                </table>
              </td>
            </tr>
            <tr><td style="padding:14px 24px 18px; border-top:1px solid #e2e8f0; background:#fbfdff;"><div style="color:#475569; font-size:12px; line-height:1.5;">United Wrestling Club • Spring Session 2026 • Saint Edmond's Academy</div><div style="margin-top:4px; color:#0f172a; font-size:12px; font-weight:700; letter-spacing:0.04em; text-transform:uppercase;">It's Bigger Than Wrestling.</div></td></tr>
          </table>
        </td>
      </tr>
    </table>
  </body>
</html>
HTML;
}

function build_club_payment_notification_html(
    string $guardianName,
    string $guardianEmail,
    string $athleteCount,
    string $amount,
    string $submissionId,
    string $sessionId,
    string $paymentIntentId,
    string $logoUrl
): string {
    $logoUrlEsc = e_out($logoUrl);
    $guardianNameEsc = e_out($guardianName !== '' ? $guardianName : '(not provided)');
    $guardianEmailEsc = e_out($guardianEmail !== '' ? $guardianEmail : '(not provided)');
    $athleteCountEsc = e_out($athleteCount !== '' ? $athleteCount : '(not available)');
    $amountEsc = e_out($amount);
    $submissionIdEsc = e_out($submissionId !== '' ? $submissionId : '(not available)');
    $sessionIdEsc = e_out($sessionId);
    $paymentIntentIdEsc = e_out($paymentIntentId !== '' ? $paymentIntentId : '(not available)');

    return <<<HTML
<!doctype html>
<html lang="en">
  <body style="margin:0; padding:0; background:#eef2f7; font-family:Arial, Helvetica, sans-serif; color:#0f172a;">
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#eef2f7; margin:0; padding:24px 12px;">
      <tr>
        <td align="center">
          <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:720px; background:#ffffff; border:1px solid #dbe4f0; border-radius:14px; overflow:hidden;">
            <tr>
              <td style="background:#081a34; padding:18px 22px; border-bottom:3px solid #c8102e;">
                <img src="{$logoUrlEsc}" alt="United Wrestling Club" width="220" style="display:block; width:220px; max-width:100%; height:auto;" />
                <div style="margin-top:8px; color:#dbeafe; font-size:12px; letter-spacing:0.08em; text-transform:uppercase;">Registration Payment Received</div>
              </td>
            </tr>
            <tr>
              <td style="padding:22px;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                  <tr><td style="padding:0 0 8px 0; color:#64748b; font-size:13px; width:38%;">Parent / Guardian</td><td style="padding:0 0 8px 0; color:#0f172a; font-size:14px; font-weight:600;">{$guardianNameEsc}</td></tr>
                  <tr><td style="padding:0 0 8px 0; color:#64748b; font-size:13px;">Parent Email</td><td style="padding:0 0 8px 0; color:#0f172a; font-size:14px;">{$guardianEmailEsc}</td></tr>
                  <tr><td style="padding:0 0 8px 0; color:#64748b; font-size:13px;">Athletes</td><td style="padding:0 0 8px 0; color:#0f172a; font-size:14px;">{$athleteCountEsc}</td></tr>
                  <tr><td style="padding:0 0 8px 0; color:#64748b; font-size:13px;">Amount Paid</td><td style="padding:0 0 8px 0; color:#c8102e; font-size:16px; font-weight:800;">{$amountEsc}</td></tr>
                  <tr><td style="padding:0 0 8px 0; color:#64748b; font-size:13px;">Submission ID</td><td style="padding:0 0 8px 0; color:#0f172a; font-size:13px; font-family:Menlo, Monaco, Consolas, monospace;">{$submissionIdEsc}</td></tr>
                  <tr><td style="padding:0 0 8px 0; color:#64748b; font-size:13px;">Stripe Session ID</td><td style="padding:0 0 8px 0; color:#0f172a; font-size:13px; font-family:Menlo, Monaco, Consolas, monospace;">{$sessionIdEsc}</td></tr>
                  <tr><td style="padding:0 0 8px 0; color:#64748b; font-size:13px;">Payment Intent ID</td><td style="padding:0 0 8px 0; color:#0f172a; font-size:13px; font-family:Menlo, Monaco, Consolas, monospace;">{$paymentIntentIdEsc}</td></tr>
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

function log_payment_email_error(string $message): void
{
    $line = '[' . gmdate('c') . '] ' . $message . PHP_EOL;
    @file_put_contents(STRIPE_PAYMENT_EMAIL_ERRORS, $line, FILE_APPEND);
}
