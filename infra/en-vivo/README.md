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
