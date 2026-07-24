# Deploy en cPanel (subdominio) por terminal

Panel PHP con persistencia en **SQLite** (un solo archivo, sin servidor de base de datos).
Requisitos: **PHP 8.1+** con la extensión **pdo_sqlite** (viene activa en casi todo
cPanel) y **HTTPS** (el pegado de imágenes usa el portapapeles del navegador, que exige contexto seguro).

> Para comprobar que el servidor tiene SQLite: `php -m | grep sqlite` (deben salir
> `pdo_sqlite` y `sqlite3`). Si no aparecen, actívalos en cPanel → **Select PHP
> Version / MultiPHP INI Editor → Extensions**.

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

- figura en el **equipo del proyecto** (Editar proyecto → Equipo), o
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

### Enviar las tareas al Google Calendar de cada responsable
En **Ajustes → Integraciones → Acceso al panel** activa «Enviar las tareas al Google
Calendar de cada responsable». Cuando una tarea tiene fecha se crea un evento (de día
completo, del inicio al fin) en el Google Calendar de cada persona asignada; al editar
la tarea el evento se actualiza y al borrarla se elimina.

Requisitos:
- En **Google Cloud Console → OAuth consent screen**, agrega el scope
  `https://www.googleapis.com/auth/calendar.events`.
- Cada colaborador debe **volver a entrar con Google una vez** para conceder el permiso
  (ahí el panel guarda su *refresh token*). Quien no lo haga, no recibe eventos; su
  tarea funciona igual.

### Mi perfil
Cada persona edita lo suyo en **Mi perfil** (se llega pinchando su nombre abajo
del menú): nombre, rol, usuario de Git, correo, foto, color y **contraseña**,
en un asistente por pasos.

Lo que **no** se toca desde ahí, a propósito: el **nivel de acceso** y el
**equipo**. Los decide un administrador desde *Equipo*; si no, cualquiera se
daría permisos de admin editando su propia ficha. El bloqueo está en el
servidor: `perfil_guardar` saca el id de la sesión y **nunca del formulario**.

Para cambiar la contraseña hay que escribir la actual (salvo si aún no tienes
ninguna porque entras con Google). Correo y usuario de Git no pueden repetirse
con los de otra persona, y no se pueden dejar los dos vacíos: son las dos
formas de entrar, y sin ninguna te quedarías fuera del panel.

### Credenciales
Las contraseñas se guardan con `password_hash` (bcrypt), nunca en claro. Los
secretos de Ajustes (Zoom, Gmail, GitHub, Google) ya **no se imprimen en el HTML**:
el campo aparece vacío con el texto “•••••••• guardado”. Si lo dejas vacío al
guardar, se conserva el que ya estaba; escribe uno nuevo solo para reemplazarlo.

## Repositorios de un proyecto

Cada proyecto puede enlazar **varios repositorios**, no solo backend y frontend.
En **Editar proyecto → Repos** agregas las filas que necesites; cada una lleva:

- **Tipo**: Backend, Frontend, Móvil o Repositorio (define el icono).
- **Nombre** (opcional): para distinguir cuando hay varios del mismo tipo —por
  ejemplo, un mismo sistema para dos instituciones, cada una con su repo.
- **URL** del repositorio en GitHub.

Se muestran como botones en la cabecera del tablero y su actividad de commits
sale en Métricas. Los proyectos creados antes (que guardaban un backend y un
frontend sueltos) se siguen viendo igual hasta que los edites.

## Vistas del tablero

Al abrir un proyecto lo primero es el **Calendario**. La pestaña **Tareas**
agrupa las tres formas de ver la misma lista —**Tabla, Kanban y Flujo**— en un
subselector «Ver como»; el panel recuerda cuál elegiste. Las demás pestañas
(Observaciones, Intercambios, Reuniones, Métricas) quedan al lado.

## Equipo del proyecto y fechas de inicio

