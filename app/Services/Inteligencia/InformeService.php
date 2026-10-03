<?php

namespace App\Services\Inteligencia;

use App\Models\Campana;
use App\Models\InformeCampana;
use Carbon\Carbon;

/** Redacta con la IA el informe de una campaña a partir del tablero agregado. */
class InformeService
{
    public function __construct(private AnalisisService $analisis, private ClaudeService $ia)
    {
    }

    public function generar(Campana $campana, Carbon $desde, Carbon $hasta): InformeCampana
    {
        $datos = $this->analisis->tablero($campana, $desde, $hasta);
        // Para la IA se quitan las matrices grandes
        $paraIa = $datos;
        unset($paraIa['horarios']['matriz'], $paraIa['horarios']['en_linea'], $paraIa['serie']);
        $paraIa['serie_resumen'] = array_map(fn($d) => [$d['fecha'], $d['alcance_pagina'], $d['publicaciones'], $d['interacciones']], $datos['serie']);

        $sistema = "Eres el estratega de comunicación de la campaña \"{$campana->nombre}\"" . ($campana->territorio ? " en {$campana->territorio}" : '') . ". "
            . "Recibes datos AGREGADOS de Facebook e Instagram (por tema, formato, página, horario, demografía y lectura de comentarios). "
            . "Escribe un informe en español, en markdown, directo y útil para tomar decisiones esta semana. Estructura: "
            . "1) Lo esencial en 5 viñetas; 2) Qué temas conectan y cuáles no (con cifras); 3) Con qué público (edad, género, ciudades) y en qué red; "
            . "4) Qué dice la gente (tono, preocupaciones) y qué responder; 5) Cuándo y cómo publicar (horarios, formatos); "
            . "6) Tendencias y pronóstico para la próxima semana; 7) Cinco acciones concretas. "
            . "No inventes datos que no estén; si algo falta, dilo en una línea. No menciones personas individuales. Usa cifras redondeadas.";
        $usuario = "Periodo: {$desde->format('d/m/Y')} a {$hasta->format('d/m/Y')}.\n" . ($campana->descripcion ? "Contexto de la campaña: {$campana->descripcion}\n" : '')
            . "\nDATOS:\n" . json_encode($paraIa, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $contenido = $this->ia->texto($sistema, $usuario, 12000);
        return InformeCampana::create([
            'campana_id' => $campana->id, 'desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString(),
            'contenido' => $contenido, 'datos' => ['resumen' => $datos['resumen'], 'por_tema' => $datos['por_tema'], 'comentarios' => array_diff_key($datos['comentarios'], ['resumenes' => 1])],
        ]);
    }
}
