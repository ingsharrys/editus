<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Subcampana;
use App\Models\TipoPublicacion;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Importa la «Ficha técnica 1 - cantidades» del plan de medios:
 *  - Filas = productos por canal (RADIO, MEDIOS DIGITALES, REDES SOCIALES FACEBOOK / INSTAGRAM / TIK TOK)
 *    → tipos de publicación con su canal, valor unitario, característica y meta de alcance.
 *  - Fila 1 = campañas del plan («Campaña 1: Poblaciones vulnerables») → programa de la subcampaña.
 *  - Fila 2 = subcampañas («Discapacidad», «Envejecimiento y vejez»); si la columna no tiene, la subcampaña
 *    es la misma campaña del plan.
 *  - Celdas = cantidades planeadas (piezas o, en radio, cuñas / espacios).
 * Se puede volver a importar: actualiza lo que ya existe (por nombre) y no borra nada.
 */
class FichaTecnicaService
{
    public function __construct(private ProductosService $productos) {}

    /** @return array{tipos:int, subcampanas:int, programas:int, cantidades:int, avisos:string[]} */
    public function importar(Campaign $c, string $ruta): array
    {
        $libro = IOFactory::load($ruta);
        $hoja = $this->hoja($libro);
        if (!$hoja) throw new \RuntimeException(__('No encontré la hoja de la ficha técnica (debe tener «TIPO DE PRODUCTO» en la celda A1).'));

        $maxCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($hoja->getHighestColumn());
        $maxFila = $hoja->getHighestRow();
        $val = fn(int $col, int $fila) => trim(preg_replace('/\s+/u', ' ', (string) $hoja->getCell([$col, $fila])->getFormattedValue()));

        // Columnas de campañas: desde la que tiene «Campaña» en la fila 1 (H) hasta la última
        $columnas = [];
        $grupoActual = null;
        $codigosVistos = [];
        $avisos = [];
        for ($col = 1; $col <= $maxCol; $col++) {
            $h1 = $val($col, 1);
            if ($h1 !== '' && preg_match('/^campa[ñn]a\s*([0-9]+(?:\s*-\s*[0-9]+)?)\s*:?\s*(.*)$/iu', $h1, $m)) {
                $codigo = preg_replace('/\s+/', '', $m[1]);
                // Código repetido (p. ej. dos «6-2»): el segundo pasa al siguiente libre (6-3)
                if (isset($codigosVistos[$codigo])) {
                    $nuevo = $this->siguienteCodigo($codigo, $codigosVistos);
                    $avisos[] = __('«Campaña :a» estaba repetida: «:n» quedó como Campaña :b.', ['a' => $codigo, 'n' => trim($m[2]), 'b' => $nuevo]);
                    $codigo = $nuevo;
                }
                $codigosVistos[$codigo] = true;
                $grupoActual = ['codigo' => $codigo, 'nombre' => trim($m[2]) ?: $h1];
                // Rango de la celda combinada de la fila 1
                $hasta = $col;
                foreach ($hoja->getMergeCells() as $rango) {
                    [$a, $b] = explode(':', $rango);
                    [$ca, $fa] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::coordinateFromString($a);
                    if ((int) $fa === 1 && \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($ca) === $col) {
                        $hasta = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::coordinateFromString($b)[0]);
                    }
                }
                for ($k = $col; $k <= $hasta; $k++) {
                    $columnas[$k] = ['grupo' => $grupoActual, 'sub' => $val($k, 2) ?: $grupoActual['nombre']];
                }
                $col = $hasta;
            }
        }
        if (!$columnas) throw new \RuntimeException(__('No encontré las columnas de las campañas («Campaña 1: …» en la fila 1).'));

