// 3D дома из measurements.json: стены = контур минус помещения минус проёмы (сетка 50 мм).
// Слои: крыша (оценка), размеры, электрика, вариант лестницы. Клик по комнате — карточка с данными.
import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';
import { roomName, m1n, m05, tol, area, windowWall, doorWall, stairVariant, ELECTRIC, PLUMBING, CONFIDENCE, wallName, roomLabel } from './house.js';

const CELL = 50; // мм

export function createViewer(canvas, h, onPick) {
  const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true });
  renderer.setPixelRatio(Math.min(devicePixelRatio, 2));
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  renderer.toneMapping = THREE.ACESFilmicToneMapping;
  renderer.shadowMap.enabled = true;
  renderer.shadowMap.type = THREE.PCFSoftShadowMap;

  const scene = new THREE.Scene();
  const pmrem = new THREE.PMREMGenerator(renderer);
  scene.environment = pmrem.fromScene(new RoomEnvironment(), 0.04).texture;
  const camera = new THREE.PerspectiveCamera(45, 1, 0.05, 400);
  const controls = new OrbitControls(camera, canvas);
  controls.enableDamping = true;
  controls.autoRotateSpeed = 1.2;
  controls.maxPolarAngle = Math.PI * 0.495;

  const fp = h.exterior.footprint_mm.value;
  const W = Math.max(...fp.map((p) => p[0])), D = Math.max(...fp.map((p) => p[1]));
  const H = h.exterior.wall_height_blocks.value;
  const mm = (v) => v / 1000;
  const radius = Math.hypot(mm(W), mm(D));

  const sun = new THREE.DirectionalLight(0xffffff, 2.2);
  sun.position.set(-radius * 0.6, radius * 1.6, -radius * 0.9);
  sun.castShadow = true;
  sun.shadow.mapSize.set(2048, 2048);
  Object.assign(sun.shadow.camera, { left: -radius, right: radius, top: radius, bottom: -radius, near: 0.5, far: radius * 5 });
  scene.add(sun, new THREE.HemisphereLight(0xdfe8ee, 0x8a8170, 0.6));
  const ground = new THREE.Mesh(new THREE.CircleGeometry(radius * 1.4, 64), new THREE.ShadowMaterial({ opacity: 0.18 }));
  ground.rotation.x = -Math.PI / 2;
  ground.receiveShadow = true;
  scene.add(ground);

  const root = new THREE.Group();
  root.position.set(-mm(W) / 2, 0, -mm(D) / 2);
  scene.add(root);
  const layers = { roof: new THREE.Group(), dims: new THREE.Group(), electric: new THREE.Group(), stair: new THREE.Group(), labels: new THREE.Group() };
  Object.values(layers).forEach((g) => root.add(g));
  layers.roof.visible = layers.dims.visible = layers.electric.visible = layers.stair.visible = false;
  const state = { roof: false, dims: false, electric: false, stair: false, labels: true };

  // --- сетка стен ---
  const nx = Math.ceil(W / CELL), ny = Math.ceil(D / CELL);
  const inPoly = (x, y, poly) => {
    let c = false;
    for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
      const [xi, yi] = poly[i], [xj, yj] = poly[j];
      if ((yi > y) !== (yj > y) && x < ((xj - xi) * (y - yi)) / (yj - yi) + xi) c = !c;
    }
    return c;
  };
  const wall = Array.from({ length: ny }, () => new Array(nx).fill(null));
  for (let j = 0; j < ny; j++) for (let i = 0; i < nx; i++) {
    const x = (i + 0.5) * CELL, y = (j + 0.5) * CELL;
    if (inPoly(x, y, fp) && !h.rooms.some((r) => inPoly(x, y, r.floor_polygon_mm.value))) wall[j][i] = [];
  }
  const cut = (x0, y0, x1, y1, lo, hi) => {
    for (let j = Math.floor(Math.min(y0, y1) / CELL); j < Math.ceil(Math.max(y0, y1) / CELL); j++)
      for (let i = Math.floor(Math.min(x0, x1) / CELL); i < Math.ceil(Math.max(x0, x1) / CELL); i++)
        if (wall[j]?.[i]) wall[j][i].push([lo, hi]);
  };
  const openings = [];
  for (const w of h.windows.filter((w) => w.level === 'ground')) {
    const g = windowWall(h, w);
    const a = w.from_mm.value, b = w.to_mm.value, t0 = g.dir > 0 ? g.at : g.at - g.t;
    const lo = w.sill_above_floor_mm.value, hi = lo + w.height_mm.value;
    if (g.axis === 'y') cut(a, t0, b, t0 + g.t, lo, hi); else cut(t0, a, t0 + g.t, b, lo, hi);
    openings.push({ kind: 'win', w, axis: g.axis, a, b, at: t0 + g.t / 2, lo, hi, out: g.dir });
  }
  for (const d of h.doors) {
    const g = doorWall(h, d);
    const a = d.from_mm.value, b = d.to_mm.value, hi = d.height_mm.value;
    if (g.axis === 'y') cut(a, g.at, b, g.at + g.t, 0, hi); else cut(g.at, a, g.at + g.t, b, 0, hi);
    openings.push({ kind: 'door', d, axis: g.axis, a, b, at: g.at + g.t / 2, hi, t: g.t });
  }
  const boxes = [];
  for (let j = 0; j < ny; j++) {
    let i = 0;
    while (i < nx) {
      const c = wall[j][i];
      if (!c) { i++; continue; }
      const key = JSON.stringify(c);
      let k = i + 1;
      while (k < nx && wall[j][k] && JSON.stringify(wall[j][k]) === key) k++;
      let z = 0;
      for (const [lo, hi] of [...c.slice().sort((p, q) => p[0] - q[0]), [H, H]]) {
        if (lo > z) boxes.push([i * CELL, j * CELL, (k - i) * CELL, CELL, z, lo - z]);
        z = Math.max(z, hi);
      }
      i = k;
    }
  }
  const wallMat = new THREE.MeshStandardMaterial({ color: 0xe4ded3, roughness: 0.92 });
  const walls = new THREE.InstancedMesh(new THREE.BoxGeometry(1, 1, 1), wallMat, boxes.length);
  const m4 = new THREE.Matrix4();
  boxes.forEach(([x, y, w, d, z, hh], n) => {
    m4.compose(new THREE.Vector3(mm(x + w / 2), mm(z + hh / 2), mm(y + d / 2)), new THREE.Quaternion(), new THREE.Vector3(mm(w), mm(hh), mm(d)));
    walls.setMatrixAt(n, m4);
  });
  walls.castShadow = walls.receiveShadow = true;
  root.add(walls);
  // цоколь
  const plinthH = h.exterior.plinth?.visible_brick_height_mm?.value || 0;
  const plinth = new THREE.Mesh(new THREE.ExtrudeGeometry(new THREE.Shape(fp.map(([x, y]) => new THREE.Vector2(mm(x), mm(y)))), { depth: mm(plinthH), bevelEnabled: false }),
    new THREE.MeshStandardMaterial({ color: 0xa65a46, roughness: 0.9 }));
  plinth.rotation.x = Math.PI / 2; // экструзия уходит вниз от пола: цоколь под стенами
  plinth.castShadow = true;
  root.add(plinth);
  root.position.y = mm(plinthH);

  // --- полы помещений (по ним кликают) ---
  const floors = [];
  const floorMat = (r, hl) => new THREE.MeshStandardMaterial({ color: hl ? 0xbfe0ef : r.confidence === 'low' ? 0xf3e1a8 : 0xf6f2ea, roughness: 0.95, side: THREE.DoubleSide });
  for (const r of h.rooms) {
    const shape = new THREE.Shape(r.floor_polygon_mm.value.map(([x, y]) => new THREE.Vector2(mm(x), mm(y))));
    const g = new THREE.ShapeGeometry(shape);
    g.rotateX(Math.PI / 2);
    const mesh = new THREE.Mesh(g, floorMat(r));
    mesh.position.y = 0.003;
    mesh.receiveShadow = true;
    mesh.userData.room = r;
    floors.push(mesh);
    root.add(mesh);
  }
  // стёкла и двери
  const glass = new THREE.MeshPhysicalMaterial({ color: 0x9fd0ec, transparent: true, opacity: 0.35, roughness: 0.05 });
  const frameMat = new THREE.MeshStandardMaterial({ color: 0xffffff, roughness: 0.5 });
  for (const o of openings.filter((o) => o.kind === 'win')) {
    const along = mm(o.b - o.a), hgt = mm(o.hi - o.lo);
    const g = new THREE.BoxGeometry(o.axis === 'y' ? along : 0.02, hgt, o.axis === 'y' ? 0.02 : along);
    const m = new THREE.Mesh(g, glass);
    m.position.set(o.axis === 'y' ? mm((o.a + o.b) / 2) : mm(o.at), mm((o.lo + o.hi) / 2), o.axis === 'y' ? mm(o.at) : mm((o.a + o.b) / 2));
    root.add(m);
    const sill = new THREE.Mesh(new THREE.BoxGeometry(o.axis === 'y' ? along + 0.06 : 0.36, 0.04, o.axis === 'y' ? 0.36 : along + 0.06), frameMat);
    sill.position.set(m.position.x, mm(o.lo) - 0.02, m.position.z);
    root.add(sill);
  }

  // --- крыша: оценка ---
  const rh = h.stair_to_attic.roof_headroom;
  const ridge = rh.ridge_above_wall_top_mm.value, ridgeY = rh.ridge_y_mm.value, y0 = h.exterior.porch_projection_y.value;
  const over = 400; // свес только для вида
  const V = (x, y, z) => [mm(x), mm(z), mm(y)];
  const slope1 = (ridge) / (ridgeY - y0), slope2 = ridge / (D - ridgeY);
  const eave1 = H - over * slope1, eave2 = H - over * slope2;
  const roofGeo = new THREE.BufferGeometry();
  roofGeo.setAttribute('position', new THREE.Float32BufferAttribute([
    ...V(-over, y0 - over, eave1), ...V(W + over, y0 - over, eave1), ...V(W + over, ridgeY, H + ridge),
    ...V(-over, y0 - over, eave1), ...V(W + over, ridgeY, H + ridge), ...V(-over, ridgeY, H + ridge),
    ...V(-over, ridgeY, H + ridge), ...V(W + over, ridgeY, H + ridge), ...V(W + over, D + over, eave2),
    ...V(-over, ridgeY, H + ridge), ...V(W + over, D + over, eave2), ...V(-over, D + over, eave2),
  ], 3));
  roofGeo.computeVertexNormals();
  const roof = new THREE.Mesh(roofGeo, new THREE.MeshStandardMaterial({ color: 0x7d3b2f, roughness: 0.8, side: THREE.DoubleSide }));
  roof.castShadow = true;
  layers.roof.add(roof);
  const gableGeo = new THREE.BufferGeometry();
  gableGeo.setAttribute('position', new THREE.Float32BufferAttribute([
    ...V(0, y0, H), ...V(0, ridgeY, H + ridge), ...V(0, D, H), ...V(W, y0, H), ...V(W, ridgeY, H + ridge), ...V(W, D, H),
  ], 3));
  gableGeo.computeVertexNormals();
  const gable = new THREE.Mesh(gableGeo, new THREE.MeshStandardMaterial({ color: 0xd8d2c6, emissive: 0x6b675f, side: THREE.DoubleSide }));
  layers.roof.add(gable);
  for (const w of h.windows.filter((w) => w.level === 'attic')) {
    const x = w.wall === 'gable_far' ? W + 15 : -15;
    const lo = H + w.sill_above_floor_mm.value, hi = lo + w.height_mm.value;
    const m = new THREE.Mesh(new THREE.BoxGeometry(0.03, mm(hi - lo), mm(w.width_mm.value)), new THREE.MeshStandardMaterial({ color: 0x6fa8c8, roughness: 0.2 }));
    m.position.set(mm(x), mm((lo + hi) / 2), mm((w.from_mm.value + w.to_mm.value) / 2));
    layers.roof.add(m);
  }
  layers.roof.add(sprite(['Крыша: оценка', 'конёк ≈ ' + m1n(ridge) + ' м над стенами ' + tol(rh.ridge_above_wall_top_mm.tolerance_mm)], mm(W / 2), mm(H + ridge) + 0.9, mm(ridgeY), { color: '#7d3b2f' }));

  // --- подписи комнат (всегда) и размеры (слой) ---
  const centerOf = (r) => {
    const p = r.floor_polygon_mm.value, xs = p.map((q) => q[0]), ys = p.map((q) => q[1]);
    if (p.length > 4) { // Г-образный холл: центр широкой части
      const xr = Math.max(...xs), xl = Math.max(...xs.filter((x) => x < xr));
      const yb = Math.min(...p.filter((q) => q[0] === Math.min(...xs)).map((q) => q[1]));
      return [(xl + xr) / 2, (Math.min(...ys) + yb) / 2];
    }
    return [(Math.min(...xs) + Math.max(...xs)) / 2, (Math.min(...ys) + Math.max(...ys)) / 2];
  };
  for (const r of h.rooms) {
    const [cx, cy] = centerOf(r);
    const name = roomName(r).name;
    layers.labels.add(sprite([name], mm(cx), 0.55, mm(cy), { small: true }));
    const b = r.bbox_size_mm;
    layers.dims.add(sprite([`≈ ${m1n(b.x.value)} × ${m1n(b.y.value)} м`, `${name} · ${area(r.floor_area_m2)} · ${tol(Math.max(b.x.tolerance_mm, b.y.tolerance_mm))}`], mm(cx), 0.7, mm(cy), { color: '#2f6f8f' }));
  }
  const e = h.exterior;
  const dimLine = (x1, z1, x2, z2, text) => {
    const pts = [new THREE.Vector3(x1, 0.05, z1), new THREE.Vector3(x2, 0.05, z2)];
    layers.dims.add(new THREE.Line(new THREE.BufferGeometry().setFromPoints(pts), new THREE.LineBasicMaterial({ color: 0x22303a })));
    layers.dims.add(sprite([text], (x1 + x2) / 2, 0.4, (z1 + z2) / 2, { color: '#22303a' }));
  };
  dimLine(0, mm(D) + 1.2, mm(W), mm(D) + 1.2, `${m05(e.total_width_x.value)} ${tol(e.total_width_x.tolerance_mm)}`);
  dimLine(mm(W) + 1.2, 0, mm(W) + 1.2, mm(D), `${m05(e.total_depth_y.value)} ${tol(e.total_depth_y.tolerance_mm)}`);
  layers.dims.add(sprite([`высота стен ${'≈ ' + m1n(H)} м ${tol(e.wall_height_blocks.tolerance_mm)}`], mm(W) + 0.3, mm(H) + 0.4, mm(D) + 0.3, { color: '#22303a', tiny: true }));

  // --- электрика: отметка на комнату, места не измерены ---
  const ICON = { socket: '🔌', socket_double: '🔌', switch: '⏻', switch_block_2: '⏻', light_pendant: '💡', light_cable: '💡', electrical_panel: '⚡', junction_box: '▣', junction_box_rough: '▣', floor_outlet_or_hole: '○', cable_outlet_low: '○' };
  for (const r of h.rooms) {
    const items = h.electrical.filter((x) => x.room === r.id);
    if (!items.length) continue;
    const sum = {};
    for (const x of items) {
      const k = /socket/.test(x.kind) ? 'розетки' : /switch/.test(x.kind) ? 'выключатели' : /light/.test(x.kind) ? 'свет' : /panel/.test(x.kind) ? 'щиток' : 'коробки и выводы';
      sum[k] = (sum[k] || 0) + (x.count || 1) * (x.kind === 'socket_double' ? 1 : 1);
    }
    const [cx, cy] = centerOf(r);
    const line = Object.entries(sum).map(([k, n]) => `${{ розетки: '🔌', выключатели: '⏻', свет: '💡', щиток: '⚡', 'коробки и выводы': '▣' }[k]} ${n}`).join('  ');
    layers.electric.add(sprite([line, roomName(r).name + ' · места не измерены'], mm(cx), 0.7, mm(cy), { color: '#6b5aa6' }));
  }

  // --- вариант лестницы ---
  const st = stairVariant(h);
  if (st) {
    const c = h.stair_to_attic.design_check_not_a_design;
    const sMat = new THREE.MeshStandardMaterial({ color: 0xc2255c, transparent: true, opacity: 0.6 });
    for (let n = 0; n < c.treads_in_first_run; n++) {
      const top = (n + 1) * c.assumed_rise_mm;
      const x = Math.max(st.x2 - (n + 1) * c.assumed_tread_mm, st.x1 + st.w);
      const m = new THREE.Mesh(new THREE.BoxGeometry(mm(c.assumed_tread_mm), mm(top), mm(st.w)), sMat);
      m.position.set(mm(x + c.assumed_tread_mm / 2), mm(top / 2), mm(st.y1 + st.w / 2));
      layers.stair.add(m);
    }
    const land = new THREE.Mesh(new THREE.BoxGeometry(mm(st.w), 0.06, mm(st.w)), sMat);
    land.position.set(mm(st.x1 + st.w / 2), mm(c.landing_height_mm), mm(st.y1 + st.w / 2));
    layers.stair.add(land);
    const len2 = st.y2 - st.y1 - st.w, rise2 = c.assumed_floor_to_attic_mm.value - c.landing_height_mm;
    const run2 = new THREE.Mesh(new THREE.BoxGeometry(mm(st.w), 0.06, Math.hypot(mm(len2), mm(rise2))), sMat);
    run2.position.set(mm(st.x1 + st.w / 2), mm(c.landing_height_mm + rise2 / 2), mm(st.y1 + st.w + len2 / 2));
    run2.rotation.x = -Math.atan2(mm(rise2), mm(len2));
    layers.stair.add(run2);
    const d7 = openings.find((o) => o.d?.id === 'D7');
    if (d7) {
      const m = new THREE.Mesh(new THREE.BoxGeometry(mm(d7.t) + 0.04, mm(d7.hi), mm(d7.b - d7.a)), new THREE.MeshBasicMaterial({ color: 0xe03131, transparent: true, opacity: 0.35 }));
      m.position.set(mm(d7.at), mm(d7.hi / 2), mm((d7.a + d7.b) / 2));
      layers.stair.add(m);
    }
    layers.stair.add(sprite(['Лестница: предварительно', 'возможен конфликт с дверью санузла'], mm((st.x1 + st.x2) / 2), 3.9, mm(st.y1 + st.w / 2), { color: '#c2255c' }));
  }

  // --- выбор комнаты кликом ---
  const ray = new THREE.Raycaster(), ptr = new THREE.Vector2();
  let picked = null, downAt = null;
  canvas.addEventListener('pointerdown', (ev) => { downAt = [ev.clientX, ev.clientY]; });
  canvas.addEventListener('pointerup', (ev) => {
    if (!downAt || Math.hypot(ev.clientX - downAt[0], ev.clientY - downAt[1]) > 6) return; // это было вращение
    const r = canvas.getBoundingClientRect();
    ptr.set(((ev.clientX - r.left) / r.width) * 2 - 1, -((ev.clientY - r.top) / r.height) * 2 + 1);
    ray.setFromCamera(ptr, camera);
    const hit = ray.intersectObjects([walls, ...floors], false)[0];
    select(hit && hit.object !== walls ? hit.object.userData.room : null);
  });
  const select = (room) => {
    for (const f of floors) f.material = floorMat(f.userData.room, room && f.userData.room === room);
    picked = room;
    onPick?.(room ? roomInfo(h, room) : null);
  };

  // --- виды ---
  const target = new THREE.Vector3(0, mm(H) / 2, 0);
  const dist = radius * 1.05;
  const views = {
    reset: [-dist * 0.75, dist * 0.75, -dist * 0.85],
    front: [0, dist * 0.32, -dist * 1.15],
    side: [-dist * 1.15, dist * 0.32, 0],
    back: [0, dist * 0.45, dist * 1.1],
    top: [0, dist * 1.45, 0.001],
  };
  let anim = null;
  const view = (name) => {
    const to = new THREE.Vector3(...views[name]);
    anim = { from: camera.position.clone(), to, fromT: controls.target.clone(), toT: target.clone(), start: performance.now() };
  };
  camera.position.set(...views.reset);
  controls.target.copy(target);
  controls.maxDistance = radius * 4;
  controls.minDistance = 2;

  const resize = () => {
    const w = canvas.clientWidth, hh = canvas.clientHeight;
    renderer.setSize(w, hh, false);
    camera.aspect = w / hh;
    camera.updateProjectionMatrix();
  };
  addEventListener('resize', resize);
  resize();
  renderer.setAnimationLoop(() => {
    if (anim) {
      const t = Math.min(1, (performance.now() - anim.start) / 700);
      const k = 1 - Math.pow(1 - t, 3);
      camera.position.lerpVectors(anim.from, anim.to, k);
      controls.target.lerpVectors(anim.fromT, anim.toT, k);
      if (t >= 1) anim = null;
    }
    controls.update();
    renderer.render(scene, camera);
  });

  return {
    view,
    layer: (name, on) => {
      state[name] = on;
      layers.roof.visible = state.roof;
      layers.stair.visible = state.stair;
      layers.dims.visible = state.dims && !state.roof;
      layers.electric.visible = state.electric && !state.roof && !state.dims;
      layers.labels.visible = state.labels && !state.roof && !state.dims && !state.electric;
    },
    autoRotate: (on) => { controls.autoRotate = on; },
    wireframe: (on) => { wallMat.wireframe = on; },
    select,
    get picked() { return picked; },
  };
}

