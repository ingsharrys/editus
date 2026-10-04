<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Autopost: al publicar una entrada se programa su envío a las redes (en segundo plano, para no
 * demorar el guardado y para que el editor de bloques alcance a guardar la casilla de la entrada).
 * Cada entrada se publica una sola vez automáticamente; se puede reenviar a mano.
 */
class SharryStreem_Autopost
{
    private const META_PUBLICAR = '_sharrystreem_publicar';
    private const META_PAGINAS = '_sharrystreem_paginas';
    private const META_MENSAJE = '_sharrystreem_mensaje';
    private const META_RESULTADO = '_sharrystreem_resultado';
    private const META_ENVIADO = '_sharrystreem_enviado';

    public static function iniciar(): void
    {
        add_action('add_meta_boxes', [self::class, 'metabox']);
        add_action('save_post', [self::class, 'guardar'], 10, 2);
        add_action('transition_post_status', [self::class, 'alPublicar'], 10, 3);
        add_action('sharrystreem_publicar_entrada', [self::class, 'publicar']);
        add_action('admin_post_sharrystreem_reenviar', [self::class, 'reenviar']);
    }

    public static function metabox(): void
    {
        foreach (SharryStreem_Admin::ajustes()['tipos'] as $tipo) {
            add_meta_box('sharrystreem', 'SharryStreem', [self::class, 'pintar'], $tipo, 'side', 'default');
        }
    }

    public static function pintar(WP_Post $post): void
    {
        $a = SharryStreem_Admin::ajustes();
        $e = SharryStreem_Licencia::estado();
        if (!SharryStreem_Licencia::valida()) {
            echo '<p>' . esc_html__('Licencia inactiva: activa o renueva tu plan en Ajustes → SharryStreem.', 'sharrystreem') . '</p>';
            return;
        }
        wp_nonce_field('sharrystreem_entrada', 'sharrystreem_nonce');
        $marcado = get_post_meta($post->ID, self::META_PUBLICAR, true);
        $marcado = $marcado === '' ? (bool) $a['autopost'] : $marcado === '1';
        $paginas = get_post_meta($post->ID, self::META_PAGINAS, true);
        $paginas = is_array($paginas) ? array_map('intval', $paginas) : array_map('intval', $a['paginas']);
        $enviado = get_post_meta($post->ID, self::META_ENVIADO, true);
        ?>
        <p><label><input type="checkbox" name="sharrystreem_publicar" value="1" <?php checked($marcado); ?> <?php disabled((bool) $enviado); ?>> <?php esc_html_e('Publicar en redes al publicar', 'sharrystreem'); ?></label></p>
        <?php foreach ($e['paginas'] ?? [] as $p) : ?>
            <label style="display:block"><input type="checkbox" name="sharrystreem_paginas[]" value="<?php echo esc_attr((string) $p['id']); ?>" <?php checked(in_array((int) $p['id'], $paginas, true)); ?>> <?php echo esc_html($p['nombre']); ?></label>
        <?php endforeach; ?>
        <p><label for="ss-msg"><?php esc_html_e('Mensaje (opcional)', 'sharrystreem'); ?></label>
            <textarea id="ss-msg" name="sharrystreem_mensaje" rows="3" style="width:100%" placeholder="<?php esc_attr_e('Vacío = plantilla de los ajustes', 'sharrystreem'); ?>"><?php echo esc_textarea((string) get_post_meta($post->ID, self::META_MENSAJE, true)); ?></textarea></p>
        <?php
        $res = get_post_meta($post->ID, self::META_RESULTADO, true);
        if (is_array($res) && !empty($res['lineas'])) {
            echo '<p><strong>' . esc_html__('Último envío', 'sharrystreem') . '</strong> (' . esc_html(wp_date('Y-m-d H:i', (int) $res['fecha'])) . ')</p><ul style="margin:0">';
            foreach ($res['lineas'] as $l) {
                echo '<li>' . ($l['url'] ? '<a href="' . esc_url($l['url']) . '" target="_blank" rel="noopener">' . esc_html($l['texto']) . '</a>' : esc_html($l['texto'])) . '</li>';
            }
            echo '</ul>';
        } elseif ($enviado) {
            echo '<p><em>' . esc_html__('Envío programado…', 'sharrystreem') . '</em></p>';
        }
        if ($post->post_status === 'publish') {
            $url = wp_nonce_url(admin_url('admin-post.php?action=sharrystreem_reenviar&post=' . $post->ID), 'sharrystreem_reenviar_' . $post->ID);
            echo '<p><a class="button" href="' . esc_url($url) . '">' . esc_html__('Publicar ahora en redes', 'sharrystreem') . '</a></p>';
        }
    }

