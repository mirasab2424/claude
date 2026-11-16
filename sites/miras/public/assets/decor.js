// Оформление «дизайн-вариант»: небо, текстуры участка, отделка дома и детали двора.
// Это только внешний вид: размеры дома и пристройки по-прежнему берутся из measurements.json и design.json.
import * as THREE from 'three';

const rnd = (seed) => () => { seed |= 0; seed = seed + 0x6D2B79F5 | 0; let t = Math.imul(seed ^ seed >>> 15, 1 | seed); t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t; return ((t ^ t >>> 14) >>> 0) / 4294967296; };
const std = (color, roughness = 0.85, extra = {}) => new THREE.MeshStandardMaterial({ color, roughness, metalness: 0, ...extra });

// --- процедурные текстуры (холст → повторяющаяся плитка, 1 повтор = size_m метров) ---
function tex(px, sizeM, draw) {
  const c = document.createElement('canvas'); c.width = c.height = px;
  draw(c.getContext('2d'), px, rnd(px * 7 + Math.round(sizeM * 100)));
  const t = new THREE.CanvasTexture(c);
  t.wrapS = t.wrapT = THREE.RepeatWrapping; t.colorSpace = THREE.SRGBColorSpace; t.anisotropy = 4;
  t.userData.sizeM = sizeM;
  return t;
}
const speck = (g, px, r, n, colors, min = 1, max = 3) => { for (let i = 0; i < n; i++) { g.fillStyle = colors[Math.floor(r() * colors.length)]; const s = min + r() * (max - min); g.fillRect(r() * px, r() * px, s, s); } };
const TEX = {
  grass: () => tex(256, 3, (g, px, r) => {
    g.fillStyle = '#5c8c3c'; g.fillRect(0, 0, px, px);
    speck(g, px, r, 2600, ['#6a9a44', '#4f7f33', '#72a34b', '#58883a', '#659540'], 1, 4);
    g.lineWidth = 1;
    for (let i = 0; i < 900; i++) { g.strokeStyle = ['#7aad52', '#48752e', '#6da047'][Math.floor(r() * 3)]; const x = r() * px, y = r() * px; g.beginPath(); g.moveTo(x, y); g.lineTo(x + (r() - 0.5) * 3, y - 2 - r() * 4); g.stroke(); }
  }),
  pavers: () => tex(256, 1.28, (g, px, r) => { // брусчатка 20 × 10 см «вразбежку»
    g.fillStyle = '#6f6a63'; g.fillRect(0, 0, px, px);
    const bw = px / 6.4, bh = px / 12.8;
    for (let j = 0; j < 13; j++) for (let i = -1; i < 8; i++) {
      const k = 150 + Math.floor(r() * 46), w = r() < 0.18 ? 12 : 0;
      g.fillStyle = `rgb(${k + w},${k - 3},${k - 10 - w})`;
      g.fillRect(i * bw + (j % 2 ? bw / 2 : 0) + 1, j * bh + 1, bw - 2, bh - 2);
    }
  }),
  slabs: () => tex(256, 1.2, (g, px, r) => { // плитка 60 × 60
    g.fillStyle = '#9b958a'; g.fillRect(0, 0, px, px);
    for (let j = 0; j < 2; j++) for (let i = 0; i < 2; i++) { const k = 196 + Math.floor(r() * 22); g.fillStyle = `rgb(${k},${k - 4},${k - 14})`; g.fillRect(i * px / 2 + 1.5, j * px / 2 + 1.5, px / 2 - 3, px / 2 - 3); }
    speck(g, px, r, 500, ['rgba(255,255,255,.12)', 'rgba(0,0,0,.07)'], 1, 3);
  }),
  asphalt: () => tex(128, 2, (g, px, r) => { g.fillStyle = '#4a4c50'; g.fillRect(0, 0, px, px); speck(g, px, r, 1500, ['#55575b', '#404246', '#5d5f63', '#3a3c40'], 1, 2.5); }),
  soil: () => tex(128, 1.5, (g, px, r) => { g.fillStyle = '#5a3f2a'; g.fillRect(0, 0, px, px); speck(g, px, r, 1200, ['#6a4b33', '#4b3322', '#735238', '#40291a'], 1, 4); }),
  sand: () => tex(128, 1.5, (g, px, r) => { g.fillStyle = '#dcc890'; g.fillRect(0, 0, px, px); speck(g, px, r, 1000, ['#e6d39e', '#cfba82', '#eedcab'], 1, 3); }),
  planks: () => tex(128, 1.2, (g, px, r) => { // доски террасы
    for (let i = 0; i < 8; i++) { const k = r() * 26; g.fillStyle = `rgb(${168 + k},${122 + k * 0.8},${78 + k * 0.6})`; g.fillRect(0, i * px / 8, px, px / 8 - 1.5); g.fillStyle = '#5d4228'; g.fillRect(0, (i + 1) * px / 8 - 1.5, px, 1.5); }
    speck(g, px, r, 300, ['rgba(60,35,15,.12)'], 6, 22);
  }),
};
const texCache = {};
const getTex = (name) => (texCache[name] ??= TEX[name]());