/** Данные для карточки комнаты. */
function roomInfo(h, r) {
  const n = roomName(r), b = r.bbox_size_mm;
  return {
    name: n.name, hint: n.hint,
    size: `≈ ${m1n(b.x.value)} × ${m1n(b.y.value)} м`, tol: tol(Math.max(b.x.tolerance_mm, b.y.tolerance_mm)),
    area: area(r.floor_area_m2), height: '≈ ' + m1n(r.wall_top_height_mm.value) + ' м',
    confidence: CONFIDENCE[r.confidence] || r.confidence,
    note: r.floor_polygon_mm.note || null,
    windows: h.windows.filter((w) => w.room === r.id).map((w) => `${w.id.replace('_small', '')}: ≈ ${m1n(w.width_mm.value)} × ${m1n(w.height_mm.value)} м, низ ≈ ${m1n(w.sill_above_floor_mm.value)} м`),
    doors: h.doors.filter((d) => d.between.includes(r.id)).map((d) => `${d.id.replace('_entrance', '')} → ${roomLabel(h, d.between.find((x) => x !== r.id))}: ширина ≈ ${m1n(d.width_mm.value)} м${d.height_mm.status === 'assumed' ? ', высота — предположение' : ''}`),
    electric: Object.values(h.electrical.filter((x) => x.room === r.id).reduce((acc, x) => {
      const k = ELECTRIC[x.kind] || x.kind;
      acc[k] ??= { k, n: 0, notes: [] };
      acc[k].n += x.count || 1;
      if (x.note) acc[k].notes.push(x.note);
      return acc;
    }, {})).map((g) => `${g.k}: ${g.n}` + (g.notes.length ? ' (' + g.notes.join('; ') + ')' : '')),
    plumbing: h.plumbing.filter((x) => x.room === r.id).map((x) => x.note && /труб/.test(x.note) ? x.note : (PLUMBING[x.kind] || x.kind) + (x.note ? ' — ' + x.note : '')),
  };
}

