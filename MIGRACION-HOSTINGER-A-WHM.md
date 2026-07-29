# Guía: Migrar "Consultorio Jurídico USCO" del VPS Hostinger al servidor WHM

Escenario real detectado en el VPS de Hostinger (`srv892565`, AlmaLinux):

| Componente | Ubicación | Servicio systemd |
|---|---|---|
| Backend principal Spring Boot (puerto 8089) | `/opt/consultorio-backend` | `consultorio-backend.service` |
| Backend notificaciones | `/opt/notificacion-backend` | `notificacion-backend.service` |
| Backend Digiturno | `/opt/digiturno-backend` | `digiturno.service` |
| Frontend web | `/var/www/consultoriojuridicousco.com` | Apache `httpd` + `php-fpm` |
| Bases de datos MariaDB | `consultoriojuridicodb`, `noticonsultoriodb`, `digiturno` | `mariadb` |
| Java | `/opt/java` | — |

Repositorio puente: `https://github.com/ingsharrys/juridicousco` (**debe ser PRIVADO**).

> **REGLAS DE ORO**
> 1. **NUNCA subir secretos a GitHub**: contraseñas de BD, JWT_SECRET y en especial
>    llaves AWS (`AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY`). GitHub y AWS las
>    detectan y AWS puede suspender la cuenta. Los archivos `.service` se suben
>    "sanitizados" (con placeholders) y los valores reales se reescriben a mano
>    en el servidor destino.
> 2. Si las llaves AWS fueron expuestas en algún chat/log: **rotarlas** en
>    IAM → Security credentials.
> 3. GitHub rechaza archivos > 100 MB. Verificar tamaños antes del push.
> 4. En el servidor WHM se necesita **acceso root por SSH** (no basta la terminal
>    de cPanel de una cuenta) porque hay que instalar Java y crear servicios systemd.

---

## PARTE 1 — En el VPS de Hostinger: empaquetar y subir a GitHub

### 1.1 Preparar carpeta de staging
```bash
mkdir -p ~/migracion && cd ~/migracion

# Binarios/artefactos de cada backend
cp -a /opt/consultorio-backend  backend
cp -a /opt/notificacion-backend notificaciones
cp -a /opt/digiturno-backend    digiturno

# Frontend
cp -a /var/www/consultoriojuridicousco.com frontend

# Configuración
mkdir -p config/systemd config/httpd
cp /etc/systemd/system/consultorio-backend.service  config/systemd/
cp /etc/systemd/system/notificacion-backend.service config/systemd/
cp /etc/systemd/system/digiturno.service            config/systemd/
cp -a /etc/httpd/conf.d/. config/httpd/
```

### 1.2 Sanitizar secretos en los .service copiados
```bash
sed -i -E \
  -e 's/(DB_PASSWORD=).*/\1__REEMPLAZAR__/' \
  -e 's/(JWT_SECRET=).*/\1__REEMPLAZAR__/' \
  -e 's/(AWS_ACCESS_KEY_ID=).*/\1__REEMPLAZAR__/' \
  -e 's/(AWS_SECRET_ACCESS_KEY=).*/\1__REEMPLAZAR__/' \
  config/systemd/*.service

grep -RE "PASSWORD|SECRET|KEY" config/systemd/   # verificar que no quedó nada real
```
Guarda los valores reales en un lugar seguro (gestor de contraseñas); los
necesitarás al recrear los servicios en el servidor WHM.

### 1.3 Exportar bases de datos frescas
```bash
mkdir -p db
mysqldump -u root -p --routines --triggers consultoriojuridicodb > db/consultoriojuridicodb.sql
mysqldump -u root -p --routines --triggers noticonsultoriodb     > db/noticonsultoriodb.sql
mysqldump -u root -p --routines --triggers digiturno             > db/digiturno.sql
```

### 1.4 Verificar tamaños (límite GitHub: 100 MB por archivo)
```bash
du -sh ~/migracion/*
find ~/migracion -type f -size +90M
```
Lo que pase de 90 MB: agregarlo a `.gitignore` y transferirlo por `scp` directo
al servidor WHM (ver 3.6).

### 1.5 Subir a GitHub
Necesitas un Personal Access Token (GitHub → Settings → Developer settings →
Personal access tokens → classic, permiso `repo`).
```bash
cd ~/migracion
git init && git branch -M main
cat > .gitignore << 'EOF'
*.log
logs/
EOF
git add -A
git commit -m "Migracion Consultorio Juridico USCO desde VPS Hostinger"
git remote add origin https://github.com/ingsharrys/juridicousco.git
git push -u origin main
# Usuario: ingsharrys — Contraseña: el Personal Access Token
```

---

## PARTE 2 — En WHM: preparar la cuenta del dominio

1. **Create a New Account**: dominio `consultoriojuridicousco.com`, usuario p.ej. `consjur`.
2. **MultiPHP Manager**: versión de PHP similar a la del VPS (`php -v` en el VPS).
3. Verificar en **Feature Manager** que la cuenta tenga Terminal habilitada
   (para la parte del frontend).

## PARTE 3 — En el servidor WHM **como root por SSH**: instalar todo

### 3.1 Clonar el repositorio
```bash
cd /root
git clone https://github.com/ingsharrys/juridicousco.git migracion
cd migracion
```

