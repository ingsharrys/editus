#!/usr/bin/env bash
# =============================================================================
# Sirve la página de la ESCENA (la que compone el egress) desde el propio VPS,
# en https://DOMINIO/escena/, para que el mezclador no dependa de editus ni de
# las protecciones anti-bots del hosting.
#
# Uso (como root, después de instalar-ubuntu.sh):
#   bash /opt/editus-src/infra/en-vivo/instalar-escena.sh
# Luego en el .env de editus:  LIVEKIT_ESCENA_URL=https://DOMINIO/escena/
# Para actualizar la escena:   cd /opt/editus-src && git pull
# =============================================================================
set -euo pipefail
ORIGEN="$(cd "$(dirname "$0")" && pwd)/escena"
CONF=/etc/nginx/sites-available/livekit
[[ -f "$ORIGEN/index.html" ]] || { echo "No encuentro $ORIGEN/index.html"; exit 1; }
[[ -f "$CONF" ]] || { echo "No existe $CONF: corre primero instalar-ubuntu.sh"; exit 1; }

if ! grep -q "location /escena/" "$CONF"; then
  # Se inserta antes de cada "location / {" (bloques 80 y 443)
  sed -i "s#^\(\s*\)location / {#\1location /escena/ {\n\1    alias ${ORIGEN}/;\n\1    index index.html;\n\1    add_header Cache-Control \"no-store\";\n\1}\n\1location / {#" "$CONF"
fi
chmod o+rx /opt /opt/editus-src /opt/editus-src/infra /opt/editus-src/infra/en-vivo "$ORIGEN" 2>/dev/null || true
chmod o+r "$ORIGEN/index.html"
nginx -t && systemctl reload nginx
DOMINIO=$(grep -m1 -oP 'server_name\s+\K[^;]+' "$CONF" | awk '{print $1}')
sleep 2
if curl -fsS "https://${DOMINIO}/escena/" | grep -q START_RECORDING; then
  echo "Escena publicada en https://${DOMINIO}/escena/"
  echo "Agrega al .env de editus:  LIVEKIT_ESCENA_URL=https://${DOMINIO}/escena/   (y php artisan config:clear)"
else
  echo "!! https://${DOMINIO}/escena/ no respondió; revisa: nginx -t y $CONF"; exit 1
fi
