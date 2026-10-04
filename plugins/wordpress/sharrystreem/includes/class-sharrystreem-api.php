<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cliente de la API de editus. Cada petición va firmada con HMAC-SHA256; la llave completa
 * nunca se envía: se firma con sha256(llave) y solo viaja el identificador "ss_<prefijo>".
 */
class SharryStreem_Api
{
    public const RUTA = '/api/sharrystreem/v1/';

    /** Firma de una petición (misma fórmula que verifica editus). */
    public static function firmar(string $llave, string $timestamp, string $nonce, string $metodo, string $ruta, string $sitio, string $cuerpo): string
    {
        $base = implode("\n", [$timestamp, $nonce, strtoupper($metodo), $ruta, $sitio, hash('sha256', $cuerpo)]);
        return hash_hmac('sha256', $base, hash('sha256', $llave));
    }

    /** ss_<prefijo>_<secreto> → ss_<prefijo>; null si la llave no tiene el formato. */
    public static function identificador(string $llave): ?string
    {
        return preg_match('/^(ss_[a-z0-9]{12})_[A-Za-z0-9]{40}$/', $llave, $m) ? $m[1] : null;
    }

    /** URL del sitio tal como la registra editus: https://dominio.com (sin barra final). */
    public static function sitio(): string
    {
        $p = wp_parse_url(home_url());
        $url = strtolower(($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? ''));
        if (!empty($p['port'])) {
            $url .= ':' . (int) $p['port'];
        }
        return $url . untrailingslashit($p['path'] ?? '');
    }

    /**
     * @return array{ok: bool, codigo: int, datos: array, error: string}
     */
    public static function llamar(string $metodo, string $accion, array $cuerpo = [], ?string $llave = null): array
    {
        $llave = $llave ?? SharryStreem_Licencia::llave();
        $id = $llave ? self::identificador($llave) : null;
        if (!$id) {
            return ['ok' => false, 'codigo' => 0, 'datos' => [], 'error' => __('Falta la llave de licencia o no es válida.', 'sharrystreem')];
        }
        $base = rtrim((string) apply_filters('sharrystreem_api_base', SHARRYSTREEM_API), '/');
        $ruta = self::RUTA . $accion;
        $json = $cuerpo ? wp_json_encode($cuerpo) : '';
        $ts = (string) time();
        $nonce = wp_generate_password(32, false, false);
        $sitio = self::sitio();
        $respuesta = wp_remote_request($base . $ruta, [
            'method' => $metodo,
            'timeout' => 60,
            'sslverify' => true,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-SharryStreem-Key' => $id,
                'X-SharryStreem-Timestamp' => $ts,
                'X-SharryStreem-Nonce' => $nonce,
                'X-SharryStreem-Site' => $sitio,
                'X-SharryStreem-Signature' => self::firmar($llave, $ts, $nonce, $metodo, $ruta, $sitio, $json),
                'User-Agent' => 'SharryStreem/' . SHARRYSTREEM_VERSION . '; ' . $sitio,
            ],
            'body' => $metodo === 'GET' ? null : $json,
        ]);
        if (is_wp_error($respuesta)) {
            return ['ok' => false, 'codigo' => 0, 'datos' => [], 'error' => sprintf(__('No hay conexión con editus: %s', 'sharrystreem'), $respuesta->get_error_message())];
        }
        $codigo = (int) wp_remote_retrieve_response_code($respuesta);
        $datos = json_decode((string) wp_remote_retrieve_body($respuesta), true);
        $datos = is_array($datos) ? $datos : [];
        $ok = $codigo >= 200 && $codigo < 300 && !empty($datos['success']);
        return ['ok' => $ok, 'codigo' => $codigo, 'datos' => $datos, 'error' => $ok ? '' : (string) ($datos['error'] ?? sprintf(__('editus respondió con el código %d.', 'sharrystreem'), $codigo))];
    }
}
