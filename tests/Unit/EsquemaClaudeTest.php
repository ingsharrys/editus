<?php

namespace Tests\Unit;

use App\Services\Inteligencia\ClaudeService;
use PHPUnit\Framework\TestCase;

/** La salida estructurada de Claude rechaza minimum/maximum/maxItems…: el esquema se limpia antes de enviarlo. */
class EsquemaClaudeTest extends TestCase
{
    public function test_quita_limites_no_soportados_y_convierte_tipos_multiples(): void
    {
        $esquema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['a', 'b', 'lista'], 'properties' => [
            'a' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
            'b' => ['type' => ['integer', 'null']],
            'lista' => ['type' => 'array', 'maxItems' => 8, 'minItems' => 1, 'items' => ['type' => 'string', 'maxLength' => 50]],
            'n' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['x'], 'properties' => ['x' => ['type' => 'integer', 'minimum' => 0]]],
        ]];
        $limpio = ClaudeService::esquemaCompatible($esquema);
        $json = json_encode($limpio);
        foreach (['minimum', 'maximum', 'maxItems', 'maxLength'] as $k) $this->assertStringNotContainsString('"' . $k . '"', $json);
        $this->assertSame(1, $limpio['properties']['lista']['minItems']);
        $this->assertSame([['type' => 'integer'], ['type' => 'null']], $limpio['properties']['b']['anyOf']);
        $this->assertSame(['a', 'b', 'lista'], $limpio['required']);
        $this->assertFalse($limpio['additionalProperties']);
        $this->assertSame('integer', $limpio['properties']['n']['properties']['x']['type']);
    }
}
