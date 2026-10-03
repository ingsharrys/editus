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
    // config/services.php
    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_LOGIN_REDIRECT_URI'),
        'version' => env('FACEBOOK_GRAPH_VERSION', 'v23.0'),

        'login_scopes' => array_values(array_filter(array_map(
            'trim',
            explode(',', env('FACEBOOK_LOGIN_SCOPES', 'email,public_profile'))
        ))),

        // opcional: por si luego tu app está en “Login for Business”
        'login_config_id' => env('FACEBOOK_LOGIN_CONFIG_ID'),

        // Conexión/sincronización de páginas (usadas por FacebookPageController)
        'link_redirect' => env('FACEBOOK_LINK_REDIRECT_URI'),
        'link_config_id' => env('FACEBOOK_LINK_CONFIG_ID'),
        'link_scopes' => array_values(array_filter(array_map(
            'trim',
            explode(',', env('FACEBOOK_LINK_SCOPES', 'email,public_profile,pages_show_list,pages_manage_posts,pages_manage_metadata,pages_read_engagement,read_insights,business_management'))
        ))),
        'scopes' => array_values(array_filter(array_map(
            'trim',
            explode(',', env('FACEBOOK_LINK_SCOPES', 'email,public_profile,pages_show_list,pages_manage_posts,pages_manage_metadata,pages_read_engagement,read_insights,business_management'))
        ))),
        'system_user_token' => env('FACEBOOK_SYSTEM_USER_TOKEN'),
    ],

    'facebook_login' => [
        'client_id' => env('FACEBOOK_LOGIN_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_LOGIN_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_LOGIN_REDIRECT_URI'),
        'version' => env('FACEBOOK_GRAPH_VERSION', 'v23.0'),
        'scopes' => array_filter(array_map('trim', explode(',', env('FACEBOOK_LOGIN_SCOPES', 'email,public_profile')))),
    ],

    'facebook_business' => [
        'client_id' => env('FACEBOOK_BUSINESS_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_BUSINESS_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_LINK_REDIRECT_URI'),
        'version' => env('FACEBOOK_GRAPH_VERSION', 'v23.0'),
        'scopes' => array_filter(array_map('trim', explode(',', env('FACEBOOK_LINK_SCOPES', '')))),
        'config_id' => env('FACEBOOK_LINK_CONFIG_ID'), // si algún día lo necesitas en business login
    ],



    // API de integración (backend esnoticia → editus). El backend manda el
    // mismo valor en el header X-Editus-Token.
    'editus' => [
        'ingest_token' => env('EDITUS_INGEST_TOKEN'),
    ],

    // IA (Claude) para la inteligencia de audiencia: clasificar temas, leer comentarios y redactar informes
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5-5'),
    ],

    'metrics' => [
        // Si 'true', el scheduler ejecuta métricas según METRICS_CRON.
        // Si 'false', el scheduler NO ejecuta métricas (puedes correrlas por cron separado).
        'enabled' => env('METRICS_ENABLED', false),
    ],

    // Transmisiones en vivo (servidor LiveKit propio: ver infra/en-vivo/README.md)
    'livekit' => [
        'url'        => env('LIVEKIT_URL'),            // wss://live.tudominio.com (lo usan la app y la escena)
        'api_url'    => env('LIVEKIT_API_URL'),        // https://live.tudominio.com (API Twirp); si falta, se deriva de url
        'api_key'    => env('LIVEKIT_API_KEY'),
        'api_secret' => env('LIVEKIT_API_SECRET'),
        // Página de la escena (plantilla en tiempo real) que compone el egress; por defecto /en-vivo/escena de este editus
        'escena_url' => env('LIVEKIT_ESCENA_URL'),
        // Calidad de la transmisión que sale a Facebook: 1080 (por defecto) o 720 si el VPS se queda corto
        'calidad'    => env('LIVEKIT_CALIDAD', '1080'),
    ],
    'whatsapp' => [
        'token' => env('WHATSAPP_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'version' => env('WHATSAPP_API_VERSION', 'v22.0'),
    ],



];
