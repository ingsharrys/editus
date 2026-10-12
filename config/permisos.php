<?php

/*
|--------------------------------------------------------------------------
| Permisos de editus (por módulo)
|--------------------------------------------------------------------------
| Cada rol tiene una lista de estas claves. El rol "admin" las tiene todas
| siempre (no se puede editar). Para agregar un módulo nuevo: añade aquí su
| clave y protege sus rutas con ->middleware('permiso:clave').
|
| "por_defecto" es lo que recibe el rol "user" (los usuarios normales) la
| primera vez: lo mismo que ya podían usar antes de existir los permisos.
*/

return [
    'grupos' => [
        'Redes y publicaciones' => [
            'paginas' => ['Mis páginas', 'Ver y conectar páginas de Facebook e Instagram'],
            'paginas.publicar' => ['Publicar desde editus', 'Publicar en las páginas desde el panel de Mis páginas'],
            'paginas.medio' => ['Vincular páginas con medios', 'Asociar cada página a un medio de esnoticia (publicación automática)'],
            'publicaciones' => ['Publicaciones (todas)', 'Ver todas las publicaciones enviadas y sus métricas'],
            'mis_publicaciones' => ['Mis publicaciones', 'Ver y editar las publicaciones propias'],
            'campanas' => ['Campañas', 'Crear y gestionar campañas'],
        ],
        'En vivo' => [
            'en_vivo' => ['Estudio en vivo y pregrabados', 'Transmitir, programar en vivos y pregrabados'],
        ],
        'Análisis' => [
            'estadisticas' => ['Estadísticas', 'Tablero de estadísticas de las páginas'],
            'informes' => ['Informe de publicaciones y análisis', 'Explorador de publicaciones, exportación y análisis de rendimiento'],
            'informe_admin' => ['Informes ejecutivos', 'Informes generales en PDF'],
            'inteligencia' => ['Inteligencia de audiencia', 'Campañas de inteligencia, radar, diagnósticos y proyecciones'],
        ],
        'App del editor' => [
            'app_editor' => ['App del editor', 'Páginas visibles en la app, canales de YouTube y recursos de producción'],
            'plantillas' => ['Plantillas de imagen', 'Plantillas de imagen de la app'],
        ],
        'Cuenta y planes' => [
            'suscripcion' => ['Mi suscripción', 'Ver y pagar el plan propio'],
            'suscripciones_admin' => ['Administrar suscripciones', 'Activar, cancelar y eximir planes de los clientes'],
        ],
        'Administración' => [
            'usuarios' => ['Usuarios', 'Crear, editar, activar y desactivar usuarios'],
            'roles' => ['Roles y permisos', 'Crear roles y decidir qué puede hacer cada uno'],
        ],
    ],

    'por_defecto' => ['paginas', 'mis_publicaciones', 'en_vivo', 'estadisticas', 'informes', 'suscripcion'],
];
