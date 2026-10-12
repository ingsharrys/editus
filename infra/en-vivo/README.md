# Transmisiones en vivo (servidor propio)

La app del editor transmite en vivo a Facebook así:

```
Teléfono (cámara) ──WebRTC──▶ LiveKit (sala) ──▶ Egress compone la ESCENA
                                                   (plantilla HTML de editus:
                                                    logo, cintillo, título)
                                                 ──RTMPS──▶ Facebook Live
```

editus crea el Live en Facebook (Graph API), la sala y el egress, y le pasa a
la app la URL y el token de LiveKit. La plantilla se cambia en tiempo real
desde la app (metadata de la sala → la escena la redibuja al instante).

## 0. Instalación automática (VPS Ubuntu 22.04 / 24.04)

Con un VPS limpio (Hostinger, por ejemplo) todo lo de abajo lo hace un solo
script. Antes crea el registro DNS `A` del subdominio apuntando a la IP del VPS.

```bash
ssh root@IP-DEL-VPS
apt-get install -y git
git clone https://github.com/ingsharrys/editus.git /opt/editus-src
bash /opt/editus-src/infra/en-vivo/instalar-ubuntu.sh live.esnoticia.org tu-correo@dominio.com
```

Al final imprime `LIVEKIT_URL`, `LIVEKIT_API_KEY` y `LIVEKIT_API_SECRET` para
el `.env` de editus (también quedan en `/opt/livekit/claves.txt`). Si prefieres
hacerlo a mano, sigue las secciones siguientes.

## Avisos: correo corporativo, celular y miniatura del anuncio

**Correo corporativo.** En el `.env` de editus (los datos los da tu proveedor de correo;
la contraseña va solo aquí, nunca en el chat ni en el repositorio):

```
MAIL_MAILER=smtp
MAIL_HOST=mail.tudominio.com        # o smtp.office365.com / smtp.gmail.com, según el proveedor
MAIL_PORT=465                        # 465 con MAIL_SCHEME=smtps, o 587
MAIL_SCHEME=smtps
MAIL_USERNAME=avisos@tudominio.com
MAIL_PASSWORD=la-contraseña-del-buzón
MAIL_FROM_ADDRESS=avisos@tudominio.com
MAIL_FROM_NAME="editus"
```

Luego `php artisan config:clear` y prueba: `php artisan editus:probar-correo tu@correo.com`.
Se envían avisos de: en vivo programado (10 min antes y si se cancela por no salir) y
pregrabado (listo, al aire, emitido, con error) a quien lo programó desde la web.

**Celular (app editor).** Recordatorios locales gratis en iPhone y Android; push remoto gratis
con el servicio de Expo (ver el README de la app). editus envía a `https://exp.host` sin costo; si
activas la "seguridad reforzada" en expo.dev, pon el token en `EXPO_ACCESS_TOKEN`.

**Miniatura del anuncio.** Al programar (web o app) se puede subir una imagen JPG/PNG de
1280×720 (máx. 2 MB): va en la publicación de anuncio de Facebook y YouTube la usa como miniatura
(YouTube solo acepta miniaturas propias en canales verificados; si no, el anuncio sale igual).
Se guardan en `storage/app/public/en-vivo/miniaturas` (requiere `php artisan storage:link`).

Migración: `php artisan migrate --force` (tabla `dispositivos_push` y columnas `miniatura`, `usuario_app`).

## En vivo programado con cámaras (como StreamYard)

En editus → **En vivo → Estudio**, en "¿Cuándo?" elige **Programar** y la fecha y hora:

1. Apenas se programa se publica un **anuncio en la página de Facebook** (post con la miniatura,
   la fecha y la hora; se puede desmarcar) y la transmisión **próxima en YouTube**.
   Meta eliminó de su API los en vivos programados («Recordarme»): la API responde
   `(#100) Invalid broadcast status, Scheduled Live has been deprecated`. Por eso en Facebook
   el en vivo se crea en directo a la hora, al salir al aire.
2. El **enlace para invitados** sirve desde ya: quien lo abra antes ve la fecha y puede probar
   su cámara; cuando el director abre el estudio, entra.
3. **10 minutos antes** se avisa: aviso en la página del estudio, notificación del navegador (si
   se dio permiso) y correo a quien lo programó (si el correo de editus está configurado).
4. A la hora: **Próximos en vivo → Abrir el estudio**, cámaras, invitados, plantilla y
   **Salir al aire**. En YouTube se sale en la MISMA transmisión anunciada (se enlaza en ese
   momento); en Facebook se crea el en vivo y la página avisa a sus seguidores como siempre.