        // Filas de productos
        $filas = [];
        $canalTexto = '';
        for ($f = 3; $f <= $maxFila; $f++) {
            $a = $val(1, $f);
            if ($a !== '') $canalTexto = $a;
            $b = $val(2, $f); $cc = $val(3, $f); $d = $val(4, $f);
            if ($b === '' && $cc === '') continue;
            if (preg_match('/^total|^administraci/iu', $a)) break;
            $canal = $this->canal($canalTexto);
            if (!$canal) continue;
            $cant = [];
            foreach ($columnas as $k => $info) {
                $n = (int) preg_replace('/[^0-9]/', '', $val($k, $f));
                if ($n > 0) $cant[$k] = $n;
            }
            $filas[] = [
                'canal' => $canal, 'producto' => $b, 'formato' => $cc, 'caracteristica' => $d,
                'valor' => $this->numero($val(5, $f)), 'meta' => $this->meta($d), 'cantidades' => $cant,
            ];
        }
        if (!$filas) throw new \RuntimeException(__('No encontré filas de productos (RADIO, MEDIOS DIGITALES, REDES SOCIALES…).'));

        return DB::transaction(function () use ($c, $filas, $columnas, $avisos) {
            $res = ['tipos' => 0, 'subcampanas' => 0, 'programas' => 0, 'cantidades' => 0, 'avisos' => $avisos];
            // Tipos
            $existentes = TipoPublicacion::where('campaign_id', $c->id)->get()->keyBy(fn($t) => mb_strtolower($t->nombre));
            $orden = (int) TipoPublicacion::where('campaign_id', $c->id)->max('orden');
            $nombres = [];
            foreach ($filas as $i => $fila) {
                $nombre = $this->nombreTipo($fila);
                // Dos filas que darían el mismo nombre se distinguen por su característica
                if (isset($nombres[$nombre])) $nombre .= ' (' . mb_substr($fila['caracteristica'], 0, 40) . ')';
                $nombres[$nombre] = true;
                $t = $existentes[mb_strtolower($nombre)] ?? new TipoPublicacion(['campaign_id' => $c->id, 'orden' => ++$orden, 'is_active' => true]);
                if (!$t->exists) $res['tipos']++;
                $t->fill([
                    'nombre' => mb_substr($nombre, 0, 120),
                    'etiqueta' => mb_substr($nombre, 0, 80),
                    'clase' => $this->clase($fila),
                    'canal' => $fila['canal'],
                    'meta_alcance' => $fila['meta'],
                    'valor_unitario' => $fila['valor'],
                    'caracteristica' => $fila['caracteristica'] ?: null,
                    'formato' => mb_substr($fila['formato'], 0, 80) ?: null,
                    'titulo_informe' => mb_strtoupper(mb_substr($nombre, 0, 120)),
                ])->save();
                $filas[$i]['tipo_id'] = $t->id;
            }
            // Subcampañas (con su campaña del plan como programa) y cantidades
            $subs = Subcampana::where('campaign_id', $c->id)->get()->keyBy(fn($s) => mb_strtolower($s->nombre));
            $programas = [];
            $ordenSub = (int) Subcampana::where('campaign_id', $c->id)->max('orden');
            foreach ($columnas as $k => $info) {
                $g = $info['grupo'];
                $claveProg = $g['codigo'];
                if (!isset($programas[$claveProg])) {
                    $antes = \App\Models\Programa::where('campaign_id', $c->id)->count();
                    $programas[$claveProg] = $this->productos->programaDesdeTexto($c, $g['codigo'] . '. ' . $g['nombre']);
                    if (\App\Models\Programa::where('campaign_id', $c->id)->count() > $antes) $res['programas']++;
                }
                $prog = $programas[$claveProg];
                $s = $subs[mb_strtolower($info['sub'])] ?? null;
                if (!$s) {
                    $s = Subcampana::create(['campaign_id' => $c->id, 'nombre' => mb_substr($info['sub'], 0, 120), 'is_active' => true, 'orden' => ++$ordenSub]);
                    $subs[mb_strtolower($info['sub'])] = $s;
                    $res['subcampanas']++;
                }
                $s->forceFill(['programa_id' => $prog?->id, 'programa' => $prog ? mb_substr($prog->etiqueta(), 0, 190) : $s->programa])->save();
                $sync = [];
                foreach ($filas as $fila) {
                    if (!empty($fila['cantidades'][$k])) $sync[$fila['tipo_id']] = ['cantidad' => $fila['cantidades'][$k]];
                }
                $s->tipos()->syncWithoutDetaching($sync);
                $res['cantidades'] += count($sync);
            }
            return $res;
        });
    }

    private function hoja($libro): ?Worksheet
    {
        foreach ($libro->getWorksheetIterator() as $h) {
            if (preg_match('/ficha\s*t[ée]cnica\s*1/iu', $h->getTitle()) && $this->esFicha($h)) return $h;
        }
        foreach ($libro->getWorksheetIterator() as $h) if ($this->esFicha($h)) return $h;
        return null;
    }

    private function esFicha(Worksheet $h): bool
    {
        return (bool) preg_match('/tipo\s+de\s+producto/iu', (string) $h->getCell('A1')->getValue());
    }

    private function canal(string $texto): ?string
    {
        $t = mb_strtolower($texto);
        return match (true) {
            str_contains($t, 'radio') => 'radio',
            str_contains($t, 'digital') => 'digital',
            str_contains($t, 'facebook') => 'facebook',
            str_contains($t, 'instagram') => 'instagram',
            str_contains($t, 'tik') => 'tiktok',
            default => null,
        };
    }

    /** «Radio comercial · Cuña programa general», «Digital · Banner largo», «Facebook · Post / Flyer»… */
    private function nombreTipo(array $f): string
    {
        $b = mb_strtolower($f['producto']); $c = mb_strtolower($f['formato']); $d = mb_strtolower($f['caracteristica']);
        $titulo = fn($s) => mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
        if ($f['canal'] === 'radio') {
            $canal = 'Radio ' . ($b ?: 'radio');
            if (str_contains($d, 'entrevista')) return $canal . ' · Entrevista ' . (preg_match('/(\d+)\s*min/u', $d, $m) ? $m[1] . ' min' : '');
            if ($c === 'cuña' || $c === 'cuna') return $canal . ' · Cuña ' . (str_contains($d, 'notic') ? 'programa noticioso' : 'programa general');
            return $canal . ' · ' . $titulo($c ?: $b);
        }
        $canal = TipoPublicacion::CANALES[$f['canal']] ?? $f['canal'];
        $canal = ['digital' => 'Digital', 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok'][$f['canal']] ?? $canal;
        $producto = match (true) {
            str_contains($b, 'banner') => $titulo($c ?: 'banner'),
            str_contains($b, 'vivo') => 'En vivo',
            str_contains($b, 'corto') => 'Video corto (Reel)',
            str_contains($b, 'largo') => 'Video largo',
            str_contains($b, 'post') || str_contains($b, 'flyer') => 'Post / Flyer',
            default => $titulo($b),
        };
        return $canal . ' · ' . trim($producto);
    }

    private function clase(array $f): string
    {
        $b = mb_strtolower($f['producto']);
        return match (true) {
            $f['canal'] === 'radio' => 'audio',
            str_contains($b, 'banner') => 'web',
            str_contains($b, 'vivo') => 'envivo',
            default => 'redes',
        };
    }

    /** «$ 1,200,000» → 1200000 */
    private function numero(string $s): ?float
    {
        $n = preg_replace('/[^0-9]/', '', $s);
        return $n === '' ? null : (float) $n;
    }

    /** «Campaña de alcance de 100.000 persona…» → 100000 */
    private function meta(string $d): ?int
    {
        return preg_match('/alcance\s+de\s+([0-9][0-9.,]*)/iu', $d, $m) ? (int) preg_replace('/[^0-9]/', '', $m[1]) : null;
    }

    private function siguienteCodigo(string $codigo, array $vistos): string
    {
        if (preg_match('/^(\d+)-(\d+)$/', $codigo, $m)) {
            $n = (int) $m[2];
            do { $n++; $nuevo = $m[1] . '-' . $n; } while (isset($vistos[$nuevo]));
            return $nuevo;
        }
        $n = 2;
        while (isset($vistos[$codigo . '-' . $n])) $n++;
        return $codigo . '-' . $n;
    }
}
