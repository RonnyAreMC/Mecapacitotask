/* =========================================================
   Diagrama de historia (pizarra.php · assets/pizarra.js)

   Cada diagrama tiene su TEXTO, en español y legible para el equipo, y se
   dibuja solo (sin IA, por palabras clave):

     Historia: R2-D01-HU04 · Autoevaluación docente con evidencias
     Como: docente
     Quiero: llenar mi autoevaluación
     Y: subir mis evidencias
     Para: que mi evaluación quede completa

     Escenario: Envío completo
       Dado: el plan está en ejecución
       Cuando: el docente sube sus evidencias y envía
       Entonces: el sistema genera un PDF de constancia
     Resultados: PDF, Correo

   QUIÉN la usa (con su dibujo) → ENTRA al sistema → los PASOS (cada
   «Quiero/Cuando/Y») → lo que OBTIENE (PDF, Excel, reporte, horario, correo…),
   con la frase simple «Mira: el docente entra al sistema, … Al final obtiene…».

   Es bilateral, con una sola fuente de verdad: el modelo que sale de
   parse(texto). Escribir en el panel de texto lo vuelve a dibujar (~300 ms);
   cambiar algo en el diagrama (doble clic en un texto, el actor desde su
   menú, añadir o quitar un paso, un escenario o un resultado) cambia ESE
   modelo y serializar(modelo) devuelve el texto. El modelo guarda cada línea
   tal como se escribió, así serializar(parse(t)) === t: nada se reordena ni
   se ensucia; solo se reescribe la línea que se tocó. Lo que no se entiende
   se marca en el panel y no rompe el resto.

   - El diagrama es UN elemento de la pizarra (tipo «hu», con su texto y su
     modelo): se mueve, se duplica, se borra y se deshace como cualquiera,
     viaja con «guardar» y los demás lo ven en vivo.
   - Del backlog: el panel «Diagrama de historia» (U) trae las historias del
     proyecto; al soltar una, su texto sale de sus campos. También se crea en
     blanco o pegando una HU con Ctrl+V. Si viene de una historia real y la
     persona arma el backlog, «Guardar en la historia» le escribe de vuelta
     Como, Quiero, Para y los escenarios.
   - «Reproducir» anima el recorrido paso a paso; si lo pulsa quien edita, lo
     ven todos (rep sube). Con «menos movimiento», sin desplazamientos.
   - En la pizarra de una reunión, el diagrama queda a nombre de quien lo puso
     (dueno), si arma el backlog de ese proyecto: solo él y el administrador lo
     cambian (lo hace cumplir también el servidor, lib/whiteboard/Pizarra.php). Los demás lo
     ven con su candado, lo reproducen y filtran sus casos, y leen su texto.
   - «Guardar en la historia» manda también su TEXTO (la historia lo muestra vivo)
     y su IMAGEN de respaldo (PNG a 2x, fondo blanco, sin los botones):
     pizarra_api.php?accion=guardar_hu y ?accion=guardar_hu_imagen.
   - «Ilustraciones» (D): los mismos dibujos (y más) para anotar sueltos en la
     pizarra. Cada uno es un elemento «ilu» que guarda solo su clave de esta
     biblioteca, su pie de texto y su color de fondo: no se sube nada.
   - Fuera de la pizarra, vista(contenedor, texto, {soloVista}) arma el mismo
     diagrama solo para ver (▶ y escenarios): lo usa historia.php con
     PizarraHU::vista($historia), que deja un [data-pz-hu-vista] con su JSON.
   - Los dibujos son SVG propios, planos, en la paleta de la pizarra.

   pizarra.js lo usa por window.PizarraHU: montar(api), pintar(el, nodo),
   botonesCtx/accionCtx, ponerCampo/alTerminar, alElegir, ocupado, dibujar…
   ========================================================= */
