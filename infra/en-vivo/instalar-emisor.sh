#!/usr/bin/env bash
# =============================================================================
# Instala el EMISOR de videos pregrabados programados en el VPS de LiveKit.
# Recibe los videos que se suben desde editus, los prepara (logo, marco, cuenta
# regresiva) y a la hora programada los emite en vivo a Facebook / YouTube.
#
# Uso (como root, después de instalar-ubuntu.sh):
#   bash /opt/editus-src/infra/en-vivo/instalar-emisor.sh https://app.editus.online
# Para actualizarlo:  cd /opt/editus-src && git pull && bash infra/en-vivo/instalar-emisor.sh https://app.editus.online
# =============================================================================
set -euo pipefail
EDITUS="${1:-}"
[[ -n "$EDITUS" ]] || { echo "Uso: bash instalar-emisor.sh https://app.editus.online"; exit 1; }
EDITUS="${EDITUS%/}"
ORIGEN="$(cd "$(dirname "$0")" && pwd)/emisor"
CONF=/etc/nginx/sites-available/livekit
[[ -f "$ORIGEN/servidor.mjs" ]] || { echo "No encuentro $ORIGEN/servidor.mjs"; exit 1; }
[[ -f "$CONF" ]] || { echo "No existe $CONF: corre primero instalar-ubuntu.sh"; exit 1; }

echo "==> 1/4 Carpeta de datos y configuración"
mkdir -p /opt/emisor/datos
chown -R 1000:1000 /opt/emisor/datos   # usuario "node" del contenedor
chmod 700 /opt/emisor/datos
if [[ ! -f /opt/emisor/.env ]]; then
  SECRETO=$(openssl rand -hex 32)
  cat > /opt/emisor/.env <<EOC
EMISOR_SECRETO=${SECRETO}
EDITUS_WEBHOOK=${EDITUS}/webhooks/emisor
EMISOR_ORIGENES=${EDITUS}
EMISOR_MAX_GB=6
# Procesados a la vez y núcleos por procesado (deja CPU libre para los en vivo con cámaras)
EMISOR_PROCESOS=1
EMISOR_HILOS=2
EMISOR_PRESET=veryfast
EOC
  chmod 600 /opt/emisor/.env
fi
sed "s#/opt/editus-src/infra/en-vivo/emisor#${ORIGEN}#" "$ORIGEN/docker-compose.yml" > /opt/emisor/docker-compose.yml

echo "==> 2/4 Nginx: /emisor/ → 127.0.0.1:7890 (subidas por partes de hasta 80 MB)"
if ! grep -q "location /emisor/" "$CONF"; then
  sed -i "s#^\(\s*\)location / {#\1location /emisor/ {\n\1    proxy_pass http://127.0.0.1:7890;\n\1    proxy_http_version 1.1;\n\1    proxy_set_header Host \$host;\n\1    proxy_request_buffering off;\n\1    client_max_body_size 90m;\n\1    proxy_read_timeout 600;\n\1    proxy_send_timeout 600;\n\1}\n\1location / {#" "$CONF"
fi
nginx -t && systemctl reload nginx

echo "==> 3/4 Construyendo y arrancando el emisor"
cd /opt/emisor
docker compose build -q
docker compose up -d
sleep 3

echo "==> 4/4 Comprobación"
DOMINIO=$(grep -m1 -oP 'server_name\s+\K[^;]+' "$CONF" | awk '{print $1}')
if curl -fsS "https://${DOMINIO}/emisor/salud" | grep -q '"ok":true'; then
  echo "El emisor responde en https://${DOMINIO}/emisor/"
else
  echo "!! https://${DOMINIO}/emisor/salud no respondió; revisa: cd /opt/emisor && docker compose logs"; exit 1
fi
SECRETO=$(grep -oP '^EMISOR_SECRETO=\K.*' /opt/emisor/.env)
cat <<EOC

=============================================================
 LISTO. Agrega esto al .env de editus (y php artisan config:clear):

EMISOR_URL=https://${DOMINIO}/emisor
EMISOR_SECRETO=${SECRETO}

 No compartas el secreto: solo va en el .env de editus y en /opt/emisor/.env
 Prueba completa (2 min, no toca Facebook):
   cd /opt/emisor && docker compose run --rm --no-deps emisor node prueba.mjs
 Logs: cd /opt/emisor && docker compose logs -f
=============================================================
EOC
