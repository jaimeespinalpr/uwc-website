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

        return uwc_excel_append_csv_row(UWC_EXCEL_EXPORT_PAYMENTS_CSV, $row);
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
}