### 3.2 Instalar Java (misma versión que el VPS: verificar con `java -version` allá)
```bash
dnf install -y java-17-openjdk   # ajustar versión según corresponda
```

### 3.3 Backends
```bash
cp -a backend        /opt/consultorio-backend
cp -a notificaciones /opt/notificacion-backend
cp -a digiturno      /opt/digiturno-backend

cp config/systemd/*.service /etc/systemd/system/
# EDITAR cada .service y poner los valores reales donde diga __REEMPLAZAR__:
#   nano /etc/systemd/system/consultorio-backend.service  (etc.)
# Ajustar también DB_URL si el nombre/usuario de BD cambia en el nuevo servidor.

systemctl daemon-reload
systemctl enable --now consultorio-backend notificacion-backend digiturno
systemctl status consultorio-backend --no-pager
```

### 3.4 Bases de datos
En cPanel de la cuenta → MySQL Databases: crear las BD y el usuario
(quedarán con prefijo, ej. `consjur_juridicodb`), o como root crear las BD
con los mismos nombres originales para no tocar los `.service`:
```bash
mysql -u root -e "CREATE DATABASE consultoriojuridicodb; CREATE DATABASE noticonsultoriodb; CREATE DATABASE digiturno;"
mysql -u root -e "CREATE USER 'cons_admin'@'localhost' IDENTIFIED BY 'NUEVA_CONTRASEÑA_FUERTE'; GRANT ALL ON consultoriojuridicodb.* TO 'cons_admin'@'localhost'; GRANT ALL ON noticonsultoriodb.* TO 'cons_admin'@'localhost'; GRANT ALL ON digiturno.* TO 'cons_admin'@'localhost'; FLUSH PRIVILEGES;"

mysql -u root consultoriojuridicodb < db/consultoriojuridicodb.sql
mysql -u root noticonsultoriodb     < db/noticonsultoriodb.sql
mysql -u root digiturno             < db/digiturno.sql
```
> Nota: el VPS usa MariaDB 10.11. Si el WHM trae MySQL 8, la importación normal
> funciona en la mayoría de los casos; si aparece un error de collation
> (`utf8mb4_uca1400_...`), reemplazarla en el .sql por `utf8mb4_unicode_ci`:
> `sed -i 's/utf8mb4_uca1400_ai_ci/utf8mb4_unicode_ci/g' archivo.sql`

### 3.5 Frontend + proxy inverso hacia los backends
```bash
# Copiar el frontend a la cuenta cPanel
cp -a frontend/. /home/consjur/public_html/
chown -R consjur:consjur /home/consjur/public_html
```

Revisar en `config/httpd/` cómo estaba configurado Apache en el VPS
(qué rutas se proxyaban a qué puerto, ej. `/api → 127.0.0.1:8089`,
`/notificaciones → puerto del backend de notificaciones`) y replicarlo en
cPanel con includes de userdata:
```bash
mkdir -p /etc/apache2/conf.d/userdata/ssl/2_4/consjur/consultoriojuridicousco.com
cat > /etc/apache2/conf.d/userdata/ssl/2_4/consjur/consultoriojuridicousco.com/proxy.conf << 'EOF'
ProxyPreserveHost On
ProxyPass        /api http://127.0.0.1:8089/api
ProxyPassReverse /api http://127.0.0.1:8089/api
# Agregar aquí las rutas de notificaciones y digiturno con sus puertos reales
EOF
# Repetir el mismo archivo en .../userdata/std/2_4/... para HTTP
/scripts/rebuildhttpdconf && systemctl restart httpd
```

### 3.6 Archivos grandes excluidos de Git (si los hubo)
Desde el VPS de Hostinger, directo al WHM:
```bash
scp /ruta/al/archivo_grande root@IP_DEL_WHM:/root/migracion/
```

---

## PARTE 4 — DNS, SSL y verificación

1. Cambiar el registro **A** de `consultoriojuridicousco.com` y `www` a la IP del WHM.
2. Probar antes de propagar editando el archivo `hosts` local del PC.
3. **WHM → Manage AutoSSL → Run AutoSSL** cuando el DNS ya apunte al servidor.
4. Verificar: frontend, login (JWT), subida de documentos (S3), notificaciones,
   digiturno, y revisar logs: `journalctl -u consultorio-backend -f`.
5. Ajustar `CORS_ALLOWED_ORIGINS` y `NOT_URL` en los `.service` si cambia algo.
6. **No apagar el VPS de Hostinger** hasta varios días de funcionamiento estable;
   hacer un último `mysqldump` + import justo antes del cambio de DNS para no
   perder datos recientes.

## Checklist final

- [ ] Repo privado con artefactos, frontend, dumps y configs sanitizadas
- [ ] Llaves AWS rotadas (si estuvieron expuestas) y NUNCA subidas a Git
- [ ] Java instalado en WHM, 3 servicios systemd activos
- [ ] Bases de datos importadas y usuario `cons_admin` recreado
- [ ] Frontend en `public_html` + ProxyPass configurado y `rebuildhttpdconf`
- [ ] DNS cambiado, AutoSSL emitido
- [ ] Dump final de BD importado justo antes del corte
- [ ] Sitio verificado antes de cancelar el VPS