5. Si nadie sale al aire en **30 minutos** (o se cancela), editus borra la publicación de anuncio
   de Facebook y la transmisión de YouTube para que no quede un anuncio fantasma.

A diferencia de StreamYard, editus avisa antes de la hora y limpia los anuncios vencidos. Si
Facebook rechaza el anuncio, la transmisión sale igual a la hora (se crea en directo).
Migración: `php artisan migrate --force` (columnas `programada_en`, `anunciada_en`, `avisada_en`).

## Videos pregrabados programados (como StreamYard)

En editus → **En vivo → Pregrabados programados** se sube un video ya editado, se
eligen páginas de Facebook y canales de YouTube, la fecha y la hora, y a esa hora sale
**en vivo solo**, aunque nadie esté conectado:

```
Navegador ──subida por partes──▶ EMISOR (VPS) ── prepara una sola vez: H.264/AAC,
                                                 cuadro clave cada 2 s, logo, marco,
                                                 etiqueta "PREGRABADO", cuenta regresiva
editus (cada minuto) ── al quedar listo: publicación de anuncio en Facebook
                        y transmisión "próxima" en YouTube
                     ── en el minuto anterior: el emisor emite SIN recodificar (-c copy),
                        un proceso por destino, y la cuenta regresiva termina justo a la hora
                     ── al terminar: cierra los Lives; 7 días después borra el archivo
```

Como el video se prepara antes y se emite sin recodificar, una emisión casi no usa CPU:
caben varias a la vez sin quitarle CPU a los en vivo con cámaras. Si la conexión con
Facebook o YouTube se corta, cada destino retoma desde donde iba.

**Instalar el emisor** (en el mismo VPS de LiveKit, después de `instalar-ubuntu.sh`):

```bash
cd /opt/editus-src && git pull
bash infra/en-vivo/instalar-emisor.sh https://app.editus.online
```

Al final imprime `EMISOR_URL` y `EMISOR_SECRETO` para el `.env` de editus (luego
`php artisan migrate --force && php artisan config:clear`). El secreto se genera en el
VPS y queda en `/opt/emisor/.env`; no lo compartas por chat. La tarea `editus:emisiones`
ya está en el programador de Laravel (el cron de `schedule:run` que ya tienes).

Prueba completa en el VPS (2 minutos, no toca Facebook: sube, prepara y emite a dos
servidores RTMP locales, retoma y simula un destino caído):

```bash
cd /opt/emisor && docker compose run --rm --no-deps emisor node prueba.mjs
```

Opciones en `/opt/emisor/.env`: `EMISOR_MAX_GB` (peso máximo, 6), `EMISOR_PROCESOS`
(videos que se preparan a la vez, 1), `EMISOR_HILOS` (núcleos por preparación, 2),
`EMISOR_PRESET` (`veryfast`; `faster` o `medium` = mejor calidad, más lento). Después de
cambiarlas: `cd /opt/emisor && docker compose up -d`.

Límites por plan (`config/planes.php`): Básico, videos de hasta 2 h y 5 programados a la
vez; Full, hasta 8 h y sin límite. Logo y marco solo en el plan Full.

Reglas de las plataformas: Facebook exige que lo pregrabado se distinga de lo que es en
vivo (por eso la etiqueta «PREGRABADO» viene activada), prohíbe los videos en bucle y pide
que la página tenga 100 seguidores y 60 días. Instagram no permite en vivos desde
aplicaciones externas.

## Estudio en vivo (al estilo StreamYard)

En editus → **En vivo → Estudio**, al abrir el estudio se ve a pantalla completa:

- **Escenario** (lo que ve la audiencia), **8 diseños** debajo (una persona, dos, grupo de hasta
  6, principal con laterales, pantalla y presentador, principal con fila, imagen en imagen y
  pantalla completa) y los **bastidores**: cada cámara o pantalla con «Añadir al escenario».
- **Controles**: micrófono, cámara, presentar (compartir pantalla), invitar, teleprompter con
  las notas, ajustes (dispositivos, escuchar a los invitados, espejo) y salir.
- **Panel** con pestañas: Comentarios (Facebook y YouTube juntos; uno se muestra en pantalla),
  Banners (fijos o cinta que corre), Activos multimedia (logo y su ubicación, superposiciones,
  clips de video y pantallas de espera/temporizador con bucle, fondos, efectos de sonido y
  música de fondo con volumen), Estilo (color, tema, fuente, nombres sobre las cámaras),
  Widgets (reloj y cuenta regresiva), Notas, Personas (rótulos, al escenario, sacar, invitar)
  y Chat privado con los invitados (no sale al aire; la página del invitado también lo tiene).
