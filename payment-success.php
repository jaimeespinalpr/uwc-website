<?php
declare(strict_types=1);

require_once __DIR__ . '/waitlist-config.php';
require_once __DIR__ . '/stripe-config.php';
require_once __DIR__ . '/stripe-helpers.php';

const STRIPE_PAYMENT_SUCCESS_LOG = __DIR__ . '/data/stripe_payment_success.csv';
const STRIPE_PAYMENT_SUCCESS_ERRORS = __DIR__ . '/data/stripe_payment_success_errors.log';

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

if (is_array($session)) {
    if ($paymentStatus === 'paid') {
        $isPaid = true;
        $statusText = 'Payment completed successfully.';
        record_paid_session($session, $submissionId);
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
  <link href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&family=Teko:wght@500;600;700&display=swap" rel="stylesheet" />
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png?v=2" />
  <link rel="icon" type="image/png" sizes="16x16" href="/assets/favicon-16.png?v=2" />
  <link rel="shortcut icon" href="/favicon.png?v=2" />
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png?v=2" />
  <link rel="stylesheet" href="styles.css?v=usa-red-23" />
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
      </nav>
    </div>
  </header>

  <main class="container page-shell">
    <section class="page-hero" aria-labelledby="payment-success-title">
      <span class="pill"><?php echo $isPaid ? 'Payment Received' : 'Checkout Status'; ?></span>
      <h1 id="payment-success-title"><?php echo $isPaid ? 'Thank you. Your payment was received.' : 'We could not confirm payment yet.'; ?></h1>
      <p><?php echo htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8'); ?></p>
      <?php if (stripe_mode_label() === 'test'): ?>
        <div class="badge-line"><span class="badge-soft">Stripe test mode</span></div>
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
