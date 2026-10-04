<?php
/**
 * Plugin Name:       SharryStreem
 * Plugin URI:        https://app.editus.online
 * Description:       Publica automáticamente tus entradas en tus páginas de Facebook e Instagram conectadas en editus. Requiere una licencia activa (plan Básico o Full).
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            editus
 * License:           GPL-2.0-or-later
 * Text Domain:       sharrystreem
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SHARRYSTREEM_VERSION', '1.0.0');
define('SHARRYSTREEM_FILE', __FILE__);
define('SHARRYSTREEM_DIR', plugin_dir_path(__FILE__));
if (!defined('SHARRYSTREEM_API')) {
    // Se puede cambiar en wp-config.php (p. ej. para pruebas): define('SHARRYSTREEM_API', 'https://...');
    define('SHARRYSTREEM_API', 'https://app.editus.online');
}

require_once SHARRYSTREEM_DIR . 'includes/class-sharrystreem-cripto.php';
require_once SHARRYSTREEM_DIR . 'includes/class-sharrystreem-api.php';
require_once SHARRYSTREEM_DIR . 'includes/class-sharrystreem-licencia.php';
require_once SHARRYSTREEM_DIR . 'includes/class-sharrystreem-admin.php';
require_once SHARRYSTREEM_DIR . 'includes/class-sharrystreem-autopost.php';

add_action('plugins_loaded', function () {
    SharryStreem_Admin::iniciar();
    SharryStreem_Autopost::iniciar();
});

register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('sharrystreem_verificar_licencia')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'twicedaily', 'sharrystreem_verificar_licencia');
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('sharrystreem_verificar_licencia');
    wp_clear_scheduled_hook('sharrystreem_publicar_entrada');
});

add_action('sharrystreem_verificar_licencia', ['SharryStreem_Licencia', 'verificar']);
