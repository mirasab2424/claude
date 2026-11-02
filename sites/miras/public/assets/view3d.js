// 3D дома из measurements.json: стены = контур минус помещения минус проёмы (сетка 50 мм).
// Слои: крыша (оценка), размеры, электрика, вариант лестницы. Клик по комнате — карточка с данными.
import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { roomName, m1n, m05, tol, area, windowWall, doorWall, stairVariant, ELECTRIC, PLUMBING, CONFIDENCE, wallName, roomLabel } from './house.js?v=5';

const CELL = 50; // мм

export function createViewer(canvas, h, onPick, design = null) {
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
  const zz = (v) => -v / 1000; // y плана идёт от улицы вглубь, в сцене это −z: улица спереди, x вправо
  const radius = Math.hypot(mm(W), mm(D));
  const anchor = design?.site_anchor_m || { x: -mm(W) / 2, z: mm(D) / 2 };
  const groundY = design?.ground_y_m ?? 0;

  const sun = new THREE.DirectionalLight(0xffffff, 2.2);
  sun.position.set(-radius * 0.6, radius * 1.6, radius * 0.9);
  sun.castShadow = true;
  sun.shadow.mapSize.set(2048, 2048);
  Object.assign(sun.shadow.camera, { left: -radius * 1.6, right: radius * 1.6, top: radius * 1.6, bottom: -radius * 1.6, near: 0.5, far: radius * 8 });
  scene.add(sun, new THREE.HemisphereLight(0xdfe8ee, 0x8a8170, 0.6));
  const ground = new THREE.Mesh(new THREE.CircleGeometry(radius * 1.4, 64), new THREE.ShadowMaterial({ opacity: 0.18 }));
  ground.rotation.x = -Math.PI / 2;
  ground.receiveShadow = true;
  scene.add(ground);

  const root = new THREE.Group();
  scene.add(root);
  const layers = { attic: new THREE.Group(), roof: new THREE.Group(), dims: new THREE.Group(), electric: new THREE.Group(), stair: new THREE.Group(), labels: new THREE.Group(), design: new THREE.Group() };
  Object.values(layers).forEach((g) => root.add(g));
  layers.site = new THREE.Group();
  scene.add(layers.site); // участок — в координатах старой модели
  layers.roof.visible = layers.dims.visible = layers.electric.visible = layers.stair.visible = false;
  layers.attic.visible = false;
  const state = { attic: false, roof: false, dims: false, electric: false, stair: false, labels: true, site: true, design: true };

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
    m4.compose(new THREE.Vector3(mm(x + w / 2), mm(z + hh / 2), zz(y + d / 2)), new THREE.Quaternion(), new THREE.Vector3(mm(w), mm(hh), mm(d)));
    walls.setMatrixAt(n, m4);
  });
  walls.castShadow = walls.receiveShadow = true;
  root.add(walls);
  // цоколь
  const plinthH = h.exterior.plinth?.visible_brick_height_mm?.value || 0;
  const plinth = new THREE.Mesh(new THREE.ExtrudeGeometry(new THREE.Shape(fp.map(([x, y]) => new THREE.Vector2(mm(x), mm(y)))), { depth: mm(plinthH), bevelEnabled: false }),
    new THREE.MeshStandardMaterial({ color: 0xa65a46, roughness: 0.9 }));
  plinth.rotation.x = -Math.PI / 2; // контур ложится на пол, экструзия идёт вверх
  plinth.position.y = -mm(plinthH); // цоколь — от земли до пола
  plinth.castShadow = true;
  root.add(plinth);
  const floorY = groundY + mm(plinthH);
  root.position.set(anchor.x, floorY, anchor.z);
  ground.position.set(anchor.x + mm(W) / 2, groundY + 0.001, anchor.z - mm(D) / 2);

  // --- полы помещений (по ним кликают) ---
  const floors = [];
  const floorMat = (r, hl) => new THREE.MeshStandardMaterial({ color: hl ? 0xbfe0ef : r.confidence === 'low' ? 0xf3e1a8 : 0xf6f2ea, roughness: 0.95, side: THREE.DoubleSide });
  for (const r of h.rooms) {
    const shape = new THREE.Shape(r.floor_polygon_mm.value.map(([x, y]) => new THREE.Vector2(mm(x), mm(y))));
    const g = new THREE.ShapeGeometry(shape);
    g.rotateX(-Math.PI / 2);
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
    m.position.set(o.axis === 'y' ? mm((o.a + o.b) / 2) : mm(o.at), mm((o.lo + o.hi) / 2), o.axis === 'y' ? zz(o.at) : zz((o.a + o.b) / 2));
    root.add(m);
    const sill = new THREE.Mesh(new THREE.BoxGeometry(o.axis === 'y' ? along + 0.06 : 0.36, 0.04, o.axis === 'y' ? 0.36 : along + 0.06), frameMat);
    sill.position.set(m.position.x, mm(o.lo) - 0.02, m.position.z);
    root.add(sill);
  }

  // пороги в проёмах — чтобы было видно, что это проход
  const sillMat = new THREE.MeshStandardMaterial({ color: 0xd9cbb4, roughness: 0.9 });
  for (const o of openings.filter((o) => o.kind === 'door')) {
    const along = mm(o.b - o.a), t = mm(o.t);
    const g = new THREE.BoxGeometry(o.axis === 'y' ? along : t, 0.012, o.axis === 'y' ? t : along);
    const m = new THREE.Mesh(g, sillMat);
    m.position.set(o.axis === 'y' ? mm((o.a + o.b) / 2) : mm(o.at), 0.006, o.axis === 'y' ? zz(o.at) : zz((o.a + o.b) / 2));
    m.receiveShadow = true;
    root.add(m);
  }
  // входная дверь (между улицей и домом): полотно открыто внутрь на ~70°
  const entrance = openings.find((o) => o.kind === 'door' && o.d.between.includes('outside'));
  if (entrance) {
    const wdt = mm(entrance.b - entrance.a), hgt = mm(entrance.hi);
    const leafGeo = new THREE.BoxGeometry(0.045, hgt - 0.02, wdt - 0.04);
    leafGeo.translate(0, (hgt - 0.02) / 2, -(wdt - 0.04) / 2); // петли на краю полотна
    const leaf = new THREE.Mesh(leafGeo, new THREE.MeshStandardMaterial({ color: 0x7a4e2a, roughness: 0.6 }));
    const inner = entrance.at + entrance.t / 2; // внутренняя грань стены
    if (entrance.axis === 'x') {
      leaf.position.set(mm(inner), 0.01, zz(entrance.a) - 0.02);
      leaf.rotation.y = -1.2;
    }
    leaf.castShadow = true;
    root.add(leaf);
    const knob = new THREE.Mesh(new THREE.SphereGeometry(0.03), new THREE.MeshStandardMaterial({ color: 0xc9a227, metalness: 0.8, roughness: 0.3 }));
    knob.position.set(0.04, 1.0, -(wdt - 0.04) + 0.08);
    leaf.add(knob);
  }

  // --- крыша: оценка ---
  const rh = h.stair_to_attic.roof_headroom;
  const ridge = rh.ridge_above_wall_top_mm.value, ridgeY = rh.ridge_y_mm.value, y0 = h.exterior.porch_projection_y.value;
  const over = 400; // свес только для вида
  const V = (x, y, z) => [mm(x), mm(z), zz(y)];
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
    m.position.set(mm(x), mm((lo + hi) / 2), zz((w.from_mm.value + w.to_mm.value) / 2));
    layers.roof.add(m);
  }
  layers.roof.add(sprite(['Крыша — оценка'], mm(W / 2), mm(H + ridge) + 0.7, zz(ridgeY), { color: '#7d3b2f', small: true }));

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
    layers.labels.add(sprite([name], mm(cx), 0.55, zz(cy), { small: true }));
    const b = r.bbox_size_mm;
    layers.dims.add(sprite([`≈ ${m1n(b.x.value)} × ${m1n(b.y.value)} м`], mm(cx), 0.7, zz(cy), { color: '#2f6f8f', small: true }));
  }
  const e = h.exterior;
  const dimLine = (x1, z1, x2, z2, text) => {
    const pts = [new THREE.Vector3(x1, 0.05, z1), new THREE.Vector3(x2, 0.05, z2)];
    layers.dims.add(new THREE.Line(new THREE.BufferGeometry().setFromPoints(pts), new THREE.LineBasicMaterial({ color: 0x22303a })));
    layers.dims.add(sprite([text], (x1 + x2) / 2, 0.4, (z1 + z2) / 2, { color: '#22303a' }));
  };
  dimLine(0, zz(D) - 1.2, mm(W), zz(D) - 1.2, m05(e.total_width_x.value));
  dimLine(mm(W) + 1.2, 0, mm(W) + 1.2, zz(D), m05(e.total_depth_y.value));
  layers.dims.add(sprite([`высота стен ${'≈ ' + m1n(H)} м ${tol(e.wall_height_blocks.tolerance_mm)}`], mm(W) + 0.3, mm(H) + 0.4, zz(D) - 0.3, { color: '#22303a', tiny: true }));

  // --- электрика: значки на стенах. Что есть в комнате — по видео, а место на стене условное (не измерено) ---
  for (const it of placeElectric(h, openings)) {
    const ic = electricIcon(it.kind);
    if (it.kind === 'light_pendant') {
      const cord = new THREE.Mesh(new THREE.CylinderGeometry(0.006, 0.006, 0.5), new THREE.MeshBasicMaterial({ color: 0x333333 }));
      cord.position.set(mm(it.x), mm(H) - 0.25, zz(it.y));
      const bulb = new THREE.Mesh(new THREE.SphereGeometry(0.07, 16, 12), new THREE.MeshStandardMaterial({ color: 0xfff3b0, emissive: 0xffd34d, emissiveIntensity: 1.2 }));
      bulb.position.set(mm(it.x), mm(H) - 0.55, zz(it.y));
      layers.electric.add(cord, bulb);
      continue;
    }
    const m = new THREE.Mesh(new THREE.PlaneGeometry(ic.w, ic.h), new THREE.MeshBasicMaterial({ map: ic.tex, transparent: true }));
    m.position.set(mm(it.x + it.nx * 12), mm(it.z), zz(it.y + it.ny * 12));
    m.lookAt(m.position.x + it.nx, m.position.y, m.position.z - it.ny);
    m.userData.electric = it;
    layers.electric.add(m);
  }

  // --- вариант лестницы по эскизу владельца ---
  const st = stairVariant(h);
  if (st) {
    const wood = new THREE.MeshStandardMaterial({ color: 0xb98552, roughness: 0.7 });
    const T = 0.05; // толщина ступени — только для вида
    for (const step of st.steps) {
      const shape = new THREE.Shape(step.pts.map(([x, y]) => new THREE.Vector2(mm(x), mm(y))));
      const g = new THREE.ExtrudeGeometry(shape, { depth: T, bevelEnabled: false });
      g.rotateX(-Math.PI / 2); // (x, y) плана → (x, −z), выдавливание вверх
      const m = new THREE.Mesh(g, wood);
      m.position.y = mm(step.top) - T;
      m.castShadow = m.receiveShadow = true;
      layers.stair.add(m);
      // подступенок — от ступени до предыдущей
      const riserH = mm(st.rise) - T;
      const [p0, p1] = step.kind === 'tread' ? [step.pts[0], step.pts[3]] : [step.pts[0], step.pts[1]];
      if (step.kind === 'tread') {
        const len = Math.hypot(p1[0] - p0[0], p1[1] - p0[1]);
        const r = new THREE.Mesh(new THREE.BoxGeometry(mm(len), riserH, 0.02), wood);
        r.position.set(mm((p0[0] + p1[0]) / 2), mm(step.top) - T - riserH / 2, zz((p0[1] + p1[1]) / 2));
        r.rotation.y = -Math.atan2(p1[1] - p0[1], p1[0] - p0[0]);
        layers.stair.add(r);
      }
    }
    // опорные столбы в центрах поворотов и перила с открытой стороны
    const postMat = new THREE.MeshStandardMaterial({ color: 0x6b4423, roughness: 0.6 });
    const pivots = [[st.x2 - st.w, st.y1 + st.w, 0], [st.x1 + st.w, st.y1 + st.w, st.nW + st.n1]];
    for (const [x, y, k] of pivots) {
      const top = mm(st.steps[Math.min(k + st.nW, st.steps.length) - 1].top) + 0.9;
      const p = new THREE.Mesh(new THREE.BoxGeometry(0.08, top, 0.08), postMat);
      p.position.set(mm(x), top / 2, zz(y));
      layers.stair.add(p);
    }
    // перила по открытой стороне: через центры поворотов и середины ступеней
    const railPts = [];
    st.steps.forEach((step, i) => {
      let p;
      if (step.kind === 'winder') p = step.pts[0];
      else if (i < st.nW + st.n1) p = [(step.pts[0][0] + step.pts[1][0]) / 2, st.y1 + st.w];
      else p = [st.x1 + st.w, (step.pts[0][1] + step.pts[2][1]) / 2];
      railPts.push(new THREE.Vector3(mm(p[0]), mm(step.top) + 0.9, zz(p[1])));
    });
    const path = new THREE.CurvePath();
    for (let i = 1; i < railPts.length; i++) if (railPts[i].distanceTo(railPts[i - 1]) > 1e-3) path.add(new THREE.LineCurve3(railPts[i - 1], railPts[i]));
    layers.stair.add(new THREE.Mesh(new THREE.TubeGeometry(path, 120, 0.022, 6), postMat));
    const od = st.overDoor;
    if (od) {
      const d7 = openings.find((o) => o.d?.id === od.door.id);
      const tight = od.minTop - od.doorTop < 300;
      if (d7 && tight) {
        const m = new THREE.Mesh(new THREE.BoxGeometry(mm(d7.t) + 0.04, mm(d7.hi), mm(d7.b - d7.a)), new THREE.MeshBasicMaterial({ color: 0xe03131, transparent: true, opacity: 0.35 }));
        m.position.set(mm(d7.at), mm(d7.hi / 2), zz((d7.a + d7.b) / 2));
        layers.stair.add(m);
      }
      layers.stair.add(sprite(['Лестница — предварительно', tight ? `над дверью санузла ступень на ≈ ${m1n(od.minTop)} м — тесно` : 'по эскизу владельца'], mm(st.x1 + st.w), mm(H) + 0.7, zz(st.y1 + st.w), { color: '#c2255c', small: true }));
    }
  }

  // --- мансарда: вариант по прошлому плану ---
  const att = design?.attic;
  const F = h.stair_to_attic.design_check_not_a_design.assumed_floor_to_attic_mm.value;
  const roofZ = (y) => y <= ridgeY ? H + ridge * (y - y0) / (ridgeY - y0) : H + ridge * (D - y) / (D - ridgeY);
  const atticRooms = [];
  if (att) {
    const k = (F + att.knee_wall_height_mm - H) / ridge;
    const yKf = Math.round(y0 + k * (ridgeY - y0)), yKb = Math.round(D - k * (D - ridgeY));
    const KY = (v, side) => v === 'knee' ? (side ? yKb : yKf) : v;
    const A = layers.attic;
    // перекрытие с проёмом лестницы
    const slabShape = new THREE.Shape([[0, y0], [W, y0], [W, D], [0, D]].map(([x, y]) => new THREE.Vector2(mm(x), mm(y))));
    if (st) slabShape.holes.push(new THREE.Path(st.opening.map(([x, y]) => new THREE.Vector2(mm(x), mm(y)))));
    const slabG = new THREE.ExtrudeGeometry(slabShape, { depth: mm(F - H), bevelEnabled: false });
    slabG.rotateX(-Math.PI / 2);
    const slab = new THREE.Mesh(slabG, new THREE.MeshStandardMaterial({ color: 0xcfc6b8, roughness: 0.95 }));
    slab.position.y = mm(H);
    slab.castShadow = slab.receiveShadow = true;
    A.add(slab);
    // полы комнат (тёплое дерево; под скатом ниже подкосной стенки — серый)
    const floorWood = new THREE.MeshStandardMaterial({ color: 0xe3c9a3, roughness: 0.85, side: THREE.DoubleSide });
    for (const r of att.rooms) {
      const rects = r.rects_mm.map(([x1, x2, a, b]) => [x1, x2, KY(a, 0), KY(b, 1)]);
      for (const [x1, x2, ya, yb] of rects) {
        const sh = new THREE.Shape([[x1, ya], [x2, ya], [x2, yb], [x1, yb]].map(([x, y]) => new THREE.Vector2(mm(x), mm(y))));
        if (st) {
          const o = st.opening, ox = o.map((p) => p[0]), oy = o.map((p) => p[1]);
          if (Math.min(...ox) >= x1 && Math.max(...ox) <= x2 && Math.min(...oy) >= ya && Math.max(...oy) <= yb) sh.holes.push(new THREE.Path(o.map(([x, y]) => new THREE.Vector2(mm(x), mm(y)))));
        }
        const g = new THREE.ShapeGeometry(sh);
        g.rotateX(-Math.PI / 2);
        const m = new THREE.Mesh(g, floorWood);
        m.position.y = mm(F) + 0.004;
        m.receiveShadow = true;
        A.add(m);
      }
      const [x1, x2, ya, yb] = rects[0];
      atticRooms.push({ ...r, rect: [x1, x2, ya, yb] });
      A.add(sprite([r.name], mm((x1 + x2) / 2), mm(F) + 0.5, zz((ya + yb) / 2), { small: true, color: '#7a4e2a' }));
    }
    // стены: профиль сверху идёт по скату (минус 100 мм на кровлю), двери — дырами
    const partMat = new THREE.MeshStandardMaterial({ color: 0xf1ece4, roughness: 0.9, side: THREE.DoubleSide });
    const wallXY = ({ axis, at, from, to, doors = [], t = 100, bottom = 0, top = null, holes = [], inset = 100 }) => {
      const a = Math.min(from, to), b = Math.max(from, to);
      const lim = (u) => top ?? (axis === 'x' ? roofZ(u) : roofZ(at)) - F - inset;
      const prof = [[a, bottom], [b, bottom], [b, lim(b)]];
      if (axis === 'x' && ridgeY > a && ridgeY < b && top == null) prof.push([ridgeY, lim(ridgeY)]);
      prof.push([a, lim(a)]);
      const sh = new THREE.Shape(prof.map(([u, v]) => new THREE.Vector2(mm(u), mm(v))));
      for (const [da, db] of doors) {
        const dh = Math.min(att.door.height_mm, lim(da) - 100, lim(db) - 100);
        if (dh > 600) sh.holes.push(new THREE.Path([[da, 0], [db, 0], [db, dh], [da, dh]].map(([u, v]) => new THREE.Vector2(mm(u), mm(v)))));
      }
      for (const hp of holes) sh.holes.push(new THREE.Path(hp.map(([u, v]) => new THREE.Vector2(mm(u), mm(v)))));
      const g = new THREE.ExtrudeGeometry(sh, { depth: mm(t), bevelEnabled: false });
      g.translate(0, 0, -mm(t) / 2);
      const m = new THREE.Mesh(g, partMat);
      if (axis === 'x') { m.rotation.y = Math.PI / 2; m.position.set(mm(at), mm(F), 0); }
      else { m.position.set(0, mm(F), zz(at)); }
      m.castShadow = m.receiveShadow = true;
      A.add(m);
    };
    for (const p of att.partitions) wallXY({ ...p, from: KY(p.from, 0), to: KY(p.to, 1) });
    for (const yk of [yKf, yKb]) wallXY({ axis: 'y', at: yk, from: 330, to: W - 330, top: att.knee_wall_height_mm, t: 80 });
    // фронтоны с окнами мансарды (толщина наружной стены)
    const tExt = h.wall_thickness.exterior_mm.value;
    for (const side of ['gable_entry_side', 'gable_far']) {
      const holes = h.windows.filter((w) => w.level === 'attic' && w.wall === side).map((w) => {
        const lo = H + w.sill_above_floor_mm.value - F, hi = lo + w.height_mm.value;
        return [[w.from_mm.value, lo], [w.to_mm.value, lo], [w.to_mm.value, hi], [w.from_mm.value, hi]];
      });
      wallXY({ axis: 'x', at: side === 'gable_far' ? W - tExt / 2 : tExt / 2, from: y0, to: D, t: tExt, bottom: H - F, holes, inset: 0 });
    }
    // перила вокруг проёма лестницы (кроме стороны выхода)
    if (st) {
      const railMat = new THREE.MeshStandardMaterial({ color: 0x6b4423, roughness: 0.6 });
      const o = st.opening;
      for (let i = 0; i < o.length; i++) {
        const [p, q] = [o[i], o[(i + 1) % o.length]];
        if (p[1] === st.y2 && q[1] === st.y2) continue; // выход на мансарду
        const len = Math.hypot(q[0] - p[0], q[1] - p[1]);
        const r = new THREE.Mesh(new THREE.BoxGeometry(mm(len), 0.05, 0.05), railMat);
        r.position.set(mm((p[0] + q[0]) / 2), mm(F) + 0.9, zz((p[1] + q[1]) / 2));
        r.rotation.y = -Math.atan2(q[1] - p[1], q[0] - p[0]);
        A.add(r);
        for (let s2 = 0; s2 <= Math.floor(len / 900); s2++) {
          const f = Math.min(1, s2 * 900 / len);
          const post = new THREE.Mesh(new THREE.BoxGeometry(0.04, 0.9, 0.04), railMat);
          post.position.set(mm(p[0] + (q[0] - p[0]) * f), mm(F) + 0.45, zz(p[1] + (q[1] - p[1]) * f));
          A.add(post);
        }
      }
    }
    // окна в скатах (вариант): стекло по плоскости ската
    const glassRoof = new THREE.MeshPhysicalMaterial({ color: 0x9fd0ec, transparent: true, opacity: 0.45, roughness: 0.05, side: THREE.DoubleSide });
    for (const rw of att.roof_windows || []) {
      const front = rw.slope === 'front';
      const ya = front ? y0 + (ridgeY - y0) * 0.45 : D - (D - ridgeY) * 0.45, yb = front ? y0 + (ridgeY - y0) * 0.68 : D - (D - ridgeY) * 0.68;
      for (const [x1, x2] of [rw.x]) {
        const g = new THREE.BufferGeometry();
        g.setAttribute('position', new THREE.Float32BufferAttribute([
          ...V(x1, ya, roofZ(ya) - 60), ...V(x2, ya, roofZ(ya) - 60), ...V(x2, yb, roofZ(yb) - 60),
          ...V(x1, ya, roofZ(ya) - 60), ...V(x2, yb, roofZ(yb) - 60), ...V(x1, yb, roofZ(yb) - 60)], 3));
        g.computeVertexNormals();
        A.add(new THREE.Mesh(g, glassRoof));
        const edge = new THREE.LineSegments(new THREE.EdgesGeometry(g), new THREE.LineBasicMaterial({ color: 0x5b4a3a }));
        A.add(edge);
      }
    }
  }

  // --- участок и мебель из старой модели ---
  const designLabels = new THREE.Group();
  root.add(designLabels);
  let siteLoaded = false;
  let onLoad = null;
  if (design?.site_model) {
    const oldToNew = (design.rooms || []).map((d) => {
      const r = h.rooms.find((x) => x.id === d.room);
      const p = r.floor_polygon_mm.value;
      const xs = p.map((q) => anchor.x + mm(q[0])), zs = p.map((q) => anchor.z + zz(q[1]));
      return { ...d, x1: d.old_rect_m[0], x2: d.old_rect_m[1], z1: d.old_rect_m[2], z2: d.old_rect_m[3],
        nx1: Math.min(...xs), nx2: Math.max(...xs), nz1: Math.min(...zs), nz2: Math.max(...zs), r };
    });
    // по оси: держим расстояние до ближайшей стены старой комнаты, не выходим за новую
    const remap = (c, half, o1, o2, n1, n2) => {
      let v = (c - o1) <= (o2 - c) ? n1 + (c - o1) : n2 - (o2 - c);
      if (n2 - n1 < half * 2) return (n1 + n2) / 2;
      return Math.min(Math.max(v, n1 + half), n2 - half);
    };
    new GLTFLoader().load(design.site_model, (gltf) => {
      const box = new THREE.Box3(), c = new THREE.Vector3(), sz = new THREE.Vector3();
      const atticGroups = {};
      for (const o of [...gltf.scene.children]) {
        o.traverse((m) => { if (m.isMesh) { m.castShadow = true; m.receiveShadow = true; } });
        if (o.name.startsWith('Furn_Attic')) {
          (atticGroups[o.name.split('_').slice(0, 4).join('_')] ??= []).push(o);
        } else if (o.name.startsWith('Furn_')) {
          box.setFromObject(o); box.getCenter(c); box.getSize(sz);
          const room = oldToNew.find((d) => c.x >= d.x1 - 0.2 && c.x <= d.x2 + 0.2 && c.z >= d.z1 - 0.2 && c.z <= d.z2 + 0.2);
          if (!room) continue;
          const nx = remap(c.x, sz.x / 2, room.x1, room.x2, room.nx1, room.nx2);
          const nz = remap(c.z, sz.z / 2, room.z1, room.z2, room.nz1, room.nz2);
          o.position.x += nx - c.x; o.position.z += nz - c.z;
          o.position.y += floorY; // в старой модели пол на нуле
          o.position.x -= anchor.x; o.position.z -= anchor.z; o.position.y -= floorY; // в координаты дома
          layers.design.add(o);
        } else {
          layers.site.add(o);
        }
      }
      // мебель мансарды: предмет (кровать из частей и т. п.) переносится целиком в свою комнату варианта
      const dy = mm(F) - (att?.old_floor_y_m ?? 0);
      for (const parts of Object.values(atticGroups)) {
        const g = new THREE.Group();
        box.makeEmpty();
        for (const o of parts) box.expandByObject(o);
        box.getCenter(c); box.getSize(sz);
        const ar = atticRooms.find((d) => c.x >= d.old_rect_m[0] - 0.2 && c.x <= d.old_rect_m[1] + 0.2 && c.z >= d.old_rect_m[2] - 0.2 && c.z <= d.old_rect_m[3] + 0.2);
        if (!ar) continue;
        const [x1, x2, ya, yb] = ar.rect, [o1, o2, oz1, oz2] = ar.old_rect_m;
        const nx = remap(c.x, sz.x / 2, o1, o2, anchor.x + mm(x1), anchor.x + mm(x2));
        const nz = remap(c.z, sz.z / 2, oz1, oz2, anchor.z + zz(yb), anchor.z + zz(ya));
        for (const o of parts) { o.position.x += nx - c.x - anchor.x; o.position.z += nz - c.z - anchor.z; o.position.y += dy; g.add(o); }
        layers.attic.add(g);
      }
      for (const d of oldToNew.filter((d) => d.label !== false)) {
        designLabels.add(sprite([d.name], (d.nx1 + d.nx2) / 2 - anchor.x, 0.55, (d.nz1 + d.nz2) / 2 - anchor.z, { small: true, color: '#7a4e2a' }));
      }
      siteLoaded = true;
      apply();
      onLoad?.();
    });
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
    if (hit && hit.object === walls) { select(null); return; }
    select(hit && hit.object !== walls ? hit.object.userData.room : null);
  });
  const select = (room) => {
    for (const f of floors) f.material = floorMat(f.userData.room, room && f.userData.room === room);
    picked = room;
    onPick?.(room ? roomInfo(h, room) : null);
  };

  // --- виды ---
  const target = new THREE.Vector3(anchor.x + mm(W) / 2, floorY + mm(H) / 2, anchor.z - mm(D) / 2);
  const dist = radius * 1.05;
  const views = { // относительно центра дома; улица — со стороны +z
    reset: [-dist * 0.75, dist * 0.75, dist * 0.85],
    front: [0, dist * 0.3, dist * 1.15],
    side: [-dist * 1.15, dist * 0.3, 0],
    back: [0, dist * 0.45, -dist * 1.1],
    top: [0, dist * 1.45, 0.001],
    site: [-dist * 1.3, dist * 1.6, dist * 2.0],
    attic: [-dist * 0.35, dist * 1.0, dist * 0.55],
  };
  let anim = null;
  const view = (name) => {
    const to = new THREE.Vector3(...views[name]).add(target);
    anim = { from: camera.position.clone(), to, fromT: controls.target.clone(), toT: target.clone(), start: performance.now() };
  };
  camera.position.copy(new THREE.Vector3(...views.reset).add(target));
  controls.target.copy(target);
  controls.maxDistance = radius * 6;
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

  function apply() {
    const a = state.attic;
    layers.attic.visible = a;
    layers.roof.visible = state.roof && !a;
    layers.stair.visible = state.stair || a;
    layers.dims.visible = state.dims && !state.roof && !a;
    layers.electric.visible = state.electric && !state.roof;
    layers.labels.visible = state.labels && !state.roof && !state.dims && !state.electric && !a && !(state.design && siteLoaded);
    layers.design.visible = state.design;
    designLabels.visible = state.design && state.labels && !state.roof && !state.dims && !state.electric && !a;
    layers.attic.traverse((o) => { if (o.isSprite) o.visible = state.labels; });
    layers.stair.traverse((o) => { if (o.isSprite) o.visible = state.labels; });
    layers.site.visible = state.site;
    ground.visible = !state.site || !siteLoaded;
  }
  apply();

  // камера в координатах плана (мм): откуда (x, y, высота) и куда смотреть
  const cam = (x, y, z, tx, ty, tz) => {
    const P = (a, b, c) => new THREE.Vector3(anchor.x + mm(a), floorY + mm(c), anchor.z + zz(b));
    anim = { from: camera.position.clone(), to: P(x, y, z), fromT: controls.target.clone(), toT: P(tx, ty, tz), start: performance.now() };
  };
  return {
    view, cam,
    layer: (name, on) => { state[name] = on; apply(); },
    isOn: (name) => state[name],
    onLoad: (fn) => { onLoad = fn; },
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
  const k = (o.tiny ? 0.0042 : o.small ? 0.0046 : 0.005);
  s.scale.set(c.width * k, c.height * k, 1);
  s.position.set(x, y, z);
  s.renderOrder = 10;
  return s;
}

