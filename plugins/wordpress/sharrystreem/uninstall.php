<?php
// Al eliminar el plugin se borran sus ajustes y la licencia guardada en este sitio.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
foreach (['sharrystreem_licencia', 'sharrystreem_estado', 'sharrystreem_ajustes', 'sharrystreem_registro'] as $opcion) {
    delete_option($opcion);
}
wp_clear_scheduled_hook('sharrystreem_verificar_licencia');
