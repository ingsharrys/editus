<?php

namespace App\Services\Inteligencia;

/**
 * Traduce los errores de Meta a un diagnóstico en español con lo que hay que hacer.
 * Los errores de conexión (token vencido, sin permiso, sin rol) son de la PÁGINA, no de una métrica.
 */
class DiagnosticoMeta
{
    public const TIPOS = [
        'token_vencido' => 'Conexión vencida',
        'cuenta_bloqueada' => 'Cuenta de Facebook bloqueada o sin confirmar',
        'sin_rol' => 'Ya no administra la página',
        'sin_permiso' => 'Sin permiso para leer la página',
        'app_sin_acceso' => 'La app editus no tiene acceso aprobado',
    ];

    private const QUE_HACER = [
        'token_vencido' => 'Facebook cerró la sesión (cambio de contraseña o revisión de seguridad). Quien conectó la página debe volver a conectarla (editus → Mis páginas, o la app → Mis cuentas).',
        'cuenta_bloqueada' => 'La cuenta de Facebook de quien conectó la página está bloqueada o sin confirmar. Esa persona debe entrar a Facebook, completar la verificación y volver a conectar la página.',
        'sin_rol' => 'Quien conectó la página ya no es administrador/editor de ella, o el negocio exige verificación en dos pasos y esa persona no la tiene activa. Devuélvele el rol (o que active la verificación en dos pasos) y que vuelva a conectar.',
        'sin_permiso' => 'La conexión no incluye el permiso para leer la página (pages_read_engagement). Pasa si al conectar no se aceptaron todos los permisos o si la persona no tiene rol en la app editus mientras el permiso siga en «Listo para prueba» en Meta.',
        'app_sin_acceso' => 'Meta no ha aprobado este permiso para la app editus (acceso avanzado). Se solicita en developers.facebook.com → Revisión de la app.',
    ];

    /** ['tipo' => ..., 'titulo' => ..., 'texto' => ...] o null si no es un error de conexión conocido. */
    public static function clasificar(string $mensaje): ?array
    {
        $m = mb_strtolower($mensaje);
        $tipo = match (true) {
            str_contains($m, 'not a confirmed user') || str_contains($m, 'checkpoint') => 'cuenta_bloqueada',
            str_contains($m, 'session has been invalidated') || str_contains($m, 'session has expired') || str_contains($m, 'has not authorized application') || str_contains($m, 'error validating access token') => 'token_vencido',
            str_contains($m, 'must be an administrator, editor, or moderator') => 'sin_rol',
            str_contains($m, 'must be granted before impersonating') || str_contains($m, "requires the 'pages_read_engagement'") || str_contains($m, "requires the 'pages_read_user_content'") => 'sin_permiso',
            str_contains($m, 'application does not have permission') => 'app_sin_acceso',
            default => null,
        };
        return $tipo ? ['tipo' => $tipo, 'titulo' => self::TIPOS[$tipo], 'texto' => self::QUE_HACER[$tipo]] : null;
    }

    /** Métrica que Meta retiró: se prueba como respaldo y su error no le sirve al administrador. */
    public static function esMetricaRetirada(string $mensaje): bool
    {
        return str_contains($mensaje, 'must be a valid insights metric');
    }
}