/**
 * Значки электрики на стенах. На видео видно, ЧТО есть в комнате; место на стене условное:
 * по пометке («под окном W2», «у проёма D5», «над входной дверью») или равномерно по свободным участкам стен.
 * Высоты типовые: розетки 0,3 м, выключатели 0,9 м, коробки 2,6 м, щиток 1,5 м.
 */
export function placeElectric(h, openings) {
  const out = [];
  const HEIGHT = { socket: 300, socket_double: 300, switch: 900, switch_block_2: 900, junction_box: 2600, junction_box_rough: 2600, electrical_panel: 1500, floor_outlet_or_hole: 150, cable_outlet_low: 120, light_cable: 2700 };
  for (const r of h.rooms) {
    const items = h.electrical.filter((x) => x.room === r.id);
    if (!items.length) continue;
    const poly = r.floor_polygon_mm.value;
    let area2 = 0;
    for (let i = 0; i < poly.length; i++) { const [a, b] = [poly[i], poly[(i + 1) % poly.length]]; area2 += a[0] * b[1] - b[0] * a[1]; }
    const ccw = area2 > 0;
    // стены комнаты: отрезок, внутренняя нормаль, проёмы на нём
    const edges = poly.map((p, i) => {
      const q = poly[(i + 1) % poly.length], len = Math.hypot(q[0] - p[0], q[1] - p[1]);
      const dx = (q[0] - p[0]) / len, dy = (q[1] - p[1]) / len;
      const [nx, ny] = ccw ? [-dy, dx] : [dy, -dx];
      const axis = Math.abs(dx) > 0.5 ? 'y' : 'x';      // стена вдоль x лежит на линии y = const
      const c = axis === 'y' ? p[1] : p[0], lo = axis === 'y' ? Math.min(p[0], q[0]) : Math.min(p[1], q[1]), hi = axis === 'y' ? Math.max(p[0], q[0]) : Math.max(p[1], q[1]);
      const ops = openings.filter((o) => o.axis === axis && Math.abs(o.at - c) < 450 && o.a < hi && o.b > lo && (o.kind === 'door' ? o.d.between.includes(r.id) : o.w.room === r.id));
      return { p, q, len, dx, dy, nx, ny, axis, c, lo, hi, ops, used: [] };
    });
    const at = (e, u, z) => ({ x: e.axis === 'y' ? u : e.c, y: e.axis === 'y' ? e.c : u, z, nx: e.nx, ny: e.ny });
    const free = (e, u, gap = 250) => u > e.lo + 200 && u < e.hi - 200 && !e.ops.some((o) => u > o.a - gap && u < o.b + gap) && !e.used.some((v) => Math.abs(v - u) < 300);
    const byWall = { street: (e) => e.axis === 'y' && e.ny > 0 && e.c === Math.min(...edges.filter((f) => f.axis === 'y').map((f) => f.c)),
      back: (e) => e.axis === 'y' && e.ny < 0 && e.c === Math.max(...edges.filter((f) => f.axis === 'y').map((f) => f.c)),
      entry_side: (e) => e.axis === 'x' && e.nx > 0 && e.c === Math.min(...edges.filter((f) => f.axis === 'x').map((f) => f.c)),
      far: (e) => e.axis === 'x' && e.nx < 0 && e.c === Math.max(...edges.filter((f) => f.axis === 'x').map((f) => f.c)) };
    const opById = (id) => openings.find((o) => (o.kind === 'win' ? o.w.id : o.d.id).startsWith(id));
    const edgeOf = (o) => edges.find((e) => e.ops.includes(o));
    // свободное место на стене: от середины к краям
    const spot = (e, pref = null) => {
      const cands = [];
      for (let u = e.lo + 300; u <= e.hi - 300; u += 100) cands.push(u);
      const mid = pref ?? (e.lo + e.hi) / 2;
      cands.sort((a, b) => Math.abs(a - mid) - Math.abs(b - mid));
      return cands.find((u) => free(e, u));
    };
    const put = (x, kind, e, u, z) => { e.used.push(u); out.push({ ...at(e, u, z), kind, room: r.id, note: x.note }); };
    const floating = [];
    for (const x of items) {
      const n = x.count || 1;
      for (let k = 0; k < n; k++) {
        const z = x.note && /один выше/.test(x.note) ? (k ? 300 : 1100) : HEIGHT[x.kind] ?? 300;
        const note = x.note || '';
        if (x.kind === 'light_pendant') { // подвес — в центре комнаты (для Г-образной — в широкой части)
          const xs = poly.map((p) => p[0]), ys = poly.map((p) => p[1]);
          let cx = (Math.min(...xs) + Math.max(...xs)) / 2, cy = (Math.min(...ys) + Math.max(...ys)) / 2;
          if (poly.length > 4) { const xr = Math.max(...xs), xl = Math.max(...xs.filter((v) => v < xr)); cx = (xl + xr) / 2; }
          out.push({ x: cx, y: cy, z: 0, nx: 0, ny: 0, kind: x.kind, room: r.id, note: x.note });
          continue;
        }
        const win = /окн\S* (W\d)/.exec(note), door = /(D\d)/.exec(note);
        let o = win ? opById(win[1]) : door ? opById(door[1]) : /входной двер/.test(note) ? openings.find((q) => q.kind === 'door' && q.d.between.includes('outside')) : /проём/.test(note) ? openings.find((q) => q.kind === 'door' && q.d.between.includes(r.id) && !q.d.between.includes('outside')) : null;
        const e = o && edgeOf(o);
        if (e) {
          let u;
          if (/под окном/.test(note)) u = (o.a + o.b) / 2 + (k ? 200 : 0);
          else if (/над/.test(note)) { u = o.b - 150; out.push({ ...at(e, u, o.hi + 250), kind: x.kind, room: r.id, note }); e.used.push(u); continue; }
          else if (/справа/.test(note)) u = e.ny > 0 || e.nx < 0 ? o.b + 250 : o.a - 250;
          else u = [o.b + 250, o.a - 250, o.b + 600, o.a - 600].find((v) => free(e, v, 200)) ?? o.b + 250;
          if (/под блоком/.test(note)) {}
          put(x, x.kind, e, u, z);
          continue;
        }
        if (/под блоком/.test(note)) { const sb = out.find((q) => q.room === r.id && q.kind === 'switch_block_2'); if (sb) { out.push({ ...sb, z, kind: x.kind, note }); continue; } }
        floating.push({ x, z, wall: x.wall, near: /угл/.test(note) ? 'corner' : null });
      }
    }
    // без пометки: по указанной стене или по всем стенам по кругу; выключатели — у первого проёма
    let turn = 0;
    const order = edges.slice().sort((a, b) => b.len - a.len);
    for (const f of floating) {
      if (/switch/.test(f.x.kind)) {
        const o = openings.find((q) => q.kind === 'door' && q.d.between.includes(r.id));
        const e = o && edgeOf(o);
        const u = e && [o.b + 250, o.a - 250, o.b + 600, o.a - 600].find((v) => free(e, v, 200));
        if (u != null) { put(f.x, f.x.kind, e, u, f.z); continue; }
      }
      const pool = f.wall && byWall[f.wall] ? edges.filter(byWall[f.wall]) : order;
      let done = false;
      for (let t = 0; t < pool.length && !done; t++) {
        const e = pool[(turn + t) % pool.length];
        const u = spot(e, f.near === 'corner' ? e.lo + 350 : null);
        if (u != null) { put(f.x, f.x.kind, e, u, f.z); done = true; turn = (turn + t + 1) % pool.length; }
      }
    }
  }
  return out;
}

