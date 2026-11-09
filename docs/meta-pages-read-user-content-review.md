# Meta App Review — módulo de comentarios sin IA

## Alcance

Rama de prueba: \`feature/meta-comments-review-test\`. No despliega cambios ni modifica la rama \`main\`.

El módulo \`/admin/inteligencia/{campana}/meta-comentarios-prueba\` muestra comentarios de publicaciones de Facebook con una consulta **en vivo** de Graph API. No utiliza Anthropic, no ejecuta clasificación y no guarda mensajes ni autores en la base de datos.

Solo está disponible dentro del grupo de rutas \`auth\` y \`role:admin\` ya existente. Para leer los comentarios exige un Page Access Token **activo perteneciente al administrador autenticado** (no utiliza el token de otra cuenta ni el token de sistema).

## Preparación para la prueba

1. Desplegar **solo en un entorno de staging**, aislado de la producción (sin fusionar la rama hasta probarla).
2. Configurar \`FACEBOOK_LINK_SCOPES\` en el archivo \`.env\` del staging para solicitar también \`pages_read_user_content\`. Mantener los permisos existentes que la app necesita; la aprobación de Meta y la modalidad de acceso pueden limitar la disponibilidad para usuarios externos.
3. Si se utiliza \`FACEBOOK_LINK_CONFIG_ID\` (Facebook Login for Business), configurar el permiso también en esa configuración de Meta. En ese flujo los permisos pueden venir de la configuración de Login for Business en lugar del array \`link_scopes\`.
4. Limpiar la caché de configuración con \`php artisan config:clear\` y, si corresponde, \`php artisan config:cache\`. Reconectar la Página para obtener un token con los permisos disponibles.
5. Asociar la Página a la campaña y tener una publicación de Facebook registrada en \`publicaciones_redes\`. La pantalla lista las últimas 50 publicaciones de Facebook de la Página.
6. Con otra cuenta de Facebook, dejar un comentario real en una de esas publicaciones.

## Grabación recomendada para Meta

1. Ingresar a Editus con el usuario que conectó la Página.
2. Abrir Inteligencia de audiencia y hacer clic en **Comentarios Meta · prueba sin IA**.
3. Seleccionar la Página y mostrar claramente su nombre e ID.
4. Seleccionar la publicación en el selector.
5. Hacer clic en **Consultar comentarios en vivo**.
6. Mostrar el texto del comentario escrito por un tercero y el nombre de la Página en la propia interfaz de Editus. Si tiene respuestas, abrir **Ver respuestas de este comentario**.

**Importante:** si Graph API devuelve un error de permisos, no se debe simular ni inventar comentarios para la grabación. Verificar primero la configuración de la aplicación Meta, el token activo y la publicación elegida.

## Pruebas

La batería de \`tests/Feature/InteligenciaAudienciaTest.php\` añade casos que verifican:
- Recuperación del texto del comentario sin clave de Anthropic.
- Rechazo de una Página que no pertenece a la campaña.
- Ausencia de solicitudes a Graph API cuando el administrador no tiene un token propio activo.
- Que la lectura no crea registros en \`comentarios_analisis\`.

Ejecutar en el staging con la base de datos de pruebas:

\`\`\`bash
php artisan test --filter=modulo_meta
\`\`\`

No se requiere migración nueva. No se ha ejecutado una prueba contra la API real ni se ha desplegado el módulo en producción.
