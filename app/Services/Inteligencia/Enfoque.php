<?php

namespace App\Services\Inteligencia;

use App\Models\Campana;

/**
 * Enfoque del análisis: cambia qué indicadores se priorizan, cómo lee la IA los comentarios
 * y qué recomienda. Se deduce del tipo de campaña y se puede cambiar en la vista.
 *   politica → opinión pública, favorabilidad, temas y narrativa
 *   medio    → alcance, viralidad, formatos, horarios y crecimiento de audiencia
 *   comercio → interés, intención de compra, preguntas, quejas y ventas
 */
final class Enfoque
{
    public const NOMBRES = ['politica' => 'Campaña política', 'medio' => 'Medio de comunicación', 'comercio' => 'Comercio y marcas'];

    public static function valido(?string $e): ?string
    {
        return array_key_exists((string) $e, self::NOMBRES) ? (string) $e : null;
    }

    public static function deCampana(?Campana $c): string
    {
        $tipo = $c?->campaign_id ? $c->campaign?->tipo : null;
        return match ($tipo) {
            'politica' => 'politica',
            'comercial' => 'comercio',
            default => 'medio', // institucional, medio y la de sistema (Esnoticia)
        };
    }

    /** Qué significa "intención" en cada enfoque (columna intencion de la lectura de comentarios). */
    public static function intencion(string $e): string
    {
        return match ($e) {
            'politica' => 'apoyo o intención de voto/participación (dicen que apoyan, votarán, asistirán o compartirán)',
            'comercio' => 'intención de compra (preguntan precio, disponibilidad, cómo comprar, piden domicilio o dicen que comprarán)',
            default => 'interés por más información (piden ampliar la noticia, seguimiento, fuentes o la nota completa)',
        };
    }

    public static function etiquetaIntencion(string $e): string
    {
        return match ($e) {
            'politica' => 'Apoyo / intención de voto',
            'comercio' => 'Intención de compra',
            default => 'Interés por más información',
        };
    }

    /** Guía para la IA: qué mirar y qué no hacer en cada enfoque. */
    public static function guia(string $e): string
    {
        return match ($e) {
            'politica' => 'Enfoque: campaña política. Prioriza favorabilidad neta (a favor menos en contra), temas que movilizan, emociones del electorado, narrativa y riesgos reputacionales. '
                . 'Recomienda comunicación ética y verificable: nada de desinformación, ataques personales ni mensajes dirigidos a personas individuales; habla de segmentos (zonas, edades, temas).',
            'comercio' => 'Enfoque: comercio y marcas. Prioriza interés e intención de compra, preguntas frecuentes, objeciones y quejas, productos o servicios que más interesan, '
                . 'atención al cliente y promociones. Recomienda publicaciones que respondan dudas, muestren producto y lleven a la compra, sin prometer nada que no esté en los datos.',
            default => 'Enfoque: medio de comunicación. Prioriza alcance, viralidad (compartidos), retención en video, formatos, horarios, frecuencia de publicación, titulares y temas que más audiencia atraen, '
                . 'y crecimiento de seguidores. Recomienda periodismo responsable: titulares atractivos pero fieles a la noticia, sin clickbait engañoso.',
        };
    }
}
