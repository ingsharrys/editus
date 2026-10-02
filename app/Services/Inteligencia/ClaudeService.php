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
    public function json(string $sistema, string $usuario, array $esquema, int $maxTokens = 8000): array
    {
        $mensaje = $this->cliente()->beta->messages->create(
            model: $this->modelo(),
            maxTokens: $maxTokens,
            system: [['type' => 'text', 'text' => $sistema, 'cacheControl' => ['type' => 'ephemeral']]],
            messages: [['role' => 'user', 'content' => $usuario]],
            outputConfig: ['format' => ['type' => 'json_schema', 'schema' => $esquema], 'effort' => 'medium'],
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