// плоские поверхности участка: UV из мировых координат, чтобы плитка шла в метрах
function worldUV(mesh, sizeM) {
  mesh.updateWorldMatrix(true, false);
  const g = mesh.geometry = mesh.geometry.clone(), p = g.attributes.position, uv = new Float32Array(p.count * 2), v = new THREE.Vector3();
  for (let i = 0; i < p.count; i++) { v.fromBufferAttribute(p, i).applyMatrix4(mesh.matrixWorld); uv[i * 2] = v.x / sizeM; uv[i * 2 + 1] = v.z / sizeM; }
  g.setAttribute('uv', new THREE.BufferAttribute(uv, 2));
}

const SITE_MATS = {
  Lawn: ['grass', 0xffffff], LawnOutside: ['grass', 0xffffff], Paving: ['slabs', 0xffffff], Paving2: ['slabs', 0xf2dcc0],
  Asphalt: ['pavers', 0xffffff], Road: ['asphalt', 0xffffff], Soil: ['soil', 0xffffff], BedSoil: ['soil', 0xb89a80], Sand: ['sand', 0xffffff],
};
const SITE_COLORS = { Fence: 0x7a5636, Gate: 0x3a3f45, Roof: 0x3c4046, Shrub: 0x3f7a35, Spruce: 0x1f5a34, Trunk: 0x5d4027, Flowers: 0xd9558a, Concrete: 0xb9b6ae, Play: 0xf0a030 };
const siteMatCache = {};
/** Материалы старой модели участка: без «металла», с текстурами газона, плитки и брусчатки. */
export function styleSite(o) {
  o.traverse((m) => {
    if (!m.isMesh) return;
    const name = m.material.name;
    if (SITE_MATS[name]) {
      const [t, tint] = SITE_MATS[name];
      worldUV(m, getTex(t).userData.sizeM);
      m.material = siteMatCache[name] ??= std(tint, 0.95, { map: getTex(t), side: THREE.DoubleSide });
      m.castShadow = false;
    } else {
      m.material = siteMatCache[name] ??= (() => { const c = m.material.clone(); c.metalness = 0; c.roughness = Math.max(c.roughness ?? 0.8, 0.6); if (SITE_COLORS[name]) c.color.setHex(SITE_COLORS[name]); if (/Shrub|Spruce|Flowers/.test(name)) c.flatShading = true; return c; })();
    }
  });
}

/** Небо-купол с градиентом, дальняя земля, облака и дымка. Возвращает setNight(on). */
export function addSky(scene) {
  const uniforms = { top: { value: new THREE.Color(0x4f8fd0) }, horizon: { value: new THREE.Color(0xdbe9f2) }, ground: { value: new THREE.Color(0x8fae6a) } };
  const dome = new THREE.Mesh(new THREE.SphereGeometry(300, 32, 16), new THREE.ShaderMaterial({
    uniforms, side: THREE.BackSide, depthWrite: false, fog: false,
    vertexShader: 'varying vec3 p; void main(){ p = position; gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0); }',
    fragmentShader: 'uniform vec3 top; uniform vec3 horizon; uniform vec3 ground; varying vec3 p; void main(){ float h = normalize(p).y; vec3 c = h > 0.0 ? mix(horizon, top, pow(h, 0.55)) : mix(horizon, ground, min(1.0, -h * 6.0)); gl_FragColor = vec4(c, 1.0); }',
  }));
  dome.renderOrder = -10;
  const ft = getTex('grass').clone(); ft.needsUpdate = true; ft.repeat.set(200, 200);
  const far = new THREE.Mesh(new THREE.CircleGeometry(300, 48), std(0xffffff, 1, { map: ft }));
  far.rotation.x = -Math.PI / 2; far.position.y = -0.6;
  const clouds = new THREE.Group(), cm = new THREE.MeshBasicMaterial({ color: 0xffffff, fog: false, transparent: true, opacity: 0.92 });
  const r = rnd(42), blob = new THREE.IcosahedronGeometry(1, 1);
  for (let i = 0; i < 9; i++) {
    const a = r() * Math.PI * 2, d = 120 + r() * 110, c = new THREE.Group();
    for (let k = 0; k < 5; k++) { const b = new THREE.Mesh(blob, cm); b.scale.set(9 + r() * 9, 3 + r() * 2.5, 6 + r() * 6); b.position.set((k - 2) * 8 + r() * 4, r() * 2, r() * 6); c.add(b); }
    c.position.set(Math.cos(a) * d, 55 + r() * 35, Math.sin(a) * d);
    clouds.add(c);
  }
  scene.add(dome, far, clouds);
  scene.fog = new THREE.Fog(0xdbe9f2, 70, 300);
  return (on) => {
    uniforms.top.value.setHex(on ? 0x0b1430 : 0x4f8fd0); uniforms.horizon.value.setHex(on ? 0x3b3f63 : 0xdbe9f2); uniforms.ground.value.setHex(on ? 0x1a1e22 : 0x8fae6a);
    scene.fog.color.setHex(on ? 0x2a2e4a : 0xdbe9f2); clouds.visible = !on;
  };
}