- **Marcas**: todo lo de identidad (logo, colores, tema, fondo, superposición, banners) se
  guarda en una marca reutilizable; se pueden tener varias («Marca 1 ▾»).
- Los efectos de sonido y las músicas incluidas se **generan en el momento** (Web Audio,
  `escena/sonidos.js`): no hay archivos ni derechos de autor. Lo que suba cada usuario (logos,
  superposiciones PNG, fondos, clips, sonidos y música) va a `storage/app/public/en-vivo/recursos`
  y lo sirve el VPS con `LIVEKIT_RECURSOS_URL`.

Migración: `php artisan migrate --force` (tablas `marcas_en_vivo`, `activos_en_vivo` y columnas
`notas`, `marca_id`). La escena cambió: en el VPS hay que actualizar `/opt/editus-src`.
Para subir clips grandes, el PHP del hosting debe permitirlo (`upload_max_filesize` y
`post_max_size`, p. ej. 300M); si no, el estudio avisa que el archivo supera el límite.

## Escena servida desde el VPS (recomendado)

El mezclador (egress) abre la página de la escena con un navegador automatizado.
Si editus está detrás de cPanel/WHM con protección anti-bots, esa carga falla
("page load error"). Para evitarlo, sirve la escena desde el propio VPS:

```bash
bash /opt/editus-src/infra/en-vivo/instalar-escena.sh
```

y en el `.env` de editus: `LIVEKIT_ESCENA_URL=https://live.esnoticia.org/escena/`
(luego `php artisan config:clear`). Para actualizar la escena en el VPS:
`cd /opt/editus-src && git pull`.

## El egress no recibe video ("Start signal not received")

El mezclador corre en el mismo VPS que LiveKit y debe poder conectarse a sí
mismo. En `livekit.yaml`, dentro de `rtc:`, agrega
`enable_loopback_candidate: true` y reinicia: `docker compose restart livekit`.
(El instalador ya lo deja puesto.)

## YouTube Live en paralelo

1. **Google Cloud** (console.cloud.google.com): crea un proyecto, habilita
   "YouTube Data API v3", configura la pantalla de consentimiento (tipo externo,
   agrega tu cuenta de Google como usuario de prueba) y crea una credencial
   "ID de cliente de OAuth" de tipo aplicación web con la URI de redirección
   `https://app.editus.online/auth/youtube/callback`.
2. En el `.env` de editus: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` y
   `php artisan config:clear`.
3. Admin → App del editor → **YouTube Live** → "Conectar canal": inicia sesión
   con la cuenta dueña del canal (si tiene varios canales, Google te deja elegir).
4. El canal debe tener las transmisiones en vivo activadas en YouTube Studio
   (la primera vez tarda 24 h).
5. En la app, al preparar la sala, marca el canal junto a las páginas de Facebook.

Mientras la app de Google esté "en pruebas", el acceso vence cada 7 días y hay
que volver a pulsar "Conectar canal". Para que sea permanente hay que publicar
la app en Google y pasar su verificación (pide un video y una política de
privacidad), lo que tarda unas semanas.

## Cuentas de cada usuario desde la app ("Mis cuentas")

Cada usuario de la app conecta **sus propias** páginas de Facebook y canales de
YouTube sin entrar a la web de editus:

1. En la app: menú → **Mis cuentas** → "Conectar Facebook" o "Conectar YouTube".
   El backend de esnoticia genera un enlace firmado (`/auth/app/facebook` o
   `/auth/app/youtube` con `u`, `exp`, `sig` = HMAC del `EDITUS_TOKEN`) y la app lo
   abre en el navegador del teléfono.
2. El usuario inicia sesión en Facebook / Google y acepta los permisos. Se usan
   los mismos callbacks de siempre (`FACEBOOK_LINK_REDIRECT_URI` y
   `/auth/youtube/callback`), así que no hay que registrar nada nuevo en Meta ni
   en Google. editus guarda los tokens con el id del usuario de la app
   (`usuario_app`) y muestra una página que vuelve a la app (`editor://cuentas`,
   esquema configurable con `EDITOR_APP_SCHEME`).
