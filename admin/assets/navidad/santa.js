/* =========================================================
   Papá Noel en 3D (three.js r187, ./three.min.js): aparece de la nada en una
   esquina, saluda con la mano y se va. Lo carga navidad.js recién la primera
   vez que va a salir y solo dibuja mientras está en pantalla (unos 5 s), así
   no gasta nada el resto del tiempo.
   ========================================================= */
import * as THREE from './three.min.js';

const { RoundedBoxGeometry } = THREE;
let escena = null;

const mat = (hex, o = {}) => new THREE.MeshPhysicalMaterial({ color: hex, roughness: 0.42, metalness: 0, clearcoat: 0.35, clearcoatRoughness: 0.3, ...o });
const piel = () => mat(0xf3c7a1, { roughness: 0.55, clearcoat: 0.1 });
const felpa = () => mat(0xfbfbfb, { roughness: 0.9, clearcoat: 0 });
const malla = (g, m, x = 0, y = 0, z = 0) => { const o = new THREE.Mesh(g, m); o.position.set(x, y, z); return o; };

function armarSanta() {
  const santa = new THREE.Group();
  const rojo = mat(0xc2333a);
  // El cuerpo, el ribete de felpa y el cinturón con su hebilla dorada
  const cuerpo = malla(new THREE.SphereGeometry(1, 48, 32), rojo, 0, 0, 0);
  cuerpo.scale.set(1, 1.08, 0.94);
  santa.add(cuerpo);
  const ribete = malla(new THREE.TorusGeometry(0.86, 0.17, 18, 56), felpa(), 0, -0.72, 0);
  ribete.rotation.x = Math.PI / 2;
  santa.add(ribete);
  santa.add(malla(new THREE.CylinderGeometry(1.0, 1.02, 0.22, 56), mat(0x1c1c22, { roughness: 0.35 }), 0, -0.12, 0));
  santa.add(malla(new RoundedBoxGeometry(0.4, 0.32, 0.1, 3, 0.05), mat(0xe2b23a, { metalness: 0.85, roughness: 0.25 }), 0, -0.12, 0.98));
  santa.add(malla(new RoundedBoxGeometry(0.2, 0.14, 0.12, 2, 0.03), mat(0x1c1c22), 0, -0.12, 1.0));
  const franja = malla(new THREE.CapsuleGeometry(0.09, 0.6, 6, 12), felpa(), 0, 0.42, 0.9);
  franja.rotation.x = -0.32;
  santa.add(franja);

  // La cabeza: cara, mejillas, nariz, ojos con brillo, cejas, bigote y barba
  const cabeza = new THREE.Group();
  cabeza.position.set(0, 1.36, 0);
  cabeza.add(malla(new THREE.SphereGeometry(0.6, 40, 28), piel()));
  for (const s of [-1, 1]) {
    cabeza.add(malla(new THREE.SphereGeometry(0.12, 18, 12), mat(0xf29a96, { roughness: 0.6, clearcoat: 0 }), s * 0.3, -0.04, 0.48));
    cabeza.add(malla(new THREE.SphereGeometry(0.065, 16, 12), mat(0x1b1b1f, { roughness: 0.2 }), s * 0.19, 0.12, 0.53));
    cabeza.add(malla(new THREE.SphereGeometry(0.022, 8, 8), new THREE.MeshBasicMaterial({ color: 0xffffff }), s * 0.19 + 0.02, 0.15, 0.59));
    const ceja = malla(new THREE.CapsuleGeometry(0.035, 0.14, 4, 8), felpa(), s * 0.2, 0.26, 0.5);
    ceja.rotation.z = Math.PI / 2 + s * 0.18;
    cabeza.add(ceja);
    const bigote = malla(new THREE.CapsuleGeometry(0.08, 0.22, 6, 12), felpa(), s * 0.15, -0.12, 0.55);
    bigote.rotation.z = Math.PI / 2 - s * 0.35;
    cabeza.add(bigote);
  }
  cabeza.add(malla(new THREE.SphereGeometry(0.1, 20, 14), mat(0xe8908a, { roughness: 0.5 }), 0, 0.0, 0.6));
  const barba = malla(new THREE.SphereGeometry(0.58, 36, 24), felpa(), 0, -0.36, 0.2);
  barba.scale.set(1, 0.92, 0.7);
  cabeza.add(barba);
  const punta = malla(new THREE.ConeGeometry(0.42, 0.6, 30), felpa(), 0, -0.82, 0.24);
  punta.rotation.x = Math.PI;
  cabeza.add(punta);
  // El gorro: el cono doblado hacia un lado, el ribete y el pompón
  const gorro = new THREE.Group();
  gorro.position.set(0, 0.38, 0);
  const ala = malla(new THREE.TorusGeometry(0.56, 0.15, 18, 48), felpa(), 0, 0, 0);
  ala.rotation.x = Math.PI / 2 - 0.12;
  gorro.add(ala);
  const cono = malla(new THREE.ConeGeometry(0.56, 1.05, 40), rojo, 0.12, 0.5, -0.04);
  cono.rotation.z = -0.42;
  gorro.add(cono);
  gorro.add(malla(new THREE.SphereGeometry(0.17, 24, 16), felpa(), 0.38, 0.98, -0.06));
  cabeza.add(gorro);
  santa.add(cabeza);

  // Los brazos (con su hombro de pivote), puños de felpa y mitones verdes
  const brazo = (lado) => {
    const hombro = new THREE.Group();
    hombro.position.set(lado * 0.86, 0.5, 0);
    const manga = malla(new THREE.CapsuleGeometry(0.21, 0.62, 8, 16), rojo, 0, -0.42, 0);
    hombro.add(manga);
    const puno = malla(new THREE.TorusGeometry(0.2, 0.08, 12, 32), felpa(), 0, -0.86, 0);
    puno.rotation.x = Math.PI / 2;
    hombro.add(puno);
    hombro.add(malla(new THREE.SphereGeometry(0.21, 24, 16), mat(0x1f7a4d), 0, -1.02, 0));
    hombro.rotation.z = lado * 0.32;
    return hombro;
  };
  const izq = brazo(-1);
  const der = brazo(1);
  // En la mano izquierda, un regalo azul InnoTech con lazo dorado
  const regalo = new THREE.Group();
  regalo.position.set(0.05, -1.25, 0.3);
  regalo.add(malla(new RoundedBoxGeometry(0.46, 0.4, 0.46, 3, 0.05), mat(0x2b76f7, { roughness: 0.3 })));
  const oro = mat(0xe2b23a, { metalness: 0.8, roughness: 0.25 });
  regalo.add(malla(new THREE.BoxGeometry(0.48, 0.42, 0.08), oro));
  regalo.add(malla(new THREE.BoxGeometry(0.08, 0.42, 0.48), oro));
  for (const s of [-1, 1]) {
    const lazo = malla(new THREE.TorusGeometry(0.09, 0.035, 10, 24), oro, s * 0.09, 0.25, 0);
    lazo.rotation.y = Math.PI / 2;
    lazo.rotation.z = s * 0.6;
    regalo.add(lazo);
  }
  izq.add(regalo);
  santa.add(izq, der);
  // Las botas, asomando bajo el abrigo
  for (const s of [-1, 1]) {
    const bota = malla(new THREE.SphereGeometry(0.3, 24, 16), mat(0x1c1c22, { roughness: 0.3 }), s * 0.42, -1.12, 0.18);
    bota.scale.set(1, 0.7, 1.35);
    santa.add(bota);
  }
  return { santa, cabeza, der, izq };
}

