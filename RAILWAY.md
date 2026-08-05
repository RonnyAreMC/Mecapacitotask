# Desplegar en Railway — InnoTech Académico

Esta rama (`innotech-academico`) es **otro proyecto**, independiente de Mecapacito.
Mismo código, pero corre en su propio servidor Railway, con su propia base de
datos y sus propias credenciales. `main` no se toca.

Es una app PHP + SQLite servida con **Apache** (por el `Dockerfile`). Apache se
usa a propósito para respetar los `.htaccess` que blindan `admin/data`.

---

## 1. Crear el proyecto en Railway

1. Entra a https://railway.app → **New Project** → **Deploy from GitHub repo**.
2. Elige este repo (`Mecapacitotask`).
3. En **Settings → Source**, fija la rama a **`innotech-academico`**.
   (Así este servidor solo se actualiza cuando subes a esa rama, no con `main`.)
4. Railway detecta el `Dockerfile` y `railway.json` solos. No hace falta build
   command ni start command.

## 2. Volumen para que los datos NO se borren

El contenedor es efímero: sin volumen, la base SQLite se reinicia en cada deploy.

1. En el servicio → **Variables/Settings → Volumes → New Volume**.
2. **Mount path:** `/var/www/html/admin/data`
3. Guarda y **redeploy**.

> La seguridad no depende de ese volumen: el `Dockerfile` bloquea `admin/data`
> y `admin/lib` desde la config de Apache, aunque el volumen tape el `.htaccess`.

## 3. Dominio

En **Settings → Networking → Generate Domain** (o conecta uno propio).
Railway ya expone el `$PORT` correcto; el `Dockerfile` hace que Apache escuche ahí.

## 4. Crear el primer administrador

La app arranca con la base vacía. Abre una terminal del contenedor
(**servicio → … → Shell**, o `railway ssh`) y corre:

```bash
php admin/crear_admin.php --listar         # ver el equipo (si sembraste roster)
php admin/crear_admin.php "Nombre" correo@dominio.com TuClaveSegura
```

Luego entra en `/admin/login.php`.

## 5. Credenciales (Zoom, Google, Gmail) — se ponen DENTRO del panel

No van en variables de entorno: se configuran en **Ajustes** una vez dentro, y
se guardan en `admin/data/config.json` (dentro del volumen, no accesible por web).

Para OAuth de Google/Zoom, agrega el dominio nuevo de Railway como URI de
redirección autorizado en cada consola:

- Google: `https://TU-DOMINIO.up.railway.app/admin/oauth_google.php`
- Zoom: los redirect que uses en la app Server-to-Server.

---

## Actualizar este servidor

```bash
git checkout innotech-academico
# ...cambios...
git push origin innotech-academico     # Railway redespliega solo
```

Para traer mejoras hechas en Mecapacito (`main`) a este proyecto:

```bash
git checkout innotech-academico
git merge main
git push origin innotech-academico
```