    public static function guardar(int $postId, WP_Post $post): void
    {
        if (!isset($_POST['sharrystreem_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['sharrystreem_nonce'])), 'sharrystreem_entrada')) {
            return;
        }
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($postId) || !current_user_can('edit_post', $postId)) {
            return;
        }
        $permitidas = array_column(SharryStreem_Licencia::estado()['paginas'] ?? [], 'id');
        update_post_meta($postId, self::META_PUBLICAR, !empty($_POST['sharrystreem_publicar']) ? '1' : '0');
        update_post_meta($postId, self::META_PAGINAS, array_values(array_intersect(array_map('intval', (array) wp_unslash($_POST['sharrystreem_paginas'] ?? [])), $permitidas)));
        update_post_meta($postId, self::META_MENSAJE, sanitize_textarea_field(wp_unslash($_POST['sharrystreem_mensaje'] ?? '')));
    }

    /** Al pasar a «publicado» por primera vez se programa el envío (60 s, para que se guarde la casilla). */
    public static function alPublicar(string $nuevo, string $anterior, WP_Post $post): void
    {
        if ($nuevo !== 'publish' || $anterior === 'publish' || wp_is_post_revision($post->ID) || !in_array($post->post_type, SharryStreem_Admin::ajustes()['tipos'], true)) {
            return;
        }
        if (!SharryStreem_Licencia::valida() || get_post_meta($post->ID, self::META_ENVIADO, true)) {
            return;
        }
        update_post_meta($post->ID, self::META_ENVIADO, time());
        wp_schedule_single_event(time() + 60, 'sharrystreem_publicar_entrada', [$post->ID]);
    }

    public static function reenviar(): void
    {
        $postId = isset($_GET['post']) ? absint($_GET['post']) : 0;
        if (!$postId || !current_user_can('edit_post', $postId)) {
            wp_die(esc_html__('No tienes permiso para hacer esto.', 'sharrystreem'), 403);
        }
        check_admin_referer('sharrystreem_reenviar_' . $postId);
        self::publicar($postId, true);
        wp_safe_redirect(get_edit_post_link($postId, 'url') ?: admin_url());
        exit;
    }

    /** Envía la entrada a editus. $manual = el editor pulsó «Publicar ahora». */
    public static function publicar(int $postId, bool $manual = false): void
    {
        $post = get_post($postId);
        if (!$post || $post->post_status !== 'publish') {
            return;
        }
        $a = SharryStreem_Admin::ajustes();
        $marcado = get_post_meta($postId, self::META_PUBLICAR, true);
        if (!$manual && ($marcado === '0' || ($marcado === '' && !$a['autopost']))) {
            return;
        }
        if (!SharryStreem_Licencia::valida()) {
            SharryStreem_Licencia::verificar();
            if (!SharryStreem_Licencia::valida()) {
                self::guardarResultado($postId, [['texto' => __('No se publicó: licencia inactiva.', 'sharrystreem'), 'url' => '']]);
                return;
            }
        }
        $paginas = get_post_meta($postId, self::META_PAGINAS, true);
        $paginas = is_array($paginas) && $paginas ? $paginas : $a['paginas'];
        $paginas = array_values(array_intersect(array_map('intval', $paginas), array_column(SharryStreem_Licencia::estado()['paginas'] ?? [], 'id')));
        if (!$paginas) {
            self::guardarResultado($postId, [['texto' => __('No se publicó: no hay páginas elegidas.', 'sharrystreem'), 'url' => '']]);
            return;
        }
        $imagen = get_the_post_thumbnail_url($postId, 'full');
        $r = SharryStreem_Api::llamar('POST', 'publicar', [
            'titulo' => html_entity_decode(wp_strip_all_tags(get_the_title($postId)), ENT_QUOTES, 'UTF-8'),
            'mensaje' => self::mensaje($post, (string) get_post_meta($postId, self::META_MENSAJE, true) ?: $a['plantilla']),
            'enlace' => get_permalink($postId),
            'imagen_url' => $imagen ?: null,
            'paginas' => $paginas,
            'facebook' => (bool) $a['facebook'],
            'instagram' => (bool) $a['instagram'] && (bool) $imagen,
            'referencia' => 'wp:' . $postId,
        ]);
        $lineas = [];
        if (!$r['ok']) {
            $lineas[] = ['texto' => sprintf(__('Error: %s', 'sharrystreem'), $r['error']), 'url' => ''];
        } else {
            foreach ((array) ($r['datos']['resultados'] ?? []) as $res) {
                foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram'] as $red => $nombre) {
                    if (empty($res[$red])) {
                        continue;
                    }
                    $ok = !empty($res[$red]['ok']);
                    $lineas[] = [
                        'texto' => ($ok ? '✓ ' : '✕ ') . $nombre . ' · ' . sanitize_text_field((string) ($res['pagina'] ?? '')) . ($ok ? '' : ': ' . sanitize_text_field((string) ($res[$red]['error'] ?? ''))),
                        'url' => $ok ? esc_url_raw((string) ($res[$red]['permalink'] ?? '')) : '',
                    ];
                }
            }
        }
        self::guardarResultado($postId, $lineas);
        SharryStreem_Admin::registrar(get_the_title($postId), implode(' | ', array_column($lineas, 'texto')));
    }

    /** Mensaje a partir de la plantilla: {titulo} {extracto} {categorias} {etiquetas}. */
    private static function mensaje(WP_Post $post, string $plantilla): string
    {
        $extracto = has_excerpt($post) ? get_the_excerpt($post) : wp_trim_words(wp_strip_all_tags(strip_shortcodes($post->post_content)), 40, '…');
        $nombres = function ($terms) {
            return is_array($terms) ? implode(', ', wp_list_pluck($terms, 'name')) : '';
        };
        $etiquetas = get_the_tags($post->ID);
        $texto = strtr($plantilla, [
            '{titulo}' => get_the_title($post),
            '{extracto}' => $extracto,
            '{categorias}' => $nombres(get_the_category($post->ID)),
            '{etiquetas}' => is_array($etiquetas) ? implode(' ', array_map(function ($t) {
                return '#' . preg_replace('/\s+/', '', $t->name);
            }, $etiquetas)) : '',
        ]);
        return trim(html_entity_decode(wp_strip_all_tags($texto), ENT_QUOTES, 'UTF-8'));
    }

    private static function guardarResultado(int $postId, array $lineas): void
    {
        update_post_meta($postId, self::META_RESULTADO, ['fecha' => time(), 'lineas' => $lineas]);
    }
}