function crearEscena() {
  const lienzo = document.createElement('canvas');
  lienzo.className = 'nv-santa';
  lienzo.setAttribute('aria-hidden', 'true');
  const globo = document.createElement('div');
  globo.className = 'nv-santa-globo';
  globo.setAttribute('aria-hidden', 'true');
  globo.textContent = '¡Jo, jo, jo!';
  document.body.append(lienzo, globo);

  let renderer;
  try {
    renderer = new THREE.WebGLRenderer({ canvas: lienzo, alpha: true, antialias: true, powerPreference: 'low-power' });
  } catch (err) {
    // Sin WebGL (equipo viejo o desactivado): se quita lo que se puso y no se vuelve a intentar
    lienzo.remove(); globo.remove();
    throw err;
  }
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  renderer.toneMapping = THREE.NeutralToneMapping;
  const scene = new THREE.Scene();
  const camara = new THREE.PerspectiveCamera(30, 1, 0.1, 50);
  camara.position.set(0, 0.9, 10.5);
  camara.lookAt(0, 0.75, 0);
  scene.add(new THREE.HemisphereLight(0xffffff, 0x8a9ab0, 1.15));
  const sol = new THREE.DirectionalLight(0xffffff, 1.7);
  sol.position.set(3, 5, 5);
  scene.add(sol);
  const contraluz = new THREE.DirectionalLight(0xffd27a, 0.9);
  contraluz.position.set(-4, 3, -3);
  scene.add(contraluz);
  const partes = armarSanta();
  scene.add(partes.santa);
  return { lienzo, globo, renderer, scene, camara, ...partes };
}

