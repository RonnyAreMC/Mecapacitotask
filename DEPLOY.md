# Deploy en cPanel (subdominio) por terminal

Panel PHP con persistencia en archivos JSON — **no necesita base de datos**.
Requisitos: **PHP 8.1+** y **HTTPS** (el pegado de imágenes usa el portapapeles del navegador, que exige contexto seguro).

## 1. Crear el subdominio (una sola vez)
En cPanel → **Dominios / Subdominios**, crea `panel.tudominio.com`.
Anota su **Document Root** (por defecto algo como `public_html/panel.tudominio.com`).

## 2. Subir el proyecto por terminal
Entra por SSH (o la **Terminal** de cPanel) y clona el repo DENTRO del document root:

```bash
# Ir al document root del subdominio (ajusta la ruta a la real)
cd ~/public_html/panel.tudominio.com

# Clonar el repo aquí mismo (el punto final = en la carpeta actual)
git clone https://github.com/RonnyAreMC/Mecapacitotask.git .

# Permisos de escritura para datos y subidas
chmod 755 admin/data admin/uploads
```

## 3. Elegir PHP 8.1+
cPanel → **MultiPHP Manager** → selecciona el subdominio → **PHP 8.1** (o superior).

## 4. Activar SSL
cPanel → **SSL/TLS Status** o **AutoSSL** → emite el certificado del subdominio (Let's Encrypt).

## 5. Listo
Abre **https://panel.tudominio.com/** — redirige solo a `/admin/`.
La primera carga siembra el equipo y los proyectos de ejemplo automáticamente.

---

## Configurar credenciales en el servidor
`admin/data/config.json` (correo Gmail, Zoom) **no viaja en git** por seguridad.
En el servidor, entra a **Ajustes** y vuelve a poner:
- **Correo**: la contraseña de aplicación o el OAuth de Gmail.
- **Zoom**: Account ID, Client ID, Client Secret.
- **Token de GitHub** (opcional, para repos privados).

(O súbelo por SFTP si prefieres reutilizar el mismo `config.json`.)

## Proteger con login (IMPORTANTE)
El panel **no trae autenticación**. En un subdominio público, protégelo con
cPanel → **Privacidad de directorios (Directory Privacy)** sobre la carpeta del
subdominio: crea un usuario/clave y Apache pedirá login antes de entrar.

## Actualizar a futuro
```bash
cd ~/public_html/panel.tudominio.com
git pull
```
Tus datos (`admin/data/*.json`, `admin/uploads/`) están en `.gitignore`, así que
`git pull` **nunca los pisa**.

## Seguridad ya incluida en el repo
- `admin/data/.htaccess` y `admin/lib/.htaccess`: bloquean el acceso web a datos y clases.
- `.htaccess` raíz: bloquea `.git` y archivos sensibles.