function sprite(lines, x, y, z, o = {}) {
  const c = document.createElement('canvas');
  const ctx = c.getContext('2d');
  const fs = o.tiny ? 34 : o.small ? 40 : 44;
  const font = (i) => `${i ? 400 : 600} ${i ? fs * 0.82 : fs}px Onest, Inter, system-ui, sans-serif`;
  const widths = lines.map((l, i) => { ctx.font = font(i); return ctx.measureText(l).width; });
  c.width = Math.ceil(Math.max(...widths) + 36);
  c.height = Math.ceil(fs * 0.5 + lines.length * fs * 1.2 + 6);
  ctx.fillStyle = 'rgba(255,255,255,.9)';
  ctx.beginPath(); ctx.roundRect(1, 1, c.width - 2, c.height - 2, 16); ctx.fill();
  ctx.strokeStyle = 'rgba(34,48,58,.12)'; ctx.stroke();
  ctx.textAlign = 'center';
  lines.forEach((l, i) => { ctx.font = font(i); ctx.fillStyle = i ? '#5d6d78' : (o.color || '#22303a'); ctx.fillText(l, c.width / 2, fs * 0.25 + fs * (i + 1) * 1.15 - 4); });
  const tex = new THREE.CanvasTexture(c);
  tex.colorSpace = THREE.SRGBColorSpace;
  tex.anisotropy = 4;
  const s = new THREE.Sprite(new THREE.SpriteMaterial({ map: tex, depthTest: false, transparent: true }));
  const k = (o.tiny ? 0.0058 : 0.0068);
  s.scale.set(c.width * k, c.height * k, 1);
  s.position.set(x, y, z);
  s.renderOrder = 10;
  return s;
}