const facil = {
  atras: (t) => { const c = 1.7; return 1 + (c + 1) * Math.pow(t - 1, 3) + c * Math.pow(t - 1, 2); },
  entra: (t) => { const c = 1.5; return (c + 1) * t * t * t - c * t * t; },
};

/**
 * Lo muestra una vez en una esquina de abajo ('der' o 'izq'). onDestello(x, y) pinta el destello
 * (el estallido de navidad.js) donde aparece. Devuelve una promesa que se cumple cuando se va.
 */
export function mostrar({ lado = 'der', onDestello } = {}) {
  escena ??= crearEscena();
  const e = escena;
  const chico = window.innerWidth < 700;
  const ancho = chico ? 120 : 170, alto = chico ? 150 : 210;
  e.lienzo.style.width = ancho + 'px';
  e.lienzo.style.height = alto + 'px';
  e.lienzo.dataset.lado = lado;
  e.globo.dataset.lado = lado;
  e.renderer.setSize(ancho, alto, false);
  e.camara.aspect = ancho / alto;
  e.camara.updateProjectionMatrix();
  e.santa.rotation.y = lado === 'der' ? -0.38 : 0.38;
  e.lienzo.classList.add('is-visible');

  const TOTAL = 5200, SUBE = 900, BAJA = 750;
  const inicio = performance.now();
  let destello = false, globo = false;
  return new Promise((listo) => {
    const cuadro = (ahora) => {
      const t = ahora - inicio;
      let y;
      if (t < SUBE) y = -3.4 + 3.4 * facil.atras(t / SUBE);
      else if (t > TOTAL - BAJA) y = -3.4 * facil.entra((t - (TOTAL - BAJA)) / BAJA);
      else y = Math.sin((t - SUBE) / 260) * 0.05;
      e.santa.position.y = y;
      // Saluda con la derecha, mece la cabeza y se balancea un poco
      e.der.rotation.z = 2.55 + Math.sin(t / 150) * 0.35;
      e.izq.rotation.z = -0.32 + Math.sin(t / 420) * 0.05;
      e.cabeza.rotation.z = Math.sin(t / 380) * 0.08;
      e.santa.rotation.z = Math.sin(t / 520) * 0.04;
      if (!destello && t > SUBE * 0.35) {
        destello = true;
        const r = e.lienzo.getBoundingClientRect();
        if (onDestello) onDestello(r.left + r.width / 2, r.top + r.height * 0.4);
      }
      if (!globo && t > SUBE + 250) { globo = true; e.globo.classList.add('is-visible'); }
      if (globo && t > TOTAL - BAJA - 200) e.globo.classList.remove('is-visible');
      e.renderer.render(e.scene, e.camara);
      if (t < TOTAL) requestAnimationFrame(cuadro);
      else { e.lienzo.classList.remove('is-visible'); listo(); }
    };
    requestAnimationFrame(cuadro);
  });
}
