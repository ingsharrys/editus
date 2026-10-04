=== SharryStreem ===
Contributors: editus
Tags: facebook, instagram, autopost, redes sociales
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Publica automáticamente tus entradas de WordPress en tus páginas de Facebook e Instagram conectadas en editus.

== Descripción ==

SharryStreem envía cada entrada que publicas a las páginas de Facebook e Instagram de tu plan de editus
(Básico: 2 páginas, Full: 10 páginas). Funciona con una licencia de suscripción: cuando el plan vence,
el autopost se detiene solo, y vuelve a funcionar al renovar.

* Publicación automática al publicar una entrada (se puede desmarcar en cada entrada).
* Imagen destacada en Facebook e Instagram; entradas sin imagen se publican como enlace en Facebook.
* Plantilla del mensaje con {titulo}, {extracto}, {categorias} y {etiquetas}.
* Botón «Publicar ahora en redes» para reenviar una entrada.

== Seguridad ==

* La llave se guarda cifrada en tu base de datos y nunca viaja completa: cada petición va firmada (HMAC-SHA256)
  con marca de tiempo y un identificador único, y solo funciona desde el sitio vinculado.
* Solo los administradores ven los ajustes; solo quien puede editar una entrada decide si se publica.

== Instalación ==

1. Plugins → Añadir nuevo → Subir plugin → elige sharrystreem.zip → Instalar → Activar.
2. Ajustes → SharryStreem → pega la llave de editus → Mi suscripción → «Activar licencia».
3. Elige las páginas, las redes y la plantilla del mensaje, y guarda.

== Changelog ==

= 1.0.0 =
* Primera versión: licencia, autopost a Facebook e Instagram, metabox por entrada y registro.
