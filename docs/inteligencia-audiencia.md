# Inteligencia de audiencia (Admin → Inteligencia)

Analiza, por campaña, qué temas conectan con qué público, en qué red y a qué
hora, qué dice la gente en los comentarios, y pronostica tendencias. Todo el
análisis es **agregado por segmento** (ciudad, edad, género, tema, horario):
no se guardan datos de personas ni textos de comentarios.

## Cómo funciona

1. **Campaña**: un grupo de páginas de Facebook/Instagram, un territorio, un
   contexto (lo lee la IA) y una lista de temas (seguridad, empleo, candidato…).
2. **Recolección** (`inteligencia:recolectar`, todos los días 02:10): recorre
   **todas las páginas integradas con token activo** (estén o no en una campaña;
   las de campañas activas primero, con una pausa entre páginas para respetar
   los límites de Meta) y guarda el histórico diario (alcance, interacciones,
   seguidores, demografía, horarios de conexión) y las publicaciones con sus
   métricas. Así la organización acumula historial completo desde el primer día.
3. **IA** (`inteligencia:analizar`, 03:10): clasifica cada publicación en un
   tema y lee los comentarios de las publicaciones con 5 o más comentarios,
   guardando solo la lectura agregada (a favor / en contra / neutro,
   preocupaciones, palabras, resumen).
4. **Tablero**: Resumen, Temas, Audiencia, Horarios, Comentarios, Pronóstico
   (tendencia por tema y alcance esperado al publicar un tema en una página),
   Publicaciones (corrección manual del tema) e Informes.
5. **Informe** (`inteligencia:informe --dias=7`, lunes 06:05): la IA redacta el
   informe semanal en markdown a partir del tablero.
6. **Vista general** (Admin → Inteligencia → "Vista general de todas las
   páginas", `/admin/inteligencia/general`): el mismo tablero pero sobre toda la
   organización, con filtros por medio, por página y por fechas (Resumen,
   Páginas y campañas, Audiencia, Horarios, Publicaciones). La pestaña "Páginas
   y campañas" ordena todas las páginas por cualquier columna y compara cada
   campaña activa contra el total (participación en el alcance y diferencia de
   tasa de interacción). "Recolectar ahora" procesa hasta 25 páginas por clic;
   el resto lo hace la tarea nocturna.

7. **Consultor de IA** (pestaña "✦ Consultor IA" en la vista general y en cada
   campaña): preguntas libres, políticas o comerciales ("¿qué emociones predominan
   frente a la seguridad?", "¿qué contenido conecta con el público de 25 a 44 años
   para vender pauta?", "propón cinco publicaciones para la próxima semana"). La IA
   recibe los datos agregados del alcance y el periodo elegidos y devuelve:
   respuesta directa, diagnóstico emocional (tono y emociones con evidencia),
   hallazgos, recomendaciones de acción con prioridad y plazo, publicaciones
   sugeridas listas para publicar (título, texto, formato, red, mejor momento,
   tema, resultado esperado), riesgos y datos faltantes. Las consultas quedan en
   el historial (`consultas_ia`). La lectura de comentarios guarda además las
   emociones (alegría, confianza, esperanza, enojo, miedo, tristeza, desconfianza,
   indiferencia), que se ven en la pestaña "Comentarios y emociones".

## Configuración

```
ANTHROPIC_API_KEY=...        # console.anthropic.com
# ANTHROPIC_MODEL=claude-opus-5-5
```

Sin clave, el módulo recolecta y muestra estadísticas, pero no clasifica, ni
lee comentarios, ni redacta informes. Las tareas programadas necesitan el cron
de Laravel (`* * * * * php artisan schedule:run`), que ya está en el servidor.

Los permisos de las páginas ya conectadas (`pages_read_engagement`,
`read_insights`, `instagram_basic`, `instagram_manage_insights`) bastan.
Meta ha ido retirando métricas demográficas de páginas de Facebook; el
recolector pide cada métrica por separado y guarda las que Meta entregue.
Instagram entrega demografía a partir de 100 seguidores.

## Comandos útiles

```
php artisan inteligencia:recolectar --dias=30 --pausa=2     # primera carga histórica de TODAS las páginas
php artisan inteligencia:recolectar --dias=30 --campana=1   # solo las páginas de una campaña
php artisan inteligencia:recolectar --solo-campanas         # solo páginas en campañas activas
php artisan inteligencia:recolectar --limite=20             # por lotes (p. ej. en hosting con poco tiempo de ejecución)
php artisan inteligencia:analizar --campana=1
php artisan inteligencia:informe 1 --dias=7
```
