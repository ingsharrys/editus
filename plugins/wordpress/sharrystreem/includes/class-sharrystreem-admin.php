<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Ajustes → SharryStreem: licencia, páginas, redes y plantilla del mensaje. */
class SharryStreem_Admin
{
    public const OPCION_AJUSTES = 'sharrystreem_ajustes';
    public const OPCION_REGISTRO = 'sharrystreem_registro';

    public static function iniciar(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_sharrystreem_activar', [self::class, 'accionActivar']);
        add_action('admin_post_sharrystreem_desactivar', [self::class, 'accionDesactivar']);
        add_action('admin_post_sharrystreem_verificar', [self::class, 'accionVerificar']);
        add_action('admin_post_sharrystreem_guardar', [self::class, 'accionGuardar']);
        add_action('admin_notices', [self::class, 'avisos']);
        add_filter('plugin_action_links_' . plugin_basename(SHARRYSTREEM_FILE), function ($links) {
            array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=sharrystreem')) . '">' . esc_html__('Ajustes', 'sharrystreem') . '</a>');
            return $links;
        });
    }

    public static function ajustes(): array
    {
        $a = get_option(self::OPCION_AJUSTES, []);
        return wp_parse_args(is_array($a) ? $a : [], [
            'autopost' => true,
            'tipos' => ['post'],
            'paginas' => [],
            'facebook' => true,
            'instagram' => true,
            'plantilla' => "{titulo}\n\n{extracto}",
        ]);
    }

    public static function menu(): void
    {
        add_options_page('SharryStreem', 'SharryStreem', 'manage_options', 'sharrystreem', [self::class, 'pagina']);
    }

    private static function volver(string $msg, string $tipo = 'success'): void
    {
        set_transient('sharrystreem_aviso_' . get_current_user_id(), ['msg' => $msg, 'tipo' => $tipo], 60);
        wp_safe_redirect(admin_url('options-general.php?page=sharrystreem'));
        exit;
    }

