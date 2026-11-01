// 3D из measurements.json: массив стен = контур минус помещения минус проёмы (сетка 50 мм), без загрузки модели.
import * as THREE from 'three';
import { OrbitControls } from '../vendor/three/addons/controls/OrbitControls.js';
import { roomName, m1n, windowWall, doorWall, stairVariant } from './house.js';

const CELL = 50; // мм

export function build3D(el, h, ui) {
  const renderer = new THREE.WebGLRenderer({ antialias: true });
  renderer.setPixelRatio(Math.min(devicePixelRatio, 2));
  el.append(renderer.domElement);
  const scene = new THREE.Scene();
  const css = getComputedStyle(document.documentElement);
  scene.background = new THREE.Color(css.getPropertyValue('--surface-2').trim() || '#f8f6f2');
  const camera = new THREE.PerspectiveCamera(40, 1, 0.1, 200);
  const controls = new OrbitControls(camera, renderer.domElement);
  controls.enableDamping = true;
  scene.add(new THREE.HemisphereLight(0xffffff, 0x8899aa, 1.6));
  const sun = new THREE.DirectionalLight(0xffffff, 1.6);
  sun.position.set(-8, 14, -6);
  scene.add(sun);

  const fp = h.exterior.footprint_mm.value;
  const W = Math.max(...fp.map((p) => p[0])), D = Math.max(...fp.map((p) => p[1]));
  const H = h.exterior.wall_height_blocks.value;
  const mm = (v) => v / 1000;
  const root = new THREE.Group();
  root.position.set(-mm(W) / 2, 0, -mm(D) / 2);
  scene.add(root);

  // --- сетка: что внутри контура, что занято комнатами ---
  const nx = Math.ceil(W / CELL), ny = Math.ceil(D / CELL);
  const inPoly = (x, y, poly) => {
    let c = false;
    for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
      const [xi, yi] = poly[i], [xj, yj] = poly[j];
      if ((yi > y) !== (yj > y) && x < ((xj - xi) * (y - yi)) / (yj - yi) + xi) c = !c;
    }
    return c;
  };
  // каждой клетке стены — список вертикальных проёмов [низ, верх]
  const wall = Array.from({ length: ny }, () => new Array(nx).fill(null));
  for (let j = 0; j < ny; j++) for (let i = 0; i < nx; i++) {
    const x = (i + 0.5) * CELL, y = (j + 0.5) * CELL;
    if (inPoly(x, y, fp) && !h.rooms.some((r) => inPoly(x, y, r.floor_polygon_mm.value))) wall[j][i] = [];
  }
  const cut = (x0, y0, x1, y1, lo, hi, kind) => {
    for (let j = Math.floor(Math.min(y0, y1) / CELL); j < Math.ceil(Math.max(y0, y1) / CELL); j++)
      for (let i = Math.floor(Math.min(x0, x1) / CELL); i < Math.ceil(Math.max(x0, x1) / CELL); i++)
        if (wall[j]?.[i]) wall[j][i].push([lo, hi, kind]);
  };
  const openings = [];
  for (const w of h.windows.filter((w) => w.level === 'ground')) {
    const g = windowWall(h, w);
    const a = w.from_mm.value, b = w.to_mm.value, t0 = g.dir > 0 ? g.at : g.at - g.t;
    const lo = w.sill_above_floor_mm.value, hi = lo + w.height_mm.value;
    if (g.axis === 'y') cut(a, t0, b, t0 + g.t, lo, hi, 'win'); else cut(t0, a, t0 + g.t, b, lo, hi, 'win');
    openings.push({ type: 'win', axis: g.axis, a, b, at: t0 + g.t / 2, lo, hi });
  }
  for (const d of h.doors) {
    const g = doorWall(h, d);
    const a = d.from_mm.value, b = d.to_mm.value, hi = d.height_mm.value;
    if (g.axis === 'y') cut(a, g.at, b, g.at + g.t, 0, hi, 'door'); else cut(g.at, a, g.at + g.t, b, 0, hi, 'door');
    openings.push({ type: 'door', id: d.id, axis: g.axis, a, b, at: g.at + g.t / 2, hi, t: g.t, assumed: d.height_mm.status === 'assumed' });
  }

  // --- стены: склеиваем клетки в полосы с одинаковыми проёмами ---
  const wallMat = new THREE.MeshStandardMaterial({ color: 0xd9d4ca, roughness: 0.9 });
  const boxes = [];
  for (let j = 0; j < ny; j++) {
    let i = 0;
    while (i < nx) {
      const c = wall[j][i];
      if (!c) { i++; continue; }
      const key = JSON.stringify(c);
      let k = i + 1;
      while (k < nx && wall[j][k] && JSON.stringify(wall[j][k]) === key) k++;
      // вертикальные куски между проёмами
      const ops = c.map((o) => [o[0], o[1]]).sort((p, q) => p[0] - q[0]);
      let z = 0;
      for (const [lo, hi] of [...ops, [H, H]]) {
        if (lo > z) boxes.push([i * CELL, j * CELL, (k - i) * CELL, CELL, z, lo - z]);
        z = Math.max(z, hi);
      }
      i = k;
    }
  }
  const geo = new THREE.BoxGeometry(1, 1, 1);
  const inst = new THREE.InstancedMesh(geo, wallMat, boxes.length);
  const m4 = new THREE.Matrix4();
  boxes.forEach(([x, y, w, d, z, hgt], n) => {
    m4.compose(new THREE.Vector3(mm(x + w / 2), mm(z + hgt / 2), mm(y + d / 2)), new THREE.Quaternion(), new THREE.Vector3(mm(w), mm(hgt), mm(d)));
    inst.setMatrixAt(n, m4);
  });
  root.add(inst);

  // --- полы помещений ---
  for (const r of h.rooms) {
    const shape = new THREE.Shape(r.floor_polygon_mm.value.map(([x, y]) => new THREE.Vector2(mm(x), mm(y))));
    const g = new THREE.ShapeGeometry(shape);
    g.rotateX(Math.PI / 2);
    const mat = new THREE.MeshStandardMaterial({ color: r.confidence === 'low' ? 0xf3e1a8 : 0xf4f1ea, side: THREE.DoubleSide });
    const mesh = new THREE.Mesh(g, mat);
    mesh.position.y = 0.002;
    root.add(mesh);
  }
  // стёкла окон
  const glass = new THREE.MeshStandardMaterial({ color: 0x6fb6e6, transparent: true, opacity: 0.45 });
  for (const o of openings.filter((o) => o.type === 'win')) {
    const g = new THREE.BoxGeometry(o.axis === 'y' ? mm(o.b - o.a) : 0.02, mm(o.hi - o.lo), o.axis === 'y' ? 0.02 : mm(o.b - o.a));
    const m = new THREE.Mesh(g, glass);
    m.position.set(o.axis === 'y' ? mm((o.a + o.b) / 2) : mm(o.at), mm((o.lo + o.hi) / 2), o.axis === 'y' ? mm(o.at) : mm((o.a + o.b) / 2));
    root.add(m);
  }

  // --- подписи комнат ---
  const labels = new THREE.Group();
  for (const r of h.rooms) {
    const p = r.floor_polygon_mm.value;
    const cx = (Math.min(...p.map((q) => q[0])) + Math.max(...p.map((q) => q[0]))) / 2;
    const cy = (Math.min(...p.map((q) => q[1])) + Math.max(...p.map((q) => q[1]))) / 2;
    const b = r.bbox_size_mm;
    labels.add(sprite([roomName(r).name, `≈ ${m1n(b.x.value)} × ${m1n(b.y.value)} м`], mm(cx), 0.5, mm(cy)));
  }
  root.add(labels);

  // --- крыша: оценка (конёк вдоль улицы) ---
  const roof = new THREE.Group();
  const rh = h.stair_to_attic.roof_headroom;
  const ridgeZ = rh.ridge_above_wall_top_mm.value, ridgeY = rh.ridge_y_mm.value, y0 = h.exterior.porch_projection_y.value;
  const roofMat = new THREE.MeshStandardMaterial({ color: 0x9c4f3a, transparent: true, opacity: 0.35, side: THREE.DoubleSide, depthWrite: false });
  const rg = new THREE.BufferGeometry();
  const V = (x, y, z) => [mm(x), mm(z), mm(y)];
  const verts = [
    ...V(0, y0, H), ...V(W, y0, H), ...V(W, ridgeY, H + ridgeZ), ...V(0, y0, H), ...V(W, ridgeY, H + ridgeZ), ...V(0, ridgeY, H + ridgeZ),
    ...V(0, ridgeY, H + ridgeZ), ...V(W, ridgeY, H + ridgeZ), ...V(W, D, H), ...V(0, ridgeY, H + ridgeZ), ...V(W, D, H), ...V(0, D, H),
    ...V(0, y0, H), ...V(0, ridgeY, H + ridgeZ), ...V(0, D, H), ...V(W, y0, H), ...V(W, ridgeY, H + ridgeZ), ...V(W, D, H),
  ];
  rg.setAttribute('position', new THREE.Float32BufferAttribute(verts, 3));
  rg.computeVertexNormals();
  roof.add(new THREE.Mesh(rg, roofMat));
  // окна мансарды на фронтонах
  for (const w of h.windows.filter((w) => w.level === 'attic')) {
    const x = w.wall === 'gable_far' ? W + 20 : -20;
    const lo = H + w.sill_above_floor_mm.value, hi = lo + w.height_mm.value;
    const g = new THREE.BoxGeometry(0.03, mm(hi - lo), mm(w.width_mm.value));
    const m = new THREE.Mesh(g, glass);
    m.position.set(mm(x), mm((lo + hi) / 2), mm((w.from_mm.value + w.to_mm.value) / 2));
    roof.add(m);
  }
  roof.add(sprite(['Крыша и конёк: оценка', '±' + (rh.ridge_above_wall_top_mm.tolerance_mm / 1000).toLocaleString('ru-RU') + ' м'], mm(W / 2), mm(H + ridgeZ) + 0.6, mm(ridgeY), '#9c4f3a'));
  roof.visible = false;
  root.add(roof);

  // --- вариант лестницы: предварительно ---
  const stair = new THREE.Group();
  const st = stairVariant(h);
  if (st) {
    const c = h.stair_to_attic.design_check_not_a_design;
    const sMat = new THREE.MeshStandardMaterial({ color: 0xc2255c, transparent: true, opacity: 0.55 });
    for (let n = 0; n < c.treads_in_first_run; n++) {
      const x = st.x2 - (n + 1) * c.assumed_tread_mm;
      const top = (n + 1) * c.assumed_rise_mm;
      const g = new THREE.BoxGeometry(mm(c.assumed_tread_mm), mm(top), mm(st.w));
      const m = new THREE.Mesh(g, sMat);
      m.position.set(mm(Math.max(x, st.x1 + st.w) + c.assumed_tread_mm / 2), mm(top / 2), mm(st.y1 + st.w / 2));
      stair.add(m);
    }
    const land = new THREE.Mesh(new THREE.BoxGeometry(mm(st.w), 0.06, mm(st.w)), sMat);
    land.position.set(mm(st.x1 + st.w / 2), mm(c.landing_height_mm), mm(st.y1 + st.w / 2));
    stair.add(land);
    const run2 = new THREE.Mesh(new THREE.BoxGeometry(mm(st.w), 0.06, mm(st.y2 - st.y1 - st.w)), sMat);
    const rise2 = h.stair_to_attic.design_check_not_a_design.assumed_floor_to_attic_mm.value - c.landing_height_mm;
    run2.position.set(mm(st.x1 + st.w / 2), mm(c.landing_height_mm + rise2 / 2), mm(st.y1 + st.w + (st.y2 - st.y1 - st.w) / 2));
    run2.rotation.x = Math.atan2(mm(rise2), mm(st.y2 - st.y1 - st.w));
    stair.add(run2);
    // дверь санузла — возможный конфликт
    const d7 = openings.find((o) => o.id === 'D7');
    if (d7) {
      const g = new THREE.BoxGeometry(mm(d7.t) + 0.04, mm(d7.hi), mm(d7.b - d7.a));
      const m = new THREE.Mesh(g, new THREE.MeshBasicMaterial({ color: 0xe03131, transparent: true, opacity: 0.35 }));
      m.position.set(mm(d7.at), mm(d7.hi / 2), mm((d7.a + d7.b) / 2));
      stair.add(m);
    }
    stair.add(sprite(['Лестница: предварительно', 'возможен конфликт с дверью санузла'], mm((st.x1 + st.x2) / 2), 3.6, mm(st.y1 + st.w / 2), '#c2255c'));
  }
  stair.visible = false;
  root.add(stair);

  // --- камера, переключатели ---
  const view = (mode) => {
    if (mode === 'top') camera.position.set(0.01, 26, 0.01);
    else camera.position.set(-mm(W) * 0.9, mm(W) * 1.1, -mm(D) * 1.25);
    controls.target.set(0, 0.8, 0);
    controls.update();
  };
  view('persp');
  ui.roof.addEventListener('change', () => { roof.visible = ui.roof.checked; });
  ui.stair.addEventListener('change', () => { stair.visible = ui.stair.checked; });
  ui.labels.addEventListener('change', () => { labels.visible = ui.labels.checked; });
  ui.top.addEventListener('click', () => view('top'));
  ui.persp.addEventListener('click', () => view('persp'));

  const resize = () => {
    const { clientWidth: w, clientHeight: hh } = el;
    renderer.setSize(w, hh, false);
    renderer.domElement.style.width = '100%';
    renderer.domElement.style.height = '100%';
    camera.aspect = w / hh;
    camera.updateProjectionMatrix();
  };
  new ResizeObserver(resize).observe(el);
  resize();
  renderer.setAnimationLoop(() => { controls.update(); renderer.render(scene, camera); });
}

