<?php

namespace App\Http\Controllers;

use App\Services\RegionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/** Cambiar el idioma del sitio (es / en) y la moneda de los precios (COP / USD). */
class RegionController extends Controller
{
    public function __construct(private RegionService $region)
    {
    }

    public function idioma(Request $request, string $idioma): RedirectResponse
    {
        $idioma = $this->region->idiomaValido($idioma) ?? abort(404);
        if (($u = $request->user()) && Schema::hasColumn('users', 'idioma')) $u->forceFill(['idioma' => $idioma])->saveQuietly();
        return $this->volver($request)->withCookie(cookie()->forever(RegionService::COOKIE_IDIOMA, $idioma));
    }

    public function moneda(Request $request, string $moneda): RedirectResponse
    {
        $moneda = strtoupper($moneda);
        abort_unless(in_array($moneda, RegionService::MONEDAS, true), 404);
        return $this->volver($request)->withCookie(cookie(RegionService::COOKIE_MONEDA, $moneda, 60 * 24 * 30));
    }

    /** Vuelve a la página anterior (solo dentro de este sitio). */
    private function volver(Request $request): RedirectResponse
    {
        $atras = (string) url()->previous();
        $propio = $atras !== '' && parse_url($atras, PHP_URL_HOST) === $request->getHost() && $atras !== $request->fullUrl();
        return $propio ? redirect()->to($atras) : redirect('/');
    }
}
