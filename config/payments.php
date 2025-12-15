<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Payment Gateway
    |--------------------------------------------------------------------------
    |
    | This option controls the default payment gateway that gets used when
    | using the Payment facade without specifying a gateway.
    |
    */

    'default' => env('PAYMENT_GATEWAY', 'stripe'),

    /*
    |--------------------------------------------------------------------------
    | Payment Mode
    |--------------------------------------------------------------------------
    |
    | This option controls the mode for all payment gateways. Set to 'sandbox'
    | for testing and 'live' for production.
    |
    */

    'mode' => env('PAYMENT_MODE', 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for handling webhooks from payment gateways.
    |
    */

    'webhook' => [
        'prefix' => 'payments/webhook',
        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Gateways
    |--------------------------------------------------------------------------
    |
    | Configure all the payment gateways here. Each gateway has its own
    | configuration requirements.
    |
    */

    'gateways' => [
        'paypal' => [
            'mode' => env('PAYPAL_MODE', 'sandbox'),
            'sandbox' => [
                'client_id' => env('PAYPAL_SANDBOX_CLIENT_ID'),
                'client_secret' => env('PAYPAL_SANDBOX_CLIENT_SECRET'),
            ],
            'live' => [
                'client_id' => env('PAYPAL_LIVE_CLIENT_ID'),
                'client_secret' => env('PAYPAL_LIVE_CLIENT_SECRET'),
            ],
            'webhook_secret' => env('PAYPAL_WEBHOOK_SECRET'),
        ],

        'stripe' => [
            'mode' => env('STRIPE_MODE', 'sandbox'),
            'sandbox' => [
                'secret_key' => env('STRIPE_SANDBOX_SECRET'),
                'api_key' => env('STRIPE_SANDBOX_KEY'),
            ],
            'live' => [
                'secret_key' => env('STRIPE_LIVE_SECRET'),
                'api_key' => env('STRIPE_LIVE_KEY'),
            ],
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        ],

        'razorpay' => [
            'mode' => env('RAZORPAY_MODE', 'sandbox'),
            'sandbox' => [
                'key_id' => env('RAZORPAY_SANDBOX_KEY_ID'),
                'key_secret' => env('RAZORPAY_SANDBOX_KEY_SECRET'),
            ],
            'live' => [
                'key_id' => env('RAZORPAY_LIVE_KEY_ID'),
                'key_secret' => env('RAZORPAY_LIVE_KEY_SECRET'),
            ],
        ],

        'paystack' => [
            'mode' => env('PAYSTACK_MODE', 'sandbox'),
            'sandbox' => [
                'secret_key' => env('PAYSTACK_SANDBOX_SECRET'),
            ],
            'live' => [
                'secret_key' => env('PAYSTACK_LIVE_SECRET'),
            ],
        ],

        'paytm' => [
            'mode' => env('PAYTM_MODE', 'sandbox'),
            'sandbox' => [
                'merchant_id' => env('PAYTM_SANDBOX_MERCHANT_ID'),
                'merchant_key' => env('PAYTM_SANDBOX_MERCHANT_KEY'),
            ],
            'live' => [
                'merchant_id' => env('PAYTM_LIVE_MERCHANT_ID'),
                'merchant_key' => env('PAYTM_LIVE_MERCHANT_KEY'),
            ],
        ],

        'flutterwave' => [
            'mode' => env('FLUTTERWAVE_MODE', 'sandbox'),
            'sandbox' => [
                'public_key' => env('FLUTTERWAVE_SANDBOX_PUBLIC_KEY'),
                'secret_key' => env('FLUTTERWAVE_SANDBOX_SECRET_KEY'),
            ],
            'live' => [
                'public_key' => env('FLUTTERWAVE_LIVE_PUBLIC_KEY'),
                'secret_key' => env('FLUTTERWAVE_LIVE_SECRET_KEY'),
            ],
        ],

        'sslcommerz' => [
            'mode' => env('SSLCOMMERZ_MODE', 'sandbox'),
            'sandbox' => [
                'store_name' => env('SSLCOMMERZ_SANDBOX_STORE_NAME'),
                'store_id' => env('SSLCOMMERZ_SANDBOX_STORE_ID'),
                'store_password' => env('SSLCOMMERZ_SANDBOX_STORE_PASSWORD'),
            ],
            'live' => [
                'store_name' => env('SSLCOMMERZ_LIVE_STORE_NAME'),
                'store_id' => env('SSLCOMMERZ_LIVE_STORE_ID'),
                'store_password' => env('SSLCOMMERZ_LIVE_STORE_PASSWORD'),
            ],
        ],

        'mollie' => [
            'mode' => env('MOLLIE_MODE', 'sandbox'),
            'sandbox' => [
                'api_key' => env('MOLLIE_SANDBOX_API_KEY'),
            ],
            'live' => [
                'api_key' => env('MOLLIE_LIVE_API_KEY'),
            ],
        ],

        'senangpay' => [
            'mode' => env('SENANGPAY_MODE', 'sandbox'),
            'sandbox' => [
                'merchant_id' => env('SENANGPAY_SANDBOX_MERCHANT_ID'),
                'secret_key' => env('SENANGPAY_SANDBOX_SECRET_KEY'),
            ],
            'live' => [
                'merchant_id' => env('SENANGPAY_LIVE_MERCHANT_ID'),
                'secret_key' => env('SENANGPAY_LIVE_SECRET_KEY'),
            ],
        ],

        'bkash' => [
            'mode' => env('BKASH_MODE', 'sandbox'),
            'sandbox' => [
                'app_key' => env('BKASH_SANDBOX_APP_KEY'),
                'app_secret' => env('BKASH_SANDBOX_APP_SECRET'),
                'username' => env('BKASH_SANDBOX_USERNAME'),
                'password' => env('BKASH_SANDBOX_PASSWORD'),
            ],
            'live' => [
                'app_key' => env('BKASH_LIVE_APP_KEY'),
                'app_secret' => env('BKASH_LIVE_APP_SECRET'),
                'username' => env('BKASH_LIVE_USERNAME'),
                'password' => env('BKASH_LIVE_PASSWORD'),
            ],
        ],

        'mercadopago' => [
            'mode' => env('MERCADOPAGO_MODE', 'sandbox'),
            'sandbox' => [
                'client_id' => env('MERCADOPAGO_SANDBOX_CLIENT_ID'),
                'client_secret' => env('MERCADOPAGO_SANDBOX_CLIENT_SECRET'),
            ],
            'live' => [
                'client_id' => env('MERCADOPAGO_LIVE_CLIENT_ID'),
                'client_secret' => env('MERCADOPAGO_LIVE_CLIENT_SECRET'),
            ],
        ],

        'cashfree' => [
            'mode' => env('CASHFREE_MODE', 'sandbox'),
            'sandbox' => [
                'app_id' => env('CASHFREE_SANDBOX_APP_ID'),
                'secret_key' => env('CASHFREE_SANDBOX_SECRET_KEY'),
            ],
            'live' => [
                'app_id' => env('CASHFREE_LIVE_APP_ID'),
                'secret_key' => env('CASHFREE_LIVE_SECRET_KEY'),
            ],
        ],

        'payfast' => [
            'mode' => env('PAYFAST_MODE', 'sandbox'),
            'sandbox' => [
                'merchant_id' => env('PAYFAST_SANDBOX_MERCHANT_ID'),
                'merchant_key' => env('PAYFAST_SANDBOX_MERCHANT_KEY'),
                'pass_phrase' => env('PAYFAST_SANDBOX_PASS_PHRASE'),
            ],
            'live' => [
                'merchant_id' => env('PAYFAST_LIVE_MERCHANT_ID'),
                'merchant_key' => env('PAYFAST_LIVE_MERCHANT_KEY'),
                'pass_phrase' => env('PAYFAST_LIVE_PASS_PHRASE'),
            ],
        ],

        'skrill' => [
            'mode' => env('SKRILL_MODE', 'sandbox'),
            'sandbox' => [
                'merchant_email' => env('SKRILL_SANDBOX_MERCHANT_EMAIL'),
                'api_password' => env('SKRILL_SANDBOX_API_PASSWORD'),
            ],
            'live' => [
                'merchant_email' => env('SKRILL_LIVE_MERCHANT_EMAIL'),
                'api_password' => env('SKRILL_LIVE_API_PASSWORD'),
            ],
        ],

        'phonepe' => [
            'mode' => env('PHONEPE_MODE', 'sandbox'),
            'sandbox' => [
                'client_id' => env('PHONEPE_SANDBOX_CLIENT_ID'),
                'merchant_user_id' => env('PHONEPE_SANDBOX_MERCHANT_USER_ID'),
                'key_index' => env('PHONEPE_SANDBOX_KEY_INDEX'),
                'secret_key' => env('PHONEPE_SANDBOX_SECRET_KEY'),
            ],
            'live' => [
                'client_id' => env('PHONEPE_LIVE_CLIENT_ID'),
                'merchant_user_id' => env('PHONEPE_LIVE_MERCHANT_USER_ID'),
                'key_index' => env('PHONEPE_LIVE_KEY_INDEX'),
                'secret_key' => env('PHONEPE_LIVE_SECRET_KEY'),
            ],
        ],

        'telr' => [
            'mode' => env('TELR_MODE', 'sandbox'),
            'sandbox' => [
                'store_id' => env('TELR_SANDBOX_STORE_ID'),
                'store_auth_key' => env('TELR_SANDBOX_STORE_AUTH_KEY'),
            ],
            'live' => [
                'store_id' => env('TELR_LIVE_STORE_ID'),
                'store_auth_key' => env('TELR_LIVE_STORE_AUTH_KEY'),
            ],
        ],

        'iyzico' => [
            'mode' => env('IYZICO_MODE', 'sandbox'),
            'sandbox' => [
                'api_key' => env('IYZICO_SANDBOX_API_KEY'),
                'secret_key' => env('IYZICO_SANDBOX_SECRET_KEY'),
            ],
            'live' => [
                'api_key' => env('IYZICO_LIVE_API_KEY'),
                'secret_key' => env('IYZICO_LIVE_SECRET_KEY'),
            ],
        ],

        'pesapal' => [
            'mode' => env('PESAPAL_MODE', 'sandbox'),
            'sandbox' => [
                'consumer_key' => env('PESAPAL_SANDBOX_CONSUMER_KEY'),
                'consumer_secret' => env('PESAPAL_SANDBOX_CONSUMER_SECRET'),
                'ipn_id' => env('PESAPAL_SANDBOX_IPN_ID'),
            ],
            'live' => [
                'consumer_key' => env('PESAPAL_LIVE_CONSUMER_KEY'),
                'consumer_secret' => env('PESAPAL_LIVE_CONSUMER_SECRET'),
                'ipn_id' => env('PESAPAL_LIVE_IPN_ID'),
            ],
        ],

        'midtrans' => [
            'mode' => env('MIDTRANS_MODE', 'sandbox'),
            'sandbox' => [
                'server_key' => env('MIDTRANS_SANDBOX_SERVER_KEY'),
                'client_key' => env('MIDTRANS_SANDBOX_CLIENT_KEY'),
            ],
            'live' => [
                'server_key' => env('MIDTRANS_LIVE_SERVER_KEY'),
                'client_key' => env('MIDTRANS_LIVE_CLIENT_KEY'),
            ],
        ],

        'myfatoorah' => [
            'mode' => env('MYFATOORAH_MODE', 'sandbox'),
            'sandbox' => [
                'api_key' => env('MYFATOORAH_SANDBOX_API_KEY'),
            ],
            'live' => [
                'api_key' => env('MYFATOORAH_LIVE_API_KEY'),
            ],
        ],

        'easypaisa' => [
            'mode' => env('EASYPAISA_MODE', 'sandbox'),
            'sandbox' => [
                'store_id' => env('EASYPAISA_SANDBOX_STORE_ID'),
                'hash_key' => env('EASYPAISA_SANDBOX_HASH_KEY'),
                'username' => env('EASYPAISA_SANDBOX_USERNAME'),
                'password' => env('EASYPAISA_SANDBOX_PASSWORD'),
            ],
            'live' => [
                'store_id' => env('EASYPAISA_LIVE_STORE_ID'),
                'hash_key' => env('EASYPAISA_LIVE_HASH_KEY'),
                'username' => env('EASYPAISA_LIVE_USERNAME'),
                'password' => env('EASYPAISA_LIVE_PASSWORD'),
            ],
        ],
    ],
];