<?php

return [

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    'public_site_url' => env('PUBLIC_SITE_URL', env('FRONTEND_URL', 'http://localhost:5173')),

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'pesapal' => [
        'environment' => env('PESAPAL_ENVIRONMENT', 'sandbox'),
        'consumer_key' => env('PESAPAL_CONSUMER_KEY'),
        'consumer_secret' => env('PESAPAL_CONSUMER_SECRET'),
        'callback_url' => env('PESAPAL_CALLBACK_URL'),
        'ipn_url' => env('PESAPAL_IPN_URL'),
        'ipn_id' => env('PESAPAL_IPN_ID'),
        'base_url' => env(
            'PESAPAL_BASE_URL',
            env('PESAPAL_ENVIRONMENT', 'sandbox') === 'production'
                ? 'https://pay.pesapal.com/v3/api'
                : 'https://cybqa.pesapal.com/pesapalv3/api'
        ),
    ],

    'mpesa' => [
        'environment' => env('MPESA_ENVIRONMENT', 'sandbox'),
        'consumer_key' => env('MPESA_CONSUMER_KEY'),
        'consumer_secret' => env('MPESA_CONSUMER_SECRET'),
        'shortcode' => env('MPESA_SHORTCODE'),
        'passkey' => env('MPESA_PASSKEY'),
        'callback_url' => env('MPESA_CALLBACK_URL'),
        'base_url' => env(
            'MPESA_BASE_URL',
            env('MPESA_ENVIRONMENT', 'sandbox') === 'production'
                ? 'https://api.safaricom.co.ke'
                : 'https://sandbox.safaricom.co.ke'
        ),
    ],

    'marketing' => [
        'platforms' => [
            'google' => [
                'name' => 'Google',
                'client_id' => env('MARKETING_GOOGLE_CLIENT_ID'),
                'client_secret' => env('MARKETING_GOOGLE_CLIENT_SECRET'),
                'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token_url' => 'https://oauth2.googleapis.com/token',
                'scopes' => array_filter(explode(' ', env(
                    'MARKETING_GOOGLE_SCOPES',
                    'openid https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/analytics.readonly'
                ))),
                'token_auth' => 'body',
                'token_access_path' => 'access_token',
                'token_refresh_path' => 'refresh_token',
                'account_id_path' => null,
                'authorize_params' => ['access_type' => 'offline', 'prompt' => 'consent'],
                'authorization_supported' => true,
            ],
            'meta' => [
                'name' => 'Meta (Facebook & Instagram)',
                'client_id' => env('MARKETING_META_CLIENT_ID'),
                'client_secret' => env('MARKETING_META_CLIENT_SECRET'),
                'authorize_url' => 'https://www.facebook.com/v23.0/dialog/oauth',
                'token_url' => 'https://graph.facebook.com/v23.0/oauth/access_token',
                'scopes' => array_filter(explode(' ', env(
                    'MARKETING_META_SCOPES',
                    'public_profile pages_show_list pages_read_engagement instagram_basic'
                ))),
                'token_auth' => 'body',
                'token_access_path' => 'access_token',
                'token_refresh_path' => 'refresh_token',
                'account_id_path' => null,
                'token_method' => 'get',
                'authorize_params' => [],
                'authorization_supported' => true,
            ],
            'tiktok' => [
                'name' => 'TikTok',
                'client_id' => env('MARKETING_TIKTOK_CLIENT_KEY'),
                'client_secret' => env('MARKETING_TIKTOK_CLIENT_SECRET'),
                'authorize_url' => 'https://www.tiktok.com/v2/auth/authorize/',
                'token_url' => 'https://open.tiktokapis.com/v2/oauth/token/',
                'scopes' => array_filter(explode(' ', env('MARKETING_TIKTOK_SCOPES', 'user.info.basic'))),
                'token_auth' => 'body',
                'token_client_id_field' => 'client_key',
                'token_access_path' => 'data.access_token',
                'token_refresh_path' => 'data.refresh_token',
                'account_id_path' => 'data.open_id',
                'authorize_params' => [],
                'authorize_client_id_field' => 'client_key',
                'authorization_supported' => true,
            ],
            'whatsapp' => [
                'name' => 'WhatsApp Business',
                'client_id' => env('MARKETING_WHATSAPP_CLIENT_ID'),
                'client_secret' => env('MARKETING_WHATSAPP_CLIENT_SECRET'),
                'authorize_url' => 'https://www.facebook.com/v23.0/dialog/oauth',
                'token_url' => 'https://graph.facebook.com/v23.0/oauth/access_token',
                'scopes' => array_filter(explode(' ', env(
                    'MARKETING_WHATSAPP_SCOPES',
                    'whatsapp_business_management whatsapp_business_messaging'
                ))),
                'token_auth' => 'body',
                'token_access_path' => 'access_token',
                'token_refresh_path' => 'refresh_token',
                'account_id_path' => null,
                'token_method' => 'get',
                'authorize_params' => [],
                'authorization_supported' => true,
            ],
        ],
    ],

];
