# Guía: Migrar sitio de Hostinger a servidor WHM/cPanel usando Git

Migración del sitio **juridicousco** desde Hostinger hacia un servidor propio con WHM/cPanel,
usando el repositorio `https://github.com/ingsharrys/juridicousco` como puente.

> **IMPORTANTE antes de empezar:**
> 1. El repositorio en GitHub debe ser **PRIVADO** (el sitio contiene archivos de configuración con credenciales).
> 2. GitHub no acepta archivos mayores a **100 MB**. Si el sitio tiene videos o backups grandes, hay que excluirlos y pasarlos aparte (ver sección 6).
> 3. Necesitas un **Personal Access Token (PAT)** de GitHub: GitHub → Settings → Developer settings → Personal access tokens → Generate new token (classic) con permiso `repo`. GitHub ya no acepta tu contraseña normal en la terminal.

---

## PARTE 1 — En la terminal de HOSTINGER (subir el sitio a GitHub)

### 1.1 Entrar por SSH
En hPanel: **Avanzado → SSH** (activa el acceso si está desactivado) o usa el **Navegador de terminal**.

```bash
# Ubicar la carpeta del sitio (en Hostinger suele ser así):
cd ~/domains/TUDOMINIO.com/public_html
# o si es cuenta antigua:
# cd ~/public_html

ls -la   # verifica que aquí están los archivos del sitio
```

### 1.2 Identificar el tipo de sitio
```bash
# ¿Es WordPress?
ls wp-config.php 2>/dev/null && echo "Es WordPress"
# ¿Es Laravel?
ls artisan 2>/dev/null && echo "Es Laravel"
```

### 1.3 Inicializar Git y excluir archivos sensibles/pesados
```bash
git config --global user.name  "ingsharrys"
git config --global user.email "dario.charry.ramos@gmail.com"

git init
git branch -M main
```

Crea un `.gitignore` (ajusta según tu caso):
```bash
cat > .gitignore << 'EOF'
error_log
*.log
.cache/
# Si el sitio es WordPress y la carpeta uploads pesa mucho (>500MB),
# descomenta la siguiente línea y pásala aparte con zip (sección 6):
# wp-content/uploads/
EOF
```

> **Si es WordPress:** deja `wp-config.php` DENTRO del repo solo si el repo es privado.
> **Si es Laravel:** el `.env` normalmente se excluye, pero para una migración a repo privado puedes incluirlo temporalmente o copiarlo aparte.

Verifica que no haya archivos gigantes antes de subir:
```bash
find . -type f -size +90M -not -path "./.git/*"
# Todo lo que aparezca aquí hay que agregarlo al .gitignore y pasarlo por zip (sección 6)
```

### 1.4 Exportar la base de datos
Busca las credenciales:
```bash
# WordPress:
grep -E "DB_NAME|DB_USER|DB_PASSWORD|DB_HOST" wp-config.php
# Laravel:
grep -E "DB_DATABASE|DB_USERNAME|DB_PASSWORD|DB_HOST" .env
```

Exporta (reemplaza con tus datos reales):
```bash
mysqldump -h localhost -u USUARIO_BD -p NOMBRE_BD > base_datos_juridicousco.sql
# Te pedirá la contraseña de la BD
ls -lh base_datos_juridicousco.sql   # verifica que no esté vacío ni pese >100MB
```

### 1.5 Subir todo a GitHub
```bash
git add -A
git commit -m "Migracion sitio juridicousco desde Hostinger"

git remote add origin https://github.com/ingsharrys/juridicousco.git
git push -u origin main
# Usuario: ingsharrys
# Contraseña: pega aquí tu Personal Access Token (NO tu contraseña de GitHub)
```

Si el push falla por tamaño, revisa la sección 6.

---

## PARTE 2 — En WHM (preparar la cuenta)

1. **WHM → Account Functions → Create a New Account**: crea la cuenta para el dominio
   (ej. `juridicousco.com`, usuario `juridico`).
2. **WHM → MultiPHP Manager**: asigna la misma versión de PHP que usaba Hostinger
   (verifícala en hPanel o con `php -v` en la terminal de Hostinger).
3. **WHM → Feature Manager**: asegúrate de que la cuenta tenga habilitado **Terminal**
   y **Git Version Control** (opcional).

---

## PARTE 3 — En la terminal de CPANEL (descargar el sitio)

Entra a cPanel de la cuenta nueva → **Avanzado → Terminal**.

