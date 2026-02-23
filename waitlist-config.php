<?php
declare(strict_types=1);

/*
 * UWC Waitlist form configuration
 * Replace these values with your real addresses before using in production.
 */

define('WAITLIST_ADMIN_EMAIL', 'register@united-wc.com');  // Where UWC receives submissions
define('WAITLIST_CONTACT_EMAIL', 'info@united-wc.com');    // Shown to families in confirmation email
define('WAITLIST_CONTACT_PHONE', '');                      // Optional: e.g. +1 (302) 555-1234

define('WAITLIST_FROM_NAME', 'United Wrestling Club');
define('WAITLIST_FROM_EMAIL', 'noreply@united-wc.com');    // Best if this mailbox exists on your domain

define('WAITLIST_STORAGE_CSV', __DIR__ . '/data/waitlist_submissions.csv');
define('WAITLIST_ERROR_LOG', __DIR__ . '/data/waitlist_errors.log');
