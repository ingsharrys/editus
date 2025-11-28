<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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
    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),

        // Login normal (si lo usas)
        'redirect' => env('FACEBOOK_LOGIN_REDIRECT_URI'),

        // Redirect específico para el "link" de páginas
        'link_redirect' => env('FACEBOOK_LINK_REDIRECT_URI'),

        // Scopes que pedirá Socialite
        'scopes' => array_map('trim', explode(',', env('FACEBOOK_SCOPES', 'email,pages_show_list,pages_manage_posts,pages_manage_metadata,pages_read_engagement,read_insights,business_management'))),

        'version' => env('FACEBOOK_GRAPH_VERSION', 'v23.0'),

        // Por si usas un system user token en otros lados
        'system_user_token' => env('FACEBOOK_SYSTEM_USER_TOKEN'),
    ],

    'metrics' => [
        // Si 'true', el scheduler ejecuta métricas según METRICS_CRON.
        // Si 'false', el scheduler NO ejecuta métricas (puedes correrlas por cron separado).
        'enabled' => env('METRICS_ENABLED', false),
    ],
    'whatsapp' => [
        'token' => env('WHATSAPP_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'version' => env('WHATSAPP_API_VERSION', 'v22.0'),
    ],



];