### 3.1 Clonar el repositorio
```bash
cd ~
# public_html ya existe y puede tener un index por defecto; clonamos aparte y copiamos:
git clone https://github.com/ingsharrys/juridicousco.git sitio_tmp
# Usuario: ingsharrys / Contraseña: tu Personal Access Token

# Vaciar public_html y copiar el sitio (incluye archivos ocultos como .htaccess):
rm -rf ~/public_html/*
cp -a ~/sitio_tmp/. ~/public_html/
rm -rf ~/public_html/.git      # opcional: quitar el repo de la carpeta pública
```

### 3.2 Crear e importar la base de datos
En cPanel → **Bases de datos MySQL**:
1. Crea la base de datos (quedará como `usuario_nombre`, ej. `juridico_db`).
2. Crea un usuario de BD con contraseña fuerte.
3. Asigna el usuario a la BD con **TODOS LOS PRIVILEGIOS**.

Luego en la Terminal:
```bash
cd ~/public_html
mysql -u juridico_dbuser -p juridico_db < base_datos_juridicousco.sql
```

### 3.3 Actualizar la configuración

**Si es WordPress** — edita `wp-config.php`:
```php
define( 'DB_NAME',     'juridico_db' );
define( 'DB_USER',     'juridico_dbuser' );
define( 'DB_PASSWORD', 'LA_NUEVA_CONTRASEÑA' );
define( 'DB_HOST',     'localhost' );
```

**Si es Laravel** — edita `.env` con los nuevos `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, y luego:
```bash
php artisan config:clear && php artisan cache:clear
```

### 3.4 Permisos
```bash
cd ~/public_html
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
```

### 3.5 (Solo WordPress) Verificar URLs
Si el dominio no cambia, no hace falta nada. Si cambia (o usas un dominio temporal), con WP-CLI:
```bash
wp search-replace 'https://dominio-viejo.com' 'https://dominio-nuevo.com' --skip-columns=guid
```

---

## PARTE 4 — DNS y SSL

1. En el registrador del dominio (o en Hostinger si el DNS está ahí), cambia el
   **registro A** del dominio y de `www` a la **IP de tu servidor WHM**.
   (O cambia los nameservers a los de tu servidor.)
2. Espera la propagación (minutos a 24h). Puedes probar antes editando el archivo
   `hosts` de tu PC: `IP_DEL_SERVIDOR juridicousco.com`.
3. Cuando el dominio ya apunte al servidor: **WHM → SSL/TLS → Manage AutoSSL → Run AutoSSL**
   para emitir el certificado gratuito.
4. Verifica el sitio completo (páginas, imágenes, formularios, admin).
5. **No canceles Hostinger** hasta confirmar que todo funciona varios días.

---

## PARTE 5 — Correos (si aplica)

Si el dominio tiene cuentas de correo en Hostinger, créalas también en cPanel
(**Email Accounts**) antes de cambiar el DNS, y respalda los correos existentes
(por IMAP con Thunderbird/Outlook o con herramientas como imapsync).

---

## PARTE 6 — Archivos grandes (si Git no alcanza)

Para carpetas pesadas (ej. `wp-content/uploads` con muchos GB) es más práctico un zip directo:

**En Hostinger:**
```bash
cd ~/domains/TUDOMINIO.com/public_html
zip -r ~/uploads.zip wp-content/uploads
```

**En cPanel (si el servidor WHM tiene IP/SSH accesible desde Hostinger):**
```bash
# Desde la terminal de Hostinger, enviar directo al servidor:
scp ~/uploads.zip usuario_cpanel@IP_DEL_SERVIDOR:~/
```
O descarga el zip por el Administrador de Archivos de Hostinger y súbelo por el
Administrador de Archivos de cPanel. Luego en la terminal de cPanel:
```bash
cd ~/public_html && unzip ~/uploads.zip
```

---

## Checklist final

- [ ] Repo GitHub privado creado y con el código subido
- [ ] Base de datos exportada e importada
- [ ] Configuración (wp-config.php / .env) actualizada con las credenciales nuevas
- [ ] Versión de PHP igualada en WHM
- [ ] Permisos 755/644 aplicados
- [ ] DNS apuntando al servidor nuevo
- [ ] SSL emitido con AutoSSL
- [ ] Correos migrados (si aplica)
- [ ] Sitio verificado antes de cancelar Hostinger
