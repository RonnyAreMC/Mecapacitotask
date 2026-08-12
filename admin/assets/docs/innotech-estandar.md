# Estándar del equipo — InnoTech Hub

Cómo escribimos **tareas** y **commits** para que el tablero se enlace y **avance
solo**. Cada commit dice a qué tarea pertenece con su **`#id`** y, con una palabra
clave, hace que el panel **mueva la tarea de estado** (al leer los commits en
*Métricas*). Solo avanza: nunca revierte un cambio que hiciste a mano.

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

### Palabras que mueven la tarea sola

La palabra clave va **pegada al `#id`**. El panel mueve la tarea al leer los
commits (al abrir *Métricas*), y **solo hacia adelante**:

| Escribes | El panel hace |
|---|---|
| `#42` (a secas) | Enlaza el commit y pasa la tarea a **En progreso** |
| `wip #42` | La deja **En progreso** (trabajo en curso) |
| `closes #42` / `fixes #42` / `cierra #42` / `resuelve #42` | La manda a **En revisión** (o **Completada** si no tiene observaciones pendientes) |

- La palabra debe ir **junto al `#id`**: `fixes #42` cierra, pero `fix(área): … #42`
  **no** (ahí `fix` es el *tipo* del commit, no un cierre).
- **Solo avanza**: si ya moviste la tarea más adelante a mano, el commit no la regresa.

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
- **Descripción**: incluye `Closes #42`. Cuando el panel lea ese commit mandará
  la tarea a revisión (o completada si no tiene observaciones pendientes).

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
4. Al terminar: `closes #id`.
5. El tablero avanza solo al leer los commits (en *Métricas*): commit bajo la
   tarea y estado al día.