// --- общие помощники геометрии ---
function kit(group) {
  const box = (w, h, d, m, x, y, z, shadow = true) => { const o = new THREE.Mesh(new THREE.BoxGeometry(w, h, d), m); o.position.set(x, y, z); o.castShadow = shadow; o.receiveShadow = true; group.add(o); return o; };
  const cyl = (r, h, m, x, y, z, seg = 12, r2 = r) => { const o = new THREE.Mesh(new THREE.CylinderGeometry(r2, r, h, seg), m); o.position.set(x, y, z); o.castShadow = true; group.add(o); return o; };
  // брус от точки к точке
  const beam = (a, b, w, hh, m) => {
    const A = new THREE.Vector3(...a), B = new THREE.Vector3(...b), o = new THREE.Mesh(new THREE.BoxGeometry(w, hh, A.distanceTo(B)), m);
    o.position.copy(A).add(B).multiplyScalar(0.5); o.quaternion.setFromUnitVectors(new THREE.Vector3(0, 0, 1), B.clone().sub(A).normalize());
    o.castShadow = true; group.add(o); return o;
  };
  return { box, cyl, beam };
}

/**
 * Отделка дома: фальцевая крыша, крыша над входным выступом, карнизы, водостоки, труба, крыльцо с навесом, рамы окон.
 * c: { h, root, roof (слой крыши), W, D, H, y0, ridge, ridgeY, over, plinthH (мм), openings, night }
 */