**Editar proyecto → Equipo** define quién participa. Con la lista puesta, al
crear o editar una tarea el selector «Asignado a» **solo ofrece a esa gente**
(en vez de a todo el equipo), y esas personas ganan acceso al proyecto aunque
todavía no tengan tareas. Si no eliges a nadie, el proyecto queda **abierto a
todo el equipo**: los proyectos de antes de esta versión siguen así, no hay que
migrar nada.

> Si alguien ya tenía una tarea asignada y luego sale del equipo del proyecto,
> se le sigue ofreciendo en el selector para no perder esa asignación.

Tareas y proyectos tienen **fecha de inicio** además de la fecha límite, así que
puedes dejar una tarea programada para más adelante. El paso «Fechas» del
asistente trae atajos (*Hoy*, *Mañana*, *El lunes que viene*, *En dos semanas*)
y avisa de la ventana que queda. Una tarea cuyo inicio aún no llegó se marca en
el tablero con **«en N d»**. El servidor rechaza guardar si el inicio queda
después del límite.

## Intercambio de tareas

Cuando alguien no puede avanzar con una tarea —indisposición de salud, carga de
trabajo, o porque su tarea bloquea la de otro— puede **ofrecerla a cambio de
otra** desde la pestaña **Intercambios** del tablero.

1. Elige **su** tarea y la del compañero (solo se ofrece lo propio).
2. Indica el **motivo** (salud, carga, bloqueo, perfil, permiso u otro) y una nota.
3. Al otro le llega un **correo**: «X quiere intercambiar tareas contigo».
4. Esa persona **acepta o rechaza** desde el panel, y quien propuso recibe otro
   correo con la respuesta.

**Nada cambia hasta que la otra parte acepta**: al aceptar se cruzan los
responsables de las dos tareas. Quien propuso puede retirar la propuesta
mientras siga pendiente, y una tarea no puede estar en dos propuestas a la vez.

Lo pueden usar también las cuentas de **solo lectura**: es su forma de pedir un
cambio sin tener que editar tareas. Cada acción comprueba en el servidor que la
tarea ofrecida sea tuya y que la respuesta la dé el destinatario.

## Qué se avisa por correo

En **Ajustes → Correo → ¿Qué avisar?**:

| Aviso | A quién |
|---|---|
| Tarea asignada | A la persona asignada |
| **Sumado al equipo de un proyecto** | A quien se acaba de sumar (solo a los nuevos) |
| **Intercambio de tareas** | Al destinatario de la propuesta y, al responder, a quien la hizo |
| Tarea próxima a vencer | Al asignado (necesita el cron, ver abajo) |
| Proyecto completado | Al correo de administrador configurado |

## Actualizar a futuro
```bash
cd ~/public_html/panel.tudominio.com
git pull
```
Tus datos (`admin/data/panel.sqlite`, `admin/uploads/`) están en `.gitignore`, así
que `git pull` **nunca los pisa**.

## Migrar de JSON a SQLite (una sola vez, si ya tenías datos)

Las versiones viejas guardaban en archivos `.json`. Al actualizar a la versión con
SQLite, los datos se pasan solos la primera vez que se abre el panel. Para hacerlo
de forma controlada (y confirmar que el servidor tiene SQLite) antes de que entren
los usuarios:

```bash
cd ~/public_html/panel.tudominio.com
git pull
php admin/migrar_sqlite.php
```

Verás cuántos registros se migraron de cada colección. Los `.json` viejos quedan
como `*.json.importado` (respaldo); puedes borrarlos cuando confirmes que todo va
bien. Es idempotente: si ya se migró, no repite.

> **Instalación nueva** (sin datos previos): no hay que hacer nada, el panel crea
> el SQLite y siembra los datos de ejemplo solo.
>
> **Respaldo:** ahora es copiar **un archivo**, `admin/data/panel.sqlite`.

## Seguridad ya incluida en el repo
- `admin/data/.htaccess` y `admin/lib/.htaccess`: bloquean el acceso web a datos y clases.
- `.htaccess` raíz: bloquea `.git` y archivos sensibles.
