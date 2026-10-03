#!/usr/bin/env bash
# =============================================================================
# El VPS sirve los recursos de producción (intro, plantillas PNG, publicidad)
# en https://DOMINIO/recursos/, trayéndolos de editus y guardándolos en caché.
# Así el mezclador y el monitor los cargan desde el mismo dominio de la escena,
# sin depender de la protección anti-bots ni del hotlink del hosting de editus.
#
# Uso (como root):  bash instalar-recursos.sh https://app.editus.online
# Luego en el .env de editus:  LIVEKIT_RECURSOS_URL=https://DOMINIO/recursos
# =============================================================================
set -euo pipefail
EDITUS="${1:-}"
[[ -n "$EDITUS" ]] || { echo "Uso: bash instalar-recursos.sh https://app.editus.online"; exit 1; }
EDITUS="${EDITUS%/}"
CONF=/etc/nginx/sites-available/livekit
[[ -f "$CONF" ]] || { echo "No existe $CONF: corre primero instalar-ubuntu.sh"; exit 1; }
HOST_EDITUS=$(echo "$EDITUS" | sed -E 's#^https?://##; s#/.*$##')

mkdir -p /var/cache/nginx/recursos
chown www-data:www-data /var/cache/nginx/recursos
# Zona de caché (una sola vez, en conf.d para que esté en el contexto http)
cat > /etc/nginx/conf.d/recursos-cache.conf <<EOC
proxy_cache_path /var/cache/nginx/recursos levels=1:2 keys_zone=recursos:20m max_size=5g inactive=30d use_temp_path=off;
EOC

if ! grep -q "location /recursos/" "$CONF"; then
  sed -i "s#^\(\s*\)location / {#\1location /recursos/ {\n\1    proxy_pass ${EDITUS}/storage/en-vivo/recursos/;\n\1    proxy_ssl_server_name on;\n\1    proxy_set_header Host ${HOST_EDITUS};\n\1    proxy_set_header Referer \"\";\n\1    proxy_set_header User-Agent \"editus-recursos\";\n\1    proxy_cache recursos;\n\1    proxy_cache_valid 200 30d;\n\1    proxy_cache_use_stale error timeout updating;\n\1    proxy_ignore_headers Cache-Control Expires Set-Cookie;\n\1    add_header X-Cache \$upstream_cache_status;\n\1    add_header Access-Control-Allow-Origin *;\n\1    proxy_read_timeout 120;\n\1}\n\1location / {#" "$CONF"
fi
nginx -t && systemctl reload nginx
DOMINIO=$(grep -m1 -oP 'server_name\s+\K[^;]+' "$CONF" | awk '{print $1}')
echo "Listo. Los recursos se sirven en https://${DOMINIO}/recursos/<archivo>"
echo "Agrega al .env de editus:  LIVEKIT_RECURSOS_URL=https://${DOMINIO}/recursos   (y php artisan config:clear)"
