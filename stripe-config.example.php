<?php
declare(strict_types=1);

/*
 * Stripe runtime configuration (example)
 * Copy to stripe-config.php on the server (or generate during deploy) and fill with real values.
 */

define('STRIPE_MODE', 'test'); // test | live
define('STRIPE_PUBLISHABLE_KEY', 'pk_test_replace_me');
define('STRIPE_SECRET_KEY', 'sk_test_replace_me');
define('STRIPE_WEBHOOK_SECRET', ''); // optional until webhook is configured
define('STRIPE_CURRENCY', 'usd');
define('STRIPE_ENABLE_AUTOMATIC_TAX', true);

