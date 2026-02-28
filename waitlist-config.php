<?php
declare(strict_types=1);

$uwcSmtpConfigPath = __DIR__ . '/mail-smtp-config.php';
if (is_file($uwcSmtpConfigPath)) {
    require_once $uwcSmtpConfigPath;
}

/*
 * UWC Waitlist form configuration
 * Primary contact addresses used by UWC forms and confirmations.
 */

define('WAITLIST_ADMIN_EMAIL', 'register@united-wc.com');  // Registration/waitlist notifications
define('WAITLIST_PAYMENT_EMAIL', 'sales@united-wc.com');   // Payment notifications
define('WAITLIST_CONTACT_EMAIL', 'info@united-wc.com');    // Shown to families in confirmation email
define('WAITLIST_EXCEL_EXPORT_EMAIL', 'gmunch@united-wc.com'); // Excel/CSV export update notifications
define('WAITLIST_EXCEL_EXPORT_EMAILS', [
    'gmunch@united-wc.com',
    'jaimeespinalpr@gmail.com',
]); // Multi-recipient Excel/CSV export update notifications
define('WAITLIST_CONTACT_NAME', 'Gary Munch');
define('WAITLIST_CONTACT_TITLE', 'Director of Operations and Founder');
define('WAITLIST_CONTACT_PHONE', '302-528-2180');

define('WAITLIST_FROM_NAME', 'United Wrestling Club');
define('WAITLIST_FROM_EMAIL', 'noreply@united-wc.com');    // Alias is fine if SMTP auth mailbox allows send-as alias
define('WAITLIST_SITE_URL', 'https://united-wc.com');
define('WAITLIST_LOGO_URL', 'https://united-wc.com/assets/uwc-logo.png');

define('WAITLIST_STORAGE_CSV', __DIR__ . '/data/waitlist_submissions.csv');
define('WAITLIST_ERROR_LOG', __DIR__ . '/data/waitlist_errors.log');
