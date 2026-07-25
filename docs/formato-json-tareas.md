# Formato del JSON para importar tareas (módulo Planificar)

Referencia para generar el JSON que se pega en **Planificar → Importar**.
Referencia proyectos y personas **por nombre** (no por número): el mismo archivo
sirve en local y en producción.

## Estructura

```json
{
  "tareas": [
    {
      "proyecto": "SIGE",
      "titulo": "Login con Google",
      "descripcion": "Pantalla partida y botón oficial.",
      "prioridad": "alta",
      "estado": "pendiente",
      "fecha_inicio": "2026-07-28",
      "fecha_limite": "2026-08-03",
      "asignados": ["Kevin Arellano"],
      "ref": "login",
      "depende_de": "otra-ref"
    }
  ]
}
```

También acepta un arreglo de tareas directo (sin la envoltura `"tareas"`).

## Campos

| Campo | Obligatorio | Valores |
|---|---|---|
| `proyecto` | **sí** | Nombre del proyecto (ver lista abajo) |
| `titulo` | **sí** | Texto libre |
| `descripcion` | no | Texto libre |
| `prioridad` | no | `baja` · `media` · `alta` (def. media) |
| `estado` | no | `pendiente` · `progreso` · `revision` · `hecho` (def. pendiente) |
| `fecha_inicio` | no | `AAAA-MM-DD` |
| `fecha_limite` | no | `AAAA-MM-DD` |
| `asignados` | no | Lista de nombres |
| `ref` | no | Id temporal en este lote (para dependencias) |
| `depende_de` | no | `ref` (o título) de otra tarea del mismo lote |

## Proyectos

`SIGE` · `TPV` · `CONTABILIDAD`

## Equipo (nombres para `asignados`)

Programadores: **Kevin Arellano**, **Ronny Arellano** (Tech Lead),
**Dulce Villacis**, **Jaione Cherres**, **Jordy Pincay**.
Analistas: **Felipe Arevalo** (admin/jefe), **Erick Pastrano**, **Ronald**.

> Basta el primer nombre si es único (el importador resuelve "Kevin" →
> "Kevin Arellano"). Con nombre completo nunca falla.

## Reglas de generación

1. **Todo o nada**: si una fila tiene error grave (proyecto inexistente, sin
   título) NO se crea ninguna. Validar antes con "Validar sin crear".
2. **Medio tiempo**: el equipo trabaja part-time. Dar **2 a 5 días hábiles**
   por módulo/tarea grande; no cargar todo a una sola persona.
3. **Fechas hábiles**: encadenar sin solaparse, saltando fines de semana.
   `depende_de` marca el orden dentro del lote.
4. **Bloques grandes** (módulos): ~1–2 semanas cada uno, no 2 días.
5. Convertir fechas relativas ("hoy", "en dos semanas") a `AAAA-MM-DD`.

## Actualizar en vez de duplicar

En **Planificar** hay una casilla **"Actualizar las que ya existan"**. Con ella
marcada, si una tarea del JSON tiene el **mismo proyecto y título** que una que
ya existe, el panel la **actualiza** (descripción, prioridad, estado, fechas y
responsables) en lugar de crear una copia. Las que no existan se crean igual.

- El emparejamiento es por **título exacto** (sin distinguir tildes/mayúsculas).
- Al actualizar, los campos del JSON **reemplazan** a los de la tarea (incluidos
  los `asignados`): el JSON manda como estado deseado.
- Sin la casilla marcada, todo se crea (y avisa si un título ya existía).

## Enlace con commits (estándar del equipo)

Cada tarea, ya creada, tiene un número `#id`. Los commits la referencian con
`#<id>` (ver `docs/estandar-equipo.md`). El JSON no lleva el número: lo asigna
el panel al importar.