3. Desde ese momento, en Redes y En vivo ese usuario ve sus páginas y canales
   (marcados como "mía") junto con los de la organización (los que conectó un
   administrador desde la web de editus y marcó como visibles en la app). Las
   publicaciones y los Lives en sus páginas se hacen con **su** token.
4. "Actualizar páginas" vuelve a leer las páginas de su cuenta de Facebook;
   "Desconectar" borra sus tokens (los de la organización no se tocan).
5. Las páginas y canales de la organización se asignan por periodista en
   Admin → App del editor (pestañas Páginas de Facebook y Canales de YouTube):
   el selector **Periodistas que la ven** trae los usuarios activos del backend de
   esnoticia (`GET /api/editus/usuarios` con `X-Editus-Token`; `ESNOTICIA_URL`
   en el `.env` de editus). "★ Todos" = cualquier periodista; sin selección =
   nadie (solo quien la conecte desde la app).

Requisitos en Meta: mientras la app de Facebook no tenga aprobados en App Review
los permisos `pages_show_list`, `pages_manage_posts`, `pages_read_engagement` y
`publish_video` (Live Video API), solo pueden conectar cuentas con rol en la app
(administrador, desarrollador o probador). En Google, mientras el cliente esté
"en pruebas", solo los usuarios de prueba y el acceso vence cada 7 días.

Migración necesaria: `php artisan migrate --force` (agrega `usuario_app` a
`social_accounts`, `meta_page_user` y `youtube_canales`).

## Flujo: sala primero, al aire después

1. En la app se eligen las páginas y el título y se toca **Entrar a la sala**:
   editus crea solo la sala de LiveKit (`POST /api/en-vivo/preparar`). Nada sale a Facebook.
2. En la sala: vista previa de la cámara, invitaciones (cámara desde celular o
   pantalla desde computador), nombres por cámara, plantilla, marco PNG e intro.
3. **Salir al aire** (`POST /api/en-vivo/{id}/iniciar {intro_recurso_id?}`): un
   Live por página, la intro en pantalla desde el primer segundo y el mezclador
   hacia todas las páginas. Si Facebook falla, la sala sigue para reintentar.

## Producción (varias páginas, rótulo, logo, cortinillas, pantalla compartida)

- **Varias páginas a la vez**: en la app se marcan las páginas; editus crea un Live
  en cada una y el mezclador envía la misma señal a todas (un solo egress con
  varias salidas RTMP). Si una página falla (permisos), las demás siguen.
- **Rótulo**: cada cámara tiene nombre y cargo (estudio de la app). El director
  elige cuál se muestra, o activa el rótulo automático: aparece el nombre de la
  cámara al aire que está hablando (LiveKit detecta quién habla).
- **Marco (plantilla de video)**: PNG transparente 1920×1080 subido como recurso
  de tipo "plantilla"; va sobre las cámaras y debajo de los textos.
- **Logo**: texto o la imagen de una plantilla de la app (Admin → App del editor).
- **Cortinillas, comerciales e imágenes**: se suben en Admin → App del editor →
  Recursos para las transmisiones en vivo (MP4/WebM o PNG/JPG/WEBP). Desde la app
  se sacan al aire a pantalla completa; los videos suenan y al terminar vuelven
  las cámaras. Mientras suena un video, los micrófonos se silencian.
- **Pantalla compartida**: en la página del invitado, desde un computador, el
  botón "Compartir pantalla" la envía al estudio como "Pantalla de X"; el
  director la pone al aire como una cámara más (los navegadores de celular no
  permiten compartir pantalla; para la pantalla del teléfono hace falta una
  extensión nativa en la app, pendiente).
- La escena no se traduce (notranslate) para que Chrome no muestre la barra de idioma.

## Los recursos (intro, plantilla, publicidad) no se ven en la escena

El mezclador y el monitor cargan esos archivos desde editus (`/storage/en-vivo/recursos/`).
Si el hosting de editus bloquea navegadores automatizados o tiene protección de
hotlink, no cargan. Solución: que el VPS los sirva (con caché) desde su propio dominio:

```bash
bash /opt/editus-src/infra/en-vivo/instalar-recursos.sh https://app.editus.online
```

y en el `.env` de editus `LIVEKIT_RECURSOS_URL=https://live.esnoticia.org/recursos`
(luego `php artisan config:clear`). Comprueba también que exista el enlace
`public/storage` en editus (`php artisan storage:link`).

## Cámaras remotas (invitados)