export function houseFinish(c) {
  const { root, roof, W, D, H, y0, ridge, ridgeY, over, plinthH, openings, night } = c;
  const mm = (v) => v / 1000, P = (x, y, z) => [mm(x), mm(z), -mm(y)]; // план (x, y), высота z → сцена
  const graphite = std(0x3c4046, 0.55, { metalness: 0.25 }), dark = std(0x2f3338, 0.6), wood = std(0xb07a45, 0.7), woodDark = std(0x8a5a33, 0.75);
  const white = std(0xf1ece2, 0.9), stone = std(0x55514c, 0.9);
  const R = kit(roof), F = kit(root);
  const s1 = ridge / (ridgeY - y0), s2 = ridge / (D - ridgeY), e1 = H - over * s1, e2 = H - over * s2, top = H + ridge;

  // фальцы на скатах
  const ribs = [];
  for (let x = -over + 250; x < W + over; x += 500) { ribs.push([P(x, y0 - over, e1 + 25), P(x, ridgeY, top + 25)], [P(x, ridgeY, top + 25), P(x, D + over, e2 + 25)]); }
  const rib = new THREE.InstancedMesh(new THREE.BoxGeometry(0.03, 0.035, 1), graphite, ribs.length), m4 = new THREE.Matrix4(), q = new THREE.Quaternion(), Z = new THREE.Vector3(0, 0, 1);
  ribs.forEach(([a, b], i) => { const A = new THREE.Vector3(...a), B = new THREE.Vector3(...b); q.setFromUnitVectors(Z, B.clone().sub(A).normalize()); m4.compose(A.clone().add(B).multiplyScalar(0.5), q, new THREE.Vector3(1, 1, A.distanceTo(B))); rib.setMatrixAt(i, m4); });
  roof.add(rib);
  // конёк, лобовые и ветровые доски, подшивка свеса
  R.beam(P(-over - 30, ridgeY, top + 40), P(W + over + 30, ridgeY, top + 40), 0.2, 0.07, graphite);
  for (const [y, z] of [[y0 - over, e1], [D + over, e2]]) R.beam(P(-over, y, z - 90), P(W + over, y, z - 90), 0.03, 0.2, woodDark);
  for (const x of [-over, W + over]) { R.beam(P(x, y0 - over, e1 - 80), P(x, ridgeY, top - 80), 0.035, 0.22, woodDark); R.beam(P(x, ridgeY, top - 80), P(x, D + over, e2 - 80), 0.035, 0.22, woodDark); }
  // водостоки: жёлоб вдоль карниза и трубы по углам
  for (const [y, z, wy] of [[y0 - over - 60, e1 - 60, y0 - 70], [D + over + 60, e2 - 60, D + 70]]) {
    const g = new THREE.Mesh(new THREE.CylinderGeometry(0.065, 0.065, mm(W + over * 2), 10), dark);
    g.rotation.z = Math.PI / 2; g.position.set(...P(W / 2, y, z)); roof.add(g);
    for (const x of [120, W - 120]) { R.beam(P(x, y, z - 40), P(x, wy, z - 420), 0.07, 0.07, dark); R.beam(P(x, wy, z - 420), P(x, wy, -plinthH + 150), 0.07, 0.07, dark); }
  }
  // дымоход на заднем скате
  const chY = ridgeY + 1700, chZ = top - 1700 * s2;
  R.box(0.55, mm(top + 500 - chZ) + 0.4, 0.55, std(0x8f8478, 0.9), mm(W * 0.72), mm((chZ + top + 500) / 2) - 0.2, -mm(chY));
  R.box(0.75, 0.06, 0.75, dark, mm(W * 0.72), mm(top + 500) + 0.03, -mm(chY));

  // крыша над входным выступом (выступ уходит вперёд от линии основного ската)
  const px1 = Math.min(...c.fp.filter((p) => p[1] === 0).map((p) => p[0])), px2 = Math.max(...c.fp.filter((p) => p[1] === 0).map((p) => p[0]));
  const xc = (px1 + px2) / 2, hw = (px2 - px1) / 2, ov = 300, hr = 1500, sp = hr / hw, he = H - ov * sp, yr = y0 + hr / s1, ye = y0 - ov * sp / s1;
  const pg = new THREE.BufferGeometry(), tri = [];
  for (const sgn of [-1, 1]) { const xe = xc + sgn * (hw + ov); const A = P(xe, -over, he), B = P(xc, -over, H + hr), C = P(xc, yr, H + hr + 5), Dd = P(xe, ye, he); tri.push(...A, ...B, ...C, ...A, ...C, ...Dd); }
  pg.setAttribute('position', new THREE.Float32BufferAttribute(tri, 3)); pg.computeVertexNormals();
  const porchRoof = new THREE.Mesh(pg, std(0x3c4046, 0.55, { metalness: 0.25, side: THREE.DoubleSide })); porchRoof.castShadow = true; roof.add(porchRoof);
  for (const sgn of [-1, 1]) {
    const xe = xc + sgn * (hw + ov);
    for (let t = 0.12; t < 1; t += 0.16) { const y = -over + t * (yr + over); const k = Math.min(1, (yr - y) / (yr - ye)); R.beam(P(xc + sgn * 10, y, H + hr + 25), P(xc + sgn * (hw + ov) * k, y, H + hr - (hw + ov) * k * sp + 25), 0.03, 0.035, graphite); }
    R.beam(P(xe, -over, he - 80), P(xc, -over, H + hr - 80), 0.035, 0.22, woodDark); // ветровая доска
    R.beam(P(xe, -over, he - 40), P(xe, ye, he - 40), 0.03, 0.16, woodDark);
  }
  R.beam(P(xc, -over - 30, H + hr + 40), P(xc, yr, H + hr + 40), 0.2, 0.07, graphite);
  // фронтон выступа — деревянная обшивка
  const gg = new THREE.BufferGeometry(); gg.setAttribute('position', new THREE.Float32BufferAttribute([...P(px1, 0, H), ...P(px2, 0, H), ...P(xc, 0, H + hr), ...P(px1, y0, H), ...P(px2, y0, H), ...P(xc, y0, H + hr)], 3)); gg.computeVertexNormals();
  roof.add(new THREE.Mesh(gg, std(0xb07a45, 0.75, { side: THREE.DoubleSide })));
  for (let k = 1; k < 7; k++) R.beam(P(xc - hw * (1 - k / 7), -12, H + hr * k / 7), P(xc + hw * (1 - k / 7), -12, H + hr * k / 7), 0.012, 0.012, woodDark);
  // перекрытие выступа, чтобы сверху не было дыры
  R.box(mm(px2 - px1), 0.05, mm(y0), white, mm(xc), mm(H) + 0.025, -mm(y0 / 2));

  // рамы окон: тёмный профиль по контуру проёма и импост
  for (const o of openings.filter((o) => o.kind === 'win')) {
    const t = mm(o.t ?? 300) + 0.03, a = mm(o.a), b = mm(o.b), lo = mm(o.lo), hi = mm(o.hi), at = mm(o.at), f = 0.06;
    const bar = (u1, u2, v1, v2) => { const w = Math.max(u2 - u1, 0.001), hh = v2 - v1; const m = o.axis === 'y' ? F.box(w, hh, t * 0.35, dark, (u1 + u2) / 2, (v1 + v2) / 2, -at, false) : F.box(t * 0.35, hh, w, dark, at, (v1 + v2) / 2, -(u1 + u2) / 2, false); return m; };
    bar(a, b, hi - f, hi); bar(a, b, lo, lo + f); bar(a, a + f, lo, hi); bar(b - f, b, lo, hi);
    if (b - a > 1.2) bar((a + b) / 2 - f / 2, (a + b) / 2 + f / 2, lo, hi);
    if (hi - lo > 1.2) bar(a, b, hi - 0.42, hi - 0.42 + f * 0.8);
  }

  // крыльцо: площадка, ступени, навес на двух столбах, светильник, кашпо
  const e = c.entrance;
  if (e && e.axis === 'x') {
    const wallX = e.at - e.t / 2, ya = e.a - 250, yb = e.b + 250, depth = 1300, pl = plinthH;
    F.box(mm(depth), mm(pl), mm(yb - ya), stone, mm(wallX - depth / 2), -mm(pl / 2), -mm((ya + yb) / 2));
    F.box(mm(depth) + 0.04, 0.04, mm(yb - ya) + 0.04, wood, mm(wallX - depth / 2) - 0.02, 0.0, -mm((ya + yb) / 2));
    const n = Math.max(1, Math.round(pl / 170) - 1);
    for (let i = 1; i <= n; i++) F.box(0.3, mm(pl) * (1 - i / (n + 1)), mm(yb - ya), stone, mm(wallX - depth) - 0.3 * i + 0.15, -mm(pl) + mm(pl) * (1 - i / (n + 1)) / 2, -mm((ya + yb) / 2));
    const ch = 2.5, cx1 = wallX - depth - 100;
    F.box(mm(wallX - cx1), 0.09, mm(yb - ya) + 0.3, woodDark, mm((wallX + cx1) / 2), ch, -mm((ya + yb) / 2));
    F.box(mm(wallX - cx1) + 0.06, 0.03, mm(yb - ya) + 0.36, graphite, mm((wallX + cx1) / 2), ch + 0.06, -mm((ya + yb) / 2));
    for (const y of [ya + 60, yb - 60]) F.box(0.1, ch, 0.1, woodDark, mm(wallX - depth + 60), ch / 2, -mm(y));
    F.box(0.9, 0.012, 0.55, std(0x4a4f55, 1), mm(wallX) - 0.5, 0.026, -mm((e.a + e.b) / 2), false); // коврик
    // настенный светильник у двери
    F.box(0.08, 0.22, 0.1, dark, mm(wallX) - 0.05, 1.9, -mm(e.b + 220), false);
    const bulb = F.box(0.06, 0.14, 0.07, new THREE.MeshStandardMaterial({ color: 0xfff1cf, emissive: 0xffc56b, emissiveIntensity: 0.25 }), mm(wallX) - 0.1, 1.9, -mm(e.b + 220), false);
    night.glow.push(bulb.material);
    const pl1 = new THREE.PointLight(0xffc773, 3, 7, 1.6); pl1.position.set(mm(wallX) - 0.5, 2.0, -mm((e.a + e.b) / 2)); night.group.add(pl1);
    // кашпо с шаровидными кустами по сторонам ступеней
    for (const y of [ya - 380, yb + 380]) {
      F.box(0.45, 0.45, 0.45, std(0x3a3d41, 0.7), mm(wallX - depth) - 0.1, -mm(pl) + 0.225, -mm(y));
      const s = new THREE.Mesh(new THREE.IcosahedronGeometry(0.33, 1), std(0x3f7a35, 0.9, { flatShading: true })); s.position.set(mm(wallX - depth) - 0.1, -mm(pl) + 0.68, -mm(y)); s.castShadow = true; root.add(s);
    }
  }
}