(() => {
  'use strict';

  /* =========================================================
     Dibujos: 96 × 96, planos. Las personas llegan hasta abajo: el círculo
     que las contiene (CSS, o el clip al exportar) las recorta.
     ========================================================= */
  const C = {
    piel: '#F5C9A8', pielS: '#E2A988', mejilla: '#F4A6A0', pelo: '#34465A', pelo2: '#7C4A2D', tinta: '#2D3E50',
    celeste: '#38BDF8', celesteF: '#0284C7', azul: '#2563EB', azulC: '#60A5FA', menta: '#34D399', verde: '#16A34A',
    lila: '#8B5CF6', lilaC: '#A78BFA', rosa: '#F43F5E', rosaF: '#E11D48', vainilla: '#FACC15', ambar: '#F59E0B',
    gris: '#CBD5E1', grisM: '#94A3B8', pizarra: '#475569', blanco: '#FFFFFF',
  };
  const S = (d) => `<svg viewBox="0 0 96 96" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">${d}</svg>`;
  const cara = `<circle cx="42.5" cy="44" r="1.9" fill="${C.tinta}"/><circle cx="53.5" cy="44" r="1.9" fill="${C.tinta}"/>`
    + `<circle cx="38" cy="49.5" r="2.4" fill="${C.mejilla}" opacity=".55"/><circle cx="58" cy="49.5" r="2.4" fill="${C.mejilla}" opacity=".55"/>`
    + `<path d="M44 50.5q4 3.2 8 0" fill="none" stroke="${C.tinta}" stroke-width="1.9" stroke-linecap="round"/>`;
  const cuello = `<rect x="42" y="54" width="12" height="13" rx="5" fill="${C.pielS}"/>`;
  const cuerpo = (c) => `<path d="M14 96c1-19 15-31 34-31s33 12 34 31z" fill="${c}"/>`;
  const cabeza = `<circle cx="31.5" cy="44" r="3.6" fill="${C.piel}"/><circle cx="64.5" cy="44" r="3.6" fill="${C.piel}"/><circle cx="48" cy="41" r="17" fill="${C.piel}"/>`;
  const peloCorto = (c) => `<path d="M31 40c-1-13 7-21 17-21 11 0 18 7 17 19-7 1-14-2-18-8-3 6-9 10-16 10z" fill="${c}"/>`;
  const peloMono = (c) => `<circle cx="48" cy="20" r="7" fill="${c}"/><path d="M31 42c-2-14 7-23 17-23s19 9 17 23c-3-7-9-12-17-12s-14 5-17 12z" fill="${c}"/>`;
  const camisaV = (corbata) => `<path d="M39 66.5l9 15 9-15c-3-1-6-1.5-9-1.5s-6 .5-9 1.5z" fill="${C.blanco}"/>`
    + `<path d="M46.4 69h3.2l1.6 10.5-3.2 3.8-3.2-3.8z" fill="${corbata}"/>`;
  const hoja = `<path d="M26 12h30l16 16v54a4 4 0 0 1-4 4H26a4 4 0 0 1-4-4V16a4 4 0 0 1 4-4z" fill="${C.blanco}" stroke="${C.gris}" stroke-width="2"/>`
    + `<path d="M56 12v12a4 4 0 0 0 4 4h12" fill="#E2E8F0" stroke="${C.gris}" stroke-width="2" stroke-linejoin="round"/>`;
  const sello = (x, y, w, h, color, texto, fs) => `<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="6" fill="${color}"/>`
    + `<text x="${x + w / 2}" y="${y + h / 2 + fs * 0.36}" text-anchor="middle" font-family="Inter, Arial, sans-serif" font-weight="800" font-size="${fs}" letter-spacing=".5" fill="#FFFFFF">${texto}</text>`;

  const ILU = {
    estudiante: S(cuello + cuerpo(C.celeste)
      + `<path d="M35 67c3 5 8 8 13 8s10-3 13-8" fill="none" stroke="#0EA5E9" stroke-width="4" stroke-linecap="round"/>`
      + `<path d="M29 73l5 23M67 73l-5 23" stroke="#1D6FB8" stroke-width="5" stroke-linecap="round"/>`
      + cabeza + peloCorto(C.pelo) + cara
      + `<g transform="rotate(-8 66 84)"><rect x="55" y="75" width="23" height="17" rx="2.5" fill="${C.vainilla}"/><path d="M66.5 75v17" stroke="#CA8A04" stroke-width="2"/></g>`),
    docente: S(`<rect x="57" y="16" width="25" height="19" rx="3" fill="#166534"/><path d="M62 23h8M62 28.5h13" stroke="#BBF7D0" stroke-width="2" stroke-linecap="round"/>`
      + cuello + cuerpo(C.lila) + camisaV(C.azul) + cabeza + peloCorto(C.pelo2)
      + `<g fill="none" stroke="${C.tinta}" stroke-width="1.8"><circle cx="42.5" cy="44" r="4.8"/><circle cx="53.5" cy="44" r="4.8"/><path d="M47.3 43.6h1.4"/></g>` + cara),
    medico: S(cuello + cuerpo(C.blanco)
      + `<path d="M40 66.5l8 13.5 8-13.5c-2.6-.9-5.3-1.3-8-1.3s-5.4.4-8 1.3z" fill="${C.menta}"/>`
      + `<path d="M37 67.5l11 15-5 13.5M59 67.5l-11 15 5 13.5" fill="none" stroke="${C.gris}" stroke-width="2.4" stroke-linejoin="round" stroke-linecap="round"/>`
      + `<path d="M35 69c-3 9 0 17 7 18" fill="none" stroke="${C.tinta}" stroke-width="2.6" stroke-linecap="round"/><circle cx="44" cy="87.5" r="3.8" fill="#0EA5E9"/>`
      + `<rect x="58" y="79" width="11" height="11" rx="2.5" fill="#FCE1EC"/><path d="M63.5 81.8v5.4M60.8 84.5h5.4" stroke="${C.rosaF}" stroke-width="2" stroke-linecap="round"/>`
      + cabeza + peloMono(C.pelo) + cara),
    administrativo: S(cuello + cuerpo(C.pizarra) + camisaV(C.celeste) + cabeza + peloCorto(C.tinta)
      + `<path d="M30.5 43a17.5 17.5 0 0 1 35 0" fill="none" stroke="${C.tinta}" stroke-width="3" stroke-linecap="round"/>`
      + `<rect x="27.5" y="40" width="6.5" height="11" rx="3.2" fill="${C.tinta}"/><path d="M31 51c1 5 5 7.5 10 7.5" fill="none" stroke="${C.tinta}" stroke-width="2" stroke-linecap="round"/><circle cx="42" cy="58.5" r="2" fill="${C.tinta}"/>`
      + cara
      + `<rect x="50" y="64" width="22" height="24" rx="3.5" fill="${C.ambar}"/><rect x="53" y="68" width="16" height="18" rx="2" fill="${C.blanco}"/>`
      + `<path d="M56 73h10M56 77.5h10M56 82h6" stroke="${C.gris}" stroke-width="2" stroke-linecap="round"/><rect x="56" y="61.5" width="10" height="5" rx="2.2" fill="${C.grisM}"/>`),
    persona: S(cuello + cuerpo(C.azulC)
      + `<path d="M40 66.5c2.4 3.6 5 5.4 8 5.4s5.6-1.8 8-5.4" fill="none" stroke="${C.blanco}" stroke-width="3" stroke-linecap="round"/>`
      + cabeza + peloCorto(C.pelo) + cara),
    empresa: S(`<path d="M38 22v-9" stroke="${C.pizarra}" stroke-width="2.4" stroke-linecap="round"/><path d="M38 13h10l-2.6 3.2L48 19.4H38z" fill="${C.rosa}"/>`
      + `<rect x="22" y="22" width="32" height="76" rx="3" fill="${C.pizarra}"/>`
      + [30, 42, 54, 66].map((y) => [27, 40].map((x) => `<rect x="${x}" y="${y}" width="8" height="7" rx="1.2" fill="#BAE6FD"/>`).join('')).join('')
      + `<rect x="33" y="80" width="10" height="16" rx="1.5" fill="#0EA5E9"/>`
      + `<rect x="54" y="44" width="26" height="54" rx="3" fill="${C.grisM}"/>`
      + [51, 62, 73, 84].map((y) => [58.5, 69].map((x) => `<rect x="${x}" y="${y}" width="6.5" height="6" rx="1.2" fill="#E0F2FE"/>`).join('')).join('')),
    sistema: S(`<rect x="14" y="20" width="68" height="46" rx="6" fill="${C.tinta}"/><rect x="19" y="25" width="58" height="36" rx="3" fill="#E0F2FE"/>`
      + `<rect x="23" y="29" width="15" height="28" rx="2" fill="${C.celeste}"/><rect x="42" y="29" width="31" height="7" rx="2" fill="${C.azul}"/>`
      + `<rect x="42" y="40" width="31" height="5" rx="2" fill="#BAE6FD"/><rect x="42" y="49" width="21" height="5" rx="2" fill="#BAE6FD"/>`
      + `<path d="M42 66h12l3 11H39z" fill="${C.grisM}"/><rect x="32" y="76" width="32" height="5" rx="2.5" fill="#64748B"/>`),
    escuela: S(`<path d="M48 15V6" stroke="${C.tinta}" stroke-width="2" stroke-linecap="round"/><path d="M48 6h10l-2.6 3L58 12H48z" fill="${C.rosa}"/>`
      + `<path d="M48 15l33 16H15z" fill="#1D6FB8" stroke="#1D6FB8" stroke-width="2" stroke-linejoin="round"/><circle cx="48" cy="24.5" r="3" fill="${C.blanco}"/>`
      + `<rect x="19" y="37" width="58" height="33" fill="#DBEAFE"/><rect x="17" y="31" width="62" height="6" rx="1.5" fill="${C.azul}"/>`
      + [21, 31, 58, 68].map((x) => `<rect x="${x}" y="39" width="7" height="31" rx="1.5" fill="${C.blanco}"/>`).join('')
      + `<path d="M41 70V58a7 7 0 0 1 14 0v12z" fill="${C.tinta}"/>`
      + `<rect x="18" y="70" width="60" height="6" rx="1.5" fill="${C.gris}"/><rect x="15" y="76" width="66" height="6" rx="1.5" fill="${C.grisM}"/>`),
    biblioteca: S(`<rect x="15" y="74" width="66" height="6" rx="2" fill="${C.grisM}"/>`
      + `<rect x="19" y="34" width="12" height="40" rx="2" fill="${C.celeste}"/><rect x="22" y="44" width="6" height="12" rx="1" fill="${C.blanco}" opacity=".85"/>`
      + `<rect x="33" y="24" width="11" height="50" rx="2" fill="${C.menta}"/><rect x="35.5" y="32" width="6" height="14" rx="1" fill="${C.blanco}" opacity=".85"/>`
      + `<rect x="46" y="36" width="13" height="38" rx="2" fill="${C.lilaC}"/><rect x="49.5" y="46" width="6" height="10" rx="1" fill="${C.blanco}" opacity=".85"/>`
      + `<g transform="rotate(16 70 74)"><rect x="62" y="32" width="11" height="42" rx="2" fill="${C.vainilla}"/><rect x="64.5" y="40" width="6" height="12" rx="1" fill="${C.blanco}" opacity=".85"/></g>`),
    subir: S(hoja + `<path d="M30 38h22M30 46h28M30 54h14" stroke="${C.gris}" stroke-width="3" stroke-linecap="round"/>`
      + `<circle cx="60" cy="66" r="17" fill="#0EA5E9"/><path d="M60 74V58M53 64.5l7-7 7 7" fill="none" stroke="#FFFFFF" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"/>`),
    tarea: S(`<rect x="22" y="16" width="52" height="68" rx="7" fill="${C.ambar}"/><rect x="28" y="24" width="40" height="54" rx="4" fill="${C.blanco}"/>`
      + `<rect x="38" y="11" width="20" height="10" rx="4" fill="#64748B"/>`
      + [37, 51].map((y) => `<circle cx="36" cy="${y}" r="4.6" fill="#22C55E"/><path d="M33.8 ${y}l1.6 1.7 3-3.4" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M45 ${y}h17" stroke="${C.gris}" stroke-width="3" stroke-linecap="round"/>`).join('')
      + `<circle cx="36" cy="65" r="4.2" fill="none" stroke="${C.gris}" stroke-width="2"/><path d="M45 65h12" stroke="${C.gris}" stroke-width="3" stroke-linecap="round"/>`),
    pdf: S(hoja + `<path d="M30 38h22M30 46h28" stroke="${C.gris}" stroke-width="3" stroke-linecap="round"/>` + sello(16, 54, 46, 22, C.rosaF, 'PDF', 15)),
    excel: S(hoja + `<rect x="29" y="30" width="34" height="27" rx="2" fill="#F0FDF4" stroke="#86EFAC" stroke-width="1.5"/><rect x="29" y="30" width="34" height="7" rx="2" fill="#4ADE80"/>`
      + `<path d="M29 44h34M29 50.5h34M40.5 37v20M51.5 37v20" stroke="#86EFAC" stroke-width="1.5"/>` + sello(16, 60, 46, 20, '#15803D', 'XLS', 13)),
    reporte: S(`<rect x="14" y="18" width="68" height="60" rx="8" fill="${C.blanco}" stroke="${C.gris}" stroke-width="2"/>`
      + `<rect x="24" y="52" width="9" height="16" rx="2" fill="${C.celeste}"/><rect x="37" y="44" width="9" height="24" rx="2" fill="${C.azul}"/>`
      + `<rect x="50" y="48" width="9" height="20" rx="2" fill="${C.lilaC}"/><rect x="63" y="38" width="9" height="30" rx="2" fill="${C.menta}"/>`
      + `<path d="M26 36l13-8 13 5 16-9" fill="none" stroke="${C.rosa}" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>`),
    horario: S(`<rect x="12" y="20" width="58" height="56" rx="8" fill="${C.blanco}" stroke="${C.gris}" stroke-width="2"/>`
      + `<path d="M12 28a8 8 0 0 1 8-8h42a8 8 0 0 1 8 8v6H12z" fill="${C.azul}"/>`
      + `<rect x="24" y="14" width="5" height="12" rx="2.5" fill="${C.tinta}"/><rect x="53" y="14" width="5" height="12" rx="2.5" fill="${C.tinta}"/>`
      + [40, 51, 62].map((y, r) => [19, 32, 45].map((x, c) => `<rect x="${x}" y="${y}" width="9" height="8" rx="2" fill="${r === 0 && c === 1 ? C.menta : r === 1 && c === 0 ? C.vainilla : '#E0F2FE'}"/>`).join('')).join('')
      + `<circle cx="68" cy="66" r="16" fill="${C.blanco}" stroke="#0EA5E9" stroke-width="3"/><path d="M68 57v9l6 4" fill="none" stroke="${C.tinta}" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>`),
    filtro: S(`<circle cx="30" cy="18" r="5" fill="${C.rosa}"/><circle cx="46" cy="14" r="5" fill="${C.menta}"/><circle cx="62" cy="19" r="5" fill="${C.vainilla}"/>`
      + `<circle cx="39" cy="27" r="4" fill="${C.lilaC}"/><circle cx="55" cy="28" r="4" fill="${C.celeste}"/>`
      + `<path d="M16 34h64a3 3 0 0 1 2.2 5L56 64v16a3 3 0 0 1-1.6 2.6l-9 4.6A2 2 0 0 1 42.5 85V64L13.8 39a3 3 0 0 1 2.2-5z" fill="#0EA5E9"/>`
      + `<path d="M22 39h52" stroke="#7DD3FC" stroke-width="3" stroke-linecap="round"/>`),
    correo: S(`<rect x="12" y="28" width="62" height="44" rx="7" fill="${C.blanco}" stroke="${C.gris}" stroke-width="2"/>`
      + `<path d="M15 33l28 20 28-20" fill="none" stroke="${C.celeste}" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/>`
      + `<circle cx="70" cy="30" r="14" fill="${C.rosa}"/><path d="M64.5 34.5h11c-1.4-1.3-2-2.6-2-5v-2a3.5 3.5 0 0 0-7 0v2c0 2.4-.6 3.7-2 5z" fill="${C.blanco}"/><circle cx="70" cy="37.2" r="1.7" fill="${C.blanco}"/>`),
    certificado: S(`<rect x="14" y="18" width="68" height="50" rx="5" fill="${C.blanco}" stroke="${C.gris}" stroke-width="2"/>`
      + `<rect x="19" y="23" width="58" height="40" rx="3" fill="none" stroke="#FDE68A" stroke-width="2"/>`
      + `<path d="M27 34h30M27 42h38M27 50h20" stroke="${C.gris}" stroke-width="3" stroke-linecap="round"/>`
      + `<path d="M58 62l-4 22 8.5-4.5 7 6 .5-23z" fill="${C.rosaF}"/><circle cx="64" cy="58" r="11" fill="${C.vainilla}" stroke="#CA8A04" stroke-width="2"/>`
      + `<path d="M59.5 58l3.2 3.2 5.5-6.2" fill="none" stroke="${C.blanco}" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>`),
    pago: S(`<rect x="10" y="26" width="60" height="40" rx="7" fill="${C.azul}"/><rect x="18" y="37" width="12" height="9" rx="2" fill="${C.vainilla}"/>`
      + `<path d="M18 56h8M30 56h8M42 56h8" stroke="#BFDBFE" stroke-width="3" stroke-linecap="round"/>`
      + `<circle cx="66" cy="62" r="16" fill="#FBBF24" stroke="${C.ambar}" stroke-width="3"/>`
      + `<text x="66" y="68.5" text-anchor="middle" font-family="Inter, Arial, sans-serif" font-weight="800" font-size="18" fill="#92400E">$</text>`),
    // ----- Más para anotar en la pizarra (panel «Ilustraciones») -----
    proceso: S(`<path d="M14 34h24l12 14-12 14H14l12-14z" fill="${C.celeste}"/><path d="M44 34h24l12 14-12 14H44l12-14z" fill="${C.azul}"/>`),
    nube: S(`<circle cx="36" cy="56" r="12" fill="${C.blanco}"/><circle cx="51" cy="46" r="16" fill="${C.blanco}"/><circle cx="66" cy="56" r="11" fill="${C.blanco}"/><rect x="36" y="52" width="30" height="16" rx="2" fill="${C.blanco}"/>`
      + `<path d="M51 63V49m-6 6 6-6 6 6" fill="none" stroke="${C.celeste}" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"/>`),
    basedatos: S(`<path d="M26 28v40c0 6 10 10 22 10s22-4 22-10V28" fill="${C.azul}"/><ellipse cx="48" cy="28" rx="22" ry="9" fill="${C.azulC}"/>`
      + `<path d="M26 42c0 6 10 10 22 10s22-4 22-10M26 55c0 6 10 10 22 10s22-4 22-10" fill="none" stroke="#93C5FD" stroke-width="2.5"/>`),
    celular: S(`<rect x="32" y="14" width="32" height="64" rx="7" fill="${C.tinta}"/><rect x="35.5" y="21" width="25" height="46" rx="3" fill="#E0F2FE"/>`
      + `<rect x="43" y="16.5" width="10" height="2" rx="1" fill="#64748B"/><circle cx="48" cy="72" r="2.6" fill="${C.grisM}"/>`
      + `<rect x="39" y="26" width="18" height="7" rx="2" fill="${C.celeste}"/><rect x="39" y="37" width="18" height="4" rx="2" fill="#BAE6FD"/><rect x="39" y="45" width="12" height="4" rx="2" fill="#BAE6FD"/>`),
    laptop: S(`<rect x="20" y="22" width="56" height="40" rx="5" fill="${C.tinta}"/><rect x="24" y="26" width="48" height="32" rx="2" fill="#E0F2FE"/>`
      + `<rect x="28" y="30" width="15" height="24" rx="2" fill="${C.celeste}"/><rect x="47" y="30" width="21" height="6" rx="2" fill="${C.azul}"/><rect x="47" y="40" width="21" height="4" rx="2" fill="#BAE6FD"/><rect x="47" y="48" width="14" height="4" rx="2" fill="#BAE6FD"/>`
      + `<path d="M12 64h72l-4 8H16z" fill="${C.grisM}"/><rect x="42" y="64" width="12" height="3" rx="1.5" fill="#64748B"/>`),
    dinero: S(`<rect x="12" y="28" width="56" height="32" rx="5" fill="#22C55E"/><rect x="17" y="33" width="46" height="22" rx="3" fill="none" stroke="#BBF7D0" stroke-width="2"/>`
      + `<circle cx="40" cy="44" r="8" fill="#BBF7D0"/><text x="40" y="48.5" text-anchor="middle" font-family="Inter, Arial, sans-serif" font-weight="800" font-size="12" fill="#15803D">$</text>`
      + `<ellipse cx="66" cy="72" rx="13" ry="5" fill="${C.ambar}"/><rect x="53" y="62" width="26" height="10" fill="#FBBF24"/><ellipse cx="66" cy="62" rx="13" ry="5" fill="#FDE68A"/>`),
    calendario: S(`<rect x="18" y="22" width="60" height="56" rx="8" fill="${C.blanco}" stroke="${C.gris}" stroke-width="2"/><path d="M18 30a8 8 0 0 1 8-8h44a8 8 0 0 1 8 8v8H18z" fill="${C.rosa}"/>`
      + `<rect x="31" y="16" width="5" height="12" rx="2.5" fill="${C.tinta}"/><rect x="60" y="16" width="5" height="12" rx="2.5" fill="${C.tinta}"/>`
      + `<text x="48" y="69" text-anchor="middle" font-family="Inter, Arial, sans-serif" font-weight="800" font-size="24" fill="${C.tinta}">12</text>`),
    reunion: S(`<rect x="42" y="56" width="10" height="9" rx="4" fill="${C.pielS}"/>`
      + `<path d="M12 78c0-11 7-18 15-18s15 7 15 18z" fill="${C.lilaC}"/><circle cx="27" cy="46" r="9" fill="${C.piel}"/><path d="M18 45c0-7 4-11 9-11s9 4 9 10c-4 0-7-2-9-5-2 3-5 6-9 6z" fill="${C.pelo2}"/>`
      + `<path d="M54 78c0-11 7-18 15-18s15 7 15 18z" fill="${C.menta}"/><circle cx="69" cy="46" r="9" fill="${C.piel}"/><path d="M60 45c0-7 4-11 9-11s9 4 9 10c-4 0-7-2-9-5-2 3-5 6-9 6z" fill="${C.pelo}"/>`
      + `<path d="M28 86c0-14 9-22 20-22s20 8 20 22z" fill="${C.celeste}"/><circle cx="48" cy="44" r="12" fill="${C.piel}"/><path d="M36 43c0-9 5-15 12-15s12 6 12 14c-5 0-10-2-12-7-2 4-7 8-12 8z" fill="${C.pelo}"/>`
      + `<circle cx="44" cy="46" r="1.5" fill="${C.tinta}"/><circle cx="52" cy="46" r="1.5" fill="${C.tinta}"/><path d="M45 50.5q3 2.2 6 0" fill="none" stroke="${C.tinta}" stroke-width="1.5" stroke-linecap="round"/>`
      + `<rect x="18" y="78" width="60" height="7" rx="3.5" fill="${C.grisM}"/>`),
    idea: S(`<path d="M17 36h-6M79 36h6M23 16l-4-4M73 16l4-4" stroke="${C.ambar}" stroke-width="3" stroke-linecap="round"/>`
      + `<path d="M48 14a22 22 0 0 0-13 40c3 2 5 6 5 9v3h16v-3c0-3 2-7 5-9a22 22 0 0 0-13-40z" fill="${C.vainilla}"/>`
      + `<path d="M37 31a12 12 0 0 1 9-7" stroke="${C.blanco}" stroke-width="3" fill="none" stroke-linecap="round"/>`
      + `<rect x="39" y="68" width="18" height="6" rx="2" fill="${C.grisM}"/><rect x="41" y="75" width="14" height="5" rx="2" fill="#64748B"/>`),
    alerta: S(`<path d="M48 18 82 74H14z" fill="${C.vainilla}" stroke="${C.vainilla}" stroke-width="6" stroke-linejoin="round"/>`
      + `<path d="M48 40v17" stroke="${C.tinta}" stroke-width="6" stroke-linecap="round"/><circle cx="48" cy="66" r="3.6" fill="${C.tinta}"/>`),
    ok: S(`<circle cx="48" cy="48" r="30" fill="#22C55E"/><path d="M34 49l9 9 19-20" fill="none" stroke="${C.blanco}" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/>`),
    error: S(`<circle cx="48" cy="48" r="30" fill="${C.rosa}"/><path d="M37 37l22 22M59 37 37 59" stroke="${C.blanco}" stroke-width="7" stroke-linecap="round"/>`),
    candado: S(`<path d="M34 42v-8a14 14 0 0 1 28 0v8" fill="none" stroke="#64748B" stroke-width="7"/><rect x="24" y="42" width="48" height="36" rx="8" fill="${C.ambar}"/>`
      + `<circle cx="48" cy="56" r="5" fill="#92400E"/><path d="M48 59v8" stroke="#92400E" stroke-width="4" stroke-linecap="round"/>`),
    carpeta: S(`<path d="M14 30a6 6 0 0 1 6-6h18l6 7h32a6 6 0 0 1 6 6v33a6 6 0 0 1-6 6H20a6 6 0 0 1-6-6z" fill="${C.ambar}"/>`
      + `<rect x="24" y="32" width="42" height="14" rx="2" fill="${C.blanco}"/><path d="M14 42h68v28a6 6 0 0 1-6 6H20a6 6 0 0 1-6-6z" fill="#FBBF24"/>`),
    grafico: S(`<circle cx="44" cy="50" r="26" fill="${C.celeste}"/><path d="M44 50h26a26 26 0 0 1-7.6 18.4z" fill="${C.menta}"/>`
      + `<path d="M48 46V20a26 26 0 0 1 26 26z" fill="${C.vainilla}"/>`),
    aula: S(`<rect x="16" y="18" width="64" height="38" rx="4" fill="#166534"/><rect x="20" y="22" width="56" height="30" rx="2" fill="#15803D"/>`
      + `<path d="M26 30h20M26 37h28M26 44h14" stroke="#BBF7D0" stroke-width="2.5" stroke-linecap="round"/><path d="M24 56v8M72 56v8" stroke="${C.grisM}" stroke-width="3"/>`
      + `<rect x="18" y="66" width="26" height="6" rx="2" fill="${C.ambar}"/><rect x="52" y="66" width="26" height="6" rx="2" fill="${C.ambar}"/>`
      + `<path d="M22 72v8M40 72v8M56 72v8M74 72v8" stroke="#B45309" stroke-width="3" stroke-linecap="round"/>`),
    graduado: S(cuello + cuerpo(C.tinta) + `<path d="M40 66l-4 30M56 66l4 30" stroke="${C.vainilla}" stroke-width="6"/>` + cabeza + cara
      + `<path d="M34 29v6c0 3 6 5 14 5s14-2 14-5v-6l-14 5z" fill="#334155"/><path d="M22 24 48 14l26 10-26 10z" fill="${C.tinta}"/>`
      + `<path d="M74 24v14" stroke="${C.vainilla}" stroke-width="2.5"/><circle cx="74" cy="40" r="3" fill="${C.vainilla}"/>`),
    familia: S(`<rect x="34" y="44" width="8" height="12" rx="3.5" fill="${C.pielS}"/><path d="M14 94c1-16 11-27 24-27s23 11 24 27z" fill="${C.lila}"/>`
      + `<circle cx="38" cy="34" r="13" fill="${C.piel}"/><path d="M25 33c-1-10 6-16 13-16s14 6 13 16c-3-5-8-8-13-8s-10 3-13 8z" fill="${C.pelo2}"/>`
      + `<circle cx="34" cy="36" r="1.6" fill="${C.tinta}"/><circle cx="42" cy="36" r="1.6" fill="${C.tinta}"/><path d="M35 41q3 2.2 6 0" fill="none" stroke="${C.tinta}" stroke-width="1.5" stroke-linecap="round"/>`
      + `<rect x="63" y="59" width="6" height="8" rx="2.5" fill="${C.pielS}"/><path d="M50 94c1-12 7-20 16-20s15 8 16 20z" fill="${C.menta}"/>`
      + `<circle cx="66" cy="54" r="10" fill="${C.piel}"/><path d="M56 53c0-7 4-11 10-11s10 4 10 10c-4 0-8-2-10-5-2 3-5 6-10 6z" fill="${C.pelo}"/>`
      + `<circle cx="63" cy="56" r="1.4" fill="${C.tinta}"/><circle cx="69" cy="56" r="1.4" fill="${C.tinta}"/>`),
    buscar: S(`<circle cx="42" cy="42" r="20" fill="#E0F2FE" stroke="${C.azul}" stroke-width="7"/><path d="M57 57l16 16" stroke="${C.tinta}" stroke-width="9" stroke-linecap="round"/>`
      + `<path d="M33 37a11 11 0 0 1 8-7" stroke="${C.blanco}" stroke-width="3" fill="none" stroke-linecap="round"/>`),
    chat: S(`<path d="M20 24h52a8 8 0 0 1 8 8v26a8 8 0 0 1-8 8H42l-14 11V66h-8a8 8 0 0 1-8-8V32a8 8 0 0 1 8-8z" fill="${C.blanco}" stroke="${C.gris}" stroke-width="2"/>`
      + `<circle cx="34" cy="45" r="4" fill="${C.celeste}"/><circle cx="46" cy="45" r="4" fill="${C.azul}"/><circle cx="58" cy="45" r="4" fill="${C.celeste}"/>`),
  };
  // El fondo suave de cada dibujo: uno de los papeles de la pizarra ([data-color] en pizarra.css)
  const TINTE = {
    estudiante: 'celeste', docente: 'lila', medico: 'menta', administrativo: 'vainilla', persona: 'durazno', empresa: 'gris', sistema: 'celeste',
    escuela: 'celeste', biblioteca: 'vainilla', tarea: 'menta', subir: 'celeste', pdf: 'rosa', excel: 'menta', reporte: 'lila', horario: 'celeste',
    filtro: 'celeste', correo: 'rosa', certificado: 'vainilla', pago: 'celeste',
    proceso: 'celeste', nube: 'celeste', basedatos: 'lila', celular: 'gris', laptop: 'gris', dinero: 'menta', calendario: 'rosa', reunion: 'lila',
    idea: 'vainilla', alerta: 'durazno', ok: 'menta', error: 'rosa', candado: 'vainilla', carpeta: 'vainilla', grafico: 'lila', aula: 'menta',
    graduado: 'lila', familia: 'durazno', buscar: 'celeste', chat: 'celeste',
  };
  /**
   * Las ilustraciones que se ponen sueltas en la pizarra (panel «Ilustraciones»):
   * [clave, nombre, categoría, palabras para buscarla]. Las mismas del diagrama.
   */
  const CATEGORIAS = [['personas', 'Personas'], ['lugares', 'Lugares'], ['sistema', 'Sistema'], ['documentos', 'Documentos y resultados'], ['acciones', 'Acciones']];
  const CATALOGO = [
    ['estudiante', 'Estudiante', 'personas', 'alumno aspirante postulante joven'],
    ['docente', 'Docente', 'personas', 'profesor maestro tutor clase'],
    ['medico', 'Médico', 'personas', 'doctor salud paciente enfermera'],
    ['administrativo', 'Administrativo', 'personas', 'secretaria coordinador oficina atención'],
    ['persona', 'Usuario', 'personas', 'persona cliente ciudadano visitante'],
    ['graduado', 'Graduado', 'personas', 'egresado título grado birrete'],
    ['familia', 'Padres de familia', 'personas', 'papá mamá representante hijo niño'],
    ['reunion', 'Reunión', 'personas', 'equipo grupo junta personas'],
    ['escuela', 'Escuela', 'lugares', 'universidad colegio institución campus'],
    ['aula', 'Aula', 'lugares', 'clase salón pizarrón curso'],
    ['biblioteca', 'Biblioteca', 'lugares', 'libros lectura estante'],
    ['empresa', 'Empresa', 'lugares', 'proveedor oficina edificio negocio'],
    ['sistema', 'Computadora', 'sistema', 'sistema pc pantalla web escritorio'],
    ['laptop', 'Laptop', 'sistema', 'portátil computadora'],
    ['celular', 'Celular', 'sistema', 'móvil app teléfono'],
    ['nube', 'Nube', 'sistema', 'cloud servidor internet subir'],
    ['basedatos', 'Base de datos', 'sistema', 'datos bd almacenamiento tabla'],
    ['candado', 'Candado', 'sistema', 'seguridad contraseña acceso login'],
    ['buscar', 'Buscar', 'sistema', 'lupa búsqueda consulta'],
    ['pdf', 'PDF', 'documentos', 'documento imprimir descargar acta'],
    ['excel', 'Excel', 'documentos', 'hoja de cálculo xls csv exportar tabla'],
    ['reporte', 'Reporte', 'documentos', 'informe estadística barras dashboard'],
    ['grafico', 'Gráfico', 'documentos', 'pastel torta porcentaje'],
    ['horario', 'Horario', 'documentos', 'reloj hora semana'],
    ['calendario', 'Calendario', 'documentos', 'fecha día agenda'],
    ['certificado', 'Certificado', 'documentos', 'diploma constancia título'],
    ['correo', 'Correo', 'documentos', 'email notificación aviso mensaje'],
    ['chat', 'Chat', 'documentos', 'mensaje conversación comentario'],
    ['carpeta', 'Carpeta', 'documentos', 'archivos documentos expediente'],
    ['tarea', 'Tarea', 'documentos', 'checklist lista pendientes registro'],
    ['subir', 'Subir archivo', 'documentos', 'adjuntar cargar evidencia documento'],
    ['pago', 'Pago', 'documentos', 'tarjeta cobro factura'],
    ['dinero', 'Dinero', 'documentos', 'efectivo billete monedas presupuesto'],
    ['proceso', 'Proceso', 'acciones', 'flujo pasos siguiente flecha'],
    ['filtro', 'Filtrar', 'acciones', 'filtro embudo seleccionar'],
    ['idea', 'Idea', 'acciones', 'bombilla propuesta mejora'],
    ['alerta', 'Alerta', 'acciones', 'aviso advertencia riesgo cuidado'],
    ['ok', 'Listo', 'acciones', 'correcto aprobado éxito check'],
    ['error', 'Error', 'acciones', 'rechazado falla incorrecto'],
  ];
  const PAPEL = { celeste: '#DCEFFD', menta: '#D4F3E3', lila: '#E9E1FC', rosa: '#FCE1EC', durazno: '#FFE6D5', vainilla: '#FFF3C9', gris: '#EBEFF5' };

  /* ---------- Quién la usa y lo que obtiene ---------- */
  const ACTORES = {
    estudiante:     { nombre: 'Estudiante', texto: 'estudiante' },
    docente:        { nombre: 'Docente', texto: 'docente' },
    empresa:        { nombre: 'Empresa', texto: 'empresa' },
    medico:         { nombre: 'Médico', texto: 'médico' },
    administrativo: { nombre: 'Administrativo', texto: 'administrativo' },
    persona:        { nombre: 'Usuario', texto: 'usuario' },
    sistema:        { nombre: 'Sistema', texto: 'sistema' },
  };
  const NOMBRE_RES = { pdf: 'PDF', excel: 'Excel', reporte: 'Reporte', horario: 'Horario', correo: 'Correo', certificado: 'Certificado', pago: 'Pago', tarea: 'Registro' };
  const TIPOS_RES = ['pdf', 'excel', 'reporte', 'horario', 'correo', 'certificado', 'pago', 'tarea'];
  const ICONOS_PASO = ['sistema', 'subir', 'filtro', 'pdf', 'excel', 'horario', 'correo', 'reporte', 'biblioteca', 'escuela', 'pago', 'certificado', 'tarea'];

  /* =========================================================
     Texto: limpiar, recortar y contar en tercera persona
     ========================================================= */
  const escHtml = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const plano = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  const limpio = (s) => String(s ?? '').replace(/\s+/g, ' ').trim();
  const corta = (s, n) => {
    s = limpio(s);
    if (s.length <= n) return s;
    const c = s.slice(0, n - 1), i = c.lastIndexOf(' ');
    return (i > n * 0.6 ? c.slice(0, i) : c).replace(/[,;:.\s]+$/, '') + '…';
  };
  const capital = (s) => (s ? s.charAt(0).toLocaleUpperCase('es') + s.slice(1) : s);
  // Primera letra en minúscula, salvo siglas (PDF, HU-12…)
  const minus = (s) => (s && !/^[A-ZÁÉÍÓÚÑ0-9]{2,}/.test(s) ? s.charAt(0).toLocaleLowerCase('es') + s.slice(1) : s);
  const unir = (xs) => { xs = xs.filter(Boolean); return xs.length < 2 ? (xs[0] || '') : xs.slice(0, -1).join(', ') + (/^h?[ií](?!e)/i.test(xs[xs.length - 1]) ? ' e ' : ' y ') + xs[xs.length - 1]; };
  const sinPunto = (s) => limpio(s).replace(/[.…;,]+$/, '');

  // De primera persona (lo que escribe el PO) a lo que se cuenta del actor
  // (lo que va entre comillas, como «Mi horario», es el nombre de una pantalla: no se toca)
  const aTercera = (s) => String(s || '').split(/(«[^»]*»|"[^"]*"|“[^”]*”)/).map((p, i) => (i % 2 ? p : p
    .replace(/\bmis\b/gi, 'sus').replace(/\bmi\b/gi, 'su').replace(/\bme\b/gi, 'le')
    .replace(/\bconmigo\b/gi, 'con él').replace(/\bnuestr[oa]s\b/gi, 'sus').replace(/\bnuestr[oa]\b/gi, 'su'))).join('');

  // Infinitivo → tercera persona del presente. Lo regular sale solo; esto es lo que no.
  const IRREG = {
    ser: 'es', ir: 'va', ver: 've', dar: 'da', estar: 'está', hacer: 'hace', poder: 'puede', tener: 'tiene', obtener: 'obtiene', mantener: 'mantiene',
    contener: 'contiene', venir: 'viene', decir: 'dice', poner: 'pone', proponer: 'propone', saber: 'sabe', querer: 'quiere', elegir: 'elige',
    corregir: 'corrige', seguir: 'sigue', conseguir: 'consigue', pedir: 'pide', medir: 'mide', repetir: 'repite', servir: 'sirve', cerrar: 'cierra',
    empezar: 'empieza', comenzar: 'comienza', pensar: 'piensa', mostrar: 'muestra', encontrar: 'encuentra', probar: 'prueba', aprobar: 'aprueba',
    comprobar: 'comprueba', recordar: 'recuerda', contar: 'cuenta', volver: 'vuelve', devolver: 'devuelve', resolver: 'resuelve', mover: 'mueve',
    entender: 'entiende', perder: 'pierde', preferir: 'prefiere', sentir: 'siente', advertir: 'advierte', convertir: 'convierte', requerir: 'requiere',
    adquirir: 'adquiere', jugar: 'juega', oir: 'oye', incluir: 'incluye', construir: 'construye', distribuir: 'distribuye', concluir: 'concluye',
    excluir: 'excluye', contribuir: 'contribuye', enviar: 'envía', actuar: 'actúa', evaluar: 'evalúa', continuar: 'continúa', reunir: 'reúne',
    renovar: 'renueva', acordar: 'acuerda', demostrar: 'demuestra', recomendar: 'recomienda', atender: 'atiende', extender: 'extiende',
    encender: 'enciende', despertar: 'despierta', negar: 'niega', impedir: 'impide', invertir: 'invierte', confiar: 'confía', ampliar: 'amplía',
    guiar: 'guía', variar: 'varía', graduar: 'gradúa', situar: 'sitúa', soltar: 'suelta', sonar: 'suena', colgar: 'cuelga', almorzar: 'almuerza',
    reir: 'ríe', freir: 'fríe',
  };
  // Lo que termina en -ar/-er/-ir y no es un verbo (para no partir ni conjugar «lugar» o «taller»)
  const NO_VERBO = new Set(['lugar', 'hogar', 'mujer', 'taller', 'par', 'mar', 'bar', 'ayer', 'placer', 'azar', 'altar', 'collar', 'militar', 'familiar',
    'particular', 'popular', 'regular', 'similar', 'titular', 'auxiliar', 'escolar', 'solar', 'polar', 'alfiler', 'cancer', 'cadaver', 'super',
    'primer', 'tercer', 'ejemplar', 'pilar', 'celular', 'modular', 'dolar', 'bienestar', 'malestar', 'radar', 'hangar', 'master', 'poster',
    'router', 'banner', 'cluster', 'docker', 'excel']);
  const RE_INF = /^([a-záéíóúñü]+?)(ar|er|ir|ír)(me|te|se|nos|los|las|lo|la|les|le)?(?=$|[^a-záéíóúñü])(.*)$/i;
  const conjugar = (inf) => {
    const k = plano(inf);
    if (IRREG[k]) return IRREG[k];
    return inf.slice(0, -2) + (/ar$/.test(k) ? 'a' : 'e');
  };
  // «que el sistema me notifique» → «el sistema le notifica» (del subjuntivo al presente)
  const SUBJ = { haga: 'hace', tenga: 'tiene', diga: 'dice', ponga: 'pone', salga: 'sale', traiga: 'trae', vea: 've', sea: 'es', vaya: 'va', pueda: 'puede',
    quiera: 'quiere', sepa: 'sabe', elija: 'elige', siga: 'sigue', pida: 'pide', incluya: 'incluye', este: 'está', de: 'da' };
  const indicativo = (w) => {
    const k = plano(w);
    if (SUBJ[k]) return SUBJ[k];
    if (/que$/.test(k)) return w.slice(0, -3) + 'ca';
    if (/gue$/.test(k)) return w.slice(0, -3) + 'ga';
    if (/ce$/.test(k)) return w.slice(0, -2) + 'za';
    if (/e$/.test(k)) return w.slice(0, -1) + 'a';
    if (/a$/.test(k)) return w.slice(0, -1) + 'e';
    return w;
  };
  /** «registrarme en el curso» → «se registra en el curso»; lo que no empieza con infinitivo, igual. */
  function tercera(frase) {
    let s = limpio(frase).replace(/^(?:yo\s+)?(?:quiero|deseo|necesito|me gustar[ií]a)\s+/i, '');
    const sub = /^que\s+(el|la)\s+(sistema|plataforma|aplicaci[oó]n|app|portal|m[oó]dulo)\s+(?:(me|le|nos|les|lo|la|los|las)\s+)?([a-záéíóúñü]+)(.*)$/i.exec(s);
    if (sub) {
      const pr = sub[3] ? (/^(me|nos)$/i.test(sub[3]) ? 'le ' : sub[3].toLowerCase() + ' ') : '';
      return `${sub[1].toLowerCase()} ${sub[2].toLowerCase()} ${pr}${indicativo(sub[4])}${aTercera(sub[5])}`;
    }
    s = s.replace(/^que\s+/i, '');
    const m = RE_INF.exec(s);
    if (!m) return aTercera(s);
    const inf = (m[1] + m[2]).toLowerCase();
    if ((inf.length < 3 && inf !== 'ir') || NO_VERBO.has(plano(inf))) return aTercera(s);
    const pron = (m[3] || '').toLowerCase();
    const pr = pron ? (/^(me|te|se|nos)$/.test(pron) ? 'se ' : pron + ' ') : '';
    return pr + conjugar(inf) + aTercera(m[4]);
  }

  /** «buscar el curso, inscribirme y descargar el comprobante» → tres acciones (para el texto inicial). */
  function partirQuiero(t) {
    t = limpio(t).replace(/[.;]+$/, '');
    if (!t) return [];
    const marcado = t.replace(/(,\s*(?:y\s+|e\s+)?|\s+(?:y|e)\s+|\s+(?:y\s+)?(?:luego|despu[eé]s)\s+)(?=([a-záéíóúñü]{2,}(?:ar|er|ir)(?:me|te|se|nos|los|las|lo|la|les|le)?)(?![a-záéíóúñü]))/gi,
      (m, sep, w) => (NO_VERBO.has(plano(w)) ? m : '\u0001'));
    return marcado.split('\u0001').map(limpio).filter(Boolean);
  }
  /** En un «Cuando…» solo se parte donde la secuencia es explícita (luego, después, «;»). */
  const partirCuando = (t) => limpio(t).replace(/[.]+$/, '').split(/\s*;\s*/)
    .flatMap((x) => x.split(/,?\s+(?:y\s+)?(?:luego|despu[eé]s|a continuaci[oó]n)\s+/i))
    .map((x) => limpio(x).replace(/^(?:y\s+)?(?:luego|despu[eé]s|a continuaci[oó]n)\s+/i, '')).filter(Boolean);

  const SUJETOS = 'usuari[oa]s?|actor|docentes?|estudiantes?|alumn[oa]s?|profesor(?:a|es)?|m[eé]dic[oa]s?|doctor(?:a)?|pacientes?|administrador(?:a)?|administrativ[oa]|'
    + 'coordinador(?:a)?|secretari[oa]|clientes?|empresa|proveedor(?:a)?|director(?:a)?|rector(?:a)?|operador(?:a)?|analista|funcionari[oa]|tutor(?:a)?|'
    + 'aspirante|postulante|representante|padre|madre|personal';
  const RE_SUJETO = new RegExp(`^(?:yo\\s+|(?:el|la|los|las|un|una)\\s+(?:${SUJETOS})\\s+)`, 'i');
  const escRe = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  function sinSujeto(t, como) {
    let s = limpio(t).replace(/^cuando\s+/i, '');
    if (como) s = s.replace(new RegExp(`^(?:el|la|los|las)\\s+${escRe(como)}\\s+`, 'i'), '');
    return s.replace(RE_SUJETO, '');
  }

  /* ----- Detección por palabras clave (sin IA) ----- */
  const REGLAS_ACTOR = [
    ['sistema', /^(?:el\s+)?sistema\b|proceso automatic|\bcron\b|tarea programada/],
    ['medico', /medic|doctor|enfermer|pacient|odontolog|psicolog|nutricion|clinic|hospital|\bsalud\b/],
    ['docente', /docente|profesor|maestr[oa]|\btutor|catedratic|instructor|educador|orientador/],
    ['estudiante', /estudiant|alumn[oa]|aspirante|postulante|matriculad|egresad|graduad|becari|bachiller|universitari/],
    ['empresa', /empresa|proveedor|compania|organizacion|negocio|corporativ|empleador|contratista|\bpyme/],
    ['administrativo', /administra|secretari|coordinador|director|rector|decan|gestor|operador|analista|funcionari|\bpersonal\b|talento humano|contador|cajer|recepcion|bibliotecari|inspector|autoridad|\bjefe/],
    ['persona', /usuari|cliente|ciudadan|visitante|padre|madre|representante|familia|persona/],
  ];
  /** El actor que aparece primero en el «Como…»; si no dice, el del título y el «quiero». */
  function detectarActor(como, resto) {
    const elegir = (t, conSistema) => {
      let mejor = null, pos = Infinity;
      for (const [k, re] of REGLAS_ACTOR) {
        if (k === 'sistema' && !conSistema) continue;
        const m = re.exec(t);
        if (m && m.index < pos) { pos = m.index; mejor = k; }
      }
      return mejor;
    };
    return elegir(plano(como), true) || elegir(plano(resto), false) || 'persona';
  }
  /** Lo que se escribe en «Actor:» → la clave del dibujo (o null si no se reconoce). */
  const actorDeTexto = (v) => {
    const t = plano(v).trim();
    if (!t) return null;
    if (ACTORES[t]) return t;
    for (const [k, re] of REGLAS_ACTOR) if (re.test(t)) return k;
    return null;
  };
  const REGLAS_ICO = [
    ['sistema', /\bingres|inici\w* sesion|login|autentic|\b(?:acced[ei]?|entra) (?:al|a la) (?:sistema|portal|plataforma|aplicacion|app)/],
    ['filtro', /filtr|busc|selecci|\belig|\belij|escog|\bordena|segment/],
    ['pdf', /\bpdf\b|imprim|descarg/],
    ['subir', /\bsub[eiao]|adjunt|\bcarg[aou]|evidencia|anex[ao]/],
    ['excel', /excel|\bxlsx?\b|\bcsv\b|export/],
    ['horario', /horario|calendario|\bfecha|agenda|\bcitas?\b|\bturnos?\b|cronograma|programa/],
    ['correo', /correo|e-?mail|notific|\benvi|\bavis|mensaje|alerta|recordator/],
    ['pago', /\bpag[oaue]|\bcobr|factur|arancel|\brecibo/],
    ['certificado', /certific|diploma|constancia/],
    ['reporte', /reporte|informe|estadist|grafic|dashboard|indicador|visualiz|consult|revis|\bver\b|\bve\b|\bmira/],
    ['biblioteca', /libro|biblioteca|catalogo|prestamo|material|recurso|bibliograf/],
    ['escuela', /matricul|inscrib|\bcurso|carrera|asignatura|materia|\baula|paralelo|periodo|institu|colegio|escuela|universidad|facultad/],
  ];
  const icoPaso = (t) => { const p = plano(t); for (const [k, re] of REGLAS_ICO) if (re.test(p)) return k; return 'tarea'; };
  // Lo que obtiene: [tipo, patrón]. El mismo orden en que se muestran.
  const REGLAS_RES = [
    ['pdf', /\bpdf\b|imprim|\bacta\b|comprobante/],
    ['excel', /excel|\bxlsx?\b|\bcsv\b|hoja de calculo|hoja electronica/],
    ['horario', /horario|calendario|agenda|cronograma|\bcitas?\b|\bturnos?\b/],
    ['reporte', /reporte|informe|estadistic|dashboard|grafic|indicador|metrica|\blistado|\bresumen\b/],
    ['correo', /correo|e-?mail|notifica|\bavis[aoe]|alerta|mensaje|recordatorio|whatsapp|\bsms\b/],
    ['certificado', /certificad|diploma|record academico|constancia(?![\w ]{0,12}pdf)(?<!pdf[\w ]{0,12}constancia)/],
    ['pago', /\bpag[oaue]|\bcobr|factura|arancel|\brecibo|transferencia bancaria|tarjeta de credito/],
  ];
  function detectarRes(texto) {
    const t = plano(texto), out = [];
    for (const [k, re] of REGLAS_RES) if (re.test(t) || (k === 'excel' && /export/.test(t) && !/\bpdf\b/.test(t))) out.push(k);
    return out;
  }
  /** Una palabra de «Resultados:» → su tipo (PDF, Excel…; «Registro» o «Guardado» = queda registrado). */
  const tipoRes = (tok) => {
    const t = plano(tok);
    for (const [k, re] of REGLAS_RES) if (re.test(t)) return k;
    if (/excel|xls|csv|hoja|export/.test(t)) return 'excel';
    if (/certific|diploma|constancia|titulo/.test(t)) return 'certificado';
    if (/registr|guardad|tarea|checklist|lista de|aprobad|listo/.test(t)) return 'tarea';
    return null;
  };
  function limpiarComo(c) {
    const s = limpio(c).replace(/^como\s+/i, '').replace(/^(?:un|una|el|la|los|las)\s+/i, '').replace(/[,.;:].*$/, '').replace(/\s+(?:quiero|deseo|necesito)\b.*$/i, '');
    return corta(capital(s), 36);
  }

  /* =========================================================
     El texto de la historia: parse(texto) → modelo, serializar(modelo) → texto

     Cada línea se guarda tal como se escribió (sangría, viñeta, la palabra
     clave con su «:» y el valor), así la ida y vuelta es idéntica. Tolerante:
     sin tildes, en mayúsculas o minúsculas, con o sin «:», con viñetas («-»,
     «1.», «##»), líneas en blanco, «Y …» como otro paso y líneas que siguen
     a la anterior (con más sangría).
     ========================================================= */
  const A = (w) => w.replace(/a/g, '[aá]').replace(/e/g, '[eé]').replace(/i/g, '[ií]').replace(/o/g, '[oó]').replace(/u/g, '[uúü]');
  // [tipo, palabras, pide «:» (si no, también vale «Como docente»)]
  const PALABRAS = [
    ['historia', ['historia de usuario', 'historia', 'titulo'], false],
    ['historia', ['hu'], true],
    ['actor', ['actor', 'quien'], false],
    ['como', ['como'], false],
    ['quiero', ['quiero'], false],
    ['para', ['para'], false],
    ['escenarios', ['escenarios', 'casos', 'criterios de aceptacion'], false],
    ['escenario', ['escenario', 'caso'], false],
    ['dado', ['dado que', 'dado'], false],
    ['cuando', ['cuando'], false],
    ['entonces', ['entonces'], false],
    ['y', ['ademas', 'y', 'e'], false],
    ['resultados', ['resultados', 'resultado'], false],
    ['resultados', ['obtiene', 'genera', 'entrega'], true],
    ['mira', ['mira', 'resumen', 'en simple'], true],
    ['extra', ['notas', 'nota', 'prioridad', 'sprint', 'puntos', 'estimacion', 'epica', 'estado', 'reglas de negocio', 'reglas', 'regla', 'id', 'codigo',
      'responsable', 'release', 'modulo', 'criterios'], true],
  ];
  const CLAVES = PALABRAS.map(([tipo, ws, dos]) => [tipo,
    new RegExp(`^(?:\\*\\*|__)?(${ws.map((w) => A(w).replace(/ /g, '\\s+')).join('|')})(?![a-z0-9áéíóúüñ])(?:\\s*#?(\\d{1,3})(?![0-9]))?([\\s:：*_\\-–—]*)`, 'i'), dos]);
  const RE_LINEA = /^(\s*)((?:[-*•·>]|#{1,6}|\d{1,2}[.)])\s+)?([\s\S]*)$/;

  function leerLinea(raw) {
    const L = { raw, tipo: 'libre', sangria: '', vineta: '', claveTxt: '', valor: '', cola: '' };
    const m = RE_LINEA.exec(raw);
    L.sangria = m[1]; L.vineta = m[2] || '';
    const resto = m[3];
    if (!resto.trim()) { L.tipo = 'vacia'; return L; }
    if (/^\/\//.test(resto)) { L.tipo = 'comentario'; return L; }
    for (const [tipo, re, dos] of CLAVES) {
      const k = re.exec(resto);
      if (!k) continue;
      const despues = resto.slice(k[0].length);
      if (dos && !/[:：]/.test(k[3]) && despues.trim()) continue;
      L.tipo = tipo; L.claveTxt = k[0]; L.num = k[2] || '';
      L.valor = despues.replace(/\s+$/, ''); L.cola = despues.slice(L.valor.length);
      return L;
    }
    L.valor = resto.replace(/\s+$/, ''); L.cola = resto.slice(L.valor.length);
    if (L.vineta.startsWith('#')) L.tipo = 'titulo-md';   // un título de Markdown suelto («## HU-31 Pago»)
    return L;
  }
  /** «Como docente, quiero X, para Y» en una sola línea: sus partes (cada una se puede cambiar sola). */
  function partesComo(v) {
    const m = /^([\s\S]*?)(\s*,\s*|\s+)(quiero\s+)([\s\S]*?)(?:(\s*,\s*)(para\s+)([\s\S]*?))?([.\s]*)$/i.exec(v);
    if (!m || !m[1].trim() || !m[4].trim()) return null;
    return [{ k: 'como', v: m[1] }, { t: m[2] + m[3] }, { k: 'quiero', v: m[4] }]
      .concat(m[6] ? [{ t: m[5] + m[6] }, { k: 'para', v: m[7] }] : [], [{ t: m[8] || '' }]);
  }
  function partesQuiero(v) {
    const m = /^([\s\S]*?)(\s*,\s*)(para\s+)([\s\S]*?)([.\s]*)$/i.exec(v);
    if (!m || !m[1].trim() || !m[4].trim()) return null;
    return [{ k: 'quiero', v: m[1] }, { t: m[2] + m[3] }, { k: 'para', v: m[4] }, { t: m[5] || '' }];
  }

  function parse(texto) {
    const lineas = String(texto ?? '').split('\n').map(leerLinea);
    const m = { lineas, hist: null, actorIt: null, comoIt: null, miraIt: null, quiero: [], para: [], escenarios: [], resIt: null, resItems: [], lRes: -1, errores: [] };
    let esc = null, grupo = null, dueno = null, gid = 0;
    let escLista = null;   // bajo «Escenarios:» / «Criterios de aceptación:», cada viñeta de ese nivel es un escenario
    const abrir = (tipo) => (grupo = { tipo, id: ++gid });
    const error = (L, msg) => { L.tipo = 'error'; L.msg = msg; m.errores.push({ l: L.i, msg }); };
    const item = (L, v, parte) => ({ v: limpio(v), l: L.i, conts: [], parte: parte || '' });
    const conDueno = (it, L) => { dueno = { it, L }; L.item = it; return it; };
    const nuevoEsc = (L, nombre) => {
      esc = { nombre: limpio(nombre), l: L ? L.i : -1, dado: [], cuando: [], entonces: [], k: m.escenarios.length };
      m.escenarios.push(esc);
      return esc;
    };
    const enEsc = (L) => { if (!esc) nuevoEsc(null, ''); L.esc = esc.k; };
    const unico = (cual, L, nombre) => {
      if (m[cual]) { error(L, `Ya hay una línea «${nombre}» más arriba`); return false; }
      m[cual] = conDueno(item(L, L.valor), L);
      return true;
    };
    // Otro elemento del grupo abierto (con «Y …» o con una viñeta debajo)
    const otroDelGrupo = (L) => {
      if (!grupo) return false;
      const g = grupo.tipo;
      L.gid = grupo.id;
      if (g === 'quiero' || g === 'para') { m[g].push(conDueno(item(L, L.valor), L)); return true; }
      if (g === 'dado' || g === 'cuando' || g === 'entonces') { L.esc = esc.k; esc[g].push(conDueno(item(L, L.valor), L)); return true; }
      if (g === 'resultados') { m.resItems.push(conDueno(item(L, L.valor), L)); return true; }
      delete L.gid;
      return false;
    };

    lineas.forEach((L, i) => {
      L.i = i;
      const t = L.tipo;
      if (t === 'titulo-md') {
        if (m.hist) { L.tipo = 'comentario'; return; }
        L.tipo = 'historia'; m.hist = conDueno(item(L, L.valor), L); esc = null; grupo = null; escLista = null;
        return;
      }
      if (t === 'vacia' || t === 'comentario' || t === 'extra') { dueno = null; return; }
      if (t === 'libre') {
        if (L.vineta && escLista && (escLista.sangria === null || L.sangria.length <= escLista.sangria.length)) {
          if (escLista.sangria === null) escLista.sangria = L.sangria;
          L.tipo = 'escenario'; nuevoEsc(L, L.valor); L.esc = esc.k; grupo = null;
          const e = esc, it = { conts: [], get v() { return e.nombre; }, set v(x) { e.nombre = x; } };
          e.it = it; dueno = { it, L };
          return;
        }
        if (L.vineta && otroDelGrupo(L)) return;
        // Sigue a la línea de arriba (con más sangría): es parte de su valor
        if (dueno && L.sangria.length > dueno.L.sangria.length) {
          dueno.it.v = limpio(dueno.it.v + ' ' + L.valor);
          dueno.it.conts.push(i);
          L.tipo = 'sigue'; L.de = dueno.L.i;
          if (dueno.L.esc != null) L.esc = dueno.L.esc;
          return;
        }
        error(L, 'No se entiende esta línea: empieza con Como, Quiero, Escenario, Dado, Cuando, Entonces, Y…');
        return;
      }
      dueno = null;
      if (['historia', 'como', 'quiero', 'para', 'resultados'].includes(t)) escLista = null;
      switch (t) {
        case 'historia': esc = null; grupo = null; unico('hist', L, 'Historia'); break;
        case 'actor': grupo = null; unico('actorIt', L, 'Actor'); break;
        case 'mira': grupo = null; unico('miraIt', L, 'Mira'); break;
        case 'como': {
          esc = null; grupo = null;
          if (m.comoIt) { error(L, 'Ya hay una línea «Como» más arriba'); break; }
          const p = partesComo(L.valor);
          if (!p) { m.comoIt = conDueno(item(L, L.valor), L); break; }
          L.partes = p;
          m.comoIt = item(L, p[0].v, 'como');
          abrir('quiero'); L.gid = grupo.id;
          m.quiero.push(item(L, p[2].v, 'quiero'));
          const pa = p.find((s) => s.k === 'para');
          if (pa) { abrir('para'); m.para.push(item(L, pa.v, 'para')); }
          L.item = m.quiero[m.quiero.length - 1];
          break;
        }
        case 'quiero': case 'para': {
          esc = null; abrir(t); L.gid = grupo.id;
          if (!L.valor) break;   // un encabezado: lo de abajo, con viñetas
          const p = t === 'quiero' ? partesQuiero(L.valor) : null;
          if (!p) { m[t].push(conDueno(item(L, L.valor), L)); break; }
          L.partes = p;
          m.quiero.push(item(L, p[0].v, 'quiero'));
          abrir('para');
          m.para.push(item(L, p[2].v, 'para'));
          L.item = m.quiero[m.quiero.length - 1];
          break;
        }
        case 'escenarios':
          if (L.valor) { L.tipo = 'escenario'; nuevoEsc(L, L.valor); L.esc = esc.k; grupo = null; dueno = { it: { get v() { return esc.nombre; }, set v(x) { esc.nombre = x; }, conts: [] }, L }; break; }
          esc = null; abrir('escenarios'); escLista = { sangria: null };
          break;
        case 'escenario': {
          const e = nuevoEsc(L, L.valor);
          L.esc = e.k; grupo = null;
          const it = { conts: [], get v() { return e.nombre; }, set v(x) { e.nombre = x; } };
          e.it = it; dueno = { it, L };
          break;
        }
        case 'dado': case 'cuando': case 'entonces':
          enEsc(L); abrir(t); L.gid = grupo.id;
          if (L.valor) esc[t].push(conDueno(item(L, L.valor), L));
          break;
        case 'y':
          if (!L.valor) break;
          if (!otroDelGrupo(L)) error(L, '«Y» va después de un Quiero, Dado, Cuando o Entonces');
          break;
        case 'resultados':
          if (m.lRes >= 0) { error(L, 'Ya hay una línea «Resultados» más arriba'); break; }
          m.lRes = i; abrir('resultados'); L.gid = grupo.id;
          if (L.valor) m.resIt = conDueno(item(L, L.valor), L);
          break;
      }
    });

    // Lo que se lee de los valores (ya con las líneas que los siguen)
    const h = m.hist ? m.hist.v : '';
    const mc = /^([A-Za-z0-9][\w.\-/]*\d[\w.\-/]*)(\s*[·:|–—]\s*|\s+-\s+|\s+)(\S[\s\S]*)$/.exec(h);
    m.codigo = mc ? mc[1] : ''; m.sepCod = mc ? mc[2] : ''; m.titulo = mc ? mc[3] : h;
    m.actor = '';
    if (m.actorIt && m.actorIt.v) {
      const k = actorDeTexto(m.actorIt.v);
      if (k) m.actor = k;
      else error(lineas[m.actorIt.l], `No conozco el actor «${m.actorIt.v}»: usa estudiante, docente, empresa, médico, administrativo, usuario o sistema`);
    }
    m.como = m.comoIt ? m.comoIt.v : '';
    m.mira = m.miraIt ? m.miraIt.v : '';
    m.resultados = null;
    if (m.lRes >= 0) {
      const toks = m.resIt ? m.resIt.v.split(/\s*(?:,|;|\/|\s+y\s+|\s+e\s+)\s*/i).map(limpio).filter(Boolean) : [];
      const items = toks.map((tk, n) => ({ texto: tk.replace(/[.]+$/, ''), tipo: tipoRes(tk), l: m.lRes, tok: n }))
        .concat(m.resItems.map((it) => ({ texto: sinPunto(it.v), tipo: tipoRes(it.v), l: it.l, tok: null })));
      const malos = items.filter((x) => !x.tipo);
      for (const x of malos) {
        const L = lineas[x.l];
        if (L.tipo !== 'error') error(L, `No conozco «${x.texto}»: usa PDF, Excel, Reporte, Horario, Correo, Certificado, Pago o Registro`);
      }
      m.resultados = { l: m.lRes, toks: m.resIt ? toks : [], items };
    }
    return m;
  }
  const serializar = (m) => m.lineas.map((L) => L.raw).join('\n');

  /* ----- Cambiar el modelo desde el diagrama (y volver a texto) ----- */
  function rehacer(L, valor) {
    valor = limpio(valor);
    let ct = L.claveTxt;
    if (valor && ct && !/[\s:：]$/.test(ct)) ct += /[:：]/.test(ct) ? ' ' : ': ';
    L.claveTxt = ct; L.valor = valor; L.cola = ''; delete L.partes;
    L.raw = valor ? L.sangria + L.vineta + ct + valor : (L.sangria + L.vineta + ct).replace(/\s+$/, '');
  }
  function ponerParte(L, k, v) {
    const segs = L.partes, i = segs.findIndex((s) => s.k === k);
    if (i < 0) return;
    if (!limpio(v) && k !== 'como') segs.splice(i - 1, 2);
    else segs[i].v = limpio(v);
    L.valor = segs.map((s) => (s.k ? s.v : s.t)).join('');
    L.raw = L.sangria + L.vineta + L.claveTxt + L.valor + (L.cola || '');
  }
  const insertar = (m, pos, raws) => { m.lineas.splice(pos, 0, ...raws.map(leerLinea)); };
  const quitarIdx = (m, idxs) => { [...new Set(idxs)].sort((a, b) => b - a).forEach((i) => m.lineas.splice(i, 1)); };
  const finDe = (it) => (it ? Math.max(it.l, ...(it.conts || [])) : -1);
  const conModelo = (texto, fn) => { const m = parse(texto); fn(m); return serializar(m); };
  const itemDe = (m, l) => {
    for (const it of [m.hist, m.actorIt, m.comoIt, m.miraIt, m.resIt, ...m.quiero, ...m.para, ...m.resItems]) if (it && it.l === l && !it.parte) return it;
    for (const e of m.escenarios) for (const it of [...e.dado, ...e.cuando, ...e.entonces]) if (it.l === l && !it.parte) return it;
    return null;
  };
  // Dónde termina lo de arriba (Historia, Actor, Como, Quiero, Para, Mira) para meter algo debajo
  const finArriba = (m, cuales) => Math.max(-1, ...cuales.map((c) => (Array.isArray(m[c]) ? Math.max(-1, ...m[c].map(finDe)) : finDe(m[c]))));
  const lineasDeEsc = (m, k) => m.lineas.filter((L) => L.esc === k).map((L) => L.i);
  function sangriaHijos(m, e) {
    const hijo = m.lineas.find((L) => L.esc === e.k && L.i !== e.l && (L.tipo === 'dado' || L.tipo === 'cuando' || L.tipo === 'entonces' || L.tipo === 'y'));
    if (hijo) return hijo.sangria;
    const cab = e.l >= 0 ? m.lineas[e.l] : null;
    return (cab ? cab.sangria : '') + '  ';
  }
  const NUEVO_PASO = 'nuevo paso';

  const Texto = {
    parse, serializar,
    /** El valor de una línea (o de una parte de «Como…, quiero…, para…»). */
    fijar: (texto, ref, v) => conModelo(texto, (m) => {
      const L = m.lineas[ref.l];
      if (!L) return;
      if (ref.parte && L.partes) { ponerParte(L, ref.parte, v); return; }
      const it = itemDe(m, ref.l);
      rehacer(L, v);
      if (it) quitarIdx(m, it.conts);
    }),
    /** Quita un paso (su línea). Si era «Cuando:» y abajo sigue un «Y:», ese pasa a ser el «Cuando:». */
    quitar: (texto, l, parte) => conModelo(texto, (m) => {
      const L = m.lineas[l];
      if (!L) return;
      if (parte && L.partes) { ponerParte(L, parte, ''); return; }
      const it = itemDe(m, l);
      const borrar = [l, ...(it ? it.conts : [])];
      if (['quiero', 'para', 'dado', 'cuando', 'entonces'].includes(L.tipo) && L.gid) {
        const sig = m.lineas.find((x) => x.i > finDe(it || { l, conts: [] }) && x.gid === L.gid);
        if (sig && sig.tipo === 'y') { sig.claveTxt = L.claveTxt; sig.raw = sig.sangria + sig.vineta + sig.claveTxt + sig.valor + (sig.cola || ''); }
        else if (sig && sig.tipo === 'libre') { rehacer(L, ''); quitarIdx(m, it ? it.conts : []); return; }   // queda de encabezado de su lista
      }
      quitarIdx(m, borrar);
    }),
    /** Un paso nuevo al final del escenario k (o del «Quiero» si no hay escenarios). Devuelve {texto, l}. */
    agregarPaso(texto, k) {
      let nueva = -1;
      const t = conModelo(texto, (m) => {
        if (m.escenarios.length) {
          const e = m.escenarios[k >= 0 && k < m.escenarios.length ? k : m.escenarios.length - 1];
          const ult = e.cuando[e.cuando.length - 1];
          if (ult) {
            const L = m.lineas[ult.l];
            nueva = finDe(ult) + 1;
            insertar(m, nueva, [L.tipo === 'libre' ? L.sangria + L.vineta + NUEVO_PASO : L.sangria + L.vineta + 'Y: ' + NUEVO_PASO]);
          } else {
            const tras = Math.max(e.l, ...e.dado.map(finDe), ...(e.l < 0 ? lineasDeEsc(m, e.k).slice(0, 1).map((x) => x - 1) : []));
            nueva = tras + 1;
            insertar(m, nueva, [sangriaHijos(m, e) + 'Cuando: ' + NUEVO_PASO]);
          }
        } else {
          const ult = m.quiero[m.quiero.length - 1];
          if (ult) {
            const L = m.lineas[ult.l];
            nueva = finDe(ult) + 1;
            insertar(m, nueva, [L.tipo === 'libre' ? L.sangria + L.vineta + NUEVO_PASO : L.sangria + 'Y: ' + NUEVO_PASO]);
          } else {
            nueva = finArriba(m, ['hist', 'actorIt', 'comoIt']) + 1;
            insertar(m, nueva, ['Quiero: ' + NUEVO_PASO]);
          }
        }
      });
      return { texto: t, l: nueva };
    },
    /** Un escenario nuevo al final (antes de «Resultados:» si va al último). Devuelve {texto, l, k}. */
    agregarEscenario(texto) {
      let nueva = -1, k = 0;
      const t = conModelo(texto, (m) => {
        k = m.escenarios.length;
        let pos = m.lineas.length;
        if (m.lRes >= 0 && m.lineas.every((L) => L.i < m.lRes || L.gid === m.lineas[m.lRes].gid || L.tipo === 'vacia' || (L.tipo === 'sigue' && L.de >= m.lRes))) pos = m.lRes;
        while (pos > 0 && m.lineas[pos - 1].tipo === 'vacia') pos--;
        const sang = k ? sangriaHijos(m, m.escenarios[k - 1]) : '  ';
        const raws = [];
        if (pos > 0) raws.push('');
        raws.push(`Escenario: Caso ${k + 1}`, `${sang}Cuando: ${NUEVO_PASO}`);
        insertar(m, pos, raws);
        nueva = pos + raws.indexOf(`Escenario: Caso ${k + 1}`);
      });
      return { texto: t, l: nueva, k };
    },
    /** Quita un escenario entero (y la línea en blanco que lo separaba). */
    quitarEscenario: (texto, k) => conModelo(texto, (m) => {
      const idx = lineasDeEsc(m, k);
      if (!idx.length) return;
      const a = idx[0], b = idx[idx.length - 1];
      for (let i = a; i <= b; i++) if (m.lineas[i].tipo === 'vacia') idx.push(i);
      const antes = m.lineas[a - 1];
      if (antes && antes.tipo === 'vacia') idx.push(a - 1);
      quitarIdx(m, idx);
    }),
    nombreEscenario: (texto, k, v) => conModelo(texto, (m) => {
      const e = m.escenarios[k];
      if (!e) return;
      if (e.l >= 0) { const L = m.lineas[e.l]; rehacer(L, v); quitarIdx(m, e.it ? e.it.conts : []); return; }
      const primera = lineasDeEsc(m, k)[0];
      if (primera != null) insertar(m, primera, ['Escenario: ' + limpio(v)]);
    }),
    titulo: (texto, v) => conModelo(texto, (m) => {
      if (!m.hist) { insertar(m, 0, ['Historia: ' + limpio(v)]); return; }
      const L = m.lineas[m.hist.l];
      rehacer(L, m.codigo ? m.codigo + (m.sepCod || ' · ') + limpio(v) : v);
      quitarIdx(m, m.hist.conts);
    }),
    como: (texto, v) => conModelo(texto, (m) => {
      if (m.comoIt) {
        const L = m.lineas[m.comoIt.l];
        if (L.partes) ponerParte(L, 'como', v);
        else { rehacer(L, v); quitarIdx(m, m.comoIt.conts); }
        return;
      }
      insertar(m, finArriba(m, ['hist', 'actorIt']) + 1, ['Como: ' + limpio(v)]);
    }),
    /** La frase simple a mano («Mira: …»); vacía, la línea se va y vuelve la automática. */
    mira: (texto, v) => conModelo(texto, (m) => {
      if (!limpio(v)) { if (m.miraIt) quitarIdx(m, [m.miraIt.l, ...m.miraIt.conts]); return; }
      if (m.miraIt) { rehacer(m.lineas[m.miraIt.l], v); quitarIdx(m, m.miraIt.conts); return; }
      insertar(m, finArriba(m, ['hist', 'actorIt', 'comoIt', 'quiero', 'para']) + 1, ['Mira: ' + limpio(v)]);
    }),
    /** El dibujo del actor («Actor: médico»). */
    actor: (texto, key) => conModelo(texto, (m) => {
      const v = (ACTORES[key] || ACTORES.persona).texto;
      if (m.actorIt) { rehacer(m.lineas[m.actorIt.l], v); quitarIdx(m, m.actorIt.conts); return; }
      insertar(m, finArriba(m, ['hist']) + 1, ['Actor: ' + v]);
    }),
    /** Prende o apaga un resultado. Sin «Resultados:», la escribe con los que ya se ven. */
    alternarRes: (texto, tipo, vistos) => conModelo(texto, (m) => {
      const R = m.resultados;
      if (!R) {
        const lista = vistos.filter((r) => r.tipo !== 'tarea' || r.texto !== 'Queda registrado').map((r) => r.texto);
        const ya = vistos.findIndex((r) => r.tipo === tipo);
        if (ya >= 0) lista.splice(ya, 1); else lista.push(NOMBRE_RES[tipo]);
        const raws = m.lineas.length && m.lineas[m.lineas.length - 1].tipo !== 'vacia' ? ['', 'Resultados: ' + lista.join(', ')] : ['Resultados: ' + lista.join(', ')];
        const fin = m.lineas.length === 1 && m.lineas[0].tipo === 'vacia' ? 0 : m.lineas.length;
        insertar(m, fin, fin ? raws : raws.slice(-1));
        return;
      }
      const ya = R.items.find((x) => x.tipo === tipo);
      const enLinea = R.items.filter((x) => x.tok != null), sueltos = R.items.filter((x) => x.tok == null);
      if (ya) {
        if (ya.tok == null) { quitarIdx(m, [ya.l]); return; }
        rehacer(m.lineas[R.l], R.toks.filter((_, n) => n !== ya.tok).join(', '));
        quitarIdx(m, m.resIt ? m.resIt.conts : []);
        return;
      }
      if (sueltos.length && !enLinea.length) { const u = m.lineas[sueltos[sueltos.length - 1].l]; insertar(m, u.i + 1, [u.sangria + u.vineta + NOMBRE_RES[tipo]]); return; }
      rehacer(m.lineas[R.l], R.toks.concat(NOMBRE_RES[tipo]).join(', '));
      quitarIdx(m, m.resIt ? m.resIt.conts : []);
    }),
    /** El texto de un resultado (el i de los que se ven). */
    textoRes: (texto, i, v, vistos) => conModelo(texto, (m) => {
      const R = m.resultados;
      if (!R) {
        const lista = vistos.map((r, n) => (n === i ? limpio(v) : r.texto)).filter(Boolean);
        const raws = m.lineas.length && m.lineas[m.lineas.length - 1].tipo !== 'vacia' ? ['', 'Resultados: ' + lista.join(', ')] : ['Resultados: ' + lista.join(', ')];
        insertar(m, m.lineas.length, raws);
        return;
      }
      const x = R.items.filter((r) => r.tipo)[i];
      if (!x) return;
      if (x.tok == null) { rehacer(m.lineas[x.l], v); return; }
      rehacer(m.lineas[R.l], R.toks.map((tk, n) => (n === x.tok ? limpio(v) : tk)).filter(Boolean).join(', '));
      quitarIdx(m, m.resIt ? m.resIt.conts : []);
    }),
  };

  /** El texto inicial de una historia del backlog (sus campos, en el formato de arriba). */
  function textoDe(h) {
    h = h || {};
    const L = [];
    const cod = limpio(h.codigo), tit = limpio(h.titulo);
    L.push('Historia: ' + (cod && tit ? `${cod} · ${tit}` : (tit || cod || 'Historia de usuario')));
    if (limpio(h.como)) L.push('Como: ' + limpio(h.como).replace(/^como\s+/i, ''));
    partirQuiero(limpio(h.quiero).replace(/^quiero\s+/i, '')).forEach((q, i) => L.push((i ? 'Y: ' : 'Quiero: ') + q));
    if (limpio(h.para)) L.push('Para: ' + limpio(h.para).replace(/^para\s+/i, ''));
    (Array.isArray(h.escenarios) ? h.escenarios : []).forEach((e, k) => {
      L.push('', 'Escenario: ' + (limpio(e.nombre) || `Caso ${k + 1}`));
      if (limpio(e.dado)) L.push('  Dado: ' + limpio(e.dado).replace(/^dado\s+/i, ''));
      partirCuando(limpio(e.cuando).replace(/^cuando\s+/i, '')).forEach((c, j) => L.push((j ? '  Y: ' : '  Cuando: ') + c));
      if (limpio(e.entonces)) L.push('  Entonces: ' + limpio(e.entonces).replace(/^entonces\s+/i, ''));
    });
    return L.join('\n');
  }
  const PLANTILLA = [
    'Historia: Título de la historia',
    'Como: estudiante',
    'Quiero: ver mis notas del periodo',
    'Y: descargarlas en PDF',
    'Para: saber cómo voy',
    '',
    'Escenario: Consulta con notas',
    '  Dado: tiene materias en el periodo',
    '  Cuando: entra a «Mis notas» y elige el periodo',
    '  Entonces: ve sus notas y puede descargarlas en PDF',
  ].join('\n');
  /** ¿Lo que se pegó es una historia escrita? (al menos dos líneas Como/Quiero/Escenario/Dado/Cuando…) */
  function pareceHistoria(texto) {
    const tipos = String(texto || '').split('\n').map((r) => leerLinea(r).tipo);
    const n = tipos.filter((t) => ['historia', 'como', 'quiero', 'para', 'escenario', 'dado', 'cuando', 'entonces'].includes(t)).length;
    return n >= 2 && tipos.some((t) => t === 'como' || t === 'quiero' || t === 'cuando');
  }
  // El modelo de cada texto, una vez (el texto es la fuente: el modelo siempre sale igual)
  const CACHE = new Map();
  function modelo(texto) {
    const t = String(texto ?? '');
    let m = CACHE.get(t);
    if (!m) { m = parse(t); CACHE.set(t, m); if (CACHE.size > 60) CACHE.delete(CACHE.keys().next().value); }
    return m;
  }
  /** Lo que se guarda del modelo junto al texto (sin las líneas): para leerlo sin parsear. */
  const resumen = (m) => ({
    codigo: m.codigo, titulo: m.titulo, actor: m.actor, como: m.como, quiero: m.quiero.map((x) => x.v), para: m.para.map((x) => x.v),
    escenarios: m.escenarios.map((e) => ({ nombre: e.nombre, dado: e.dado.map((x) => x.v), cuando: e.cuando.map((x) => x.v), entonces: e.entonces.map((x) => x.v) })),
    resultados: m.resultados ? m.resultados.items.filter((x) => x.tipo).map((x) => x.tipo) : null, errores: m.errores.length,
  });

  /* =========================================================
     Del modelo a lo que se dibuja
     ========================================================= */
  function vistaDe(m) {
    const quiero = m.quiero.map((q) => q.v).join(' ');
    const actor = m.actor || detectarActor(m.como, `${m.titulo} ${quiero}`);
    const pasos = [{ texto: actor === 'sistema' ? 'Arranca el proceso' : 'Ingresa al sistema', ico: 'sistema', esc: -1, fijo: true }];
    if (m.escenarios.length) {
      m.escenarios.forEach((e, k) => {
        const ent = e.entonces.map((x) => x.v).join(' ');
        if (!e.cuando.length) {
          pasos.push({ texto: e.nombre || `Caso ${k + 1}`, esc: k, ico: icoPaso(`${e.nombre} ${ent}`), campo: 'escenario', si: e.dado, det: e.entonces, sinCuando: true });
          return;
        }
        e.cuando.forEach((c, j) => {
          const ult = j === e.cuando.length - 1;
          // El dibujo sale del paso; si el paso no dice nada, de lo que pasa después («Entonces»)
          const ico = icoPaso(c.v);
          pasos.push({ texto: c.v, it: c, esc: k, ico: ico !== 'tarea' || !ult ? ico : icoPaso(ent), si: j === 0 ? e.dado : [], det: ult ? e.entonces : [] });
        });
      });
    } else m.quiero.forEach((q) => pasos.push({ texto: q.v, it: q, esc: -1, ico: icoPaso(q.v), si: [], det: [] }));

    const textoEsc = (e) => `${e.nombre} ${e.cuando.map((x) => x.v).join(' ')} ${e.entonces.map((x) => x.v).join(' ')}`;
    let resultados;
    if (m.resultados) {
      resultados = m.resultados.items.filter((x) => x.tipo).map((x, i) => ({
        tipo: x.tipo, texto: x.texto, i,
        escs: m.escenarios.map((e, k) => (detectarRes(textoEsc(e)).includes(x.tipo) ? k : -9)).filter((k) => k >= 0),
      }));
    } else {
      resultados = [];
      const sumar = (texto, k) => {
        for (const tipo of detectarRes(texto)) {
          let r = resultados.find((x) => x.tipo === tipo);
          if (!r) resultados.push(r = { tipo, texto: NOMBRE_RES[tipo], escs: [] });
          if (!r.escs.includes(k)) r.escs.push(k);
        }
      };
      sumar(`${m.titulo} ${quiero} ${m.para.map((x) => x.v).join(' ')}`, -1);
      m.escenarios.forEach((e, k) => sumar(textoEsc(e), k));
      resultados.sort((a, b) => TIPOS_RES.indexOf(a.tipo) - TIPOS_RES.indexOf(b.tipo));
      resultados.forEach((r, i) => { r.i = i; });
    }
    return { m, actor, actorNombre: limpiarComo(m.como) || ACTORES[actor].nombre, pasos, resultados, explicitos: !!m.resultados };
  }
  const escDe = (V, el, n) => {
    const total = V.m.escenarios.length;
    let k = n && n._escLocal != null ? n._escLocal : Number(el.esc);
    if (!Number.isFinite(k) || k < 0 || k >= total) k = -1;
    return k;
  };
  const pasosDe = (V, k) => V.pasos.filter((p) => k < 0 || p.esc < 0 || p.esc === k);
  function resultadosDe(V, k) {
    let out = V.resultados;
    if (k >= 0) {
      const del = out.filter((r) => r.escs.includes(k));
      out = del.length ? del : (V.explicitos ? out : out.filter((r) => r.escs.includes(-1)));
    }
    return out.length ? out : [{ tipo: 'tarea', texto: 'Queda registrado', fijo: true }];
  }
  function articulo(nombre) {
    const w = plano(String(nombre).split(' ')[0]);
    if (/(?:ista|ema|ama|eta)$/.test(w)) return 'el';
    return /(?:a|cion|sion|dad|tud)$/.test(w) ? 'la' : 'el';
  }
  const FRASE_RES = { pdf: 'un PDF', excel: 'un Excel', reporte: 'un reporte', horario: 'su horario', correo: 'un correo', certificado: 'su certificado', pago: 'su pago registrado' };
  /** La frase simple (sin el «Mira:»): «el docente entra al sistema, … Al final obtiene un PDF y un correo.» */
  function narrar(V, pasos, res) {
    const quien = V.actor === 'sistema' ? 'el sistema' : `${articulo(V.actorNombre)} ${V.actorNombre.split(' ').map(minus).join(' ')}`;
    const como = limpiarComo(V.m.como);
    const acc = pasos.filter((p) => !p.sinCuando).map((p) => {
      if (p.fijo) return V.actor === 'sistema' ? 'arranca el proceso' : 'entra al sistema';
      return minus(sinPunto(tercera(p.esc >= 0 ? sinSujeto(p.texto, como) : p.texto)));
    }).filter(Boolean);
    // Si el último paso ya trae su «y» («entra a X y filtra»), se separa con coma: «entra al sistema, entra a X y filtra»
    const cuerpo = acc.length > 5 ? acc.slice(0, 4).join(', ') + ` y ${acc.length - 4} pasos más`
      : (acc.length > 1 && /\s(?:y|e)\s/.test(acc[acc.length - 1]) ? acc.join(', ') : unir(acc));
    const conFrase = res.filter((r) => r.tipo !== 'tarea');
    let fin = '';
    if (conFrase.length) {
      const deFabrica = conFrase.every((r) => r.texto === NOMBRE_RES[r.tipo]);
      fin = deFabrica ? ' Al final obtiene ' + unir(conFrase.map((r) => FRASE_RES[r.tipo])) + '.'
        : ' Al final obtiene: ' + unir(conFrase.map((r) => sinPunto(r.texto))) + '.';
    } else fin = ' Al final, todo queda registrado.';
    return `${quien} ${cuerpo}.${fin}`.replace(/\.\./g, '.');
  }
  const sinMira = (s) => limpio(s).replace(/^mira\s*:\s*/i, '');
  /** La frase automática del diagrama. Con «Todos los casos», la cuenta del primero (el camino feliz). */
  function fraseAuto(V, k) {
    const escs = V.m.escenarios;
    if (k >= 0 || escs.length < 2) return narrar(V, pasosDe(V, k), resultadosDe(V, k));
    const otros = escs.length - 1;
    return narrar(V, pasosDe(V, 0), resultadosDe(V, 0)) + ` Así va «${sinPunto(escs[0].nombre) || 'Caso 1'}»; arriba ${otros === 1 ? 'está el otro caso' : `están los otros ${otros} casos`}.`;
  }

  /* =========================================================
     Pintar el diagrama (un solo elemento de la pizarra)
     ========================================================= */
  let api = null;   // lo que pizarra.js presta en montar()
  let edActual = false;   // ¿el diagrama que se está armando se puede escribir? (htmlDe lo pone)
  const esAjeno = (el) => !!api?.bloqueado(el);
  const editable = (el) => !!api?.EDITAR && !esAjeno(el) && !el._soloVista;
  /** ¿Puede ponerlo a su nombre? (pizarra de una reunión y arma el backlog de ese proyecto). */
  const puedeBloquear = (pid) => !!api && !api.CFG.mia && (api.CFG.admin || (api.CFG.hu?.puede || []).map(Number).includes(Number(pid || api.CFG.proyecto)));
  const conDueno = (d, pid) => (puedeBloquear(pid) ? Object.assign(d, { dueno: api.YO, dueno_nombre: api.CFG.yoNombre || '' }) : d);
  const icoApi = (n) => (api ? api.ico(n) : '');
  const FLECHA = '<svg viewBox="0 0 34 24" aria-hidden="true" focusable="false"><path d="M3 12h24M20 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  const FLECHITA = '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M3 4v3.5a2 2 0 0 0 2 2h7M9.5 6.5l3 3-3 3" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  const ilu = (k, clase) => `<span class="${clase}" data-color="${TINTE[k] || 'celeste'}" data-ilu="${ILU[k] ? k : 'tarea'}">${ILU[k] || ILU.tarea}</span>`;
  // Lo que se escribe en el diagrama: data-k lo encuentra; el resto dice qué parte del modelo es
  const campo = (campoN, k, extra, texto, clase = '') => {
    const ed = edActual;
    const attrs = Object.entries(extra || {}).filter(([, v]) => v !== '' && v != null).map(([a, v]) => ` data-${a}="${escHtml(String(v))}"`).join('');
    return `<span class="pz-campo${clase ? ' ' + clase : ''}"${ed ? ` data-campo="${campoN}" data-k="${escHtml(k)}"${attrs}` : ''}>${escHtml(texto)}</span>`;
  };
  const deLinea = (it, texto) => ({ l: it.l, parte: it.parte, minus: /^[a-záéíóúñü]/.test(it.v) ? '1' : '' });
  const quitarBtn = (it, tip) => (edActual ? `<button type="button" class="pz-hu__quitar" data-hu="quitar" data-l="${it.l}"${it.parte ? ` data-parte="${it.parte}"` : ''} data-tip="${escHtml(tip)}" aria-label="${escHtml(tip)}" data-solo-editar>${icoApi('ds-cerrar')}</button>` : '');

  /** El diagrama. limpio: para la imagen (sin botones ni avisos, todo legible). */
  function htmlDe(el, n, V, limpio = false) {
    const m = V.m, k = escDe(V, el, n), ed = !limpio && editable(el);
    edActual = ed;
    const pasos = pasosDe(V, k), res = resultadosDe(V, k), hu = el.hu || {};
    const escs = m.escenarios;
    const meta = [hu.id ? (hu.sprint || 'Backlog') : '', hu.estado_txt, hu.puntos ? hu.puntos + (hu.puntos === 1 ? ' punto' : ' puntos') : ''].filter(Boolean);
    const jugando = !!n._anim;
    const codigo = m.codigo || (!m.hist && hu.codigo) || '';
    const formal = m.como || m.quiero.length
      ? `${m.como ? `Como <b>${escHtml(sinPunto(m.como))}</b>, ` : ''}${m.quiero.length ? `quiero ${escHtml(unir(m.quiero.map((q) => sinPunto(q.v))))}` : ''}${m.para.length ? `, para ${escHtml(unir(m.para.map((p) => sinPunto(p.v))))}` : ''}`
      : '';
    const frase = m.mira ? sinMira(m.mira) : fraseAuto(V, k);
    let h = `<header class="pz-hu__cab">${codigo ? `<span class="pz-hu__codigo">${escHtml(codigo)}</span>` : ''}`
      + campo('titulo', 'titulo', {}, m.titulo || 'Historia de usuario', 'pz-hu__titulo')
      + (meta.length ? `<span class="pz-hu__meta">${escHtml(meta.join(' · '))}</span>` : '')
      + (m.errores.length && ed ? `<span class="pz-hu__aviso" data-tip="Hay líneas que no se entienden: míralas en el texto">${m.errores.length === 1 ? '1 línea por revisar' : m.errores.length + ' líneas por revisar'}</span>` : '')
      + (!limpio && Number(el.dueno) > 0 && !api?.CFG.mia ? `<span class="pz-hu__candado${esAjeno(el) ? ' is-ajeno' : ''}" data-tip="${escHtml(Number(el.dueno) === api.YO ? 'Solo tú lo editas: los demás lo ven y lo reproducen' : `Solo lo edita ${el.dueno_nombre || 'quien lo puso'}`)}" aria-label="Bloqueado">${icoApi('ds-candado')}</span>` : '')
      + (limpio ? '' : `<button type="button" class="pz-hu__play${jugando ? ' is-jugando' : ''}" data-hu="play" data-tip="${jugando ? 'Detener' : 'Reproducir el recorrido'}" aria-label="${jugando ? 'Detener' : 'Reproducir el recorrido'}">${icoApi(jugando ? 'ds-pausa' : 'ds-play')}</button>`) + '</header>';
    if (formal) h += `<p class="pz-hu__formal">${formal}</p>`;
    h += `<div class="pz-hu__bocadillo"><b class="pz-hu__mira">Mira:</b> ${campo('mira', 'mira', {}, frase, 'pz-hu__frase')}</div>`;
    if (escs.length > 1) {
      h += '<div class="pz-hu__escs" role="group" aria-label="Ver un escenario">'
        + `<button type="button" class="pz-hu__esc${k < 0 ? ' is-activa' : ''}" data-hu-esc="-1" aria-pressed="${k < 0}">Todos los casos</button>`
        + escs.map((e, i) => `<button type="button" class="pz-hu__esc${k === i ? ' is-activa' : ''}" data-hu-esc="${i}" aria-pressed="${k === i}" data-tip="${escHtml(e.nombre || 'Caso ' + (i + 1))}"><span>${i + 1}</span><em>${escHtml(e.nombre || 'Caso ' + (i + 1))}</em></button>`).join('')
        + '</div>';
    }
    // El flujo: quién → pasos → lo que obtiene
    h += '<div class="pz-hu__flujo">'
      + `<div class="pz-hu__actor">${ilu(V.actor, 'pz-hu__ilu')}${campo('actor', 'actor', {}, V.actorNombre, 'pz-hu__anombre')}<small>${V.actor === 'sistema' ? 'Lo hace solo' : 'Quien la usa'}</small></div>`
      + `<span class="pz-hu__flecha" aria-hidden="true">${FLECHA}</span>`
      + '<ol class="pz-hu__pasos">';
    let caso = null, num = 0;
    const lineaChica = (it, clase, pre) => `<small class="${clase}">${pre}${campo('linea', 'l' + it.l + (it.parte ? '-' + it.parte : ''), deLinea(it), sinPunto(it.v).replace(/^que\s+/i, '') || '…')}</small>`;
    for (const p of pasos) {
      if (k < 0 && escs.length > 1 && p.esc >= 0 && p.esc !== caso) {
        caso = p.esc;
        h += `<li class="pz-hu__caso"><span class="pz-hu__caso-n">${caso + 1}</span>${campo('escenario', 'e' + caso, { i: caso }, escs[caso].nombre || 'Caso ' + (caso + 1))}`
          + (ed ? `<button type="button" class="pz-hu__quitar" data-hu="quitar-esc" data-i="${caso}" data-tip="Quitar este escenario" aria-label="Quitar este escenario" data-solo-editar>${icoApi('ds-cerrar')}</button>` : '') + '</li>';
      }
      num++;
      let txt;
      if (p.fijo) txt = `<span class="pz-campo pz-hu__pt">${escHtml(p.texto)}</span>`;
      else if (p.sinCuando) txt = campo('escenario', 'e' + p.esc, { i: p.esc }, p.texto, 'pz-hu__pt');
      else txt = campo('linea', 'l' + p.it.l + (p.it.parte ? '-' + p.it.parte : ''), deLinea(p.it), capital(sinPunto(p.texto)), 'pz-hu__pt');
      h += `<li class="pz-hu__paso${p.fijo ? ' is-fijo' : ''}" data-n="${num}">`
        + `<span class="pz-hu__num"><i>${num}</i>${icoApi('ds-check')}</span>`
        + ilu(ICONOS_PASO.includes(p.ico) ? p.ico : 'tarea', 'pz-hu__pico')
        + '<span class="pz-hu__ptxt">'
        + (p.si || []).map((it) => lineaChica(it, 'pz-hu__si', 'Si ')).join('')
        + txt
        + (p.det || []).map((it) => lineaChica(it, 'pz-hu__det', FLECHITA)).join('')
        + '</span>'
        + (!p.fijo && !p.sinCuando && p.it ? quitarBtn(p.it, 'Quitar este paso') : '')
        + '</li>';
    }
    if (pasos.length === 1) h += '<li class="pz-hu__pista">Todavía no hay pasos: escribe «Quiero: …» o un «Escenario:» en el texto.</li>';
    if (ed) {
      h += '<li class="pz-hu__mas-li">'
        + `<button type="button" class="pz-hu__mas" data-hu="paso" data-solo-editar>${icoApi('ds-mas')} Añadir paso</button>`
        + `<button type="button" class="pz-hu__mas" data-hu="escenario" data-solo-editar>${icoApi('ds-mas')} Añadir escenario</button></li>`;
    }
    h += '</ol>'
      + `<span class="pz-hu__flecha" aria-hidden="true">${FLECHA}</span>`
      + `<div class="pz-hu__res"><b class="pz-hu__rt">${res.some((r) => r.tipo !== 'tarea' || !r.fijo) ? 'Obtiene' : 'Al final'}</b>`
      + res.map((r) => `<div class="pz-hu__r">${ilu(TIPOS_RES.includes(r.tipo) ? r.tipo : 'tarea', 'pz-hu__rilu')}`
        + (r.fijo ? `<b class="pz-campo">${escHtml(r.texto)}</b>` : campo('res', 'r' + r.i, { i: r.i }, r.texto, 'pz-hu__rnombre')) + '</div>').join('')
      + (m.para.length ? `<div class="pz-hu__para"><b>Para qué</b><span>${escHtml(capital(unir(m.para.map((p) => sinPunto(p.v)))))}</span></div>` : '')
      + '</div></div>'
      + (limpio ? '' : `<span class="pz-hu__ficha" aria-hidden="true">${ilu(V.actor, 'pz-hu__ficha-ilu')}</span>`);
    edActual = false;
    return h;
  }

  /** Pinta (o deja como está) el nodo de un «hu». Reproduce si alguien pulsó ▶ (rep subió). */
  function pintar(el, n) {
    // El filtro de quien solo mira es suyo; si quien edita cambia el de todos, manda el de todos
    if (n._escRemoto !== el.esc) { if (n._escVisto && editable(el)) n._escLocal = null; n._escRemoto = el.esc; }
    n._escVisto = true;
    const V = vistaDe(modelo(el.texto));
    const firma = JSON.stringify([el.texto, el.hu, escDe(V, el, n), editable(el), el.dueno || 0]);
    n.classList.toggle('is-bloqueado', esAjeno(el));
    if (n._firma !== firma) {
      if (n._anim) detener(n, false);
      n._firma = firma;
      n.innerHTML = htmlDe(el, n, V);
    }
    const rep = Number(el.rep) || 0;
    if (n._rep === undefined) n._rep = rep;
    else if (rep > n._rep) { n._rep = rep; reproducir(n); }
    else n._rep = rep;
    if (editor.id === el.id) sincronizarEditor(el);
  }

  /* ----- Animación: el actor recorre los pasos y salen los resultados ----- */
  const reducido = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  function posEn(nodo, raizNodo) {
    let x = 0, y = 0, e = nodo;
    while (e && e !== raizNodo) { x += e.offsetLeft; y += e.offsetTop; e = e.offsetParent; }
    return { x, y, w: nodo.offsetWidth, h: nodo.offsetHeight };
  }
  function ponerPlay(n, jugando) {
    const b = n.querySelector('.pz-hu__play');
    if (!b) return;
    b.classList.toggle('is-jugando', jugando);
    b.innerHTML = icoApi(jugando ? 'ds-pausa' : 'ds-play');
    b.dataset.tip = jugando ? 'Detener' : 'Reproducir el recorrido';
    b.setAttribute('aria-label', b.dataset.tip);
  }
  function detener(n, repintarBoton = true) {
    (n._anim || []).forEach(clearTimeout);
    n._anim = null;
    n.classList.remove('is-jugando', 'is-reducido');
    n.querySelectorAll('.is-luz, .is-hecho, .is-sale').forEach((x) => x.classList.remove('is-luz', 'is-hecho', 'is-sale'));
    n.querySelector('.pz-hu__ficha')?.classList.remove('is-visible');
    if (repintarBoton) ponerPlay(n, false);
  }
  function reproducir(n) {
    detener(n, false);
    const pasos = [...n.querySelectorAll('.pz-hu__paso')], res = [...n.querySelectorAll('.pz-hu__r')];
    const actor = n.querySelector('.pz-hu__actor'), ficha = n.querySelector('.pz-hu__ficha'), rt = n.querySelector('.pz-hu__res');
    if (!pasos.length || !actor) return;
    const quieto = reducido();
    const T = quieto ? { actor: 500, paso: 1000, res: 260, fin: 1600 } : { actor: 800, paso: 1150, res: 360, fin: 1900 };
    const linea = [];
    let t = 0;
    const llevar = (destino, dx = 0, dy = 0) => {
      if (quieto || !ficha || !destino) return;
      const p = posEn(destino, n);
      ficha.style.transform = `translate(${Math.round(p.x + dx)}px,${Math.round(p.y + dy)}px)`;
    };
    n.classList.add('is-jugando');
    n.classList.toggle('is-reducido', quieto);
    ponerPlay(n, true);
    linea.push([0, () => {
      actor.classList.add('is-luz');
      const i0 = actor.querySelector('.pz-hu__ilu');
      if (ficha && !quieto && i0) {
        ficha.style.transition = 'none';
        llevar(i0, i0.offsetWidth / 2 - 20, i0.offsetHeight - 30);
        void ficha.offsetWidth;
        ficha.style.transition = '';
        ficha.classList.add('is-visible');
      }
    }]);
    t += T.actor;
    pasos.forEach((p, i) => {
      linea.push([t, () => {
        if (i === 0) actor.classList.remove('is-luz');
        if (i > 0) { pasos[i - 1].classList.remove('is-luz'); pasos[i - 1].classList.add('is-hecho'); }
        p.classList.add('is-luz');
        const num = p.querySelector('.pz-hu__num');
        llevar(num, -48, (num ? num.offsetHeight / 2 : 0) - 20);
      }]);
      t += T.paso;
    });
    linea.push([t, () => {
      const u = pasos[pasos.length - 1];
      u.classList.remove('is-luz'); u.classList.add('is-hecho');
      llevar(rt, -46, 0);
    }]);
    res.forEach((r, i) => linea.push([t + 120 + i * T.res, () => r.classList.add('is-sale')]));
    t += 120 + res.length * T.res + T.fin;
    linea.push([t, () => detener(n)]);
    n._anim = linea.map(([ms, fn]) => setTimeout(fn, ms));
  }

  /* =========================================================
     Menú propio (en vez de <select>): filtros, actor y resultados
     ========================================================= */
  let menuAbierto = null;
  function cerrarMenu(devolverFoco = false) {
    if (!menuAbierto) return false;
    const { m, ancla } = menuAbierto;
    menuAbierto = null;
    m.remove();
    if (ancla?.isConnected) { ancla.setAttribute('aria-expanded', 'false'); if (devolverFoco) ancla.focus({ preventScroll: true }); }
    return true;
  }
  /** items: [{v, t, d?, ilu?, on?}] o '|'. multi: se queda abierto y cada uno se prende y se apaga. */
  function abrirMenu(ancla, { titulo = '', items, multi = false, alElegir }) {
    if (menuAbierto && menuAbierto.ancla === ancla) { cerrarMenu(true); return; }
    cerrarMenu();
    const m = document.createElement('div');
    m.className = 'pz-menu pz-vidrio';
    m.setAttribute('role', 'menu');
    if (titulo) m.setAttribute('aria-label', titulo);
    m.innerHTML = (titulo ? `<b class="pz-menu__t">${escHtml(titulo)}</b>` : '') + items.map((it, i) => (it === '|' ? '<span class="pz-menu__sep" role="separator"></span>'
      : `<button type="button" class="pz-menu__op${it.on ? ' is-activa' : ''}" role="${multi ? 'menuitemcheckbox' : 'menuitemradio'}" aria-checked="${it.on ? 'true' : 'false'}" data-i="${i}">`
        + (it.ilu ? ilu(it.ilu, 'pz-menu__ilu') : '')
        + `<span class="pz-menu__txt"><span>${escHtml(it.t)}</span>${it.d ? `<small>${escHtml(it.d)}</small>` : ''}</span>`
        + `<span class="pz-menu__check" aria-hidden="true">${icoApi('ds-check')}</span></button>`)).join('');
    (api?.raiz || document.body).appendChild(m);
    const r = ancla.getBoundingClientRect(), W = window.innerWidth, H = window.innerHeight;
    m.style.maxHeight = Math.max(160, H - 24) + 'px';
    const w = m.offsetWidth, hh = Math.min(m.offsetHeight, H - 24);
    let y = r.bottom + 6;
    if (y + hh > H - 8) y = r.top - hh - 6;
    if (y < 8) y = Math.max(8, H - hh - 8);
    m.style.setProperty('--mx', Math.round(Math.min(Math.max(8, r.left), W - w - 8)) + 'px');
    m.style.setProperty('--my', Math.round(y) + 'px');
    m.addEventListener('pointerdown', (e) => { if (e.target.closest('button')) e.preventDefault(); });
    m.addEventListener('click', (e) => {
      const b = e.target.closest('.pz-menu__op');
      if (!b) return;
      const it = items[Number(b.dataset.i)];
      const on = alElegir(it);
      if (!multi) { cerrarMenu(true); return; }
      if (typeof on === 'boolean') { it.on = on; b.classList.toggle('is-activa', on); b.setAttribute('aria-checked', on ? 'true' : 'false'); }
    });
    m.addEventListener('keydown', (e) => {
      const ops = [...m.querySelectorAll('.pz-menu__op')], i = ops.indexOf(document.activeElement);
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); ops[(i + (e.key === 'ArrowDown' ? 1 : -1) + ops.length) % ops.length]?.focus(); }
      else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); cerrarMenu(true); }
      else if (e.key === 'Tab') cerrarMenu();
    });
    menuAbierto = { m, ancla };
    ancla.setAttribute('aria-expanded', 'true');
    requestAnimationFrame(() => (m.querySelector('.pz-menu__op.is-activa') || m.querySelector('.pz-menu__op'))?.focus({ preventScroll: true }));
  }
  document.addEventListener('pointerdown', (e) => {
    if (menuAbierto && !menuAbierto.m.contains(e.target) && !menuAbierto.ancla.contains(e.target)) cerrarMenu();
  }, { capture: true, passive: true });
  window.addEventListener('resize', () => cerrarMenu(), { passive: true });

  /* =========================================================
     Diagrama → texto: lo que se cambia en el dibujo pasa por el modelo
     ========================================================= */
  const conTexto = (el, t, unirCon) => api.cambiar(el, (x) => { x.texto = t; x.modelo = resumen(modelo(t)); }, unirCon);
  function alternarPlay(el, n) {
    if (n._anim) { detener(n); return; }
    // Quien edita lo reproduce para todos (rep sube y cada pizarra lo anima); quien mira, solo para sí
    if (editable(el)) api.sinHistoria(el, (x) => { x.rep = Math.max(Date.now(), (Number(x.rep) || 0) + 1); });
    else reproducir(n);
  }
  function elegirEscenario(el, n, k) {
    if (editable(el)) { api.cambiar(el, (x) => { x.esc = k; }); return; }
    n._escLocal = k;
    if (el._soloVista) { pintar(el, n); return; }
    api.pintar(el);
  }
  function agregarPaso(el) {
    const n = api.nodo(el.id), V = vistaDe(modelo(el.texto));
    const r = Texto.agregarPaso(el.texto || '', escDe(V, el, n));
    conTexto(el, r.texto);
    if (r.l >= 0) api.editar(el.id, { campo: 'linea', k: 'l' + r.l, l: String(r.l), todo: true });
  }
  function agregarEscenario(el) {
    const r = Texto.agregarEscenario(el.texto || '');
    conTexto(el, r.texto);
    api.cambiar(el, (x) => { x.esc = -1; }, 'hu-esc');
    api.editar(el.id, { campo: 'escenario', k: 'e' + r.k, i: String(r.k), todo: true });
  }
  function quitarPaso(el, l, parte) { conTexto(el, Texto.quitar(el.texto || '', l, parte)); }
  function quitarEscenario(el, k) {
    const t = Texto.quitarEscenario(el.texto || '', k);
    api.cambiar(el, (x) => {
      x.texto = t; x.modelo = resumen(modelo(t));
      const e = Number(x.esc);
      if (e === k) x.esc = -1; else if (e > k) x.esc = e - 1;
    });
  }

  /* ----- Barra de lo elegido (pizarra.js · pintarCtx) ----- */
  function botonesCtx(el, boton) {
    const V = vistaDe(modelo(el.texto));
    const abierto = editor.id === el.id && editor.el && !editor.el.hidden;
    const textoBtn = `<button type="button" class="pz-boton pz-boton--icono${abierto ? ' is-activa' : ''}" data-ctx="hu-texto" data-tip="${abierto ? 'Cerrar el texto' : (esAjeno(el) ? 'Leer el texto' : 'Ver y escribir el texto')}" aria-label="Texto de la historia" aria-pressed="${abierto}">${icoApi('pz-texto')}</button>`;
    if (esAjeno(el)) {
      // De otro: reproducirlo (solo para mí), leer su texto y quién lo edita
      return boton('hu-play', 'ds-play', 'Reproducir (solo para ti)') + textoBtn
        + `<span class="pz-hu-ctx-dueno" data-tip="Solo lo edita ${escHtml(el.dueno_nombre || 'quien lo puso')}">${icoApi('ds-candado')}<span>${escHtml(el.dueno_nombre || 'Bloqueado')}</span></span>`;
    }
    let h = boton('hu-play', 'ds-play', 'Reproducir para todos')
      + `<button type="button" class="pz-boton pz-boton--icono pz-hu-ctx-actor" data-ctx="hu-actor" data-tip="Quién la usa: ${escHtml(V.actorNombre)}" aria-label="Cambiar quién la usa" aria-haspopup="menu">${ilu(V.actor, 'pz-hu-ctx-ilu')}</button>`
      + boton('hu-res', 'ds-descargar', 'Lo que obtiene (PDF, Excel…)')
      + textoBtn;
    if (el.hu?.id) h += boton('hu-rehacer', 'ds-reintentar', 'Volver a lo que dice la historia del backlog');
    // El candado: en la pizarra de una reunión, quien arma el backlog lo deja solo para sí (o lo suelta)
    if (puedeBloquear(el.hu?.proyecto_id) && (!Number(el.dueno) || Number(el.dueno) === api.YO || api.CFG.admin)) {
      const puesto = Number(el.dueno) > 0;
      const tip = puesto ? 'Bloqueado: solo tú lo editas. Pulsa para que el equipo también pueda' : 'Que solo yo lo edite (los demás lo ven y lo reproducen)';
      h += `<button type="button" class="pz-boton pz-boton--icono${puesto ? ' is-activa' : ''}" data-ctx="hu-candado" data-tip="${escHtml(tip)}" aria-label="${escHtml(tip)}" aria-pressed="${puesto}">${icoApi('ds-candado')}</button>`;
    }
    return h;
  }
  function accionCtx(accion, el, b) {
    const n = api.nodo(el.id);
    switch (accion) {
      case 'hu-play': if (n) alternarPlay(el, n); break;
      case 'hu-texto':
        if (editor.id === el.id && editor.el && !editor.el.hidden) cerrarEditor(true);
        else { ponerLocal(CLAVE_ED, 1); abrirEditor(el.id, { foco: true }); }
        api.refrescarCtx();
        break;
      case 'hu-actor': {
        const V = vistaDe(modelo(el.texto));
        const items = Object.keys(ACTORES).map((k) => ({ v: k, t: ACTORES[k].nombre, ilu: k, on: V.actor === k }));
        abrirMenu(b, { titulo: 'Quién la usa', items, alElegir: (it) => { const x = api.vivo(el.id); if (x) conTexto(x, Texto.actor(x.texto || '', it.v)); } });
        break;
      }
      case 'hu-res': {
        const V = vistaDe(modelo(el.texto));
        const items = TIPOS_RES.map((t) => ({ v: t, t: NOMBRE_RES[t], ilu: t, on: V.resultados.some((r) => r.tipo === t) }));
        abrirMenu(b, { titulo: 'Lo que obtiene', items, multi: true, alElegir: (it) => {
          const x = api.vivo(el.id);
          if (!x) return undefined;
          const Vx = vistaDe(modelo(x.texto));
          const ya = Vx.resultados.some((r) => r.tipo === it.v);
          conTexto(x, Texto.alternarRes(x.texto || '', it.v, Vx.resultados));
          return !ya;
        } });
        break;
      }
      case 'hu-rehacer': volverAHistoria(el, b); break;
      case 'hu-candado':
        if (esAjeno(el)) return;
        api.cambiar(el, (x) => {
          if (Number(x.dueno) > 0) { delete x.dueno; delete x.dueno_nombre; }
          else { x.dueno = api.YO; x.dueno_nombre = api.CFG.yoNombre || ''; }
        });
        api.toast(Number(el.dueno) > 0 ? 'Bloqueado: solo tú lo editas. Los demás lo ven, lo reproducen y filtran sus casos.' : 'Listo: ahora el equipo también puede editarlo.');
        api.refrescarCtx();
        break;
    }
  }
  async function volverAHistoria(el, b) {
    b?.classList.add('is-cargando');
    let j;
    try { j = await api.pedir('historia', { params: '&id=' + Number(el.hu?.id || 0) }); }
    catch (err) { api.toast(err.message || 'No se pudo traer la historia.', true); return; }
    finally { b?.classList.remove('is-cargando'); }
    const actual = api.vivo(el.id);
    if (!actual || !j.historia) return;
    const d = datosDe(j.historia);
    api.cambiar(actual, (x) => { x.texto = d.texto; x.modelo = d.modelo; x.hu = d.hu; x.esc = -1; });   // el dueño se queda
    api.toast('Listo: el diagrama volvió a lo que dice la historia del backlog. Ctrl Z lo deshace.');
  }

  /* ----- Escribir en el diagrama (pizarra.js · editar / terminarEdicion) ----- */
  function aplicarCampo(base, op, v) {
    const val = op.minus === '1' ? minus(v) : v;
    switch (op.campo) {
      case 'titulo': return Texto.titulo(base, val);
      case 'actor': return Texto.como(base, val);
      case 'mira': return Texto.mira(base, val);
      case 'linea': return Texto.fijar(base, { l: Number(op.l), parte: op.parte || '' }, val);
      case 'escenario': return Texto.nombreEscenario(base, Number(op.i), val);
      case 'res': return Texto.textoRes(base, Number(op.i), val, vistaDe(modelo(base)).resultados);
    }
    return null;
  }
  /** Mientras se escribe: el cambio sale siempre del texto de cuando empezó (no se acumula). */
  function ponerCampo(el, op, v) {
    if (op._base == null) op._base = el.texto || '';
    const t = aplicarCampo(op._base, op, v);
    if (t == null) return;
    el.texto = t;
    if (editor.id === el.id && !editor.foco) { editor.ta.value = t; pintarAyudas(); }
  }
  function alTerminar(el, op, antes) {
    const base = op._base ?? antes?.texto ?? el.texto ?? '';
    const v = limpio(op.campo === 'linea' ? (modelo(el.texto).lineas[Number(op.l)]?.valor ?? '') : '');
    if (op.campo === 'linea' && (!v || (op.parte && !limpio(textoParte(el.texto, op))))) el.texto = Texto.quitar(base, Number(op.l), op.parte || '');
    else if (op.campo === 'titulo' && !limpio(modelo(el.texto).titulo)) el.texto = base;
    else if (op.campo === 'res' && el.texto !== base) {
      const V = vistaDe(modelo(el.texto));
      if (!V.resultados.some((r) => r.i === Number(op.i))) el.texto = base;
    } else if (op.campo === 'mira') {
      // Si quedó igual que la frase automática, sigue siendo automática (cambia con el filtro)
      const m = modelo(el.texto);
      if (m.mira) {
        const V = vistaDe(parse(Texto.mira(el.texto, ''))), n = api.nodo(el.id);
        if (sinMira(m.mira) === fraseAuto(V, escDe(V, el, n))) el.texto = Texto.mira(el.texto, '');
      }
    }
    el.modelo = resumen(modelo(el.texto));
  }
  function textoParte(texto, op) {
    const L = modelo(texto).lineas[Number(op.l)];
    return L?.partes?.find((s) => s.k === op.parte)?.v || '';
  }

  /* =========================================================
     El panel de texto: lo que se escribe se dibuja solo (y al revés)
     ========================================================= */
  const editor = { el: null, ta: null, nums: null, espejo: null, estado: null, pie: null, id: null, reloj: 0, foco: false, raf: 0, confirmar: false };
  const CLAVE_ED = 'pz-hu-texto-abierto';
  const leerLocal = (k) => { try { return JSON.parse(localStorage.getItem(k)); } catch (e) { return null; } };
  const ponerLocal = (k, v) => { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* sin almacenamiento */ } };

  function armarEditor() {
    if (editor.el || !api) return;
    const s = document.createElement('section');
    s.className = 'pz-hu-texto pz-vidrio';
    s.id = 'pz-hu-texto';
    s.hidden = true;
    s.setAttribute('aria-label', 'Texto de la historia');
    s.innerHTML = '<header class="pz-hu-texto__cab">'
      + `<span class="pz-hu-texto__ico" aria-hidden="true">${icoApi('pz-texto')}</span>`
      + '<span class="pz-hu-texto__t"><b>Texto de la historia</b><small>Escribe aquí y el diagrama se dibuja solo</small></span>'
      + `<button type="button" class="pz-boton pz-boton--icono" data-ed="guia" data-tip="Cómo se escribe" aria-label="Cómo se escribe" aria-expanded="false">${icoApi('ds-ayuda')}</button>`
      + `<button type="button" class="pz-boton pz-boton--icono" data-ed="cerrar" data-tip="Cerrar el texto" data-tecla="Esc" aria-label="Cerrar el texto">${icoApi('ds-cerrar')}</button></header>`
      + '<div class="pz-hu-texto__guia" hidden><dl>'
      + '<dt>Historia:</dt><dd>código · título</dd>'
      + '<dt>Como:</dt><dd>quién la usa (docente, estudiante, empresa, médico…)</dd>'
      + '<dt>Quiero:</dt><dd>lo que hace; <b>Y:</b> otro paso</dd>'
      + '<dt>Para:</dt><dd>para qué le sirve</dd>'
      + '<dt>Escenario:</dt><dd>un caso, con <b>Dado:</b> lo que ya pasa, <b>Cuando:</b> lo que hace (y <b>Y:</b> otro paso) y <b>Entonces:</b> lo que pasa</dd>'
      + '<dt>Resultados:</dt><dd>PDF, Excel, Reporte, Horario, Correo, Certificado, Pago, Registro</dd>'
      + '<dt>Actor:</dt><dd>otro dibujo, si hace falta (opcional)</dd>'
      + '<dt>Mira:</dt><dd>la frase simple, si la quieres a mano (opcional)</dd>'
      + '</dl><small>Da igual mayúsculas, tildes, los «:» o las líneas en blanco. Con <kbd>Tab</kbd> se mete sangría.</small></div>'
      + '<div class="pz-hu-texto__caja"><div class="pz-hu-texto__nums" aria-hidden="true"></div>'
      + '<textarea class="pz-hu-texto__ta" spellcheck="false" autocomplete="off" aria-label="Texto de la historia"></textarea>'
      + '<div class="pz-hu-texto__espejo" aria-hidden="true"></div></div>'
      + '<div class="pz-hu-texto__estado" aria-live="polite"></div>'
      + '<footer class="pz-hu-texto__pie" hidden></footer>';
    api.raiz.appendChild(s);
    editor.el = s;
    editor.ta = s.querySelector('textarea');
    editor.nums = s.querySelector('.pz-hu-texto__nums');
    editor.espejo = s.querySelector('.pz-hu-texto__espejo');
    editor.estado = s.querySelector('.pz-hu-texto__estado');
    editor.pie = s.querySelector('.pz-hu-texto__pie');
    const ta = editor.ta;
    ta.addEventListener('input', () => {
      pintarAyudas();
      clearTimeout(editor.reloj);
      editor.reloj = setTimeout(aplicarEditor, 300);
    });
    ta.addEventListener('focus', () => { editor.foco = true; });
    ta.addEventListener('blur', () => { editor.foco = false; aplicarEditor(); api.retomar(); });
    ta.addEventListener('scroll', () => { editor.nums.scrollTop = ta.scrollTop; }, { passive: true });
    ta.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); cerrarEditor(false); api.lienzo.focus({ preventScroll: true }); return; }
      if (e.key === 'Tab' && !e.ctrlKey && !e.metaKey && !e.altKey) {
        // Tab mete dos espacios de sangría (Shift+Tab los quita) en la línea donde está el cursor
        e.preventDefault();
        const v = ta.value, a = ta.selectionStart, ini = v.lastIndexOf('\n', a - 1) + 1;
        if (e.shiftKey) {
          const quita = /^ {1,2}/.exec(v.slice(ini))?.[0].length || 0;
          if (!quita) return;
          ta.setRangeText('', ini, ini + quita, 'preserve');
          ta.selectionStart = ta.selectionEnd = Math.max(ini, a - quita);
        } else ta.setRangeText('  ', ini, ini, 'end');
        ta.selectionStart = ta.selectionEnd = e.shiftKey ? ta.selectionStart : a + 2;
        ta.dispatchEvent(new Event('input'));
      }
    });
    s.addEventListener('click', (e) => {
      const b = e.target.closest('[data-ed], [data-ed-linea]');
      if (!b) return;
      if (b.dataset.edLinea != null) { irALinea(Number(b.dataset.edLinea)); return; }
      const a = b.dataset.ed;
      if (a === 'cerrar') { cerrarEditor(true); api.refrescarCtx(); }
      else if (a === 'guia') { const g = s.querySelector('.pz-hu-texto__guia'); g.hidden = !g.hidden; b.setAttribute('aria-expanded', String(!g.hidden)); medirLuego(); }
      else if (a === 'guardar') { editor.confirmar = true; pintarPie(); }
      else if (a === 'no') { editor.confirmar = false; pintarPie(); }
      else if (a === 'si') guardarEnHistoria();
    });
    window.addEventListener('resize', () => { if (!s.hidden) medirLuego(); }, { passive: true });
  }
  function irALinea(i) {
    const lineas = editor.ta.value.split('\n');
    const a = lineas.slice(0, i).reduce((s, l) => s + l.length + 1, 0);
    editor.ta.focus();
    editor.ta.setSelectionRange(a, a + (lineas[i] || '').length);
  }
  const medirLuego = () => { cancelAnimationFrame(editor.raf); editor.raf = requestAnimationFrame(pintarAyudas); };
  /** Los números de línea (con las que no se entienden marcadas) y el estado debajo. */
  function pintarAyudas() {
    if (!editor.el || editor.el.hidden) return;
    const ta = editor.ta, m = parse(ta.value), V = vistaDe(m);
    const mal = new Set(m.errores.map((x) => x.l));
    editor.espejo.style.width = ta.clientWidth + 'px';
    editor.espejo.innerHTML = ta.value.split('\n').map((l) => `<div>${escHtml(l) || '&#8203;'}</div>`).join('');
    const altos = [...editor.espejo.children].map((d) => d.offsetHeight);
    editor.nums.innerHTML = altos.map((h, i) => `<span style="height:${h}px"${mal.has(i) ? ' class="is-error"' : ''}>${i + 1}</span>`).join('') + '<span style="height:40px"></span>';
    editor.nums.scrollTop = ta.scrollTop;
    const n = m.errores.length;
    if (n) {
      editor.estado.className = 'pz-hu-texto__estado is-error';
      editor.estado.innerHTML = `<b>${n === 1 ? '1 línea no se entiende' : n + ' líneas no se entienden'}: el resto se dibuja igual</b>`
        + m.errores.slice(0, 3).map((x) => `<button type="button" data-ed-linea="${x.l}"><span>Línea ${x.l + 1}</span>${escHtml(x.msg)}</button>`).join('')
        + (n > 3 ? `<small>y ${n - 3} más</small>` : '');
    } else {
      editor.estado.className = 'pz-hu-texto__estado';
      const vistos = V.resultados.map((r) => r.texto);
      editor.estado.innerHTML = `${icoApi('ds-check')}<span>Todo se entiende.${!V.explicitos && vistos.length ? ` Obtiene: ${escHtml(vistos.join(', '))} (lo saca del texto; con «Resultados:» lo fijas).` : ''}</span>`;
    }
  }
  function aplicarEditor() {
    clearTimeout(editor.reloj);
    editor.reloj = 0;
    const el = editor.id ? api.vivo(editor.id) : null;
    if (!el || esAjeno(el)) return;
    const t = editor.ta.value;
    if (t !== (el.texto || '')) conTexto(el, t, 'hu-texto');
  }
  /** De otro (tiene dueño): el texto se lee, no se escribe. */
  function ponerLectura(el) {
    const ajeno = esAjeno(el);
    editor.ta.readOnly = ajeno;
    editor.el.classList.toggle('is-lectura', ajeno);
    const sub = editor.el.querySelector('.pz-hu-texto__t small');
    if (sub) sub.textContent = ajeno ? `Solo lo edita ${el.dueno_nombre || 'quien lo puso'}: aquí lo lees` : 'Escribe aquí y el diagrama se dibuja solo';
  }
  function sincronizarEditor(el) {
    if (editor.el && !editor.el.hidden) ponerLectura(el);
    if (!editor.el || editor.el.hidden || editor.foco || editor.reloj) return;
    if (editor.ta.value !== (el.texto || '')) { editor.ta.value = el.texto || ''; pintarAyudas(); }
    pintarPie();
  }
  function abrirEditor(id, { foco = false, seleccionar = false } = {}) {
    armarEditor();
    const el = api.vivo(id);
    if (!el || !editor.el) return;
    if (editor.id && editor.id !== id) aplicarEditor();
    editor.id = id;
    editor.confirmar = false;
    editor.ta.value = el.texto || '';
    ponerLectura(el);
    editor.el.hidden = false;
    api.raiz.classList.add('con-hu-texto');
    pintarAyudas();
    pintarPie();
    if (foco) requestAnimationFrame(() => { editor.ta.focus({ preventScroll: true }); if (seleccionar) editor.ta.select(); });
  }
  function cerrarEditor(recordar) {
    if (!editor.el || editor.el.hidden) { editor.id = null; return; }
    aplicarEditor();
    editor.el.hidden = true;
    editor.id = null;
    editor.foco = false;
    api.raiz.classList.remove('con-hu-texto');
    if (recordar) ponerLocal(CLAVE_ED, 0);
  }
  /** Al cambiar lo elegido: con un diagrama, su texto al lado (si no lo cerraron a propósito). */
  function alElegir(sel) {
    if (!api?.EDITAR) return;
    const ids = [...sel];
    const el = ids.length === 1 ? api.vivo(ids[0]) : null;
    if (el && el.tipo === 'hu') {
      if (editor.id === el.id && editor.el && !editor.el.hidden) return;
      if (leerLocal(CLAVE_ED) !== 0) abrirEditor(el.id);
      return;
    }
    if (editor.id && !editor.foco) cerrarEditor(false);
  }
  const ocupado = (id) => editor.id === id && (editor.foco || !!editor.reloj);

  /* ----- «Guardar en la historia»: Como, Quiero, Para y los escenarios, de vuelta al backlog ----- */
  const puedeGuardar = (el) => !!(el?.hu?.id && !esAjeno(el) && (api.CFG.hu?.puede || []).map(Number).includes(Number(el.hu.proyecto_id)));
  function pintarPie() {
    const el = editor.id ? api.vivo(editor.id) : null;
    if (!editor.pie) return;
    if (!el || !puedeGuardar(el)) { editor.pie.hidden = true; editor.pie.innerHTML = ''; return; }
    const cod = escHtml(el.hu.codigo || 'la historia');
    editor.pie.hidden = false;
    const html = editor.confirmar
      ? `<span>Se reemplazan Como, Quiero, Para, los escenarios y la imagen del diagrama de <b>${cod}</b>.</span><span class="pz-hu-texto__botones"><button type="button" class="pz-pop__b" data-ed="no">Cancelar</button><button type="button" class="pz-pop__b pz-pop__b--si" data-ed="si">Guardar</button></span>`
      : `<span>Viene de <b>${cod}</b> del backlog.</span><button type="button" class="pz-hu-texto__guardar" data-ed="guardar">${icoApi('ds-check')} Guardar en la historia (con su imagen)</button>`;
    if (editor.pie._html !== html) { editor.pie._html = html; editor.pie.innerHTML = html; }
  }
  async function guardarEnHistoria() {
    aplicarEditor();
    const el = editor.id ? api.vivo(editor.id) : null;
    if (!el || !puedeGuardar(el)) return;
    const m = modelo(el.texto), juntar = (xs) => unir(xs.map((x) => sinPunto(x.v)));
    const cuerpo = {
      historia: Number(el.hu.id), como: sinPunto(m.como), quiero: juntar(m.quiero), para: juntar(m.para), texto: el.texto || '',
      escenarios: m.escenarios.map((e, k) => ({ nombre: e.nombre || `Caso ${k + 1}`, dado: juntar(e.dado), cuando: juntar(e.cuando), entonces: juntar(e.entonces) })),
    };
    const b = editor.pie.querySelector('[data-ed="si"]');
    b?.classList.add('is-cargando');
    const cod = el.hu.codigo || 'la historia';
    try {
      await api.pedir('guardar_hu', { metodo: 'POST', cuerpo });
    } catch (err) {
      b?.classList.remove('is-cargando');
      api.toast(err.red ? 'Sin conexión: no se pudo guardar en la historia.' : (err.message || 'No se pudo guardar en la historia.'), true);
      return;
    }
    panel.cuando = 0;   // el panel la vuelve a traer al abrirse
    // El texto ya está; ahora su imagen (si falla, se dice: el texto igual quedó)
    try {
      const png = await imagenDe(el);
      const fd = new FormData();
      fd.append('historia', String(Number(el.hu.id)));
      fd.append('imagen', png, 'diagrama.png');
      await api.pedir('guardar_hu_imagen', { metodo: 'POST', cuerpo: fd });
      api.toast(`Listo: ${cod} quedó con lo que dice el diagrama y con su imagen.`);
    } catch (err) {
      api.toast(`El texto quedó en ${cod}, pero la imagen no se pudo subir: ${err.red ? 'sin conexión' : (err.message || 'inténtalo de nuevo')}.`, true);
    }
    editor.confirmar = false;
    pintarPie();
  }

  /**
   * La imagen del diagrama para la historia: el mismo dibujo del PNG exportado, a 2x,
   * con fondo blanco, todos los casos y sin botones (se arma aparte, fuera de la vista).
   * Si pasara de ~3,8 MB, se achica. Devuelve un Blob PNG.
   */
  async function imagenDe(el) {
    try { await document.fonts?.ready; } catch (e) { /* sin fuentes, igual */ }
    await precargar(true);
    const V = vistaDe(modelo(el.texto));
    const n = document.createElement('div');
    n.className = 'pz-el pz-hu pz-hu--foto';
    n.setAttribute('aria-hidden', 'true');
    n.style.cssText = `position:fixed;left:-40000px;top:0;width:${Math.max(820, Number(el.w) || 1100)}px;transform:none;visibility:hidden;`;
    api.raiz.appendChild(n);
    try {
      n.innerHTML = htmlDe(Object.assign({}, el, { esc: -1 }), n, V, true);
      const g = { x: 0, y: 0, w: n.offsetWidth, h: n.offsetHeight }, m = 32;
      for (const s of [2, 1.5, 1]) {
        const c = document.createElement('canvas');
        c.width = Math.round((g.w + m * 2) * s); c.height = Math.round((g.h + m * 2) * s);
        const x = c.getContext('2d');
        x.scale(s, s);
        x.fillStyle = '#FFFFFF'; x.fillRect(0, 0, g.w + m * 2, g.h + m * 2);
        x.translate(m, m);
        dibujar(x, el, g, n, api.dibujo);
        const blob = await new Promise((r) => c.toBlob(r, 'image/png'));
        if (!blob) throw new Error('No se pudo dibujar la imagen.');
        if (blob.size <= 3.8 * 1024 * 1024 || s === 1) return blob;
      }
      throw new Error('La imagen salió demasiado grande.');
    } finally {
      n.remove();
    }
  }

  /* =========================================================
     El panel: las historias del proyecto, para arrastrarlas
     ========================================================= */
  const panel = { el: null, armado: false, datos: null, cuando: 0, cargando: false, error: '', q: '', sprint: '*', estado: '*', proyecto: 0 };
  const CLAVE_PROY = 'pz-hu-proyecto';
  const $p = (s) => panel.el.querySelector(s);

  /** Lo que se guarda en el elemento para una historia del backlog. */
  function datosDe(h) {
    const texto = limpio(h.diagrama_texto) ? String(h.diagrama_texto) : textoDe(h);   // el que se guardó en la historia, si hay
    return conDueno({
      texto, modelo: resumen(modelo(texto)), esc: -1, rep: 0,
      hu: { id: Number(h.id) || 0, proyecto_id: Number(h.proyecto_id) || 0, codigo: String(h.codigo || ''), sprint: String(h.sprint || ''),
            estado_txt: String(h.estado_txt || ''), puntos: Number(h.puntos) || 0 },
    }, h.proyecto_id);
  }
  const datosDeTexto = (texto) => conDueno({ texto, modelo: resumen(modelo(texto)), esc: -1, rep: 0, hu: null }, 0);

  function armarPanel() {
    if (panel.armado || !panel.el) return;
    panel.armado = true;
    const flecha = icoApi('ds-chevron-abajo');
    panel.el.innerHTML = '<header class="pz-hu-panel__cab">'
      + `<span class="pz-hu-panel__ico" aria-hidden="true">${icoApi('pz-historia')}</span>`
      + '<span class="pz-hu-panel__t"><b>Historias de usuario</b><small data-hp-sub>Cargando…</small></span>'
      + `<button type="button" class="pz-boton pz-boton--icono" data-hp="blanco" data-tip="Diagrama en blanco: escribe o pega la historia" aria-label="Diagrama en blanco">${icoApi('ds-mas')}</button>`
      + `<button type="button" class="pz-boton pz-boton--icono" data-hp="cerrar" data-tip="Cerrar" data-tecla="U" aria-label="Cerrar las historias">${icoApi('ds-cerrar')}</button></header>`
      + '<div class="pz-hu-panel__filtros">'
      + `<button type="button" class="pz-hu-filtro pz-hu-filtro--ancho" data-hp="proyecto" aria-haspopup="menu" hidden><span data-hp-txt>Elige un proyecto</span>${flecha}</button>`
      + `<label class="pz-hu-buscar">${icoApi('ds-buscar')}<input type="search" data-hp-q placeholder="Buscar por código, título o actor" autocomplete="off" spellcheck="false" aria-label="Buscar historias"></label>`
      + '<div class="pz-hu-panel__chips">'
      + `<button type="button" class="pz-hu-filtro" data-hp="sprint" aria-haspopup="menu"><span data-hp-txt>Todos los sprints</span>${flecha}</button>`
      + `<button type="button" class="pz-hu-filtro" data-hp="estado" aria-haspopup="menu"><span data-hp-txt>Todos los estados</span>${flecha}</button>`
      + '</div></div>'
      + '<div class="pz-hu-panel__lista" data-hp-lista role="list" aria-label="Historias"></div>'
      + '<p class="pz-hu-panel__pie">Arrastra una historia a la pizarra, o haz clic, y se arma su diagrama. Con + empiezas una en blanco.</p>';
    $p('[data-hp-q]').addEventListener('input', (e) => { panel.q = e.target.value; pintarLista(); });
    panel.el.addEventListener('click', (e) => {
      const b = e.target.closest('[data-hp]');
      if (!b) return;
      const a = b.dataset.hp;
      if (a === 'cerrar') alternarPanel(false);
      else if (a === 'blanco') enBlanco();
      else if (a === 'sprint') menuSprint(b);
      else if (a === 'estado') menuEstado(b);
      else if (a === 'proyecto') menuProyecto(b);
      else if (a === 'reintentar') cargar(true);
      else if (a === 'limpiar') { panel.q = ''; panel.sprint = '*'; panel.estado = '*'; $p('[data-hp-q]').value = ''; pintarLista(); }
    });
    panel.el.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !menuAbierto) { e.preventDefault(); e.stopPropagation(); alternarPanel(false); }
    });
    panel.el.addEventListener('pointerdown', alBajarCarta);
    panel.el.addEventListener('click', alClicCarta);
  }

  function alternarPanel(abrir) {
    if (!panel.el || !api?.EDITAR) return;
    if (abrir === undefined) abrir = panel.el.hidden;
    armarPanel();
    if (abrir) alternarIlus(false);
    panel.el.hidden = !abrir;
    const b = api.raiz.querySelector('[data-hu-panel]');
    b?.classList.toggle('is-abierta', abrir);
    b?.setAttribute('aria-pressed', abrir ? 'true' : 'false');
    api.raiz.classList.toggle('con-hu', abrir);
    if (!abrir) { cerrarMenu(); if (panel.el.contains(document.activeElement)) api.lienzo.focus({ preventScroll: true }); return; }
    if (!panel.datos || Date.now() - panel.cuando > 60000) cargar();
    else pintarLista();
    requestAnimationFrame(() => $p('[data-hp-q]')?.focus({ preventScroll: true }));
  }

  async function cargar(forzar = false) {
    if (panel.cargando && !forzar) return;
    panel.cargando = true;
    panel.error = '';
    if (!panel.datos) pintarLista();
    let j;
    try {
      const pid = api.CFG.mia ? (panel.proyecto || Number(leerLocal(CLAVE_PROY)) || 0) : 0;
      j = await api.pedir('historias', { params: pid ? '&proyecto=' + pid : '' });
      // En «Mis notas» sin proyecto elegido: el último que usó, o el primero que puede ver
      if (api.CFG.mia && !j.proyecto && Array.isArray(j.proyectos) && j.proyectos.length) {
        const recordado = Number(leerLocal(CLAVE_PROY)) || 0;
        const elegido = (j.proyectos.find((p) => p.id === recordado) || j.proyectos[0]).id;
        j = await api.pedir('historias', { params: '&proyecto=' + elegido });
      }
    } catch (err) {
      panel.error = err.red ? 'Sin conexión: no se pudieron traer las historias.' : (err.message || 'No se pudieron traer las historias.');
      panel.cargando = false;
      pintarLista();
      return;
    }
    panel.cargando = false;
    panel.datos = j;
    panel.cuando = Date.now();
    if (j.proyecto) { panel.proyecto = j.proyecto; if (api.CFG.mia) ponerLocal(CLAVE_PROY, j.proyecto); }
    // Un filtro que ya no existe en este proyecto vuelve a «Todos»
    const hs = j.historias || [];
    if (panel.sprint !== '*' && !hs.some((h) => h.sprint === panel.sprint)) panel.sprint = '*';
    if (panel.estado !== '*' && !(j.estados || {})[panel.estado]) panel.estado = '*';
    pintarLista();
  }

  const metaDe = (h) => [h.sprint || 'Backlog', h.estado_txt, h.puntos ? h.puntos + (h.puntos === 1 ? ' pt' : ' pts') : ''].filter(Boolean).join(' · ');
  function filtradas() {
    const hs = panel.datos?.historias || [];
    const q = plano(panel.q).trim();
    return hs.filter((h) => (panel.sprint === '*' || h.sprint === panel.sprint)
      && (panel.estado === '*' || h.estado === panel.estado)
      && (!q || plano(`${h.codigo} ${h.titulo} ${h.como} ${h.quiero} ${h.epica}`).includes(q)));
  }
  const enPizarra = () => new Set(api.vivos().filter((e) => e.tipo === 'hu' && e.hu?.id).map((e) => Number(e.hu.id)));

  function pintarLista() {
    if (!panel.el || !panel.armado) return;
    const lista = $p('[data-hp-lista]'), sub = $p('[data-hp-sub]'), d = panel.datos;
    const proy = $p('[data-hp="proyecto"]');
    const nombreProy = api.CFG.mia ? ((d?.proyectos || []).find((p) => p.id === d?.proyecto)?.nombre || '') : '';
    proy.hidden = !api.CFG.mia || !(d?.proyectos || []).length;
    proy.querySelector('[data-hp-txt]').textContent = nombreProy || 'Elige un proyecto';
    const fSprint = $p('[data-hp="sprint"]'), fEstado = $p('[data-hp="estado"]');
    fSprint.querySelector('[data-hp-txt]').textContent = panel.sprint === '*' ? 'Todos los sprints' : (panel.sprint || 'Backlog');
    fSprint.classList.toggle('is-activa', panel.sprint !== '*');
    fEstado.querySelector('[data-hp-txt]').textContent = panel.estado === '*' ? 'Todos los estados' : ((d?.estados || {})[panel.estado] || panel.estado);
    fEstado.classList.toggle('is-activa', panel.estado !== '*');

    const vacio = (titulo, texto, extra = '') => `<div class="pz-hu-vacio">${ilu('biblioteca', 'pz-hu-vacio__ilu')}<b>${escHtml(titulo)}</b><span>${texto}</span>${extra}</div>`;
    const blanco = '<button type="button" class="pz-hu-vacio__b" data-hp="blanco">Escribir una desde cero</button>';
    if (panel.error) {
      sub.textContent = 'Algo no salió bien';
      lista.innerHTML = vacio('No se pudieron traer', escHtml(panel.error), '<button type="button" class="pz-hu-vacio__b" data-hp="reintentar">Reintentar</button>');
      return;
    }
    if (!d) { sub.textContent = 'Cargando…'; lista.innerHTML = '<div class="pz-hu-cargando" aria-label="Cargando"><i></i><i></i><i></i></div>'; return; }
    const hs = d.historias || [];
    sub.textContent = (nombreProy ? nombreProy + ' · ' : '') + (hs.length === 1 ? '1 historia' : hs.length + ' historias');
    if (d.sin_modulo) { lista.innerHTML = vacio('Sin el módulo de Sprints', 'Este panel todavía no tiene historias de usuario, pero puedes escribir una aquí.', blanco); return; }
    if (api.CFG.mia && !(d.proyectos || []).length) { lista.innerHTML = vacio('No hay proyectos a la vista', 'Cuando tengas un proyecto, sus historias aparecen aquí.', blanco); return; }
    if (!hs.length) {
      const pid = d.proyecto || api.CFG.proyecto;
      lista.innerHTML = vacio('Todavía no hay historias', 'Se escriben en la pestaña Sprints del proyecto. También puedes escribir o pegar una aquí.',
        blanco + (pid ? `<a class="pz-hu-vacio__b pz-hu-vacio__b--suave" href="proyecto.php?id=${Number(pid)}#vista-sprints" target="_blank" rel="noopener">Ir a Sprints</a>` : ''));
      return;
    }
    const fs = filtradas();
    if (!fs.length) { lista.innerHTML = vacio('Ninguna coincide', 'Prueba con otra palabra o quita los filtros.', '<button type="button" class="pz-hu-vacio__b" data-hp="limpiar">Quitar filtros</button>'); return; }
    const ya = enPizarra();
    lista.innerHTML = fs.map((h) => {
      const actor = detectarActor(h.como, `${h.titulo} ${h.quiero}`);
      return `<button type="button" class="pz-hu-carta" role="listitem" data-h="${Number(h.id)}" aria-label="${escHtml(`${h.codigo} ${h.titulo}. Arrástrala o pulsa para ponerla en la pizarra`)}">`
        + ilu(actor, 'pz-hu-carta__ilu')
        + '<span class="pz-hu-carta__txt">'
        + `<span class="pz-hu-carta__fila">${h.codigo ? `<b class="pz-hu-carta__cod">${escHtml(h.codigo)}</b>` : ''}<span class="pz-trunca">${escHtml(metaDe(h))}</span></span>`
        + `<b class="pz-hu-carta__t">${escHtml(h.titulo || 'Sin título')}</b>`
        + (h.como ? `<small class="pz-trunca">Como ${escHtml(limpiarComo(h.como).toLowerCase())}</small>` : '')
        + '</span>'
        + (ya.has(Number(h.id)) ? `<span class="pz-hu-carta__ya" data-tip="Ya está en la pizarra: pulsa para ir a ella">${icoApi('ds-check')}</span>` : '')
        + '</button>';
    }).join('');
  }

  function menuSprint(b) {
    const hs = panel.datos?.historias || [];
    const sprints = [...new Set(hs.map((h) => h.sprint).filter(Boolean))].sort();
    const items = [{ v: '*', t: 'Todos los sprints' }];
    if (hs.some((h) => !h.sprint)) items.push({ v: '', t: 'Backlog', d: 'Sin sprint todavía' });
    sprints.forEach((s) => { const c = hs.filter((h) => h.sprint === s).length; items.push({ v: s, t: s, d: c === 1 ? '1 historia' : `${c} historias` }); });
    items.forEach((it) => { if (it.v === panel.sprint) it.on = true; });
    abrirMenu(b, { titulo: 'Sprint', items, alElegir: (it) => { panel.sprint = it.v; pintarLista(); } });
  }
  function menuEstado(b) {
    const est = panel.datos?.estados || {};
    const items = [{ v: '*', t: 'Todos los estados' }].concat(Object.entries(est).map(([v, t]) => ({ v, t })));
    items.forEach((it) => { if (it.v === panel.estado) it.on = true; });
    abrirMenu(b, { titulo: 'Estado', items, alElegir: (it) => { panel.estado = it.v; pintarLista(); } });
  }
  function menuProyecto(b) {
    const items = (panel.datos?.proyectos || []).map((p) => ({ v: p.id, t: p.nombre, on: p.id === panel.datos?.proyecto }));
    abrirMenu(b, { titulo: 'Proyecto', items, alElegir: (it) => {
      panel.proyecto = it.v; ponerLocal(CLAVE_PROY, it.v); panel.datos = null; panel.sprint = '*'; panel.estado = '*'; cargar(true);
    } });
  }

  /* ----- Arrastrar una historia al lienzo (o clic: al centro de lo que se ve) ----- */
  let arrastre = null, suprimirClic = false;
  const historiaDe = (id) => (panel.datos?.historias || []).find((h) => Number(h.id) === Number(id));
  function alBajarCarta(e) {
    const c = e.target.closest('.pz-hu-carta');
    if (!c || e.button !== 0) return;
    arrastre = { c, id: Number(c.dataset.h), sx: e.clientX, sy: e.clientY, pid: e.pointerId, movido: false, fantasma: null };
    c.addEventListener('pointermove', alMoverCarta);
    c.addEventListener('pointerup', alSoltarCarta);
    c.addEventListener('pointercancel', cancelarArrastre);
  }
  function alMoverCarta(e) {
    const a = arrastre;
    if (!a || e.pointerId !== a.pid) return;
    if (!a.movido) {
      if (Math.hypot(e.clientX - a.sx, e.clientY - a.sy) < 6) return;
      a.movido = true;
      try { a.c.setPointerCapture(a.pid); } catch (err) { /* nada */ }
      const h = historiaDe(a.id);
      const f = document.createElement('div');
      f.className = 'pz-hu-fantasma';
      f.innerHTML = ilu(detectarActor(h?.como, `${h?.titulo} ${h?.quiero}`), 'pz-hu-carta__ilu')
        + `<span><b>${escHtml(h?.codigo || 'Historia')}</b>${escHtml(corta(h?.titulo || '', 60))}</span>`;
      api.raiz.appendChild(f);
      a.fantasma = f;
      panel.el.classList.add('is-arrastrando');
    }
    a.fantasma.style.transform = `translate(${Math.round(e.clientX + 14)}px,${Math.round(e.clientY + 10)}px)`;
    const sobre = document.elementFromPoint(e.clientX, e.clientY);
    const ok = !!sobre && api.lienzo.contains(sobre);
    a.fantasma.classList.toggle('is-fuera', !ok);
    api.raiz.classList.toggle('is-soltando', ok);
  }
  function terminarArrastre() {
    const a = arrastre;
    if (!a) return null;
    arrastre = null;
    a.c.removeEventListener('pointermove', alMoverCarta);
    a.c.removeEventListener('pointerup', alSoltarCarta);
    a.c.removeEventListener('pointercancel', cancelarArrastre);
    a.fantasma?.remove();
    panel.el.classList.remove('is-arrastrando');
    api.raiz.classList.remove('is-soltando');
    return a;
  }
  const cancelarArrastre = () => { const a = terminarArrastre(); if (a?.movido) suprimirClic = true; };
  function alSoltarCarta(e) {
    const a = terminarArrastre();
    if (!a || !a.movido) return;
    suprimirClic = true;
    const sobre = document.elementFromPoint(e.clientX, e.clientY);
    if (!sobre || !api.lienzo.contains(sobre)) return;   // se soltó en el panel o en una barra: nada
    ponerHistoria(a.id, { x: e.clientX, y: e.clientY });
  }
  function alClicCarta(e) {
    const c = e.target.closest('.pz-hu-carta');
    if (!c) return;
    if (suprimirClic) { suprimirClic = false; return; }
    const id = Number(c.dataset.h);
    // Si ya está, se va a ella; para otra copia, se arrastra
    const ya = api.vivos().find((x) => x.tipo === 'hu' && Number(x.hu?.id) === id);
    if (ya) { api.elegir([ya.id]); api.ajustarA([ya.id], areaLibre()); api.toast('Ya estaba en la pizarra. Para otra copia, arrástrala.'); return; }
    ponerHistoria(id, null);
  }
  /** El hueco de la pantalla entre el panel de historias y el del texto (conTexto: el texto se va a abrir). */
  function areaLibre(conTexto = leerLocal(CLAVE_ED) !== 0) {
    const W = window.innerWidth;
    if (W < 760) return { izq: 16, der: W - 16 };
    const izqEl = [panel.el, ilus.el].find((x) => x && !x.hidden);
    const r = izqEl ? izqEl.getBoundingClientRect() : null;
    const ed = editor.el && !editor.el.hidden ? editor.el.getBoundingClientRect().left : (conTexto ? W - 12 - Math.min(420, W - 24) : W);
    return { izq: (r ? r.right : 0) + 24, der: ed - 24 };
  }
  const centroLibre = () => { const a = areaLibre(); return api.aMundo((a.izq + a.der) / 2, window.innerHeight / 2); };
  function ponerHistoria(id, pantalla) {
    const h = historiaDe(id);
    if (!h) return;
    const id2 = api.crear(datosDe(h), pantalla ? api.aMundo(pantalla.x, pantalla.y) : centroLibre(), pantalla ? {} : { centro: true, ajustar: true, libre: areaLibre() });
    pintarLista();
    if (id2 && leerLocal(CLAVE_ED) !== 0) abrirEditor(id2);
    api.toast(`Listo: el diagrama de ${h.codigo || 'la historia'} está en la pizarra. Su texto, al lado; ▶ lo recorre paso a paso.`);
  }
  /** Un diagrama en blanco con un ejemplo, listo para escribir o pegar encima. */
  function enBlanco(texto, p) {
    const id = api.crear(datosDeTexto(texto || PLANTILLA), p || centroLibre(), { centro: true, ajustar: !p, libre: areaLibre(true) });
    if (!id) return null;
    ponerLocal(CLAVE_ED, 1);
    abrirEditor(id, { foco: true, seleccionar: !texto });
    api.toast(texto ? 'Listo: la historia que pegaste ya es un diagrama. Su texto está al lado.' : 'Escribe o pega encima la historia: el diagrama se dibuja solo.');
    return id;
  }

  /* =========================================================
     Ilustraciones sueltas (panel «Ilustraciones», tecla D)
     Para anotar con dibujos: con un clic se pone una en el centro de lo que se
     ve; arrastrándola, donde se suelte. En el lienzo es un elemento «ilu»
     (pizarra.js lo pinta, lo mueve, lo agranda en proporción y le pega
     flechas) que guarda solo su clave, su pie de texto y su color de fondo.
     ========================================================= */
  const ilus = { el: null, cat: '', q: '', ultimo: null };
  const svgIlu = (k) => ILU[k] || ILU.persona;
  const tinteIlu = (k) => TINTE[k] || 'celeste';
  const nombreIlu = (k) => (CATALOGO.find((c) => c[0] === k) || [k, 'Ilustración'])[1];
  const $i = (q) => ilus.el.querySelector(q);

  function armarIlus() {
    if (ilus.el || !api) return;
    const a = document.createElement('aside');
    a.className = 'pz-hu-panel pz-ilu-panel pz-vidrio';
    a.id = 'pz-ilu-panel';
    a.hidden = true;
    a.setAttribute('aria-label', 'Ilustraciones');
    a.innerHTML = '<header class="pz-hu-panel__cab">'
      + `<span class="pz-hu-panel__ico" aria-hidden="true">${icoApi('pz-ilustracion')}</span>`
      + '<span class="pz-hu-panel__t"><b>Ilustraciones</b><small>Clic: al centro · arrástrala a su lugar</small></span>'
      + `<button type="button" class="pz-boton pz-boton--icono" data-il="cerrar" data-tip="Cerrar" data-tecla="D" aria-label="Cerrar las ilustraciones">${icoApi('ds-cerrar')}</button></header>`
      + '<div class="pz-hu-panel__filtros">'
      + `<label class="pz-hu-buscar">${icoApi('ds-buscar')}<input type="search" data-il-q placeholder="Buscar: docente, PDF, nube…" autocomplete="off" spellcheck="false" aria-label="Buscar ilustraciones"></label>`
      + '<div class="pz-ilu-cats" role="group" aria-label="Categorías">'
      + [['', 'Todas']].concat(CATEGORIAS).map(([c, t]) => `<button type="button" class="pz-ilu-cat${c === ilus.cat ? ' is-activa' : ''}" data-il-cat="${c}" aria-pressed="${c === ilus.cat}">${escHtml(t)}</button>`).join('')
      + '</div></div>'
      + '<div class="pz-hu-panel__lista pz-ilu-lista" data-il-lista></div>'
      + '<p class="pz-hu-panel__pie">Con doble clic le pones un pie de texto («Docente revisa»). Se une con flechas como cualquier forma.</p>';
    api.raiz.appendChild(a);
    ilus.el = a;
    $i('[data-il-q]').addEventListener('input', (e) => { ilus.q = e.target.value; pintarIlus(); });
    a.addEventListener('click', (e) => {
      const b = e.target.closest('[data-il], [data-il-cat]');
      if (!b) return;
      if (b.dataset.il === 'cerrar') { alternarIlus(false); return; }
      if (b.dataset.ilCat != null) {
        ilus.cat = b.dataset.ilCat;
        a.querySelectorAll('[data-il-cat]').forEach((x) => { const on = x.dataset.ilCat === ilus.cat; x.classList.toggle('is-activa', on); x.setAttribute('aria-pressed', String(on)); });
        pintarIlus();
      }
    });
    a.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); alternarIlus(false); } });
    // Arrastrar una al lienzo (o clic: al centro de lo que se ve)
    let arr = null, suprimir = false;
    const fin = (e, soltar) => {
      const x = arr;
      arr = null;
      if (!x) return;
      x.c.removeEventListener('pointermove', mover);
      x.c.removeEventListener('pointerup', alzar);
      x.c.removeEventListener('pointercancel', cancelar);
      x.f?.remove();
      api.raiz.classList.remove('is-soltando');
      if (!x.movido) return;
      suprimir = true;
      if (!soltar) return;
      const sobre = document.elementFromPoint(e.clientX, e.clientY);
      if (sobre && api.lienzo.contains(sobre)) ponerIlu(x.k, { x: e.clientX, y: e.clientY });
    };
    const mover = (e) => {
      if (!arr || e.pointerId !== arr.pid) return;
      if (!arr.movido) {
        if (Math.hypot(e.clientX - arr.sx, e.clientY - arr.sy) < 6) return;
        arr.movido = true;
        try { arr.c.setPointerCapture(arr.pid); } catch (er) { /* nada */ }
        arr.f = document.createElement('div');
        arr.f.className = 'pz-ilu-fantasma';
        arr.f.innerHTML = ilu(arr.k, 'pz-ilu-fantasma__ilu');
        api.raiz.appendChild(arr.f);
      }
      arr.f.style.transform = `translate(${Math.round(e.clientX - 36)}px,${Math.round(e.clientY - 36)}px)`;
      const sobre = document.elementFromPoint(e.clientX, e.clientY), ok = !!sobre && api.lienzo.contains(sobre);
      arr.f.classList.toggle('is-fuera', !ok);
      api.raiz.classList.toggle('is-soltando', ok);
    };
    const alzar = (e) => fin(e, true), cancelar = (e) => fin(e, false);
    a.addEventListener('pointerdown', (e) => {
      const c = e.target.closest('.pz-ilu-op');
      if (!c || e.button !== 0) return;
      arr = { c, k: c.dataset.ilu, sx: e.clientX, sy: e.clientY, pid: e.pointerId, movido: false, f: null };
      c.addEventListener('pointermove', mover);
      c.addEventListener('pointerup', alzar);
      c.addEventListener('pointercancel', cancelar);
    });
    a.addEventListener('click', (e) => {
      const c = e.target.closest('.pz-ilu-op');
      if (!c) return;
      if (suprimir) { suprimir = false; return; }
      ponerIlu(c.dataset.ilu, null);
    });
  }
  function pintarIlus() {
    const lista = $i('[data-il-lista]'), q = plano(ilus.q).trim();
    const hay = CATALOGO.filter(([k, n, c, w]) => (!ilus.cat || c === ilus.cat) && (!q || plano(`${n} ${w} ${k}`).includes(q)));
    if (!hay.length) { lista.innerHTML = `<div class="pz-hu-vacio">${ilu('buscar', 'pz-hu-vacio__ilu')}<b>Ninguna se llama así</b><span>Prueba con otra palabra: docente, PDF, nube, pago…</span></div>`; return; }
    const op = ([k, n]) => `<button type="button" class="pz-ilu-op" data-ilu="${k}" aria-label="${escHtml(n)}: arrástrala o pulsa para ponerla">${ilu(k, 'pz-ilu-op__ilu')}<span>${escHtml(n)}</span></button>`;
    // Con «Todas» y sin buscar, por grupos con su título; si no, una sola cuadrícula
    lista.innerHTML = !ilus.cat && !q
      ? CATEGORIAS.map(([c, t]) => `<b class="pz-ilu-grupo">${escHtml(t)}</b><div class="pz-ilu-grid">${hay.filter((x) => x[2] === c).map(op).join('')}</div>`).join('')
      : `<div class="pz-ilu-grid">${hay.map(op).join('')}</div>`;
  }
  function alternarIlus(abrir) {
    if (!api?.EDITAR || !api.crearIlu) return;
    if (abrir === undefined) abrir = !ilus.el || ilus.el.hidden;
    if (!abrir && !ilus.el) return;
    armarIlus();
    if (abrir && panel.el && !panel.el.hidden) alternarPanel(false);
    ilus.el.hidden = !abrir;
    const b = api.raiz.querySelector('[data-ilu-panel]');
    b?.classList.toggle('is-abierta', abrir);
    b?.setAttribute('aria-pressed', abrir ? 'true' : 'false');
    if (!abrir) { if (ilus.el.contains(document.activeElement)) api.lienzo.focus({ preventScroll: true }); return; }
    pintarIlus();
    requestAnimationFrame(() => $i('[data-il-q]')?.focus({ preventScroll: true }));
  }
  /** Una ilustración en el lienzo: donde se soltó, o al centro (las que siguen, un poco corridas). */
  function ponerIlu(k, pantalla) {
    if (!ILU[k]) return;
    let sx, sy;
    if (pantalla) { sx = pantalla.x; sy = pantalla.y; ilus.ultimo = null; }
    else {
      const a = areaLibre(false), ahora = Date.now(), u = ilus.ultimo;
      ilus.ultimo = u && ahora - u.t < 8000 ? { n: u.n + 1, t: ahora } : { n: 0, t: ahora };
      // Las que siguen, en fila (4 por fila) para que no queden una encima de otra
      const n = ilus.ultimo.n % 12;
      sx = (a.izq + a.der) / 2 + ((n % 4) - 1.5) * 170; sy = window.innerHeight / 2 + (Math.floor(n / 4) - 0.5) * 190;
    }
    api.crearIlu(k, api.aMundo(sx, sy));
  }

  /* =========================================================
     Exportar el PNG: el diagrama se dibuja en el canvas tal como se ve
     (las medidas salen del propio nodo), en tema claro.
     ========================================================= */
  const IMG = {};
  function imagen(k) {
    if (!IMG[k]) {
      const i = new Image();
      i.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent((ILU[k] || ILU.tarea).replace('<svg ', '<svg width="192" height="192" '));
      IMG[k] = i;
    }
    return IMG[k];
  }
  function precargar(siempre = false) {
    const hay = siempre || (api ? api.vivos().some((e) => e.tipo === 'hu' || e.tipo === 'ilu') : false);
    if (!hay) return Promise.resolve();
    return Promise.all(Object.keys(ILU).map((k) => { const i = imagen(k); return i.complete ? null : (i.decode ? i.decode().catch(() => {}) : null); }));
  }
  function dibujar(x, el, g, n, h) {
    if (!n) return;
    const caja = (nodo) => { const p = posEn(nodo, n); return { x: g.x + p.x + n.clientLeft, y: g.y + p.y + n.clientTop, w: p.w, h: p.h }; };
    const texto = (nodo, o) => {
      if (!nodo || !nodo.offsetWidth) return;
      const c = caja(nodo), cs = getComputedStyle(nodo);
      const fs = parseFloat(cs.fontSize) || 14, lh = (parseFloat(cs.lineHeight) || fs * 1.3) / fs;
      x.font = `${o.peso || cs.fontWeight || 500} ${fs}px ${h.FUENTE}`;
      x.fillStyle = o.color || '#2D3E50';
      x.textAlign = 'left'; x.textBaseline = 'middle';
      const pl = parseFloat(cs.paddingLeft) || 0, pt = parseFloat(cs.paddingTop) || 0;
      let t = limpio(o.texto ?? nodo.textContent);
      if (cs.textTransform === 'uppercase') t = t.toLocaleUpperCase('es');
      let ls = h.envolver(x, t, c.w - pl * 2 + 1);
      const max = Math.max(1, Math.round((c.h - pt * 2) / (fs * lh)));
      if (ls.length > max) { ls = ls.slice(0, max); ls[max - 1] = ls[max - 1].replace(/\s*\S*$/, '') + '…'; }
      // Lo de una sola línea en una pastilla (código, escenarios), centrado de alto
      if (o.centro && ls.length === 1) { x.fillText(ls[0], c.x + pl, c.y + c.h / 2 + 0.5); return; }
      const centrado = cs.textAlign === 'center';   // el nombre del actor, bajo su dibujo
      if (centrado) x.textAlign = 'center';
      ls.forEach((l, i) => x.fillText(l, centrado ? c.x + c.w / 2 : c.x + pl, c.y + pt + fs * lh * (i + 0.5)));
    };
    const fondo = (nodo, color, radio, borde) => {
      if (!nodo || !nodo.offsetWidth) return;
      const c = caja(nodo);
      h.rr(x, c.x, c.y, c.w, c.h, Math.min(radio, c.w / 2, c.h / 2));
      x.fillStyle = color; x.fill();
      if (borde) { x.lineWidth = 1; x.strokeStyle = borde; x.stroke(); }
    };
    const dibujo = (nodo, radio) => {
      if (!nodo || !nodo.offsetWidth) return;
      const c = caja(nodo), k = ILU[nodo.dataset.ilu] ? nodo.dataset.ilu : 'tarea';
      x.save();
      h.rr(x, c.x, c.y, c.w, c.h, radio === 'circulo' ? c.w / 2 : radio);
      x.fillStyle = PAPEL[nodo.dataset.color] || PAPEL.celeste; x.fill(); x.clip();
      const i = imagen(k), mm = c.w * (radio === 'circulo' ? 0.04 : 0.12);
      if (i.complete && i.naturalWidth) x.drawImage(i, c.x + mm, c.y + mm, c.w - mm * 2, c.h - mm * 2);
      x.restore();
    };
    x.save();
    h.rr(x, g.x, g.y, g.w, g.h, 22); x.fillStyle = '#FFFFFF'; x.fill(); x.lineWidth = 1; x.strokeStyle = '#DFE9F0'; x.stroke();
    const cod = n.querySelector('.pz-hu__codigo');
    fondo(cod, '#E0F2FE', 8); texto(cod, { color: '#0369A1', peso: 750, centro: true });
    texto(n.querySelector('.pz-hu__titulo'), { color: '#2D3E50', peso: 750 });
    texto(n.querySelector('.pz-hu__meta'), { color: '#5A6B7E', peso: 600 });
    texto(n.querySelector('.pz-hu__formal'), { color: '#5A6B7E', peso: 450 });
    const boc = n.querySelector('.pz-hu__bocadillo');
    if (boc) { fondo(boc, '#EEF8FE', 16, '#CDEBFB'); texto(boc, { color: '#1F2A37', peso: 600 }); }
    n.querySelectorAll('.pz-hu__esc').forEach((b) => {
      const on = b.classList.contains('is-activa'), nn = b.querySelector('span')?.textContent, nom = b.querySelector('em')?.textContent ?? b.textContent;
      fondo(b, on ? '#E0F2FE' : '#F1F5F9', 999);
      texto(b, { color: on ? '#0369A1' : '#5A6B7E', peso: 650, centro: true, texto: nn ? `${nn} · ${nom}` : nom });
    });
    const actor = n.querySelector('.pz-hu__actor');
    if (actor) {
      dibujo(actor.querySelector('.pz-hu__ilu'), 'circulo');
      texto(actor.querySelector('.pz-hu__anombre'), { color: '#2D3E50', peso: 750 });
      texto(actor.querySelector('small'), { color: '#7A8898', peso: 600 });
    }
    n.querySelectorAll('.pz-hu__flecha').forEach((f) => {
      const c = caja(f), cy = c.y + c.h / 2;
      x.strokeStyle = '#94A3B8'; x.lineWidth = 2.4; x.lineCap = 'round'; x.lineJoin = 'round';
      x.beginPath(); x.moveTo(c.x + 3, cy); x.lineTo(c.x + c.w - 6, cy); x.moveTo(c.x + c.w - 13, cy - 7); x.lineTo(c.x + c.w - 6, cy); x.lineTo(c.x + c.w - 13, cy + 7); x.stroke();
    });
    n.querySelectorAll('.pz-hu__caso').forEach((c) => texto(c, { color: '#5A6B7E', peso: 700, centro: true, texto: `${c.querySelector('.pz-hu__caso-n')?.textContent || ''} · ${c.querySelector('.pz-campo')?.textContent || ''}` }));
    n.querySelectorAll('.pz-hu__paso').forEach((p) => {
      fondo(p, '#F7FAFC', 14);
      const nu = p.querySelector('.pz-hu__num');
      if (nu) {
        const c = caja(nu);
        x.beginPath(); x.arc(c.x + c.w / 2, c.y + c.h / 2, c.w / 2, 0, Math.PI * 2); x.fillStyle = '#E0F2FE'; x.fill();
        x.font = `750 13px ${h.FUENTE}`; x.fillStyle = '#0369A1'; x.textAlign = 'center'; x.textBaseline = 'middle';
        x.fillText(nu.querySelector('i')?.textContent || '', c.x + c.w / 2, c.y + c.h / 2 + 0.5);
      }
      dibujo(p.querySelector('.pz-hu__pico'), 12);
      p.querySelectorAll('.pz-hu__si').forEach((s) => texto(s, { color: '#7A8898', peso: 550 }));
      texto(p.querySelector('.pz-hu__pt'), { color: '#1F2A37', peso: 600 });
      p.querySelectorAll('.pz-hu__det').forEach((d) => texto(d, { color: '#5A6B7E', peso: 500, texto: '→ ' + d.textContent }));
    });
    n.querySelectorAll('.pz-hu__pista').forEach((p) => texto(p, { color: '#7A8898', peso: 500 }));
    texto(n.querySelector('.pz-hu__rt'), { color: '#7A8898', peso: 750 });
    n.querySelectorAll('.pz-hu__r').forEach((r) => {
      fondo(r, '#F7FAFC', 14, '#E7EEF4');
      dibujo(r.querySelector('.pz-hu__rilu'), 12);
      texto(r.querySelector('.pz-campo'), { color: '#2D3E50', peso: 700 });
    });
    const para = n.querySelector('.pz-hu__para');
    if (para) { fondo(para, '#EEFBF3', 14); texto(para.querySelector('b'), { color: '#5A6B7E', peso: 750 }); texto(para.querySelector('span'), { color: '#2D3E50', peso: 500 }); }
    x.restore();
  }

  /* =========================================================
     Arranque (lo llama pizarra.js con lo que presta)
     ========================================================= */
  function montar(a) {
    api = a;
    panel.el = document.getElementById('pz-hu-panel');
    // Los botones del diagrama: ▶ y los escenarios valen también para quien solo mira
    api.lienzo.addEventListener('click', (e) => {
      const b = e.target.closest('[data-hu], [data-hu-esc]');
      if (!b) return;
      const n = b.closest('.pz-hu'), el = n ? api.vivo(n.dataset.id) : null;
      if (!el) return;
      if (b.dataset.huEsc != null) { elegirEscenario(el, n, Number(b.dataset.huEsc)); return; }
      const acc = b.dataset.hu;
      if (acc === 'play') { alternarPlay(el, n); return; }
      if (!editable(el)) return;
      if (acc === 'paso') agregarPaso(el);
      else if (acc === 'escenario') agregarEscenario(el);
      else if (acc === 'quitar') quitarPaso(el, Number(b.dataset.l), b.dataset.parte || '');
      else if (acc === 'quitar-esc') quitarEscenario(el, Number(b.dataset.i));
    });
  }
  /**
   * Ya cargada la pizarra: si se abrió con &hu=ID («Generar diagrama» en la historia),
   * el diagrama de esa historia. Si ya está, se va a él y queda elegido (no se duplica);
   * si no, se arma como al arrastrarla, centrado. El &hu= sale de la dirección para que
   * al recargar no se repita. pizarra.php solo lo manda si quien entra ve su proyecto.
   */
  function alCargar() {
    const h = api?.CFG.hu?.abrir;
    try {
      const u = new URL(location.href);
      if (u.searchParams.has('hu')) { u.searchParams.delete('hu'); history.replaceState(history.state, '', u.pathname + u.search + u.hash); }
    } catch (e) { /* sin history: no pasa nada */ }
    if (!h || !h.id || !api.EDITAR) return;
    api.CFG.hu.abrir = null;
    const ya = api.vivos().find((x) => x.tipo === 'hu' && Number(x.hu?.id) === Number(h.id));
    if (ya) {
      api.elegir([ya.id]);
      api.ajustarA([ya.id], areaLibre());
      api.toast(`El diagrama de ${h.codigo || 'la historia'} ya estaba aquí: es este.`);
      return;
    }
    const id = api.crear(datosDe(h), centroLibre(), { centro: true, ajustar: true, libre: areaLibre() });
    if (id && leerLocal(CLAVE_ED) !== 0) abrirEditor(id);
    api.toast(`Listo: el diagrama de ${h.codigo || 'la historia'} está en tu pizarra. Su texto, al lado; ▶ lo recorre paso a paso.`);
  }
  /** Escape: primero el menú, después el panel. Devuelve si hizo algo. */
  function escape() {
    if (cerrarMenu(true)) return true;
    if (panel.el && !panel.el.hidden) { alternarPanel(false); return true; }
    if (ilus.el && !ilus.el.hidden) { alternarIlus(false); return true; }
    return false;
  }

  /* =========================================================
     Visor de solo lectura (fuera de la pizarra: historia.php)
     El mismo diagrama, vivo: ▶ Reproducir y los escenarios. Sin escribir, mover,
     el texto ni los menús. Sin pizarra, se presta lo mínimo (los íconos los deja
     PizarraHU::vista en un <template id="pz-hu-iconos">).
     ========================================================= */
  const VISTAS = new Map();
  let nVista = 0;
  function apiVista() {
    const ICO = {};
    document.getElementById('pz-hu-iconos')?.content.querySelectorAll('[data-ico]').forEach((x) => { ICO[x.dataset.ico] = x.innerHTML; });
    return {
      EDITAR: false, YO: 0, CFG: { mia: true, hu: {} }, raiz: document.body, lienzo: document.body,
      ico: (k) => ICO[k] || '', bloqueado: () => false, vivos: () => [], vivo: (id) => VISTAS.get(id)?.el || null,
      nodo: (id) => VISTAS.get(id)?.n || null, pintar: (el) => { const v = VISTAS.get(el.id); if (v) pintar(v.el, v.n); },
      toast() {}, cambiar() {}, sinHistoria() {}, editar() {}, elegir() {}, ajustarA() {}, refrescarCtx() {}, retomar() {},
    };
  }
  /**
   * Arma en «cont» el diagrama de «texto», solo para ver. op.historia: los campos de la
   * historia, para armarlo como al arrastrarla si el texto viene vacío.
   * Devuelve {el, nodo, reproducir()}.
   */
  function vista(cont, texto, op = {}) {
    if (!cont) return null;
    if (!api) api = apiVista();
    const t = limpio(texto) ? String(texto) : (op.historia ? textoDe(op.historia) : '');
    const el = { id: 'pz-hu-vista-' + (++nVista), tipo: 'hu', texto: t, hu: null, esc: -1, rep: 0, _soloVista: true };
    const n = document.createElement('div');
    n.className = 'pz-hu pz-hu--vista';
    n.dataset.id = el.id;
    cont.classList.add('pz-hu-vista');
    cont.querySelectorAll(':scope > :not(script)').forEach((x) => x.remove());
    cont.appendChild(n);
    VISTAS.set(el.id, { el, n });
    pintar(el, n);
    // ▶ y los escenarios (lo único que se toca: no cambia nada)
    n.addEventListener('click', (e) => {
      const b = e.target.closest('[data-hu], [data-hu-esc]');
      if (!b) return;
      if (b.dataset.huEsc != null) elegirEscenario(el, n, Number(b.dataset.huEsc));
      else if (b.dataset.hu === 'play') alternarPlay(el, n);
    });
    return { el, nodo: n, reproducir: () => reproducir(n) };
  }
  // Los visores que dejó la página (PizarraHU::vista): se arman al cargar
  function armarVistas() {
    document.querySelectorAll('[data-pz-hu-vista]:not([data-armado])').forEach((cont) => {
      cont.dataset.armado = '1';
      let d = {};
      try { d = JSON.parse(cont.querySelector(':scope > script[type="application/json"]')?.textContent || '{}'); } catch (e) { d = {}; }
      vista(cont, d.texto || '', { historia: d.historia || null });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', armarVistas);
  else armarVistas();

  window.PizarraHU = {
    vista, armarVistas,
    ILU, ACTORES, Texto, montar, pintar, reproducir, detener, botonesCtx, accionCtx, ponerCampo, alTerminar, alElegir, ocupado,
    alternarPanel, escape, precargar, dibujar, alternarIlus, svgIlu, tinteIlu, nombreIlu, imagen, CATALOGO, cerrarMenu, pareceHistoria, enBlanco, alCargar,
    // Para las pruebas: lo que se detecta y lo que se dibuja, sin pizarra
    imagenDe,
    _prueba: { parse, serializar, vistaDe, narrar, fraseAuto, textoDe, tercera, partirQuiero, detectarActor, detectarRes, resultadosDe, pasosDe },
  };
})();
