<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Licencia: llave guardada cifrada y estado en caché (plan, vencimiento, páginas del plan). */
class SharryStreem_Licencia
{
    public const OPCION_LLAVE = 'sharrystreem_licencia';
    public const OPCION_ESTADO = 'sharrystreem_estado';

    public static function llave(): string
    {
        return SharryStreem_Cripto::descifrar((string) get_option(self::OPCION_LLAVE, ''));
    }

    public static function guardarLlave(string $llave): void
    {
        update_option(self::OPCION_LLAVE, SharryStreem_Cripto::cifrar($llave), false);
    }

    public static function estado(): array
    {
        $e = get_option(self::OPCION_ESTADO, []);
        return is_array($e) ? $e : [];
    }

    /** ¿Se puede publicar? Licencia verificada, vigente y no vencida según la fecha guardada. */
    public static function valida(): bool
    {
        $e = self::estado();
        if (empty($e['valida']) || empty($e['vence_en'])) {
            return false;
        }
        return strtotime((string) $e['vence_en']) > time();
    }

    /** Vincula este sitio a la licencia y guarda el estado. */
    public static function activar(string $llave): array
    {
        $r = SharryStreem_Api::llamar('POST', 'activar', [], $llave);
        if ($r['ok']) {
            self::guardarLlave($llave);
        }
        self::guardarEstado($r);
        return $r;
    }

    /** Verificación periódica (cron dos veces al día): si la suscripción venció, el autopost se detiene. */
    public static function verificar(): array
    {
        if (self::llave() === '') {
            return ['ok' => false, 'codigo' => 0, 'datos' => [], 'error' => ''];
        }
        $r = SharryStreem_Api::llamar('GET', 'estado');
        // Sin conexión no se invalida: se conserva el último estado conocido
        if ($r['codigo'] === 0 && self::llave() !== '') {
            return $r;
        }
        self::guardarEstado($r);
        return $r;
    }

    public static function desactivar(): void
    {
        if (self::llave() !== '') {
            SharryStreem_Api::llamar('POST', 'desactivar');
        }
        delete_option(self::OPCION_LLAVE);
        delete_option(self::OPCION_ESTADO);
    }

    private static function guardarEstado(array $r): void
    {
        $d = $r['datos'];
        update_option(self::OPCION_ESTADO, [
            'valida' => $r['ok'],
            'error' => $r['ok'] ? '' : $r['error'],
            'codigo' => $r['codigo'],
            'plan' => sanitize_text_field((string) ($d['plan_nombre'] ?? '')),
            'vence_en' => sanitize_text_field((string) ($d['vence_en'] ?? '')),
            'dias_restantes' => (int) ($d['dias_restantes'] ?? 0),
            'paginas' => array_values(array_map(function ($p) {
                return ['id' => (int) ($p['id'] ?? 0), 'nombre' => sanitize_text_field((string) ($p['nombre'] ?? '')), 'instagram' => !empty($p['instagram'])];
            }, is_array($d['paginas'] ?? null) ? $d['paginas'] : [])),
            'verificado_en' => time(),
        ], false);
    }
}
