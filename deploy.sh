#!/bin/bash
# =====================================================================
# Despliegue optimizado de Editus para hosting compartido (cPanel).
# Uso:  bash deploy.sh
#
# Trae los últimos cambios, migra la base de datos y deja la app en
# modo producción: config, rutas, vistas y eventos precompilados
# (sin esto, PHP relee el .env y TODOS los archivos de config en
# CADA request — un gran costo en hosting compartido).
# =====================================================================
set -e
cd "$(dirname "$0")"

RAMA="${1:-claude/upload-app-to-repo-z5jl1x}"

echo "→ Trayendo cambios de ${RAMA}..."
git fetch origin "$RAMA"
git merge --no-edit "origin/${RAMA}"

echo "→ Migrando base de datos..."
php artisan migrate --force

echo "→ Reconstruyendo cachés de producción..."
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "✅ Despliegue completo. La aplicación quedó en modo optimizado."
echo "   IMPORTANTE: tras editar el .env, ejecuta siempre: php artisan config:cache"
