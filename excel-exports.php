<?php
declare(strict_types=1);

if (!defined('UWC_EXCEL_EXPORT_REGISTRATIONS_CSV')) {
    define('UWC_EXCEL_EXPORT_REGISTRATIONS_CSV', __DIR__ . '/data/excel_registrations_master.csv');
}
if (!defined('UWC_EXCEL_EXPORT_ATHLETES_CSV')) {
    define('UWC_EXCEL_EXPORT_ATHLETES_CSV', __DIR__ . '/data/excel_registration_athletes.csv');
}
if (!defined('UWC_EXCEL_EXPORT_PAYMENTS_CSV')) {
    define('UWC_EXCEL_EXPORT_PAYMENTS_CSV', __DIR__ . '/data/excel_payments.csv');
}
if (!defined('UWC_EXCEL_EXPORT_EMAIL_ERROR_LOG')) {
    define('UWC_EXCEL_EXPORT_EMAIL_ERROR_LOG', __DIR__ . '/data/excel_export_email_errors.log');
}

if (!function_exists('uwc_excel_export_registration')) {
    function uwc_excel_export_registration(array $submission, array $athletes = [], array $pricingLines = []): bool
    {
        $recordedAt = (string) ($submission['submitted_at_utc'] ?? gmdate('c'));
        $submissionId = (string) ($submission['submission_id'] ?? '');

        $masterRow = [
            'recorded_at_utc' => $recordedAt,
            'record_type' => 'registration_started',
            'submission_id' => $submissionId,
            'source' => (string) ($submission['submission_source'] ?? ''),
            'stripe_mode' => (string) ($submission['stripe_mode'] ?? ''),
            'checkout_session_id' => (string) ($submission['checkout_session_id'] ?? ''),
            'checkout_session_status' => (string) ($submission['checkout_session_status'] ?? ''),
            'payment_status' => (string) ($submission['payment_status'] ?? ''),
            'parent_name' => (string) ($submission['guardian_name'] ?? ''),
            'parent_email' => (string) ($submission['email'] ?? ''),
            'parent_phone' => (string) ($submission['phone'] ?? ''),
            'preferred_contact_method' => (string) ($submission['preferred_contact_method'] ?? ''),
            'total_athletes' => (string) ($submission['total_athletes'] ?? ''),
            'estimated_subtotal' => (string) ($submission['estimated_subtotal'] ?? ''),
            'estimated_discount_total' => (string) ($submission['estimated_discount_total'] ?? ''),
            'estimated_total' => (string) ($submission['estimated_total'] ?? ''),
            'currency' => (string) ($submission['currency'] ?? ''),
            'tax_mode' => ((string) ($submission['automatic_tax_enabled_in_session'] ?? '') === 'yes')
                ? 'stripe_automatic_tax_enabled'
                : 'stripe_automatic_tax_not_enabled_in_session',
            'notes' => (string) ($submission['notes'] ?? ''),
            'athlete_summary' => uwc_excel_build_athlete_summary($athletes),
            'ip_address' => (string) ($submission['ip_address'] ?? ''),
        ];

        if (!uwc_excel_append_csv_row(UWC_EXCEL_EXPORT_REGISTRATIONS_CSV, $masterRow)) {
            return false;
        }

        foreach ($athletes as $index => $athlete) {
            $pricing = $pricingLines[$index] ?? [];
            $discountRate = isset($pricing['discount_rate']) ? (float) $pricing['discount_rate'] : 0.0;

            $athleteRow = [
                'recorded_at_utc' => $recordedAt,
                'submission_id' => $submissionId,
                'athlete_index' => (string) ($athlete['athlete_index'] ?? ($index + 1)),
                'parent_name' => (string) ($submission['guardian_name'] ?? ''),
                'parent_email' => (string) ($submission['email'] ?? ''),
                'athlete_name' => (string) ($athlete['athlete_name'] ?? ''),
                'age_group' => (string) ($athlete['athlete_age_group'] ?? ''),
                'class_name' => (string) ($athlete['class_interest'] ?? ''),
                'experience' => (string) ($athlete['experience_label'] ?? ''),
                'base_price' => uwc_excel_money_string($pricing['base_price'] ?? null),
                'discount_rate_percent' => $discountRate > 0 ? (string) (int) round($discountRate * 100) : '0',
                'discount_label' => (string) ($pricing['discount_label'] ?? 'Full price'),
                'discount_amount' => uwc_excel_money_string($pricing['discount_amount'] ?? null),
                'line_total' => uwc_excel_money_string($pricing['line_total'] ?? null),
            ];

            if (!uwc_excel_append_csv_row(UWC_EXCEL_EXPORT_ATHLETES_CSV, $athleteRow)) {
                return false;
            }
        }

        try {
            if (!uwc_excel_send_registration_export_email($submission, $athletes)) {
                uwc_excel_log_email_error('Registration export email failed for submission ' . $submissionId);
            }
        } catch (Throwable $e) {
            uwc_excel_log_email_error('Registration export email exception for submission ' . $submissionId . ': ' . $e->getMessage());
        }

        return true;
    }

    function uwc_excel_export_payment(array $session, string $submissionId = '', string $source = 'stripe'): bool
    {
        $sessionId = trim((string) ($session['id'] ?? ''));
        if ($sessionId === '') {
            return false;
        }

        if (uwc_excel_csv_row_exists_by_value(UWC_EXCEL_EXPORT_PAYMENTS_CSV, 'stripe_session_id', $sessionId)) {
            return true; // idempotent
        }

        $paymentIntentId = is_array($session['payment_intent'] ?? null)
            ? (string) (($session['payment_intent']['id'] ?? ''))
            : (string) ($session['payment_intent'] ?? '');

        $customerEmail = (string) ($session['customer_email'] ?? (($session['customer_details']['email'] ?? '')));
        $customerName = (string) (($session['customer_details']['name'] ?? ''));
        $currency = (string) ($session['currency'] ?? stripe_currency_code());
        $amountCents = (int) ($session['amount_total'] ?? 0);
        $submissionIdFinal = $submissionId !== ''
            ? $submissionId
            : (string) ($session['client_reference_id'] ?? (($session['metadata']['submission_id'] ?? '')));

        $row = [
            'recorded_at_utc' => gmdate('c'),
            'record_type' => 'payment_received',
            'source' => $source,
            'stripe_mode' => function_exists('stripe_mode_label') ? stripe_mode_label() : '',
            'submission_id' => $submissionIdFinal,
            'stripe_session_id' => $sessionId,
            'payment_intent_id' => $paymentIntentId,
            'payment_status' => (string) ($session['payment_status'] ?? ''),
            'checkout_status' => (string) ($session['status'] ?? ''),
            'customer_email' => $customerEmail,
            'customer_name' => $customerName,
            'guardian_name_meta' => (string) ($session['metadata']['guardian_name'] ?? ''),
            'athlete_count_meta' => (string) ($session['metadata']['athlete_count'] ?? ''),
            'amount_total' => number_format($amountCents / 100, 2, '.', ''),
            'currency' => strtoupper($currency),
            'amount_total_display' => '$' . number_format($amountCents / 100, 2) . ' ' . strtoupper($currency),
        ];

        $saved = uwc_excel_append_csv_row(UWC_EXCEL_EXPORT_PAYMENTS_CSV, $row);
        if (!$saved) {
            return false;
        }

        try {
            if (!uwc_excel_send_payment_export_email($row, $session)) {
                uwc_excel_log_email_error('Payment export email failed for Stripe session ' . $sessionId);
            }
        } catch (Throwable $e) {
            uwc_excel_log_email_error('Payment export email exception for Stripe session ' . $sessionId . ': ' . $e->getMessage());
        }

        return true;
    }

    function uwc_excel_build_athlete_summary(array $athletes): string
    {
        $parts = [];
        foreach ($athletes as $athlete) {
            $name = trim((string) ($athlete['athlete_name'] ?? ''));
            $age = trim((string) ($athlete['athlete_age_group'] ?? ''));
            $class = trim((string) ($athlete['class_interest'] ?? ''));
            if ($name === '' && $age === '' && $class === '') {
                continue;
            }
            $bits = array_values(array_filter([$name, $age, $class], static fn ($v) => $v !== ''));
            $parts[] = implode(' | ', $bits);
        }
        return implode(' || ', $parts);
    }

    function uwc_excel_money_string($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_numeric($value)) {
            return number_format((float) $value, 2, '.', '');
        }
        return (string) $value;
    }

    function uwc_excel_append_csv_row(string $csvPath, array $row): bool
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
                fputcsv($handle, array_keys($row));
            }

            fputcsv($handle, array_values($row));
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        return true;
    }

    function uwc_excel_csv_row_exists_by_value(string $csvPath, string $columnName, string $needle): bool
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

            $index = array_search($columnName, $header, true);
            if ($index === false) {
                return false;
            }

            while (($row = fgetcsv($handle)) !== false) {
                if ((string) ($row[$index] ?? '') === $needle) {
                    return true;
                }
            }
        } finally {
            fclose($handle);
        }

        return false;
    }

    function uwc_excel_send_registration_export_email(array $submission, array $athletes): bool
    {
        $submissionId = trim((string) ($submission['submission_id'] ?? ''));
        $guardianName = trim((string) ($submission['guardian_name'] ?? ''));
        $guardianEmail = trim((string) ($submission['email'] ?? ''));
        $athleteCount = (string) ($submission['total_athletes'] ?? count($athletes));
        $estimatedTotal = uwc_excel_money_string($submission['estimated_total'] ?? '');
        $recordedAt = (string) ($submission['submitted_at_utc'] ?? gmdate('c'));

        $subjectSuffix = $submissionId !== '' ? ' - ' . $submissionId : '';
        $subject = 'UWC Excel Update - Registration' . $subjectSuffix;

        $lines = [
            'A new registration was added to the Excel-friendly CSV exports.',
            '',
            'Recorded (UTC): ' . $recordedAt,
            'Submission ID: ' . ($submissionId !== '' ? $submissionId : '(not provided)'),
            'Parent/Guardian: ' . ($guardianName !== '' ? $guardianName : '(not provided)'),
            'Parent Email: ' . ($guardianEmail !== '' ? $guardianEmail : '(not provided)'),
            'Athletes: ' . $athleteCount,
        ];
        if ($estimatedTotal !== '') {
            $lines[] = 'Estimated Total: $' . $estimatedTotal;
        }
        if (!empty($athletes)) {
            $lines[] = '';
            $lines[] = 'Athletes:';
            foreach ($athletes as $idx => $athlete) {
                $athleteName = trim((string) ($athlete['athlete_name'] ?? 'Athlete ' . ($idx + 1)));
                $age = trim((string) ($athlete['athlete_age_group'] ?? ''));
                $class = trim((string) ($athlete['class_interest'] ?? ''));
                $parts = array_values(array_filter([$athleteName, $age, $class], static fn ($v) => $v !== ''));
                $lines[] = '- ' . implode(' | ', $parts);
            }
        }
        $lines[] = '';
        $lines[] = 'Attached files:';
        $lines[] = '- ' . basename(UWC_EXCEL_EXPORT_REGISTRATIONS_CSV);
        $lines[] = '- ' . basename(UWC_EXCEL_EXPORT_ATHLETES_CSV);

        return uwc_excel_send_export_email(
            $subject,
            implode("\n", $lines),
            [UWC_EXCEL_EXPORT_REGISTRATIONS_CSV, UWC_EXCEL_EXPORT_ATHLETES_CSV]
        );
    }

    function uwc_excel_send_payment_export_email(array $paymentRow, array $session = []): bool
    {
        $submissionId = trim((string) ($paymentRow['submission_id'] ?? ''));
        $sessionId = trim((string) ($paymentRow['stripe_session_id'] ?? (($session['id'] ?? ''))));
        $customerEmail = trim((string) ($paymentRow['customer_email'] ?? (($session['customer_email'] ?? ''))));
        $customerName = trim((string) ($paymentRow['customer_name'] ?? (($session['customer_details']['name'] ?? ''))));
        $amountDisplay = trim((string) ($paymentRow['amount_total_display'] ?? ''));
        $source = trim((string) ($paymentRow['source'] ?? 'stripe'));
        $recordedAt = trim((string) ($paymentRow['recorded_at_utc'] ?? gmdate('c')));

        $subjectSuffix = $sessionId !== '' ? ' - ' . $sessionId : '';
        $subject = 'UWC Excel Update - Payment' . $subjectSuffix;

        $lines = [
            'A payment was added to the Excel-friendly CSV exports.',
            '',
            'Recorded (UTC): ' . ($recordedAt !== '' ? $recordedAt : gmdate('c')),
            'Source: ' . ($source !== '' ? $source : 'stripe'),
            'Submission ID: ' . ($submissionId !== '' ? $submissionId : '(not provided)'),
            'Stripe Session ID: ' . ($sessionId !== '' ? $sessionId : '(not provided)'),
            'Customer: ' . ($customerName !== '' ? $customerName : '(not provided)'),
            'Customer Email: ' . ($customerEmail !== '' ? $customerEmail : '(not provided)'),
        ];
        if ($amountDisplay !== '') {
            $lines[] = 'Amount: ' . $amountDisplay;
        }
        $lines[] = '';
        $lines[] = 'Attached file:';
        $lines[] = '- ' . basename(UWC_EXCEL_EXPORT_PAYMENTS_CSV);

        return uwc_excel_send_export_email(
            $subject,
            implode("\n", $lines),
            [UWC_EXCEL_EXPORT_PAYMENTS_CSV]
        );
    }

    function uwc_excel_send_export_email(string $subject, string $textBody, array $attachments): bool
    {
        if (!uwc_excel_bootstrap_mailer()) {
            return false;
        }
        if (!function_exists('uwc_transport_mail')) {
            return false;
        }

        $recipients = uwc_excel_export_recipients();
        if (empty($recipients)) {
            return false;
        }

        $fromName = defined('WAITLIST_FROM_NAME') ? trim((string) WAITLIST_FROM_NAME) : 'United Wrestling Club';
        $fromEmail = defined('WAITLIST_FROM_EMAIL') ? trim((string) WAITLIST_FROM_EMAIL) : $recipients[0];
        $replyTo = defined('WAITLIST_CONTACT_EMAIL') ? trim((string) WAITLIST_CONTACT_EMAIL) : '';

        try {
            $boundaryToken = bin2hex(random_bytes(12));
        } catch (Throwable $e) {
            $boundaryToken = str_replace('.', '', uniqid('', true));
        }
        $boundary = '=_UWC_CSV_' . $boundaryToken;
        $headers = [
            'From: ' . uwc_excel_format_from_header($fromName, $fromEmail),
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
        ];
        if ($replyTo !== '') {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        $body = [];
        $body[] = 'This is a multi-part message in MIME format.';
        $body[] = '--' . $boundary;
        $body[] = 'Content-Type: text/plain; charset=UTF-8';
        $body[] = 'Content-Transfer-Encoding: 8bit';
        $body[] = '';
        $body[] = str_replace(["\r\n", "\r"], "\n", $textBody);

        foreach ($attachments as $attachmentPath) {
            $attachmentPath = (string) $attachmentPath;
            if ($attachmentPath === '' || !is_file($attachmentPath) || !is_readable($attachmentPath)) {
                continue;
            }

            $fileContents = file_get_contents($attachmentPath);
            if (!is_string($fileContents)) {
                continue;
            }

            $filename = basename($attachmentPath);
            $body[] = '--' . $boundary;
            $body[] = 'Content-Type: text/csv; name="' . $filename . '"';
            $body[] = 'Content-Transfer-Encoding: base64';
            $body[] = 'Content-Disposition: attachment; filename="' . $filename . '"';
            $body[] = '';
            $body[] = trim(chunk_split(base64_encode($fileContents)));
        }

        $body[] = '--' . $boundary . '--';
        $body[] = '';

        $payload = implode("\r\n", $body);
        $headerString = implode("\r\n", $headers);
        $allSent = true;

        foreach ($recipients as $recipient) {
            if (!uwc_transport_mail($recipient, $subject, $payload, $headerString)) {
                $allSent = false;
                uwc_excel_log_email_error(
                    'Excel export email send failed to ' . $recipient . ' | subject: ' . $subject
                );
            }
        }

        return $allSent;
    }

    function uwc_excel_bootstrap_mailer(): bool
    {
        if (!defined('WAITLIST_FROM_EMAIL') && is_file(__DIR__ . '/waitlist-config.php')) {
            require_once __DIR__ . '/waitlist-config.php';
        }
        if (!function_exists('uwc_transport_mail') && is_file(__DIR__ . '/smtp-mailer.php')) {
            require_once __DIR__ . '/smtp-mailer.php';
        }

        return function_exists('uwc_transport_mail');
    }

    function uwc_excel_format_from_header(string $name, string $email): string
    {
        $safeEmail = trim($email);
        if ($safeEmail === '') {
            return 'United Wrestling Club <>';
        }

        $safeName = trim(preg_replace('/[\r\n]+/', ' ', $name) ?? '');
        if ($safeName === '') {
            return $safeEmail;
        }

        return $safeName . ' <' . $safeEmail . '>';
    }

    function uwc_excel_export_recipients(): array
    {
        $raw = [];

        if (defined('WAITLIST_EXCEL_EXPORT_EMAILS')) {
            $configValue = WAITLIST_EXCEL_EXPORT_EMAILS;
            if (is_array($configValue)) {
                foreach ($configValue as $item) {
                    $raw[] = (string) $item;
                }
            } else {
                $raw[] = (string) $configValue;
            }
        }

        if (defined('WAITLIST_EXCEL_EXPORT_EMAIL')) {
            $raw[] = (string) WAITLIST_EXCEL_EXPORT_EMAIL;
        }

        if (empty($raw) && defined('WAITLIST_ADMIN_EMAIL')) {
            $raw[] = (string) WAITLIST_ADMIN_EMAIL;
        }

        $emails = [];
        foreach ($raw as $value) {
            foreach (preg_split('/[;,]+/', $value) as $part) {
                $candidate = trim((string) $part);
                if ($candidate === '') {
                    continue;
                }
                $candidateLower = strtolower($candidate);
                if (!filter_var($candidateLower, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $emails[$candidateLower] = $candidateLower;
            }
        }

        return array_values($emails);
    }

    function uwc_excel_log_email_error(string $message): void
    {
        $directory = dirname(UWC_EXCEL_EXPORT_EMAIL_ERROR_LOG);
        if (!is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        @file_put_contents(
            UWC_EXCEL_EXPORT_EMAIL_ERROR_LOG,
            '[' . gmdate('c') . '] ' . $message . PHP_EOL,
            FILE_APPEND
        );
    }
}