Desde la app, en una transmisión activa, "Crear y compartir enlace" genera una
URL `https://app.editus.online/en-vivo/invitado/CODIGO`. Quien la abre (celular
o computador, sin instalar nada) toca "Enviar mi cámara" y aparece en el
estudio de la app; el director elige el diseño (una cámara, dos, imagen en
imagen, cuadrícula), quién sale al aire y puede sacar a cualquiera. La
invitación deja de servir cuando la transmisión termina.

## 1. Requisitos en el servidor

- Docker y Docker Compose (root o sudo).
- Un subdominio para LiveKit, por ejemplo `live.esnoticia.org`, apuntando a la IP del servidor.
- Puertos abiertos en el firewall:
  - `443/tcp` (WebSocket de señalización, detrás del proxy con TLS)
  - `7881/tcp` y `50000-60000/udp` (medios WebRTC)
  - `3478/udp` y `5349/tcp` (TURN, para teléfonos en redes restrictivas)
- Facebook: el token de la página necesita el permiso **publish_video**. En el
  `.env` de editus agrega `publish_video` a `FACEBOOK_LINK_SCOPES` y vuelve a
  conectar la página en editus → Mis Páginas.

## 2. Instalar LiveKit

```bash
sudo mkdir -p /opt/livekit && sudo cp docker-compose.yml livekit.yaml egress.yaml /opt/livekit/
cd /opt/livekit
docker run --rm livekit/livekit-server generate-keys   # imprime API Key y API Secret
```

Pon esas claves en `livekit.yaml` (bloque `keys:`) y en `egress.yaml`
(`api_key` / `api_secret`), y tu dominio en `turn.domain`. Luego:

```bash
docker compose up -d
docker compose logs -f livekit   # debe decir "starting LiveKit server"
```

## 3. Proxy con TLS (Apache en cPanel/WHM)

LiveKit escucha en `7880` sin TLS. El subdominio `live.esnoticia.org` debe
tener certificado (AutoSSL) y pasar el WebSocket a LiveKit. En WHM → Apache
Configuration → Include Editor → Pre VirtualHost Include (o en el archivo
`/etc/apache2/conf.d/userdata/ssl/2_4/CUENTA/live.esnoticia.org/livekit.conf`):

```apache
ProxyPreserveHost On
ProxyRequests Off
RewriteEngine On
RewriteCond %{HTTP:Upgrade} =websocket [NC]
RewriteRule /(.*)  ws://127.0.0.1:7880/$1 [P,L]
ProxyPass        /  http://127.0.0.1:7880/
ProxyPassReverse /  http://127.0.0.1:7880/
```

(Módulos necesarios: `proxy`, `proxy_http`, `proxy_wstunnel`, `rewrite`.)
Con Nginx sería un `location /` con `proxy_pass http://127.0.0.1:7880` y las
cabeceras `Upgrade`/`Connection`.

Comprobación: `https://live.esnoticia.org/` debe responder `OK`.

## 4. Configurar editus (`.env`)

```
LIVEKIT_URL=wss://live.esnoticia.org
LIVEKIT_API_KEY=APIxxxx
LIVEKIT_API_SECRET=xxxxxxxx
# opcional: LIVEKIT_API_URL=https://live.esnoticia.org   (se deriva de LIVEKIT_URL)
# opcional: LIVEKIT_ESCENA_URL=https://app.editus.online/en-vivo/escena
```

y `php artisan migrate --force && php artisan config:clear`.

El egress (en el servidor) debe poder abrir
`https://app.editus.online/en-vivo/escena` (la plantilla) y llegar a
`rtmps://live-api-s.facebook.com:443`.

## 5. Probar

1. En la app: Inicio → **En vivo** → elige la página, escribe el título y toca
   **Iniciar transmisión**.
2. La página de Facebook queda en vivo en unos 10-20 s (Facebook necesita
   recibir señal antes de mostrarla).
3. Cambia el título o la etiqueta desde la app: la escena se actualiza al
   instante en la transmisión.
4. **Terminar** detiene todo. El video queda publicado en la página.

## Problemas comunes

- *"LiveKit no está configurado"*: faltan variables en el `.env` de editus.
- *"Requires publish_video permission"*: agrega el permiso a
  `FACEBOOK_LINK_SCOPES` y reconecta la página.
- La transmisión aparece pero en negro: el egress no pudo abrir la escena
  (revisa `docker compose logs egress`, el certificado del subdominio y que
  `/en-vivo/escena` cargue en un navegador).
- Teléfonos que no conectan (datos móviles): abre los puertos de TURN
  (`3478/udp`, `5349/tcp`) o pon `external_tls` detrás del proxy.
