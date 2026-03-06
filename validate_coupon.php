<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

const REGISTRATION_PROMO_COUPON_CODE = 'UWCULTRATEAM';
const REGISTRATION_PROMO_COUPON_DISCOUNT = 142.50; // fixed discount, one athlete equivalent
const REGISTRATION_NOFEE_COUPON_CODE = 'NOFEE';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'valid' => false,
        'message' => 'Method not allowed.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$couponCode = clean_text((string) ($_POST['coupon_code'] ?? ''));
$estimatedPreCouponTotal = normalize_money((float) ($_POST['estimated_total_pre_coupon'] ?? 0));

if ($couponCode === '') {
    echo json_encode([
        'ok' => true,
        'valid' => false,
        'discount_amount' => 0.0,
        'message' => 'Enter a coupon code before pressing Apply.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if (
    strcasecmp($couponCode, REGISTRATION_PROMO_COUPON_CODE) !== 0
    && strcasecmp($couponCode, REGISTRATION_NOFEE_COUPON_CODE) !== 0
) {
    echo json_encode([
        'ok' => true,
        'valid' => false,
        'discount_amount' => 0.0,
        'message' => 'Coupon not recognized. Please check the code and try again.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$discountAmount = 0.0;
$message = '';
if (strcasecmp($couponCode, REGISTRATION_PROMO_COUPON_CODE) === 0) {
    $discountAmount = normalize_money(min(REGISTRATION_PROMO_COUPON_DISCOUNT, max(0.0, $estimatedPreCouponTotal)));
    $message = 'Coupon applied: -$' . number_format($discountAmount, 2, '.', '') . ' (one athlete).';
} elseif (strcasecmp($couponCode, REGISTRATION_NOFEE_COUPON_CODE) === 0) {
    $discountAmount = normalize_money(max(0.0, $estimatedPreCouponTotal));
    $message = 'Coupon applied: 100% off. Total due is now $0.00.';
}

echo json_encode([
    'ok' => true,
    'valid' => true,
    'discount_amount' => $discountAmount,
    'message' => $message,
], JSON_UNESCAPED_SLASHES);
exit;

function clean_text(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return $value;
}

function normalize_money(float $amount): float
{
    return round($amount + 0.0000001, 2);
}