const iconCache = {};
/** Значок для стены: текстура и размер в метрах (крупнее настоящего, чтобы было видно издалека). */
function electricIcon(kind) {
  if (iconCache[kind]) return iconCache[kind];
  const wide = kind === 'socket_double' || kind === 'switch_block_2';
  const big = kind === 'electrical_panel';
  const c = document.createElement('canvas');
  c.width = wide ? 256 : 128; c.height = big ? 170 : 128;
  const g = c.getContext('2d');
  const color = /socket/.test(kind) ? '#2f6fd0' : /switch/.test(kind) ? '#2f9e44' : /panel/.test(kind) ? '#e8590c' : /light/.test(kind) ? '#f0b400' : '#6b7480';
  g.fillStyle = '#fff'; g.strokeStyle = color; g.lineWidth = 10;
  g.beginPath(); g.roundRect(6, 6, c.width - 12, c.height - 12, 22); g.fill(); g.stroke();
  g.fillStyle = '#33404a'; g.strokeStyle = '#33404a'; g.lineWidth = 6;
  const cells = wide ? [64, 192] : [64];
  for (const cx of cells) {
    if (/socket/.test(kind)) {
      g.beginPath(); g.arc(cx, 64, 36, 0, 7); g.stroke();
      g.beginPath(); g.arc(cx - 14, 64, 7, 0, 7); g.arc(cx + 14, 64, 7, 0, 7); g.fill();
    } else if (/switch/.test(kind)) {
      g.strokeRect(cx - 26, 30, 52, 68); g.fillRect(cx - 26, 30, 52, 30);
    }
  }
  if (/junction|floor_outlet|cable_outlet|light_cable/.test(kind)) {
    g.beginPath(); g.arc(64, 64, 34, 0, 7); g.stroke();
    if (/junction/.test(kind)) { g.beginPath(); g.moveTo(40, 64); g.lineTo(88, 64); g.moveTo(64, 40); g.lineTo(64, 88); g.stroke(); }
    else { g.beginPath(); g.arc(64, 64, 10, 0, 7); g.fill(); }
  }
  if (big) {
    g.fillStyle = '#e8590c'; g.font = '700 100px system-ui, sans-serif'; g.textAlign = 'center'; g.fillText('⚡', 64, 125);
  }
  const tex = new THREE.CanvasTexture(c);
  tex.colorSpace = THREE.SRGBColorSpace;
  const k = 0.22 / 128 * (big ? 1.5 : 1);
  return (iconCache[kind] = { tex, w: c.width * k, h: c.height * k });
}
