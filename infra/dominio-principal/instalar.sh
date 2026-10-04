#!/usr/bin/env bash
# Publica la landing de editus en el dominio principal (editus.online) usando la MISMA aplicación
# que app.editus.online: crea index.php y .htaccess en la carpeta del dominio principal y enlaces
# a los archivos públicos (imágenes, estilos, descargas). No borra nada: respalda lo que reemplaza.
#
# Uso (en el servidor):  bash ~/public_html/app.editus.online/infra/dominio-principal/instalar.sh
# Deshacer:              copiar de vuelta los archivos de la carpeta de respaldo que indica al final.
set -euo pipefail

APP="${1:-$HOME/public_html/app.editus.online}"
DESTINO="${2:-$HOME/public_html}"
PLANTILLAS="$APP/infra/dominio-principal"

[ -f "$APP/artisan" ] || { echo "No encuentro la aplicación editus en $APP"; exit 1; }
[ -d "$DESTINO" ] || { echo "No existe la carpeta del dominio principal: $DESTINO"; exit 1; }
APP="$(cd "$APP" && pwd)"; DESTINO="$(cd "$DESTINO" && pwd)"
[ "$APP" != "$DESTINO" ] || { echo "La carpeta del dominio principal no puede ser la de la aplicación"; exit 1; }

RESPALDO="$HOME/respaldo-editus-online-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$RESPALDO"
echo "Respaldo de lo que se reemplaza en: $RESPALDO"

# 1) Respaldar y reemplazar la página de inicio y el .htaccess
for f in index.php .htaccess; do
    [ -e "$DESTINO/$f" ] && cp -a "$DESTINO/$f" "$RESPALDO/"
done
for f in index.html index.htm default.html; do   # tienen prioridad sobre index.php: se apartan
    [ -e "$DESTINO/$f" ] && mv "$DESTINO/$f" "$RESPALDO/"
done
sed "s#__APP__#$APP#g" "$PLANTILLAS/index.php.plantilla" > "$DESTINO/index.php"
cp "$PLANTILLAS/htaccess.plantilla" "$DESTINO/.htaccess"
# Conserva la versión de PHP elegida en cPanel (bloques generados por cPanel del .htaccess anterior,
# o los de la aplicación si no había)
ORIGEN_PHP="$RESPALDO/.htaccess"; [ -f "$ORIGEN_PHP" ] || ORIGEN_PHP="$APP/public/.htaccess"
awk '/BEGIN cPanel-generated/,/END cPanel-generated/' "$ORIGEN_PHP" >> "$DESTINO/.htaccess" || true

# 2) Enlaces a los archivos públicos de la aplicación
for d in img build descargas js favicon.ico robots.txt; do
    [ -e "$APP/public/$d" ] || continue
    if [ -e "$DESTINO/$d" ] && [ ! -L "$DESTINO/$d" ]; then mv "$DESTINO/$d" "$RESPALDO/"; fi
    ln -sfn "$APP/public/$d" "$DESTINO/$d"
done

chmod 644 "$DESTINO/index.php" "$DESTINO/.htaccess"
echo
echo "Listo: https://editus.online ahora muestra la landing de editus."
echo "Recuerda en el .env de la aplicación:  SESSION_DOMAIN=.editus.online  y luego  php artisan config:clear"
echo "Para deshacer: copia de vuelta a $DESTINO lo que quedó en $RESPALDO"
