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
