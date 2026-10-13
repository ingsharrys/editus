<?php

/*
|--------------------------------------------------------------------------
| Idioma y país del visitante
|--------------------------------------------------------------------------
| El sitio está en español e inglés. El idioma sale de (en orden): lo que la
| persona eligió (cookie o su perfil), el país (de habla hispana → español,
| el resto → inglés), el idioma del navegador y, si no hay nada, el de por defecto.
|
| El país decide la moneda y la pasarela: Colombia paga en pesos con Wompi y
| el resto del mundo en dólares con Square. Se lee de las cabeceras del proxy
| (Cloudflare: CF-IPCountry; Apache/LiteSpeed con GeoIP: GEOIP_COUNTRY_CODE)
| y, si no vienen, se consulta GEOIP_URL una vez por IP (queda en caché 7 días).
| Deja GEOIP_URL vacío para no consultar ningún servicio externo.
*/

return [
    'idiomas' => ['es' => 'Español', 'en' => 'English'],
    'idioma_defecto' => env('IDIOMA_DEFECTO', 'es'),

    'pais_local' => 'CO',
    // Países de habla hispana: el sitio sale en español; en el resto del mundo, en inglés
    // (siempre que la persona no haya elegido otro idioma con el selector ES | EN).
    'paises_espanol' => ['AR', 'BO', 'CL', 'CO', 'CR', 'CU', 'DO', 'EC', 'ES', 'GQ', 'GT', 'HN', 'MX', 'NI', 'PA', 'PE', 'PR', 'PY', 'SV', 'UY', 'VE'],
    // Si no se puede saber el país: true = cobrar como en Colombia (COP / Wompi)
    'pais_desconocido_local' => (bool) env('PAIS_DESCONOCIDO_LOCAL', true),
    'geoip_url' => env('GEOIP_URL', 'https://ipapi.co/{ip}/country/'),
    'geoip_timeout' => 2,
];