    private static function permitir(string $accion): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permiso para hacer esto.', 'sharrystreem'), 403);
        }
        check_admin_referer($accion);
    }

    public static function accionActivar(): void
    {
        self::permitir('sharrystreem_activar');
        $llave = trim(sanitize_text_field(wp_unslash($_POST['llave'] ?? '')));
        if (!SharryStreem_Api::identificador($llave)) {
            self::volver(__('La llave no tiene el formato correcto (ss_xxxxxxxxxxxx_…). Cópiala desde editus → Mi suscripción.', 'sharrystreem'), 'error');
        }
        $r = SharryStreem_Licencia::activar($llave);
        self::volver($r['ok'] ? __('Licencia activada. Ya puedes publicar en tus redes.', 'sharrystreem') : $r['error'], $r['ok'] ? 'success' : 'error');
    }

    public static function accionDesactivar(): void
    {
        self::permitir('sharrystreem_desactivar');
        SharryStreem_Licencia::desactivar();
        self::volver(__('Licencia desvinculada de este sitio.', 'sharrystreem'));
    }

    public static function accionVerificar(): void
    {
        self::permitir('sharrystreem_verificar');
        $r = SharryStreem_Licencia::verificar();
        self::volver($r['ok'] ? __('Licencia verificada.', 'sharrystreem') : ($r['error'] ?: __('No se pudo verificar.', 'sharrystreem')), $r['ok'] ? 'success' : 'error');
    }

    public static function accionGuardar(): void
    {
        self::permitir('sharrystreem_guardar');
        $permitidas = array_column(SharryStreem_Licencia::estado()['paginas'] ?? [], 'id');
        $tiposValidos = array_keys(get_post_types(['public' => true]));
        $paginas = array_values(array_intersect(array_map('intval', (array) wp_unslash($_POST['paginas'] ?? [])), $permitidas));
        $tipos = array_values(array_intersect(array_map('sanitize_key', (array) wp_unslash($_POST['tipos'] ?? [])), $tiposValidos));
        update_option(self::OPCION_AJUSTES, [
            'autopost' => !empty($_POST['autopost']),
            'tipos' => $tipos ?: ['post'],
            'paginas' => $paginas,
            'facebook' => !empty($_POST['facebook']),
            'instagram' => !empty($_POST['instagram']),
            'plantilla' => sanitize_textarea_field(wp_unslash($_POST['plantilla'] ?? '')) ?: "{titulo}\n\n{extracto}",
        ], false);
        self::volver(__('Ajustes guardados.', 'sharrystreem'));
    }

    public static function avisos(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $clave = 'sharrystreem_aviso_' . get_current_user_id();
        $aviso = get_transient($clave);
        if ($aviso) {
            delete_transient($clave);
            printf('<div class="notice notice-%s is-dismissible"><p><strong>SharryStreem:</strong> %s</p></div>', esc_attr($aviso['tipo'] === 'error' ? 'error' : 'success'), esc_html($aviso['msg']));
        }
        $e = SharryStreem_Licencia::estado();
        if (SharryStreem_Licencia::llave() !== '' && !SharryStreem_Licencia::valida()) {
            printf('<div class="notice notice-warning"><p><strong>SharryStreem:</strong> %s <a href="%s">%s</a></p></div>',
                esc_html(($e['error'] ?? '') ?: __('Tu licencia no está activa: el autopost está detenido.', 'sharrystreem')),
                esc_url(rtrim(SHARRYSTREEM_API, '/') . '/suscripcion'), esc_html__('Renovar suscripción', 'sharrystreem'));
        }
    }

    public static function pagina(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $e = SharryStreem_Licencia::estado();
        $a = self::ajustes();
        $tieneLlave = SharryStreem_Licencia::llave() !== '';
        $valida = SharryStreem_Licencia::valida();
        $id = $tieneLlave ? SharryStreem_Api::identificador(SharryStreem_Licencia::llave()) : null;
        $registro = get_option(self::OPCION_REGISTRO, []);
        ?>
        <div class="wrap">
            <h1>SharryStreem <span style="font-size:13px;color:#646970;font-weight:400">v<?php echo esc_html(SHARRYSTREEM_VERSION); ?></span></h1>
            <p><?php esc_html_e('Publica automáticamente tus entradas en las páginas de Facebook e Instagram de tu plan de editus.', 'sharrystreem'); ?></p>

            <div class="card" style="max-width:820px">
                <h2><?php esc_html_e('Licencia', 'sharrystreem'); ?></h2>
                <?php if ($tieneLlave) : ?>
                    <table class="form-table" role="presentation">
                        <tr><th><?php esc_html_e('Llave', 'sharrystreem'); ?></th><td><code><?php echo esc_html($id . '_••••••••'); ?></code></td></tr>
                        <tr><th><?php esc_html_e('Estado', 'sharrystreem'); ?></th><td><?php echo $valida ? '<span style="color:#008a20;font-weight:600">● ' . esc_html__('Activa', 'sharrystreem') . '</span>' : '<span style="color:#d63638;font-weight:600">● ' . esc_html__('Inactiva', 'sharrystreem') . '</span> ' . esc_html($e['error'] ?? ''); ?></td></tr>
                        <tr><th><?php esc_html_e('Plan', 'sharrystreem'); ?></th><td><?php echo esc_html(($e['plan'] ?? '') ?: '—'); ?><?php if (!empty($e['vence_en'])) : ?> · <?php printf(esc_html__('vence el %1$s (%2$d días)', 'sharrystreem'), esc_html(wp_date(get_option('date_format'), strtotime($e['vence_en']))), (int) ($e['dias_restantes'] ?? 0)); ?><?php endif; ?></td></tr>
                        <tr><th><?php esc_html_e('Sitio', 'sharrystreem'); ?></th><td><code><?php echo esc_html(SharryStreem_Api::sitio()); ?></code></td></tr>
                    </table>
                    <p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline"><?php wp_nonce_field('sharrystreem_verificar'); ?><input type="hidden" name="action" value="sharrystreem_verificar"><?php submit_button(__('Verificar ahora', 'sharrystreem'), 'secondary', 'submit', false); ?></form>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js(__('¿Desvincular la licencia de este sitio?', 'sharrystreem')); ?>')"><?php wp_nonce_field('sharrystreem_desactivar'); ?><input type="hidden" name="action" value="sharrystreem_desactivar"><?php submit_button(__('Desvincular', 'sharrystreem'), 'delete', 'submit', false); ?></form>
                    </p>
                <?php else : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('sharrystreem_activar'); ?>
                        <input type="hidden" name="action" value="sharrystreem_activar">
                        <p><label for="ss-llave"><?php esc_html_e('Pega la llave de tu suscripción (editus → Mi suscripción):', 'sharrystreem'); ?></label></p>
                        <p><input type="password" id="ss-llave" name="llave" class="regular-text code" autocomplete="off" placeholder="ss_xxxxxxxxxxxx_…" required style="width:100%;max-width:560px"></p>
                        <?php submit_button(__('Activar licencia', 'sharrystreem')); ?>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ($tieneLlave) : ?>
            <div class="card" style="max-width:820px">
                <h2><?php esc_html_e('Publicación automática', 'sharrystreem'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('sharrystreem_guardar'); ?>
                    <input type="hidden" name="action" value="sharrystreem_guardar">
                    <table class="form-table" role="presentation">
                        <tr><th><?php esc_html_e('Autopost', 'sharrystreem'); ?></th><td><label><input type="checkbox" name="autopost" value="1" <?php checked($a['autopost']); ?>> <?php esc_html_e('Publicar en redes al publicar una entrada (se puede desmarcar en cada entrada)', 'sharrystreem'); ?></label></td></tr>
                        <tr><th><?php esc_html_e('Tipos de contenido', 'sharrystreem'); ?></th><td>
                            <?php foreach (get_post_types(['public' => true], 'objects') as $tipo) : if ($tipo->name === 'attachment') { continue; } ?>
                                <label style="margin-right:12px"><input type="checkbox" name="tipos[]" value="<?php echo esc_attr($tipo->name); ?>" <?php checked(in_array($tipo->name, $a['tipos'], true)); ?>> <?php echo esc_html($tipo->labels->singular_name); ?></label>
                            <?php endforeach; ?>
                        </td></tr>
                        <tr><th><?php esc_html_e('Páginas por defecto', 'sharrystreem'); ?></th><td>
                            <?php if (empty($e['paginas'])) : ?>
                                <em><?php esc_html_e('Tu plan aún no tiene páginas. Conéctalas y elígelas en editus → Mi suscripción, y pulsa «Verificar ahora».', 'sharrystreem'); ?></em>
                            <?php else : foreach ($e['paginas'] as $p) : ?>
                                <label style="display:block;margin-bottom:4px"><input type="checkbox" name="paginas[]" value="<?php echo esc_attr((string) $p['id']); ?>" <?php checked(in_array((int) $p['id'], array_map('intval', $a['paginas']), true)); ?>> <?php echo esc_html($p['nombre']); ?><?php echo $p['instagram'] ? ' <span style="color:#c13584">+ Instagram</span>' : ''; ?></label>
                            <?php endforeach; endif; ?>
                        </td></tr>
                        <tr><th><?php esc_html_e('Redes', 'sharrystreem'); ?></th><td>
                            <label style="margin-right:12px"><input type="checkbox" name="facebook" value="1" <?php checked($a['facebook']); ?>> Facebook</label>
                            <label><input type="checkbox" name="instagram" value="1" <?php checked($a['instagram']); ?>> Instagram <span class="description"><?php esc_html_e('(necesita imagen destacada)', 'sharrystreem'); ?></span></label>
                        </td></tr>
                        <tr><th><label for="ss-plantilla"><?php esc_html_e('Mensaje', 'sharrystreem'); ?></label></th><td>
                            <textarea id="ss-plantilla" name="plantilla" rows="4" class="large-text"><?php echo esc_textarea($a['plantilla']); ?></textarea>
                            <p class="description"><?php esc_html_e('Variables: {titulo} {extracto} {categorias} {etiquetas}. El enlace de la entrada se agrega solo.', 'sharrystreem'); ?></p>
                        </td></tr>
                    </table>
                    <?php submit_button(__('Guardar ajustes', 'sharrystreem')); ?>
                </form>
            </div>

            <div class="card" style="max-width:820px">
                <h2><?php esc_html_e('Últimas publicaciones', 'sharrystreem'); ?></h2>
                <?php if (empty($registro)) : ?>
                    <p><em><?php esc_html_e('Aún no hay publicaciones.', 'sharrystreem'); ?></em></p>
                <?php else : ?>
                    <table class="widefat striped"><thead><tr><th><?php esc_html_e('Fecha', 'sharrystreem'); ?></th><th><?php esc_html_e('Entrada', 'sharrystreem'); ?></th><th><?php esc_html_e('Resultado', 'sharrystreem'); ?></th></tr></thead><tbody>
                    <?php foreach ($registro as $fila) : ?>
                        <tr><td><?php echo esc_html(wp_date('Y-m-d H:i', (int) $fila['fecha'])); ?></td><td><?php echo esc_html($fila['titulo']); ?></td><td><?php echo esc_html($fila['resumen']); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function registrar(string $titulo, string $resumen): void
    {
        $registro = get_option(self::OPCION_REGISTRO, []);
        $registro = is_array($registro) ? $registro : [];
        array_unshift($registro, ['fecha' => time(), 'titulo' => wp_strip_all_tags($titulo), 'resumen' => $resumen]);
        update_option(self::OPCION_REGISTRO, array_slice($registro, 0, 20), false);
    }
}