function sprite(lines, x, y, z, color = '#22303a') {
  const c = document.createElement('canvas');
  const ctx = c.getContext('2d');
  const fs = 44;
  ctx.font = `600 ${fs}px Inter, system-ui, sans-serif`;
  const w = Math.max(...lines.map((l) => ctx.measureText(l).width)) + 40;
  c.width = w; c.height = lines.length * fs * 1.25 + 24;
  ctx.font = `600 ${fs}px Inter, system-ui, sans-serif`;
  ctx.fillStyle = 'rgba(255,255,255,.88)';
  ctx.beginPath(); ctx.roundRect(0, 0, c.width, c.height, 18); ctx.fill();
  ctx.fillStyle = color;
  ctx.textAlign = 'center';
  lines.forEach((l, i) => { ctx.font = `${i ? 400 : 600} ${fs * (i ? 0.85 : 1)}px Inter, system-ui, sans-serif`; ctx.fillText(l, c.width / 2, 12 + fs * (i + 1) * 1.15); });
  const tex = new THREE.CanvasTexture(c);
  tex.colorSpace = THREE.SRGBColorSpace;
  const s = new THREE.Sprite(new THREE.SpriteMaterial({ map: tex, depthTest: false, transparent: true }));
  const k = 0.0062;
  s.scale.set(c.width * k, c.height * k, 1);
  s.position.set(x, y, z);
  s.renderOrder = 10;
  return s;
}
