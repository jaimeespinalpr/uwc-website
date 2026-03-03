<?php
declare(strict_types=1);

const CLASS_SPOTS_CAPACITY = 24;
const CLASS_SPOTS_REGISTRATIONS_CSV = __DIR__ . '/data/stripe_registration_submissions.csv';
const CLASS_SPOTS_PAID_LOG_CSV = __DIR__ . '/data/stripe_payment_success.csv';
const CLASS_SPOTS_WAITLIST_CSV = __DIR__ . '/data/stripe_class_waitlist.csv';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_SLASHES);
    exit;
}

$classesByKey = [
    'future-champions' => 'Future Champions (Ages 5-10)',
    'foundation' => 'Rising Competitors - Foundation (Ages 11-13)',
    'development' => 'Rising Competitors - Development (Ages 11-13)',
    'advanced-11-13' => 'Rising Competitors - Advanced (Ages 12-16)',
    'elite-competition-team' => 'Elite Wrestlers (Ages 14+)',
];

$paidSessionIds = class_spots_paid_session_ids();
$paidCountsByName = class_spots_paid_counts_by_name($paidSessionIds);
$waitlistCountsByName = class_spots_waitlist_counts_by_name();

$classes = [];
foreach ($classesByKey as $classKey => $className) {
    $paid = (int) ($paidCountsByName[$className] ?? 0);
    $waitlist = (int) ($waitlistCountsByName[$className] ?? 0);
    $spotsLeft = max(0, CLASS_SPOTS_CAPACITY - $paid);

    $classes[$classKey] = [
        'class_name' => $className,
        'capacity' => CLASS_SPOTS_CAPACITY,
        'paid' => $paid,
        'spots_left' => $spotsLeft,
        'waitlist' => $waitlist,
        'is_full' => $spotsLeft <= 0,
    ];
}

echo json_encode([
    'ok' => true,
    'generated_at_utc' => gmdate('c'),
    'classes' => $classes,
], JSON_UNESCAPED_SLASHES);
exit;

function class_spots_paid_session_ids(): array
{
    if (!file_exists(CLASS_SPOTS_PAID_LOG_CSV)) {
        return [];
    }

    $handle = fopen(CLASS_SPOTS_PAID_LOG_CSV, 'rb');
    if ($handle === false) {
        return [];
    }

    $ids = [];
    try {
        $header = fgetcsv($handle);
        if (!is_array($header)) {
            return [];
        }
        $sessionIdx = array_search('session_id', $header, true);
        if ($sessionIdx === false) {
            return [];
        }

        while (($row = fgetcsv($handle)) !== false) {
            $sessionId = trim((string) ($row[$sessionIdx] ?? ''));
            if ($sessionId !== '') {
                $ids[$sessionId] = true;
            }
        }
    } finally {
        fclose($handle);
    }

    return $ids;
}

function class_spots_paid_counts_by_name(array $paidSessionIds): array
{
    $counts = [];
    if (empty($paidSessionIds) || !file_exists(CLASS_SPOTS_REGISTRATIONS_CSV)) {
        return $counts;
    }

    $handle = fopen(CLASS_SPOTS_REGISTRATIONS_CSV, 'rb');
    if ($handle === false) {
        return $counts;
    }

    try {
        $header = fgetcsv($handle);
        if (!is_array($header)) {
            return $counts;
        }

        $sessionIdx = array_search('checkout_session_id', $header, true);
        $athletesIdx = array_search('athletes_json', $header, true);
        if ($sessionIdx === false || $athletesIdx === false) {
            return $counts;
        }

        while (($row = fgetcsv($handle)) !== false) {
            $sessionId = trim((string) ($row[$sessionIdx] ?? ''));
            if ($sessionId === '' || !isset($paidSessionIds[$sessionId])) {
                continue;
            }

            $athletesJson = (string) ($row[$athletesIdx] ?? '');
            $athletes = json_decode($athletesJson, true);
            if (!is_array($athletes)) {
                continue;
            }

            foreach ($athletes as $athlete) {
                $className = class_spots_canonical_name((string) ($athlete['class_interest'] ?? ''));
                if ($className === '') {
                    continue;
                }
                if (!isset($counts[$className])) {
                    $counts[$className] = 0;
                }
                $counts[$className]++;
            }
        }
    } finally {
        fclose($handle);
    }

    return $counts;
}

function class_spots_waitlist_counts_by_name(): array
{
    $counts = [];
    if (!file_exists(CLASS_SPOTS_WAITLIST_CSV)) {
        return $counts;
    }

    $handle = fopen(CLASS_SPOTS_WAITLIST_CSV, 'rb');
    if ($handle === false) {
        return $counts;
    }

    try {
        $header = fgetcsv($handle);
        if (!is_array($header)) {
            return $counts;
        }

        $athletesIdx = array_search('athletes_json', $header, true);
        if ($athletesIdx === false) {
            return $counts;
        }

        while (($row = fgetcsv($handle)) !== false) {
            $athletesJson = (string) ($row[$athletesIdx] ?? '');
            $athletes = json_decode($athletesJson, true);
            if (!is_array($athletes)) {
                continue;
            }

            foreach ($athletes as $athlete) {
                $className = class_spots_canonical_name((string) ($athlete['class_interest'] ?? ''));
                if ($className === '') {
                    continue;
                }
                if (!isset($counts[$className])) {
                    $counts[$className] = 0;
                }
                $counts[$className]++;
            }
        }
    } finally {
        fclose($handle);
    }

    return $counts;
}

function class_spots_canonical_name(string $className): string
{
    $className = trim($className);
    if ($className === '') {
        return '';
    }

    $aliases = [
        'Foundation (11-13 Beginner)' => 'Rising Competitors - Foundation (Ages 11-13)',
        'Development (11-13 Intermediate)' => 'Rising Competitors - Development (Ages 11-13)',
        'Advanced (11-13 Advanced)' => 'Rising Competitors - Advanced (Ages 12-16)',
        'Rising Competitors - Advanced (Ages 11-13)' => 'Rising Competitors - Advanced (Ages 12-16)',
        'Elite Competition Team (14+)' => 'Elite Wrestlers (Ages 14+)',
        'Elite Competition Team (Ages 14+)' => 'Elite Wrestlers (Ages 14+)',
        'Elite Wrestler (Ages 14+)' => 'Elite Wrestlers (Ages 14+)',
        'Future Champions (5-10)' => 'Future Champions (Ages 5-10)',
    ];

    return $aliases[$className] ?? $className;
}
