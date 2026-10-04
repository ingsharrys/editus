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
            explode(',', env('FACEBOOK_LINK_SCOPES', 'email,public_profile,pages_show_list,pages_manage_posts,pages_manage_metadata,pages_read_engagement,pages_read_user_content,read_insights,business_management,instagram_basic,instagram_manage_insights,instagram_manage_comments,instagram_content_publish'))
        ))),
        'scopes' => array_values(array_filter(array_map(
            'trim',
            explode(',', env('FACEBOOK_LINK_SCOPES', 'email,public_profile,pages_show_list,pages_manage_posts,pages_manage_metadata,pages_read_engagement,pages_read_user_content,read_insights,business_management,instagram_basic,instagram_manage_insights,instagram_manage_comments,instagram_content_publish'))
        ))),
        'system_user_token' => env('FACEBOOK_SYSTEM_USER_TOKEN'),
    ],

    // Pagos de suscripciones (Wompi). Las llaves solo en el .env.
    'wompi' => [
        'env' => env('WOMPI_ENV', 'sandbox'), // sandbox | production
        'public_key' => env('WOMPI_PUBLIC_KEY', ''),
        'integrity_secret' => env('WOMPI_INTEGRITY_SECRET', ''),
        'events_secret' => env('WOMPI_EVENTS_SECRET', ''),
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



    // Backend de esnoticia (usuarios de la app = periodistas). editus le pide la lista
    // con el mismo token de integración para el selector "Periodistas que ven esta página".
    'esnoticia' => [
        'url' => env('ESNOTICIA_URL', 'https://backend.esnoticia.org/public'),
    ],

    // App del editor (React Native): esquema del deep link al que vuelve el navegador
    // después de conectar una cuenta desde la app (editor://cuentas)
    'editor_app' => [
        'scheme' => env('EDITOR_APP_SCHEME', 'editor'),
    ],

    // Google (YouTube Live): OAuth para conectar canales y transmitir en vivo desde la app
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL') . '/auth/youtube/callback'),
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
    // Integración con el sistema de noticias (backend.esnoticia.org):
    // token compartido del endpoint puente /api/articulos/publicar.
    'editus' => [
        'ingest_token' => env('EDITUS_INGEST_TOKEN'),

        // Medios del sistema de noticias (los slugs que envía esnoticia)
        'medios' => [
            'opanoticias' => 'Opanoticias',
            'depindo' => 'De Pindo',
            'labalsa' => 'La Balsa',
            'elcivico' => 'El Cívico',
            'prensayuma' => 'Prensa Yuma',
            'greengonews' => 'Greengo News',
            'neiva24' => 'Neiva 24',
            'lasurco' => 'La Surco',
            'latinreds' => 'Latin Reds',
            'neivaaldia' => 'Neiva al Día',
        ],
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
        // Opcional: origen desde el que la escena carga los recursos (intro, plantillas, publicidad), p. ej. https://live.tudominio.com/recursos
        'recursos_url' => env('LIVEKIT_RECURSOS_URL'),
    ],
    'whatsapp' => [
        'token' => env('WHATSAPP_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'version' => env('WHATSAPP_API_VERSION', 'v22.0'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
    ],



];
