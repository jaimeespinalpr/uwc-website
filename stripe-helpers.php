<?php
declare(strict_types=1);

if (!defined('STRIPE_SECRET_KEY')) {
    throw new RuntimeException('Stripe secret key is not configured.');
}

function stripe_api_request(string $method, string $path, array $params = []): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL is not available on this server.');
    }

    $method = strtoupper($method);
    $url = 'https://api.stripe.com' . $path;

    $curlOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => STRIPE_SECRET_KEY . ':',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
        ],
    ];

    if ($method === 'GET') {
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
    } else {
        $curlOptions[CURLOPT_POSTFIELDS] = http_build_query($params);
        if ($method === 'POST') {
            $curlOptions[CURLOPT_POST] = true;
        } else {
            $curlOptions[CURLOPT_CUSTOMREQUEST] = $method;
        }
    }

    $curlOptions[CURLOPT_URL] = $url;

    $ch = curl_init();
    if ($ch === false) {
        throw new RuntimeException('Could not initialize Stripe request.');
    }

    curl_setopt_array($ch, $curlOptions);
    $raw = curl_exec($ch);
    $curlErr = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('Stripe request failed: ' . $curlErr);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid response from Stripe.');
    }

    if ($status >= 400) {
        $message = $decoded['error']['message'] ?? 'Stripe API error';
        throw new RuntimeException($message);
    }

    return $decoded;
}

function stripe_mode_label(): string
{
    $mode = defined('STRIPE_MODE') ? strtolower((string) STRIPE_MODE) : 'test';
    return $mode === 'live' ? 'live' : 'test';
}

function stripe_currency_code(): string
{
    $currency = defined('STRIPE_CURRENCY') ? strtolower((string) STRIPE_CURRENCY) : 'usd';
    return $currency !== '' ? $currency : 'usd';
}

function stripe_automatic_tax_enabled(): bool
{
    if (!defined('STRIPE_ENABLE_AUTOMATIC_TAX')) {
        return true;
    }

    return (bool) STRIPE_ENABLE_AUTOMATIC_TAX;
}

