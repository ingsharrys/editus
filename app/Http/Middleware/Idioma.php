<?php

namespace App\Http\Middleware;

use App\Services\RegionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/** Pone el idioma de la petición (español o inglés) en Laravel y en las fechas. */
class Idioma
{
    public function __construct(private RegionService $region)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $idioma = $this->region->idioma($request);
        App::setLocale($idioma);
        \Carbon\Carbon::setLocale($idioma);
        return $next($request);
    }
}
