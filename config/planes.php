<?php

/*
 * Planes de suscripción de editus (precios en pesos colombianos).
 * Anual = 11 meses (un mes gratis). Los administradores y los usuarios marcados como
 * "exentos" (el equipo interno) no tienen límites.
 */
return [
    'moneda' => 'COP',
    'dias_mes' => 30,
    'dias_anio' => 365,
    'planes' => [
        'basico' => [
            'nombre' => 'Básico',
            'mensual' => 60000,
            'anual' => 660000,
            'paginas' => 2,          // páginas de Facebook/Instagram donde puede publicar
            'camaras' => 2,          // cámaras simultáneas en una transmisión
            'plantillas_logo' => false, // logo, marco e imagen de marca en el en vivo
            'sitios' => 1,           // sitios WordPress con el plugin SharryStreem
            'resumen' => ['Publica en Facebook e Instagram en 2 páginas', 'En vivo con hasta 2 cámaras', 'Plugin SharryStreem para autopost en WordPress (1 sitio)'],
        ],
        'full' => [
            'nombre' => 'Full',
            'mensual' => 90000,
            'anual' => 990000,
            'paginas' => 10,
            'camaras' => 6,
            'plantillas_logo' => true,
            'sitios' => 3,
            'resumen' => ['Publica en Facebook e Instagram en 10 páginas', 'En vivo con hasta 6 cámaras, plantillas con logo y marco', 'Plugin SharryStreem para autopost en WordPress (3 sitios)', 'Todas las funciones de publicación y transmisión'],
        ],
    ],
];
