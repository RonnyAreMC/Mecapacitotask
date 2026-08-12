# Estándar del equipo — InnoTech Hub

Cómo escribimos **tareas** y **commits** para que todo quede enlazado por el
**`#id`** de la tarea. Si todos seguimos esto, cada commit dice a qué tarea
pertenece; el **estado lo mueves tú en el panel** (el commit no lo cambia solo).

> Regla de oro: **cada commit apunta a una tarea con `#<número>`**.

---

## 1. El número de tarea (`#id`)

Cada tarea del panel tiene un número, visible como **`#42`** en su detalle y en
su tarjeta. Ese número es su identidad para todo: commits, ramas y conversación.

- Para copiarlo: abre la tarea en el panel → arriba dice **Tarea #42**.
- Si planificas en JSON (módulo *Planificar*), el número lo asigna el panel al
  importar; luego lo consultas en el tablero.

---

## 2. Commits

Formato (basado en *Conventional Commits* + la referencia a la tarea):

```
<tipo>(<área>): <descripción en presente, corta>  #<id>
```

- **tipo**: `feat` (nueva función), `fix` (arreglo), `refactor`, `docs`,
  `test`, `style`, `chore` (tareas de mantenimiento).
- **área** (opcional): módulo tocado, en minúscula: `login`, `nomina`, `cxc`…
- **descripción**: qué hace el commit, en presente y sin punto final.
- **`#<id>`**: la tarea a la que pertenece. **Obligatorio.**

Ejemplos:

```
feat(login): agrega botón de Google  #42
fix(cxc): corrige el cálculo de anticipos  #17
refactor(nomina): separa el rol de pagos en su módulo  #88
```

### El `#id` solo enlaza (no cambia el estado)

El `#<id>` sirve para **referenciar** la tarea: deja claro a qué pertenece el
commit. **No cambia el estado por sí solo** — eso lo haces tú moviendo la tarea
en el panel cuando corresponde.

Puedes escribir `closes #42` / `fixes #42` / `cierra #42` como costumbre (por
ejemplo, para que GitHub/GitLab cierren su propio issue), pero **el panel no
mueve la tarea**: el estado siempre lo decides tú.

> Un commit puede referenciar varias tareas: `… #42 #43`.

---

## 3. Ramas

```
<tipo>/<id>-<slug-corto>
```

Ejemplos: `feat/42-login-google`, `fix/17-anticipos-cxc`.

Así, con solo ver la rama sabes de qué tarea es, y el panel también.

---

## 4. Pull Requests (si usan PR)

- **Título**: igual que un commit → `feat(login): … #42`.
- **Descripción**: menciona la tarea con `#42`. El estado de la tarea lo mueves
  tú en el panel; el merge no lo cambia.

---

## 5. Planificación en JSON (solo el admin)

El admin carga tareas en lote en *Planificar → Importar*. Referencia por
**nombre** (no por número), porque los números los pone el panel:

```json
{
  "tareas": [
    {
      "proyecto": "SIGE",
      "titulo": "Login con Google",
      "descripcion": "Pantalla partida y botón oficial.",
      "prioridad": "alta",
      "fecha_inicio": "2026-07-28",
      "fecha_limite": "2026-08-03",
      "asignados": ["Kevin Arellano"],
      "ref": "login"
    },
    {
      "proyecto": "SIGE",
      "titulo": "Conectar login al backend",
      "asignados": ["Dulce Villacis"],
      "depende_de": "login"
    }
  ]
}
```

- Obligatorios: `proyecto`, `titulo`.
- Opcionales: `descripcion`, `prioridad` (baja/media/alta), `estado`,
  `fecha_inicio`, `fecha_limite`, `asignados` (nombres), `ref` + `depende_de`
  para encadenar tareas dentro del mismo lote.

---

## 6. Resumen de un vistazo

1. Tomo mi tarea del panel → anoto su **`#id`**.
2. Creo la rama `tipo/id-slug`.
3. Cada commit lleva `tipo(área): descripción #id`.
4. Muevo la tarea en el panel según avanzo (En progreso, Revisión, Hecho).
5. El `#id` mantiene todo enlazado; el estado siempre lo pongo yo.
