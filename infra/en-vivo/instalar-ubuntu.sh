#!/usr/bin/env bash
# =============================================================================
# Instala el servidor de transmisiones en vivo (LiveKit + Egress + Redis +
# Nginx con certificado) en un VPS limpio con Ubuntu 22.04 / 24.04 / 26.04.
#
# Uso (como root):
#   bash instalar-ubuntu.sh live.esnoticia.org correo@tudominio.com
#
# Antes: el subdominio debe apuntar (registro A) a la IP pública del VPS.
# Al final imprime las variables para el .env de editus.
# =============================================================================
set -euo pipefail

DOMINIO="${1:-}"
CORREO="${2:-}"
if [[ -z "$DOMINIO" || -z "$CORREO" ]]; then
  echo "Uso: bash instalar-ubuntu.sh live.tudominio.com correo@tudominio.com"; exit 1
fi
if [[ "$(id -u)" != "0" ]]; then echo "Ejecuta como root (sudo -i)"; exit 1; fi

echo "==> 1/7 Paquetes base"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y ca-certificates curl gnupg ufw nginx certbot python3-certbot-nginx

echo "==> 2/7 Docker"
if ! command -v docker >/dev/null 2>&1; then
  # Repositorio oficial de Docker; si esta versión de Ubuntu aún no está en él, se usa el paquete de Ubuntu
  if ! curl -fsSL https://get.docker.com | sh; then
    echo "   (el instalador oficial no soporta esta versión de Ubuntu; usando docker.io)"
    apt-get install -y docker.io docker-compose-v2
  fi
fi
if ! docker compose version >/dev/null 2>&1; then
  apt-get install -y docker-compose-v2 || apt-get install -y docker-compose-plugin
fi
systemctl enable --now docker

echo "==> 3/7 Claves y configuración de LiveKit"
mkdir -p /opt/livekit
cd /opt/livekit
if [[ ! -f claves.txt ]]; then
  docker run --rm livekit/livekit-server generate-keys > claves.txt
fi
API_KEY=$(grep -i "API Key" claves.txt | awk '{print $NF}')
API_SECRET=$(grep -i "API Secret" claves.txt | awk '{print $NF}')
IP_PUBLICA=$(curl -4 -s https://api.ipify.org || curl -4 -s https://ifconfig.me)

cat > livekit.yaml <<EOF
port: 7880
rtc:
  tcp_port: 7881
  port_range_start: 50000
  port_range_end: 60000
  use_external_ip: true
  # El egress (mezclador) corre en este mismo VPS: necesita poder conectarse por loopback
  enable_loopback_candidate: true
redis:
  address: 127.0.0.1:6379
keys:
  ${API_KEY}: ${API_SECRET}
turn:
  enabled: true
  domain: ${DOMINIO}
  tls_port: 5349
  udp_port: 3478
  external_tls: true
logging:
  level: info
EOF

cat > egress.yaml <<EOF
api_key: ${API_KEY}
api_secret: ${API_SECRET}
ws_url: ws://127.0.0.1:7880
redis:
  address: 127.0.0.1:6379
logging:
  level: info
EOF

cat > docker-compose.yml <<'EOF'
services:
  redis:
    image: redis:7-alpine
    restart: unless-stopped
    network_mode: host
    command: ["redis-server", "--bind", "127.0.0.1", "--port", "6379", "--save", "", "--appendonly", "no"]
  livekit:
    image: livekit/livekit-server:latest
    restart: unless-stopped
    network_mode: host
    command: ["--config", "/etc/livekit.yaml"]
    volumes:
      - ./livekit.yaml:/etc/livekit.yaml:ro
    depends_on: [redis]
  egress:
    image: livekit/egress:latest
    restart: unless-stopped
    network_mode: host
    environment:
      - EGRESS_CONFIG_FILE=/etc/egress.yaml
    volumes:
      - ./egress.yaml:/etc/egress.yaml:ro
      - /tmp/livekit-egress:/out
    cap_add: [SYS_ADMIN]
    shm_size: "1gb"
    depends_on: [redis, livekit]
EOF

echo "==> 4/7 Firewall"
ufw allow OpenSSH >/dev/null
ufw allow 80/tcp >/dev/null
ufw allow 443/tcp >/dev/null
ufw allow 7881/tcp >/dev/null
ufw allow 5349/tcp >/dev/null
ufw allow 3478/udp >/dev/null
ufw allow 50000:60000/udp >/dev/null
ufw --force enable >/dev/null

echo "==> 5/7 Nginx + certificado para ${DOMINIO}"
cat > /etc/nginx/sites-available/livekit <<EOF
server {
    listen 80;
    server_name ${DOMINIO};
    location / {
        proxy_pass http://127.0.0.1:7880;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host \$host;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 86400;
    }
}
EOF
ln -sf /etc/nginx/sites-available/livekit /etc/nginx/sites-enabled/livekit
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
certbot --nginx -d "${DOMINIO}" -m "${CORREO}" --agree-tos --non-interactive --redirect || {
  echo "!! certbot falló: revisa que ${DOMINIO} apunte a ${IP_PUBLICA} y vuelve a correr el script"; exit 1; }

echo "==> 6/7 Arrancando LiveKit"
cd /opt/livekit
docker compose pull -q
docker compose up -d
sleep 5
docker compose ps

echo "==> 7/7 Comprobación"
if curl -fsS "https://${DOMINIO}" | grep -q OK; then echo "LiveKit responde en https://${DOMINIO}"; else echo "!! https://${DOMINIO} no respondió OK; revisa: docker compose logs livekit"; fi

cat <<EOF

=============================================================
 LISTO. Agrega esto al .env de editus (y php artisan config:clear):

LIVEKIT_URL=wss://${DOMINIO}
LIVEKIT_API_KEY=${API_KEY}
LIVEKIT_API_SECRET=${API_SECRET}

 Las claves también quedan guardadas en /opt/livekit/claves.txt
 Logs: cd /opt/livekit && docker compose logs -f
=============================================================
EOF
