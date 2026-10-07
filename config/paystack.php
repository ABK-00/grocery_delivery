
<?php

// Paystack configuration

define('PAYSTACK_SECRET_KEY', 'sk_test_sk_test_00ee567fd03da1043da4597271a31575a4862d0c');

define(
    'PAYSTACK_BASE_URL',
    'https://api.paystack.co'
);

// Local development callback
define(
    'PAYSTACK_CALLBACK_URL',
    'http://localhost/grocery_delivery/payment_callback.php'
);
