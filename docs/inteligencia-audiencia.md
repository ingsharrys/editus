# Inteligencia de audiencia (Admin → Inteligencia)

Analiza, por campaña, qué temas conectan con qué público, en qué red y a qué
hora, qué dice la gente en los comentarios, y pronostica tendencias. Todo el
análisis es **agregado por segmento** (ciudad, edad, género, tema, horario):
no se guardan datos de personas ni textos de comentarios.

## Cómo funciona

1. **Campaña**: un grupo de páginas de Facebook/Instagram, un territorio, un
   contexto (lo lee la IA) y una lista de temas (seguridad, empleo, candidato…).
2. **Recolección** (`inteligencia:recolectar`, todos los días 02:10): por cada
   página guarda el histórico diario (alcance, interacciones, seguidores,
   demografía, horarios de conexión) y las publicaciones con sus métricas.
3. **IA** (`inteligencia:analizar`, 03:10): clasifica cada publicación en un
   tema y lee los comentarios de las publicaciones con 5 o más comentarios,
   guardando solo la lectura agregada (a favor / en contra / neutro,
   preocupaciones, palabras, resumen).
4. **Tablero**: Resumen, Temas, Audiencia, Horarios, Comentarios, Pronóstico
   (tendencia por tema y alcance esperado al publicar un tema en una página),
   Publicaciones (corrección manual del tema) e Informes.
5. **Informe** (`inteligencia:informe --dias=7`, lunes 06:05): la IA redacta el
   informe semanal en markdown a partir del tablero.

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
php artisan inteligencia:recolectar --dias=30 --campana=1   # primera carga histórica
php artisan inteligencia:analizar --campana=1
php artisan inteligencia:informe 1 --dias=7
```
