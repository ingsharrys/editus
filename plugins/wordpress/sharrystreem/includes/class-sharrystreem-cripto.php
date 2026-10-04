<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Guarda la llave de licencia cifrada en la base de datos de WordPress (AES-256-GCM con
 * una clave derivada de las SALT de wp-config.php). Si el servidor no tiene OpenSSL,
 * se guarda tal cual (la base de datos de WordPress ya es privada).
 */
class SharryStreem_Cripto
{
    private static function clave(): string
    {
        return hash('sha256', wp_salt('auth') . '|sharrystreem', true);
    }

    public static function cifrar(string $texto): string
    {
        if ($texto === '' || !function_exists('openssl_encrypt')) {
            return $texto;
        }
        $iv = random_bytes(12);
        $tag = '';
        $cifrado = openssl_encrypt($texto, 'aes-256-gcm', self::clave(), OPENSSL_RAW_DATA, $iv, $tag);
        return $cifrado === false ? $texto : 'v1:' . base64_encode($iv . $tag . $cifrado);
    }

    public static function descifrar(string $guardado): string
    {
        if (strpos($guardado, 'v1:') !== 0 || !function_exists('openssl_decrypt')) {
            return $guardado;
        }
        $bin = base64_decode(substr($guardado, 3), true);
        if ($bin === false || strlen($bin) < 29) {
            return '';
        }
        $texto = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', self::clave(), OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16));
        return $texto === false ? '' : $texto;
    }
}
