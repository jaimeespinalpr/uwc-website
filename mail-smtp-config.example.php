<?php
declare(strict_types=1);

/*
 * GoDaddy / Microsoft 365 SMTP config (example)
 * Copy to mail-smtp-config.php on the server (or generate during deploy from secrets).
 */

define('UWC_SMTP_ENABLED', true);
define('UWC_SMTP_HOST', 'smtp.office365.com');  // GoDaddy M365 SMTP
define('UWC_SMTP_PORT', 587);
define('UWC_SMTP_ENCRYPTION', 'tls');           // tls | ssl | none
define('UWC_SMTP_USERNAME', 'gmunch@united-wc.com');
define('UWC_SMTP_PASSWORD', 'replace_with_real_password');
define('UWC_SMTP_TIMEOUT', 20);