/**
 * Детали двора в координатах участка (x вправо, d вглубь, сцена z = −d): зона отдыха с перголой, деревья, туи,
 * садовые фонари, гирлянды к барбекю, скамья, клумбы, улица. Места выбраны по свободным зонам прошлой модели.
 * c: { group, groundY, plot, L (пристройка), house: {x1, x2, d1, d2, eaveY}, night }
 */
export function yardDecor(c) {
  const { group, groundY: G0, plot, L, house, night } = c;
  const G = G0 + 0.03, K = kit(group), r = rnd(7);
  const wood = std(0xb07a45, 0.75), woodDark = std(0x7a5636, 0.8), dark = std(0x2f3338, 0.6), cushion = std(0xe8e2d4, 0.95), sofa = std(0x6b7178, 0.9);
  const glowMat = () => { const m = new THREE.MeshStandardMaterial({ color: 0xfff1cf, emissive: 0xffc56b, emissiveIntensity: 0.25 }); night.glow.push(m); return m; };
  const lampMat = glowMat();
  const light = (x, y, d, power = 2.2, dist = 7) => { const l = new THREE.PointLight(0xffc773, power, dist, 1.6); l.position.set(x, y, -d); night.group.add(l); };

  // --- деревья: ствол + несколько «шапок» ---
  const leafMats = [0x4f8f3a, 0x5c9c42, 0x447f33, 0x6aa84a].map((k) => std(k, 0.95, { flatShading: true })), trunkMat = std(0x5d4027, 0.9);
  const blob = new THREE.IcosahedronGeometry(1, 1);
  const tree = (x, d, hgt = 5, crown = 1.6, y = G) => {
    K.cyl(0.14, hgt * 0.55, trunkMat, x, y + hgt * 0.275, -d, 7, 0.09);
    const n = 6;
    for (let i = 0; i < n; i++) {
      const a = (i / n) * Math.PI * 2 + r(), rr = i === 0 ? 0 : crown * 0.5, s = crown * (0.45 + r() * 0.25);
      const b = new THREE.Mesh(blob, leafMats[Math.floor(r() * leafMats.length)]);
      b.scale.set(s, s * 0.85, s); b.position.set(x + Math.cos(a) * rr, y + hgt * 0.62 + (i === 0 ? crown * 0.5 : r() * crown * 0.5), -d + Math.sin(a) * rr); b.rotation.y = r() * 3;
      b.castShadow = true; group.add(b);
    }
  };
  const yardEnd = L ? (L.rooms.find((q) => q.id === 'sauna')?.d2 ?? L.total) : plot.depth_m;
  const xr = L ? L.x1 : plot.width_m; // правая граница свободного двора
  // сад за домом справа и деревья на улице перед забором
  for (const [x, d, hh, cr] of [[xr - 3.6, 30.6, 4.6, 1.5], [xr - 1.9, 33.6, 5.2, 1.7], [xr - 5.6, 34.3, 4.2, 1.4]]) tree(x, d, hh, cr);
  for (const x of [0.8, 13.4]) tree(x, -1.7, 4.6, 1.25, G0);

  // --- туи: живая изгородь вдоль левого и заднего забора ---
  const thujas = [];
  for (let d = 8.3; d < 18; d += 1.15) thujas.push([0.75, d]);
  for (let x = 11.4; x < xr - 0.4; x += 1.15) thujas.push([x, plot.depth_m - 0.7]);
  const th = new THREE.InstancedMesh(new THREE.ConeGeometry(0.48, 2.3, 7), std(0x2c6a3b, 0.95, { flatShading: true }), thujas.length), m4 = new THREE.Matrix4();
  thujas.forEach(([x, d], i) => { const s = 0.85 + r() * 0.3; m4.compose(new THREE.Vector3(x, G + 1.15 * s, -d), new THREE.Quaternion().setFromAxisAngle(new THREE.Vector3(0, 1, 0), r() * 3), new THREE.Vector3(s, s, s)); th.setMatrixAt(i, m4); });
  th.castShadow = true; group.add(th);

  // --- зона отдыха: терраса, пергола, диван, очаг ---
  const tx1 = 11.4, tx2 = 16.4, td1 = 29.9, td2 = 34.2, tcx = (tx1 + tx2) / 2, tcd = (td1 + td2) / 2;
  const deckTex = getTex('planks').clone(); deckTex.needsUpdate = true; deckTex.repeat.set((tx2 - tx1) / 1.2, (td2 - td1) / 1.2);
  const deck = K.box(tx2 - tx1, 0.14, td2 - td1, std(0xffffff, 0.8, { map: deckTex }), tcx, G + 0.07, -tcd, false);
  deck.receiveShadow = true;
  const ph = 2.6, Y = G + 0.14;
  for (const x of [tx1 + 0.15, tx2 - 0.15]) for (const d of [td1 + 0.15, td2 - 0.15]) K.box(0.14, ph, 0.14, woodDark, x, Y + ph / 2, -d);
  for (const d of [td1 + 0.15, td2 - 0.15]) K.box(tx2 - tx1 + 0.5, 0.16, 0.1, woodDark, tcx, Y + ph + 0.08, -d);
  for (let x = tx1; x <= tx2 + 0.01; x += 0.5) K.box(0.06, 0.14, td2 - td1 + 0.6, wood, x, Y + ph + 0.23, -tcd);
  // угловой диван у задней стороны
  K.box(3.2, 0.4, 0.85, sofa, tcx + 0.3, Y + 0.2, -(td2 - 0.75)); K.box(3.2, 0.45, 0.2, sofa, tcx + 0.3, Y + 0.62, -(td2 - 0.42));
  K.box(0.85, 0.4, 1.9, sofa, tx2 - 0.75, Y + 0.2, -(td2 - 2.1)); K.box(0.2, 0.45, 1.9, sofa, tx2 - 0.42, Y + 0.62, -(td2 - 2.1));
  for (let i = 0; i < 3; i++) K.box(0.95, 0.12, 0.7, cushion, tcx - 0.75 + i * 1.02, Y + 0.46, -(td2 - 0.8), false);
  for (let i = 0; i < 2; i++) K.box(0.7, 0.12, 0.85, cushion, tx2 - 0.8, Y + 0.46, -(td2 - 1.7 - i * 0.9), false);
  // очаг
  K.cyl(0.55, 0.4, std(0x4a4743, 0.9), tcx - 0.6, Y + 0.2, -(tcd - 0.5), 14, 0.6);
  const fire = new THREE.Mesh(new THREE.CylinderGeometry(0.46, 0.46, 0.03, 14), new THREE.MeshStandardMaterial({ color: 0x3a2a20, emissive: 0xff6a1a, emissiveIntensity: 0.15 }));
  fire.position.set(tcx - 0.6, Y + 0.41, -(tcd - 0.5)); group.add(fire); night.glow.push(fire.material);
  for (let i = 0; i < 3; i++) K.beam([tcx - 0.9, Y + 0.45, -(tcd - 0.5) + (i - 1) * 0.12], [tcx - 0.3, Y + 0.5, -(tcd - 0.5) - (i - 1) * 0.1], 0.07, 0.07, std(0x3a2a20, 1));
  light(tcx - 0.6, Y + 0.9, tcd - 0.5, 4, 8);
  // два кресла напротив дивана
  for (const dx of [-1.9, -0.3]) { K.box(0.75, 0.38, 0.75, sofa, tcx + dx, Y + 0.19, -(td1 + 0.9)); K.box(0.75, 0.45, 0.16, sofa, tcx + dx, Y + 0.58, -(td1 + 0.6)); K.box(0.6, 0.1, 0.55, cushion, tcx + dx, Y + 0.43, -(td1 + 0.95), false); }
  // плитки-дорожка от двора к террасе
  const slab = std(0xcfc8bb, 0.95);
  for (let d = yardEnd + 0.9; d < td1 + 2; d += 0.75) K.box(0.6, 0.05, 0.6, slab, xr - 0.55, G, -d, false);
  for (let x = xr - 1.3; x > tx2 + 0.2; x -= 0.75) K.box(0.6, 0.05, 0.6, slab, x, G, -(td1 + 1.4), false);

  // --- садовые фонари-столбики ---
  const bollard = (x, d, lit = false) => { K.cyl(0.05, 0.7, dark, x, G + 0.35, -d, 8); const b = new THREE.Mesh(new THREE.CylinderGeometry(0.065, 0.065, 0.14, 10), lampMat); b.position.set(x, G + 0.77, -d); group.add(b); if (lit) light(x, G + 0.9, d, 1.6, 5.5); };
  if (L) for (let d = 3.5, i = 0; d < yardEnd; d += 4.9, i++) bollard(L.x1 - 1.3, d, i % 2 === 0);
  for (const [x, d, lit] of [[4.2, 2.0, true], [9.8, 2.0, false], [15.2, 2.0, true], [tx1 - 0.4, td1 - 0.4, false], [tx2 + 0.4, td1 - 0.4, false], [2.2, 19.9, true]]) bollard(x, d, lit);

  // --- гирлянды от дома к барбекю-зоне ---
  const bbq = L?.rooms.find((q) => q.id === 'bbq');
  if (bbq && house) {
    const bulbs = [], hA = house.eaveY - 0.15, hB = G0 + 0.12 + L.ob.wall_height_m - 0.1;
    for (const d of [bbq.d1 + 0.6, (bbq.d1 + bbq.d2) / 2, bbq.d2 - 0.6]) {
      const A = new THREE.Vector3(house.x2 + 0.35, hA, -d), B = new THREE.Vector3(L.x1 - 0.05, hB, -d), pts = [];
      for (let i = 0; i <= 24; i++) { const t = i / 24, p = A.clone().lerp(B, t); p.y -= Math.sin(t * Math.PI) * 0.55; pts.push(p); if (i % 2 === 1) bulbs.push(p.clone()); }
      group.add(new THREE.Line(new THREE.BufferGeometry().setFromPoints(pts), new THREE.LineBasicMaterial({ color: 0x22262a })));
    }
    const bm = new THREE.InstancedMesh(new THREE.SphereGeometry(0.05, 8, 6), glowMat(), bulbs.length);
    bulbs.forEach((p, i) => { m4.makeTranslation(p.x, p.y - 0.06, p.z); bm.setMatrixAt(i, m4); });
    group.add(bm);
    light((house.x2 + L.x1) / 2, hA - 0.6, (bbq.d1 + bbq.d2) / 2, 5, 11);
    // подвесы под навесом барбекю
    for (const f of [0.3, 0.7]) { const x = L.x1 + 1.6, d = bbq.d1 + (bbq.d2 - bbq.d1) * f; K.cyl(0.006, 0.5, dark, x, hB - 0.2, -d, 5); K.cyl(0.16, 0.14, dark, x, hB - 0.5, -d, 12, 0.05); const b = new THREE.Mesh(new THREE.SphereGeometry(0.06, 8, 6), lampMat); b.position.set(x, hB - 0.58, -d); group.add(b); }
    light(L.x1 + 1.6, hB - 0.7, (bbq.d1 + bbq.d2) / 2, 4, 8);
  }

  // --- скамья у фасада и клумбы ---
  if (house) {
    const bx = house.x1 + 1.5, bd = house.dFacade - 0.45;
    K.box(1.6, 0.06, 0.42, wood, bx, G + 0.45, -bd); K.box(1.6, 0.4, 0.05, wood, bx, G + 0.72, -(bd + 0.2));
    for (const s of [-0.7, 0.7]) K.box(0.06, 0.45, 0.4, dark, bx + s, G + 0.22, -bd);
  }
  const petals = [0xe85d8a, 0xf6c445, 0xffffff, 0xa86ad6, 0xf08a4b].map((k) => new THREE.Color(k));
  const beds = [[xr - 3.2, 30.2, 1.3, 0.8], [tx1 - 1.0, 32.2, 0.6, 1.6], [house ? house.x2 + 0.9 : 16.3, 12, 0.45, 4.2]];
  const flowers = []; let fi = 0;
  for (const [x, d, rx, rd] of beds) {
    const s = new THREE.Mesh(new THREE.CylinderGeometry(1, 1, 0.08, 20), std(0x5a3f2a, 1)); s.scale.set(rx, 1, rd); s.position.set(x, G + 0.02, -d); s.receiveShadow = true; group.add(s);
    for (let i = 0; i < Math.round(rx * rd * 60); i++) { const a = r() * 6.283, k = Math.sqrt(r()) * 0.9; flowers.push([x + Math.cos(a) * rx * k, d + Math.sin(a) * rd * k, 0.06 + r() * 0.05]); }
  }
  const fm = new THREE.InstancedMesh(new THREE.IcosahedronGeometry(1, 0), std(0xffffff, 0.9, { flatShading: true }), flowers.length);
  for (const [x, d, s] of flowers) { m4.compose(new THREE.Vector3(x, G + 0.12 + s, -d), new THREE.Quaternion(), new THREE.Vector3(s, s, s)); fm.setMatrixAt(fi, m4); fm.setColorAt(fi++, r() < 0.35 ? new THREE.Color(0x4f8f3a) : petals[Math.floor(r() * petals.length)]); }
  group.add(fm);
  // камни у клумб
  const rock = new THREE.DodecahedronGeometry(1, 0), rockMat = std(0x8d8a84, 0.95, { flatShading: true });
  for (const [x, d, s] of [[xr - 4.4, 29.7, 0.3], [xr - 2.1, 30.9, 0.22], [tx1 - 1.5, 30.6, 0.26], [xr - 4.1, 30.9, 0.16]]) { const o = new THREE.Mesh(rock, rockMat); o.scale.set(s, s * 0.7, s * 1.2); o.position.set(x, G + s * 0.4, -d); o.rotation.y = r() * 3; o.castShadow = true; group.add(o); }

  // --- улица: бордюр, разметка, фонари, почтовый ящик ---
  const curb = std(0xbdb9b0, 0.9), sy = G0 - 0.01;
  K.box(46, 0.14, 0.18, curb, 15, sy + 0.05, 3.0, false);
  for (let x = -7; x < 38; x += 4) K.box(1.8, 0.01, 0.14, std(0xf2f2ee, 0.8), x, sy + 0.005, 6.0, false);
  for (const x of [0.6, 13.6]) {
    K.cyl(0.07, 5.2, dark, x, sy + 2.6, 2.5, 8, 0.05); K.beam([x, sy + 5.1, 2.5], [x, sy + 5.3, 3.7], 0.06, 0.06, dark);
    const hd = K.box(0.2, 0.07, 0.5, lampMat, x, sy + 5.26, 3.75, false); hd.castShadow = false;
    light(x, sy + 5.1, -3.8, 9, 16);
  }
  const g0 = plot.main_gate_x_m[0];
  K.box(0.08, 1.1, 0.08, dark, g0 - 0.5, G0 + 0.55, 0.25); K.box(0.36, 0.26, 0.2, std(0x8f2f2a, 0.6), g0 - 0.5, G0 + 1.2, 0.25);
}
