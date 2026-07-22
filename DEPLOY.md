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
`admin/data/config.json` (correo Gmail, Zoom) **no viaja en git** por seguridad,
así que en el servidor hay que ponerlo aparte. La forma rápida:

1. En tu panel local: **Ajustes → Acceso y respaldo → Exportar configuración**.
   Se descarga un `mchub-config-AAAA-MM-DD.json` con todo (marca, catálogos,
   correo, Zoom, tokens).
2. En el servidor: **Ajustes → Acceso y respaldo → Elegir archivo .json → Importar**.

Listo, no hay que reescribir nada a mano. El importador solo acepta claves
conocidas, así que un archivo manipulado no puede meter datos raros.

> El archivo exportado lleva **tus claves en texto plano**. Guárdalo en un lugar
> seguro y bórralo cuando termines.

Si prefieres hacerlo a mano, entra a **Ajustes** y vuelve a poner correo, Zoom y
token de GitHub (o sube el `config.json` por SFTP).

## Login y roles
El panel trae autenticación propia y **no hay forma de crear un administrador
desde la web**: el primero se crea por terminal, así nadie que llegue a la URL
puede darse acceso.

```bash
cd ~/mchub.mecapacito.com
php admin/crear_admin.php --listar          # ver el equipo con sus ids
php admin/crear_admin.php "Ronny" tucorreo@gmail.com TuClaveSegura
```

Sin argumentos (`php admin/crear_admin.php`) va preguntando paso a paso y
oculta la contraseña mientras la escribes. El mismo comando sirve para cambiarle
la clave a un admin que la olvidó. Los siguientes administradores ya se agregan
desde el panel: **Equipo → editar la persona → Acceso: Administrador**.

Hay dos niveles de acceso (se definen al crear o editar un colaborador):

| Acceso | Puede |
|---|---|
| **Administrador** | Todo: proyectos, tareas, equipo, reuniones y Ajustes. |
| **Solo lectura** | Ver **los proyectos en los que participa** y **anotar observaciones** en ellos. No crea ni edita nada más. |

A los de solo lectura se les ocultan los botones de crear/editar/eliminar, no
pueden arrastrar en el Kanban y `Ajustes` no aparece en el menú. El bloqueo real
está en el servidor (`actions.php`), no solo en la interfaz.

### Qué ve un colaborador de solo lectura

Solo los proyectos **en los que participa**, y se participa si en ese proyecto:

- tiene al menos una **tarea asignada**, o
- está **invitado a una reunión**, o
- **escribió una observación**.

El resto del panel se ajusta solo: el dashboard, el menú lateral y los contadores
del equipo cuentan únicamente esos proyectos. Escribir `proyecto.php?id=N` a mano
para un proyecto ajeno devuelve al dashboard con un aviso, y tampoco se pueden
anotar observaciones ahí (`actions.php` lo valida). La ficha de un colaborador
(`colaborador.php`) lista sus tareas de todos los proyectos, así que cada quien
solo abre la suya; el administrador las abre todas.

El selector **«Ver como»** es exclusivo del administrador: no aparece en el menú
y, si alguien fuerza `?ver_como=N` en la URL, el filtro se descarta. Todo esto se
decide en `alcanceProyectos()` / `puedeVerProyecto()` (`admin/lib/bootstrap.php`).

Para dar acceso a alguien: **Equipo → editar la persona → Acceso + Contraseña**.
Siempre debe quedar al menos un administrador (el sistema lo impide).

### Entrar con Google (opcional)
En **Ajustes → Integraciones → Acceso al panel** puedes activar “Continuar con
Google”. Copia la **URI de redirección** que muestra ahí y pégala en
[Google Cloud Console](https://console.cloud.google.com/apis/credentials) →
tu OAuth Client → *Authorized redirect URIs*. Si dejas Client ID/Secret vacíos,
reutiliza los del correo (API de Gmail).

Solo entran los correos que **ya existen** como colaboradores: Google nunca crea
usuarios nuevos en el panel.

### Credenciales
Las contraseñas se guardan con `password_hash` (bcrypt), nunca en claro. Los
secretos de Ajustes (Zoom, Gmail, GitHub, Google) ya **no se imprimen en el HTML**:
el campo aparece vacío con el texto “•••••••• guardado”. Si lo dejas vacío al
guardar, se conserva el que ya estaba; escribe uno nuevo solo para reemplazarlo.

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
