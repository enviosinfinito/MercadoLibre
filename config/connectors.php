<?php

return [

    'mercadolibre' => [
        'client_id' => env('MELI_CLIENT_ID', env('MELI_APP_ID')),
        'client_secret' => env('MELI_CLIENT_SECRET'),
        'redirect_uri' => env('MELI_REDIRECT_URI'),
        'auth_base_url' => env('MELI_AUTH_BASE_URL', 'https://auth.mercadolibre.com.mx'),
        'api_base_url' => env('MELI_API_BASE_URL', 'https://api.mercadolibre.com'),
        'webhook_secret' => env('MELI_WEBHOOK_SECRET'),
    ],

    'mercadopago' => [
        // Seller ML OAuth token is reused against Mercado Pago report APIs.
        'api_base_url' => env('MERCADOPAGO_API_BASE_URL', 'https://api.mercadopago.com'),
    ],

    'amazon' => [
        'lwa_client_id' => env('AMAZON_LWA_CLIENT_ID'),
        'lwa_client_secret' => env('AMAZON_LWA_CLIENT_SECRET'),
        'aws_access_key' => env('AMAZON_AWS_ACCESS_KEY'),
        'aws_secret_key' => env('AMAZON_AWS_SECRET_KEY'),
        'role_arn' => env('AMAZON_ROLE_ARN'),
        'sqs_queue_url' => env('AMAZON_SQS_QUEUE_URL'),
        'marketplace_id' => env('AMAZON_MARKETPLACE_ID', 'A1AM78C64UM0Y8'),
    ],

];
