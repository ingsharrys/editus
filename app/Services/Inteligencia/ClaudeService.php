<?php

namespace App\Services\Inteligencia;

use Anthropic\Client;
use Illuminate\Support\Facades\Log;

/**
 * Acceso a Claude (SDK oficial de Anthropic) para el módulo de inteligencia:
 * respuestas en JSON con esquema (clasificación, lectura de comentarios) y
 * texto largo (informes). Si no hay clave configurada, el módulo sigue
 * funcionando sin IA.
 */
class ClaudeService
{
    private ?Client $cliente = null;

    public function configurado(): bool
    {
        return (string) config('services.anthropic.key') !== '';
    }

    public function modelo(): string
    {
        return (string) config('services.anthropic.model', 'claude-opus-5-5');
    }

    /**
     * Pide una respuesta que cumpla el esquema JSON dado y la devuelve decodificada.
     * @param array $esquema JSON Schema (type object, additionalProperties false, required)
     */
    public function json(string $sistema, string $usuario, array $esquema, int $maxTokens = 8000, string $effort = 'medium'): array
    {
        $mensaje = $this->cliente()->beta->messages->create(
            model: $this->modelo(),
            maxTokens: $maxTokens,
            system: [['type' => 'text', 'text' => $sistema, 'cacheControl' => ['type' => 'ephemeral']]],
            messages: [['role' => 'user', 'content' => $usuario]],
            outputConfig: ['format' => ['type' => 'json_schema', 'schema' => $esquema], 'effort' => $effort],
            betas: ['server-side-fallback-2026-07-01'],
            fallbacks: 'default',
            requestOptions: ['timeout' => 240.0],
        );
        $this->comprobarParada($mensaje);
        foreach ($mensaje->content as $bloque) {
            if ($bloque->type === 'text') {
                $datos = json_decode($bloque->text, true);
                if (is_array($datos)) return $datos;
            }
        }
        throw new \RuntimeException('La IA no devolvió un JSON válido');
    }

    /** Pide un texto largo (markdown). */
    public function texto(string $sistema, string $usuario, int $maxTokens = 16000): string
    {
        $mensaje = $this->cliente()->beta->messages->create(
            model: $this->modelo(),
            maxTokens: $maxTokens,
            system: [['type' => 'text', 'text' => $sistema, 'cacheControl' => ['type' => 'ephemeral']]],
            messages: [['role' => 'user', 'content' => $usuario]],
            outputConfig: ['effort' => 'high'],
            betas: ['server-side-fallback-2026-07-01'],
            fallbacks: 'default',
            requestOptions: ['timeout' => 300.0],
        );
        $this->comprobarParada($mensaje);
        $texto = '';
        foreach ($mensaje->content as $bloque) {
            if ($bloque->type === 'text') $texto .= $bloque->text;
        }
        if (trim($texto) === '') throw new \RuntimeException('La IA no devolvió texto');
        return $texto;
    }

    /**
     * Investiga en la web con la herramienta de búsqueda de Claude (se ejecuta en los servidores de Anthropic).
     * Devuelve el texto de la respuesta, las fuentes que la búsqueda trajo de verdad (url, título, fecha),
     * cuántas búsquedas hizo y si el turno quedó en pausa (límite de iteraciones del servidor).
     * @param array $ubicacion ['city' => ..., 'region' => ..., 'country' => 'CO']
     */
    public function investigar(string $sistema, string $usuario, array $ubicacion = [], int $maxBusquedas = 3, int $maxTokens = 12000): array
    {
        $herramienta = ['type' => 'web_search_20260209', 'name' => 'web_search', 'maxUses' => $maxBusquedas];
        $ubicacion = array_filter($ubicacion);
        if ($ubicacion) $herramienta['userLocation'] = ['type' => 'approximate'] + $ubicacion + ['timezone' => 'America/Bogota'];

        $mensaje = $this->cliente()->beta->messages->create(
            model: $this->modelo(),
            maxTokens: $maxTokens,
            system: [['type' => 'text', 'text' => $sistema, 'cacheControl' => ['type' => 'ephemeral']]],
            messages: [['role' => 'user', 'content' => $usuario]],
            tools: [$herramienta],
            outputConfig: ['effort' => 'medium'],
            betas: ['server-side-fallback-2026-07-01'],
            fallbacks: 'default',
            requestOptions: ['timeout' => 160.0],
        );
        if (($mensaje->stopReason ?? null) === 'refusal') $this->comprobarParada($mensaje);

        $texto = '';
        $fuentes = [];
        $busquedas = 0;
        $agregar = function ($url, $titulo, $fecha = null) use (&$fuentes) {
            $url = trim((string) $url);
            if ($url === '' || !preg_match('#^https?://#i', $url)) return;
            $fuentes[$url] ??= ['url' => $url, 'titulo' => trim((string) $titulo) ?: $url, 'fecha' => $fecha ? (string) $fecha : null];
        };
        foreach ($mensaje->content as $bloque) {
            $tipo = $bloque->type ?? '';
            if ($tipo === 'server_tool_use' && ($bloque->name ?? '') === 'web_search') $busquedas++;
            if ($tipo === 'web_search_tool_result' && is_array($bloque->content ?? null)) {
                foreach ($bloque->content as $r) $agregar($r->url ?? null, $r->title ?? null, $r->pageAge ?? null);
            }
            if ($tipo === 'text') {
                $texto .= $bloque->text;
                foreach ((array) ($bloque->citations ?? []) as $c) $agregar($c->url ?? null, $c->title ?? null);
            }
        }
        return ['texto' => $texto, 'fuentes' => array_values($fuentes), 'busquedas' => $busquedas, 'pausado' => ($mensaje->stopReason ?? null) === 'pause_turn'];
    }

    /** Extrae el primer objeto JSON de un texto (la búsqueda web no usa salida estructurada). */
    public static function extraerJson(string $texto): ?array
    {
        $texto = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $texto);
        $ini = strpos($texto, '{');
        $fin = strrpos($texto, '}');
        if ($ini === false || $fin === false || $fin <= $ini) return null;
        $datos = json_decode(substr($texto, $ini, $fin - $ini + 1), true);
        return is_array($datos) ? $datos : null;
    }

    private function comprobarParada(object $mensaje): void
    {
        if (($mensaje->stopReason ?? null) === 'refusal') {
            $cat = $mensaje->stopDetails->category ?? 'sin categoría';
            Log::warning('[inteligencia] la IA declinó la solicitud', ['categoria' => $cat]);
            throw new \RuntimeException("La IA declinó la solicitud ({$cat})");
        }
        if (($mensaje->stopReason ?? null) === 'max_tokens') {
            throw new \RuntimeException('La respuesta de la IA quedó cortada (max_tokens)');
        }
    }

    private function cliente(): Client
    {
        if (!$this->configurado()) {
            throw new \RuntimeException('Falta ANTHROPIC_API_KEY en el .env de editus');
        }
        return $this->cliente ??= new Client(apiKey: (string) config('services.anthropic.key'));
    }
}
