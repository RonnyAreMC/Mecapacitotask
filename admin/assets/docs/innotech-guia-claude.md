# Prompt para tu Claude (o Claude Code)

Copia **todo** el bloque de abajo y pégalo a tu Claude al empezar a trabajar en
un repo del equipo. Con esto, tus commits y ramas salen al estándar de InnoTech Hub
sin que tengas que acordarte del formato.

> Tip: si usas Claude Code, guarda este texto como `CLAUDE.md` en la raíz del
> repo y se aplica solo en cada sesión.

---

```
Trabajo en el equipo de InnoTech. Seguimos un estándar para que nuestro
tablero (InnoTech Hub) enlace los commits con las tareas automáticamente. Respétalo
SIEMPRE que me ayudes con git en este repo.

CADA TAREA tiene un número, por ejemplo #42. Yo te lo doy. Si no te lo di,
pídemelo antes de commitear.

COMMITS — formato obligatorio:
  <tipo>(<área>): <descripción en presente, corta, sin punto>  #<id>

  - tipo: feat | fix | refactor | docs | test | style | chore
  - área: el módulo, en minúscula (login, nomina, cxc…). Es opcional.
  - Termina SIEMPRE con la referencia a la tarea: #<id>.
  - Si el trabajo cierra la tarea, usa "closes #<id>" en vez de "#<id>".
  - Un commit puede referenciar varias tareas: … #42 #43

  Ejemplos:
    feat(login): agrega botón de Google  #42
    fix(cxc): corrige el cálculo de anticipos  #17
    refactor(nomina): separa el rol de pagos  closes #88

RAMAS:
  <tipo>/<id>-<slug-corto>
  Ejemplos: feat/42-login-google, fix/17-anticipos-cxc

PULL REQUESTS:
  - Título como un commit: feat(login): … #42
  - En la descripción incluye "Closes #42".

REGLAS:
  - No agrupes cambios de tareas distintas en un mismo commit; si tocaste dos
    tareas, haz dos commits.
  - Mensajes en español, en presente ("agrega", no "agregado").
  - Si no sabes a qué tarea pertenece un cambio, pregúntame el #id.

Cuando te pida "commitea esto", genera el mensaje ya con este formato y
muéstramelo antes de ejecutarlo.
```

---

## Para el admin que planifica (opcional)

Si planificas tareas y quieres que tu Claude te arme el JSON de importación,
pásale además esto:

```
Cuando te pida planificar tareas para InnoTech Hub, devuélveme un JSON con este
formato, listo para pegar en "Planificar → Importar":

{
  "tareas": [
    { "proyecto": "<SIGE|TPV|CONTABILIDAD>", "titulo": "…",
      "descripcion": "…", "prioridad": "baja|media|alta",
      "fecha_inicio": "AAAA-MM-DD", "fecha_limite": "AAAA-MM-DD",
      "asignados": ["Nombre Apellido"], "ref": "clave-corta",
      "depende_de": "ref-de-otra-tarea" }
  ]
}

Reglas:
  - "proyecto" y "asignados" van por NOMBRE, no por número.
  - Obligatorios: proyecto y titulo. El resto es opcional.
  - Usa "ref" + "depende_de" para encadenar tareas del mismo lote.
  - Respeta días hábiles y no me sobrecargues a una sola persona.
```
