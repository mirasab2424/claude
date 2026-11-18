// 3D дома из measurements.json: стены = контур минус помещения минус проёмы (сетка 50 мм).
// Слои: крыша (оценка), размеры, электрика, вариант лестницы. Клик по комнате — карточка с данными.
import * as THREE from 'three';
import { addInterior } from './interior.js?v=23';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { addOutbuildingInterior } from './ob_interior.js?v=23';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';
import { addOutbuildingFacade } from './ob_facade.js?v=23';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { addSky, styleSite, houseFinish, yardDecor } from './decor.js?v=23';
import { roomName, m1n, m05, tol, area, windowWall, doorWall, stairVariant, ELECTRIC, PLUMBING, CONFIDENCE, wallName, roomLabel, roomFinish, outbuildingLayout } from './house.js?v=23';

const CELL = 50; // мм

export function createViewer(canvas, h, onPick, design = null) {
  const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true });
  renderer.setPixelRatio(Math.min(devicePixelRatio, 2));
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  renderer.toneMapping = THREE.ACESFilmicToneMapping;
  renderer.shadowMap.enabled = true;
  renderer.shadowMap.type = THREE.PCFSoftShadowMap;
  renderer.localClippingEnabled = true; // чтобы срезать забор там, где стоит пристройка

  const scene = new THREE.Scene();
  const pmrem = new THREE.PMREMGenerator(renderer);
  scene.environment = pmrem.fromScene(new RoomEnvironment(), 0.04).texture;
  const camera = new THREE.PerspectiveCamera(45, 1, 0.05, 900);
  const setSkyNight = addSky(scene);
  const night = { group: null, glow: [] }; // светящиеся детали и лампы режима «Вечер»
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

  const SUN = 3.0, HEMI = 0.55;
  const sun = new THREE.DirectionalLight(0xfff1dc, SUN);
  // солнце светит на весь участок, а не только на дом
  const plotC = design?.plot ? new THREE.Vector3(design.plot.width_m / 2, groundY, -design.plot.depth_m / 2) : new THREE.Vector3(anchor.x + mm(W) / 2, groundY, anchor.z - mm(D) / 2);
  const ext = design?.plot ? Math.hypot(design.plot.width_m, design.plot.depth_m) * 0.62 : radius * 1.6;
  sun.position.copy(plotC).add(new THREE.Vector3(-ext * 0.75, ext * 1.25, ext * 0.9));
  sun.target.position.copy(plotC);
  sun.castShadow = true;
  sun.shadow.mapSize.set(4096, 4096);
  sun.shadow.bias = -0.0006; sun.shadow.normalBias = 0.12;
  Object.assign(sun.shadow.camera, { left: -ext, right: ext, top: ext, bottom: -ext, near: 0.5, far: ext * 5 });
  const hemi = new THREE.HemisphereLight(0xcfe2f5, 0x8a8f70, HEMI);
  scene.add(sun, sun.target, hemi);
  renderer.toneMappingExposure = 0.9;
  const evening = new THREE.Group(); // лампы для режима «Вечер»
  evening.visible = false;
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
  const state = { outbuilding: true, attic: false, roof: false, dims: false, electric: false, stair: false, labels: true, site: true, design: true };

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
    openings.push({ kind: 'win', w, axis: g.axis, a, b, at: t0 + g.t / 2, lo, hi, out: g.dir, t: g.t });
  }
  for (const d of h.doors) {
    const g = doorWall(h, d);
    const a = d.from_mm.value, b = d.to_mm.value, hi = d.height_mm.value;
    if (g.axis === 'y') cut(a, g.at, b, g.at + g.t, 0, hi); else cut(g.at, a, g.at + g.t, b, 0, hi);
    openings.push({ kind: 'door', d, axis: g.axis, a, b, at: g.at + g.t / 2, hi, t: g.t });
  }
  // одинаковые отрезки в соседних рядах склеиваем в один блок: без стыков стены гладкие (и меньше геометрии)
  const boxes = [];
  let open = new Map();
  for (let j = 0; j <= ny; j++) {
    const row = new Map();
    let i = 0;
    while (j < ny && i < nx) {
      const c = wall[j][i];
      if (!c) { i++; continue; }
      const key = JSON.stringify(c);
      let k = i + 1;
      while (k < nx && wall[j][k] && JSON.stringify(wall[j][k]) === key) k++;
      const id = `${i}|${k}|${key}`, prev = open.get(id);
      row.set(id, prev ? { ...prev, n: prev.n + 1 } : { i, k, c, j, n: 1 });
      open.delete(id);
      i = k;
    }
    for (const { i: a, k, c, j: j0, n } of open.values()) {
      let z = 0;
      for (const [lo, hi] of [...c.slice().sort((p, q) => p[0] - q[0]), [H, H]]) {
        if (lo > z) boxes.push([a * CELL, j0 * CELL, (k - a) * CELL, n * CELL, z, lo - z]);
        z = Math.max(z, hi);
      }
    }
    open = row;
  }
  const wallMat = new THREE.MeshStandardMaterial({ color: 0xf1ece2, roughness: 0.92, shadowSide: THREE.FrontSide });
  const walls = new THREE.InstancedMesh(new THREE.BoxGeometry(1, 1, 1), wallMat, boxes.length);
  const m4 = new THREE.Matrix4();
  boxes.forEach(([x, y, w, d, z, hh], n) => {
    m4.compose(new THREE.Vector3(mm(x + w / 2), mm(z + hh / 2), zz(y + d / 2)), new THREE.Quaternion(), new THREE.Vector3(mm(w), mm(hh), mm(d)));
    walls.setMatrixAt(n, m4);
  });
  walls.castShadow = walls.receiveShadow = true;
  root.add(walls);
  root.add(evening);
  // цоколь
  const plinthH = h.exterior.plinth?.visible_brick_height_mm?.value || 0;
  const plinth = new THREE.Mesh(new THREE.ExtrudeGeometry(new THREE.Shape(fp.map(([x, y]) => new THREE.Vector2(mm(x), mm(y)))), { depth: mm(plinthH), bevelEnabled: false }),
    new THREE.MeshStandardMaterial({ color: 0x55514c, roughness: 0.9 }));
  plinth.rotation.x = -Math.PI / 2; // контур ложится на пол, экструзия идёт вверх
  plinth.position.y = -mm(plinthH); // цоколь — от земли до пола
  plinth.castShadow = true;
  root.add(plinth);
  const floorY = groundY + mm(plinthH);
  root.position.set(anchor.x, floorY, anchor.z);
  ground.position.set(anchor.x + mm(W) / 2, groundY + 0.001, anchor.z - mm(D) / 2);

  // --- полы помещений (по ним кликают) ---
  const floors = [];
  // отделка пола — выбор посетителя, хранится только в его браузере
  let finishes = {};
  try { finishes = JSON.parse(localStorage.getItem('floor-finish')) || {}; } catch {}
  const floorMat = (r, hl) => {
    const c = new THREE.Color(finishes[r.id] || (r.confidence === 'low' ? '#f3e1a8' : '#f6f2ea'));
    if (hl) c.lerp(new THREE.Color('#7cc4e8'), 0.45);
    return new THREE.MeshStandardMaterial({ color: c, roughness: finishes[r.id] ? 0.6 : 0.95, side: THREE.DoubleSide });
  };
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
      leaf.rotation.y = -1.65; // распахнута внутрь почти на 95°, чтобы снаружи было видно, что открыто
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
  const roof = new THREE.Mesh(roofGeo, new THREE.MeshStandardMaterial({ color: 0x3c4046, roughness: 0.55, metalness: 0.25, side: THREE.DoubleSide }));
  roof.castShadow = true;
  layers.roof.add(roof);
  const gableGeo = new THREE.BufferGeometry();
  gableGeo.setAttribute('position', new THREE.Float32BufferAttribute([
    ...V(0, y0, H), ...V(0, ridgeY, H + ridge), ...V(0, D, H), ...V(W, y0, H), ...V(W, ridgeY, H + ridge), ...V(W, D, H),
  ], 3));
  gableGeo.computeVertexNormals();
  const gable = new THREE.Mesh(gableGeo, new THREE.MeshStandardMaterial({ color: 0xf1ece2, roughness: 0.92, side: THREE.DoubleSide }));
  layers.roof.add(gable);
  for (const w of h.windows.filter((w) => w.level === 'attic')) {
    const x = w.wall === 'gable_far' ? W + 15 : -15;
    const lo = H + w.sill_above_floor_mm.value, hi = lo + w.height_mm.value;
    const m = new THREE.Mesh(new THREE.BoxGeometry(0.03, mm(hi - lo), mm(w.width_mm.value)), new THREE.MeshStandardMaterial({ color: 0x6fa8c8, roughness: 0.2 }));
    m.position.set(mm(x), mm((lo + hi) / 2), zz((w.from_mm.value + w.to_mm.value) / 2));
    layers.roof.add(m);
  }
  night.group = evening;
  houseFinish({ root, roof: layers.roof, fp, W, D, H, y0, ridge, ridgeY, over, plinthH, openings, entrance, night });
  addInterior({ h, root, openings, H, night });
  layers.roof.add(sprite(['Крыша — оценка'], mm(W / 2), mm(H + ridge) + 0.7, zz(ridgeY), { color: '#3c4046', small: true }));

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
      // вечером — тёплый свет из этой точки (место подвеса условное)
      const lamp = new THREE.PointLight(0xffc773, 2.2, 7, 1.6);
      lamp.position.set(mm(it.x), mm(H) - 0.6, zz(it.y));
      const glow = new THREE.Mesh(new THREE.SphereGeometry(0.09, 16, 12), new THREE.MeshBasicMaterial({ color: 0xffe2a0 }));
      glow.position.copy(lamp.position);
      evening.add(lamp, glow);
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
  // --- пристройка вдоль правого забора по схеме владельца (координаты участка, как у site.glb) ---
  const obLayout = outbuildingLayout(design);
  layers.outbuilding = new THREE.Group();
  layers.obRoof = new THREE.Group();
  layers.obLabels = new THREE.Group();
  scene.add(layers.outbuilding, layers.obRoof, layers.obLabels);
  const obBlockers = [];
  if (obLayout) buildOutbuilding(obLayout, groundY, layers.outbuilding, layers.obRoof, layers.obLabels, obBlockers);
  if (obLayout) addOutbuildingInterior({ L: obLayout, groundY, group: layers.outbuilding, night });

  const designLabels = new THREE.Group();
  root.add(designLabels);
  if (obLayout) addOutbuildingFacade({ L: obLayout, groundY, group: layers.outbuilding, roofGroup: layers.obRoof, night });
  const yardNight = new THREE.Group();
  const setGlow = (on) => { for (const m of night.glow) m.emissiveIntensity = on ? 2.6 : 0.25; };
  let siteLoaded = false;
  let onLoad = null, onProgress = null;
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
          // старые гараж, хозблок и ели вдоль правого забора стоят там, где теперь пристройка по схеме
          if (obLayout && /^Site_(ob|Roof_|tree\d)/.test(o.name)) {
            box.setFromObject(o);
            if (box.max.x > obLayout.x1 - 0.3 || /^Site_(ob|Roof_)/.test(o.name)) continue;
          }
          // забор вдоль пристройки не нужен: её стена у забора и ворота гаража — сами граница участка
          if (obLayout && /^Site_fence_(front|right)_/.test(o.name)) {
            const last = obLayout.rooms.at(-1);
            const plane = /front/.test(o.name)
              ? new THREE.Plane(new THREE.Vector3(-1, 0, 0), obLayout.x1 - 0.05) // оставить x < начала пристройки
              : new THREE.Plane(new THREE.Vector3(0, 0, -1), -last.d2); // оставить только за туалетом
            o.traverse((m) => { if (m.isMesh) { m.material = m.material.clone(); m.material.clippingPlanes = [plane]; } });
          }
          styleSite(o);
          layers.site.add(o);
        }
      }
      if (design.plot) {
        yardNight.visible = evening.visible;
        layers.site.add(yardNight);
        yardDecor({ group: layers.site, groundY, plot: design.plot, L: obLayout, night: { group: yardNight, glow: night.glow },
          house: { x1: anchor.x, x2: anchor.x + mm(W), dFacade: -anchor.z + mm(y0), eaveY: floorY + mm(H) } });
        setGlow(evening.visible);
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
    }, (e) => onProgress?.(e.total ? e.loaded / e.total : null), () => onProgress?.(-1));
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
    if (ruler.on) { if (hit) rulerPoint(hit.point); return; }
    if (hit && hit.object === walls) { select(null); return; }
    select(hit && hit.object !== walls ? hit.object.userData.room : null);
  });
  // --- линейка: две точки на полу или стенах, расстояние по оценочным размерам ---
  const ruler = { on: false, pts: [], group: new THREE.Group(), onChange: null };
  scene.add(ruler.group);
  const rulerMat = new THREE.MeshBasicMaterial({ color: 0xe8590c, depthTest: false });
  const rulerClear = () => { ruler.pts = []; ruler.group.clear(); ruler.onChange?.(null); };
  function rulerPoint(p) {
    if (ruler.pts.length === 2) rulerClear();
    ruler.pts.push(p.clone());
    const dot = new THREE.Mesh(new THREE.SphereGeometry(0.06, 12, 8), rulerMat);
    dot.position.copy(p); dot.renderOrder = 20;
    ruler.group.add(dot);
    if (ruler.pts.length === 2) {
      const [a, b] = ruler.pts;
      const line = new THREE.Line(new THREE.BufferGeometry().setFromPoints([a, b]), new THREE.LineBasicMaterial({ color: 0xe8590c, depthTest: false }));
      line.renderOrder = 20;
      const d = a.distanceTo(b), horiz = Math.hypot(a.x - b.x, a.z - b.z);
      const label = sprite([`≈ ${m1n(d * 1000)} м`], (a.x + b.x) / 2, Math.max(a.y, b.y) + 0.35, (a.z + b.z) / 2, { color: '#e8590c', small: true });
      ruler.group.add(line, label);
      ruler.onChange?.({ d, horiz, dy: Math.abs(a.y - b.y) });
    } else ruler.onChange?.({ first: true });
  }

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
    yard: [dist * 1.3, dist * 0.9, dist * 0.6],
  };
  let anim = null;
  const view = (name) => {
    let tgt = target, off = new THREE.Vector3(...views[name]);
    if (name === 'yard' && obLayout) { // пристройка: смотрим со двора чуть сверху на середину полосы
      tgt = new THREE.Vector3((obLayout.x1 + obLayout.x2) / 2, groundY + 1.5, -obLayout.total / 2);
      off = new THREE.Vector3(-16, 15, 8);
    }
    const to = off.add(tgt);
    anim = { from: camera.position.clone(), to, fromT: controls.target.clone(), toT: tgt.clone(), start: performance.now() };
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
    if (walk.on) stepWalk(); else controls.update();
    renderer.render(scene, camera);
  });

  // --- прогулка от первого лица: стены из той же сетки, проходы — проёмы дверей от пола ---
  const EYE = 1600, R = 200, SPEED = 2200, RUN = 2.5; // мм, мм/с; Shift — бег
  const walk = { on: false, x: 0, y: 0, yaw: 0, pitch: 0, keys: new Set(), joy: { f: 0, t: 0 }, last: 0, saved: null };
  const solid = (x, y) => {
    const c = wall[Math.floor(y / CELL)]?.[Math.floor(x / CELL)];
    if (c && !c.some(([lo, hi]) => lo <= 10 && hi >= 1800)) return true;
    // стены пристройки (координаты участка, м): проходим только через двери
    const sx = anchor.x + x / 1000, sd = -anchor.z + y / 1000;
    return obBlockers.some((b) => sx > b.x1 && sx < b.x2 && sd > b.d1 && sd < b.d2);
  };
  const free = (x, y) => ![[R, 0], [-R, 0], [0, R], [0, -R], [R * .7, R * .7], [-R * .7, R * .7], [R * .7, -R * .7], [-R * .7, -R * .7]].some(([dx, dy]) => solid(x + dx, y + dy));
  const placeCam = () => {
    camera.position.set(anchor.x + mm(walk.x), floorY + mm(EYE), anchor.z + zz(walk.y));
    const dx = Math.sin(walk.yaw) * Math.cos(walk.pitch), dy = Math.cos(walk.yaw) * Math.cos(walk.pitch);
    camera.lookAt(camera.position.x + dx, camera.position.y + Math.sin(walk.pitch), camera.position.z - dy);
  };
  function stepWalk() {
    const now = performance.now(), dt = Math.min(0.05, (now - walk.last) / 1000);
    walk.last = now;
    const k = walk.keys;
    const f = (k.has('KeyW') || k.has('ArrowUp') ? 1 : 0) - (k.has('KeyS') || k.has('ArrowDown') ? 1 : 0) + walk.joy.f;
    const side = (k.has('KeyD') ? 1 : 0) - (k.has('KeyA') ? 1 : 0);
    walk.yaw += ((k.has('ArrowRight') ? 1 : 0) - (k.has('ArrowLeft') ? 1 : 0) + walk.joy.t) * 1.8 * dt;
    const step = SPEED * dt * (k.has('ShiftLeft') || k.has('ShiftRight') || walk.run ? RUN : 1);
    const mx = (Math.sin(walk.yaw) * f + Math.cos(walk.yaw) * side) * step, my = (Math.cos(walk.yaw) * f - Math.sin(walk.yaw) * side) * step;
    if (free(walk.x + mx, walk.y)) walk.x += mx; // скольжение вдоль стены
    if (free(walk.x, walk.y + my)) walk.y += my;
    placeCam();
  }
  let look = null;
  canvas.addEventListener('pointerdown', (ev) => { if (walk.on) look = [ev.clientX, ev.clientY]; });
  addEventListener('pointerup', () => { look = null; });
  canvas.addEventListener('pointermove', (ev) => {
    if (!walk.on || !look) return;
    walk.yaw += (ev.clientX - look[0]) * 0.005;
    walk.pitch = Math.max(-0.8, Math.min(0.8, walk.pitch - (ev.clientY - look[1]) * 0.004));
    look = [ev.clientX, ev.clientY];
  });
  addEventListener('keydown', (ev) => { if (walk.on && /^(Key[WASD]|Arrow|Shift)/.test(ev.code)) { walk.keys.add(ev.code); ev.preventDefault(); } });
  addEventListener('keyup', (ev) => walk.keys.delete(ev.code));
  addEventListener('blur', () => walk.keys.clear());
  const setWalk = (on) => {
    if (on === walk.on) return;
    walk.on = on;
    if (on) {
      walk.saved = { pos: camera.position.clone(), target: controls.target.clone() };
      // старт снаружи у входной двери, лицом к ней
      const d = openings.find((o) => o.kind === 'door' && o.d.between.includes('outside'));
      if (d && d.axis === 'x') { walk.x = d.at - 1700; walk.y = (d.a + d.b) / 2; walk.yaw = Math.PI / 2; }
      else { walk.x = W / 2; walk.y = -1200; walk.yaw = 0; }
      walk.pitch = -0.05;
      walk.last = performance.now();
      controls.enabled = false;
      camera.near = 0.05; camera.fov = 70; camera.updateProjectionMatrix();
    } else {
      controls.enabled = true;
      camera.fov = 45; camera.updateProjectionMatrix();
      camera.position.copy(walk.saved.pos); controls.target.copy(walk.saved.target);
    }
    apply();
  };

  function apply() {
    const a = state.attic;
    if (walk?.on) {
      Object.assign(layers.roof, { visible: false }); layers.attic.visible = false; layers.dims.visible = false; layers.labels.visible = false; designLabels.visible = false;
      layers.stair.visible = state.stair; layers.electric.visible = state.electric; layers.design.visible = state.design; layers.site.visible = state.site;
      layers.stair.traverse((o) => { if (o.isSprite) o.visible = false; });
      layers.obLabels.visible = false; layers.obRoof.visible = false; layers.outbuilding.visible = state.outbuilding;
      return;
    }
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
    ground.visible = !(state.site && siteLoaded); // тень-подложка нужна только без участка
    layers.outbuilding.visible = state.outbuilding;
    layers.obRoof.visible = state.outbuilding && state.roof;
    layers.obLabels.visible = state.outbuilding && state.labels && !state.roof;
    ground.visible = !state.site || !siteLoaded;
  }
  apply();

  // камера в координатах плана (мм): откуда (x, y, высота) и куда смотреть
  const cam = (x, y, z, tx, ty, tz) => {
    const P = (a, b, c) => new THREE.Vector3(anchor.x + mm(a), floorY + mm(c), anchor.z + zz(b));
    anim = { from: camera.position.clone(), to: P(x, y, z), fromT: controls.target.clone(), toT: P(tx, ty, tz), start: performance.now() };
  };
  return {
    view: (name) => { setWalk(false); view(name); }, cam,
    // ракурс для ссылки: позиция камеры и точка взгляда в координатах сцены
    getCamera: () => [...camera.position.toArray(), ...controls.target.toArray()].map((v) => Math.round(v * 100) / 100),
    setCamera: (a) => { if (a?.length === 6 && a.every(Number.isFinite)) { setWalk(false); anim = null; camera.position.set(a[0], a[1], a[2]); controls.target.set(a[3], a[4], a[5]); } },
    walk: setWalk,
    evening: (on) => {
      evening.visible = on;
      scene.userData.env ??= scene.environment;
      scene.environment = on ? null : scene.userData.env; // отражения «студии» делают вечер слишком светлым
      sun.intensity = on ? 0.1 : SUN;
      hemi.intensity = on ? 0.14 : HEMI;
      renderer.toneMappingExposure = on ? 1.25 : 0.9;
      setSkyNight(on); yardNight.visible = on; setGlow(on);
    },
    ruler: (on, onChange) => { ruler.on = on; ruler.onChange = onChange || ruler.onChange; if (!on) rulerClear(); },
    // навести камеру на комнату и выбрать её (для ссылки ?room=)
    focusRoom: (id) => {
      const r = h.rooms.find((x) => x.id === id);
      if (!r) return;
      const p = r.floor_polygon_mm.value, xs = p.map((q) => q[0]), ys = p.map((q) => q[1]);
      const cx = (Math.min(...xs) + Math.max(...xs)) / 2, cy = (Math.min(...ys) + Math.max(...ys)) / 2;
      const span = Math.max(Math.max(...xs) - Math.min(...xs), Math.max(...ys) - Math.min(...ys));
      cam(cx - span * 0.35, cy - span * 0.9, span * 1.3 + 3000, cx, cy, 0);
      select(r);
    },
    walkMove: (f, t) => { walk.joy = { f, t }; },
    walkRun: (on) => { walk.run = on; },
    get walking() { return walk.on; },
    layer: (name, on) => { state[name] = on; apply(); },
    isOn: (name) => state[name],
    onLoad: (fn) => { onLoad = fn; },
    onProgress: (fn) => { onProgress = fn; },
    autoRotate: (on) => { controls.autoRotate = on; },
    wireframe: (on) => { wallMat.wireframe = on; },
    select,
    get picked() { return picked; },
    floorFinish: (id) => finishes[id] || null,
    setFloor: (id, color) => {
      if (color) finishes[id] = color; else delete finishes[id];
      try { localStorage.setItem('floor-finish', JSON.stringify(finishes)); } catch {}
      for (const f of floors) f.material = floorMat(f.userData.room, picked && f.userData.room === picked);
    },
  };
}

/** Данные для карточки комнаты. */
function roomInfo(h, r) {
  const n = roomName(r), b = r.bbox_size_mm;
  return {
    id: r.id, name: n.name, hint: n.hint,
    size: `≈ ${m1n(b.x.value)} × ${m1n(b.y.value)} м`, tol: tol(Math.max(b.x.tolerance_mm, b.y.tolerance_mm)),
    area: area(r.floor_area_m2), height: '≈ ' + m1n(r.wall_top_height_mm.value) + ' м',
    confidence: CONFIDENCE[r.confidence] || r.confidence,
    finish: (() => { const f = roomFinish(h, r); return `стены без проёмов ≈ ${Math.round(f.walls)} м² · плинтус ≈ ${m1n(f.plinth * 1000)} м`; })(),
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


/**
 * Пристройка по схеме владельца: гараж, барбекю-зона под навесом, кладовая, кухня, асхана, столярка,
 * предбанник, баня, туалет и проход к калитке. Размеры — из design.json, высоты — 3 м у двора и меньше у забора.
 */
function buildOutbuilding(L, groundY, group, roofGroup, labels, blockers = []) {
  const ob = L.ob, T = ob.wall_m, hY = ob.wall_height_m, hF = ob.roof.fence_side_height_m;
  const y0 = groundY + 0.12;
  const Z = (d) => -d;
  const H = (x) => hY + (hF - hY) * (x - L.x1) / (L.x2 - L.x1); // высота стены у точки x (скат к забору)
  const wallMat = new THREE.MeshStandardMaterial({ color: 0xece6da, roughness: 0.9, side: THREE.DoubleSide });
  const mat = (c, r = 0.8) => new THREE.MeshStandardMaterial({ color: c, roughness: r });
  const box = (w, h, d, m, x, y, z) => { const o = new THREE.Mesh(new THREE.BoxGeometry(w, h, d), m); o.position.set(x, y, z); o.castShadow = o.receiveShadow = true; group.add(o); return o; };
  const last = L.rooms.at(-1);
  // плита пола
  box(L.x2 - L.x1, 0.12, last.d1, mat(0xcfc8bb), (L.x1 + L.x2) / 2, groundY + 0.06, Z(last.d1 / 2));
  box(last.x2 - last.x1, 0.12, last.d2 - last.d1, mat(0xcfc8bb), (last.x1 + last.x2) / 2, groundY + 0.06, Z((last.d1 + last.d2) / 2));
  // стена в плоскости «вдоль участка» (x = const): профиль по d, с проёмами
  // для прогулки: следы стен без дверных проёмов (окна — стена)
  const block = (xa, xb, da, db, gaps) => {
    let a = da;
    for (const [u, v] of gaps.slice().sort((p, q) => p[0] - q[0])) { if (u > a) blockers.push(xa === null ? null : { x1: xa, x2: xb, d1: a, d2: u }); a = Math.max(a, v); }
    if (db > a) blockers.push({ x1: xa, x2: xb, d1: a, d2: db });
  };
  const longWall = (x, d1, d2, h, holes = []) => {
    block(x - T / 2, x + T / 2, d1, d2, holes.filter((o) => o[2] <= 0.05).map((o) => [o[0], o[1]]));
    const sh = new THREE.Shape([[d1, 0], [d2, 0], [d2, h], [d1, h]].map(([a, b]) => new THREE.Vector2(a, b)));
    for (const [a, b, lo, hi] of holes) sh.holes.push(new THREE.Path([[a, lo], [b, lo], [b, hi], [a, hi]].map(([u, v]) => new THREE.Vector2(u, v))));
    const g = new THREE.ExtrudeGeometry(sh, { depth: T, bevelEnabled: false });
    const m = new THREE.Mesh(g, wallMat);
    m.rotation.y = Math.PI / 2; // локальный x → −z (d), выдавливание → +x
    m.position.set(x - T / 2, y0, 0);
    m.castShadow = m.receiveShadow = true;
    group.add(m);
  };
  // стена поперёк (d = const): профиль по x со скатом, с проёмами
  const crossWall = (d, xa, xb, holes = [], t = 0.12) => {
    { // по x: тот же подход, проёмы дверей — проход
      let a = xa;
      for (const [u, v] of holes.filter((o) => o[2] <= 0.05).map((o) => [o[0], o[1]]).sort((p, q) => p[0] - q[0])) { if (u > a) blockers.push({ x1: a, x2: u, d1: d - t / 2, d2: d + t / 2 }); a = Math.max(a, v); }
      if (xb > a) blockers.push({ x1: a, x2: xb, d1: d - t / 2, d2: d + t / 2 });
    }
    const sh = new THREE.Shape([[xa, 0], [xb, 0], [xb, H(xb)], [xa, H(xa)]].map(([a, b]) => new THREE.Vector2(a, b)));
    for (const [a, b, lo, hi] of holes) sh.holes.push(new THREE.Path([[a, lo], [b, lo], [b, hi], [a, hi]].map(([u, v]) => new THREE.Vector2(u, v))));
    const m = new THREE.Mesh(new THREE.ExtrudeGeometry(sh, { depth: t, bevelEnabled: false }), wallMat);
    m.position.set(0, y0, Z(d) - t / 2);
    m.castShadow = m.receiveShadow = true;
    group.add(m);
  };
  // стена у забора — по всей длине построек
  longWall(L.x2 - T / 2, 0, last.d2, hF);
  // стена со стороны двора: двери и окна по схеме; у барбекю стены нет (открыто во двор)
  const DH = 2.05;
  const yardHoles = [...(ob.doors || []).filter((o) => o.wall === 'yard').map((o) => [o.d[0], o.d[1], 0, DH]),
    ...(ob.windows || []).map((o) => [o.d[0], o.d[1], 0.9, 2.1])];
  const bbq = L.rooms.find((r) => r.open_to_yard);
  const sauna = L.rooms.find((r) => r.id === 'sauna');
  const yardEnd = sauna ? sauna.d2 : last.d1;
  const segs = bbq ? [[0, bbq.d1], [bbq.d2, yardEnd]] : [[0, yardEnd]];
  for (const [a, b] of segs) longWall(L.x1 + T / 2, a, b, hY, yardHoles.filter(([u, v]) => u >= a && v <= b));
  // туалет уже: его стена со стороны прохода (с дверью) и задняя стена
  if (last.x1 > L.x1) {
    longWall(last.x1 + T / 2, last.d1, last.d2, H(last.x1), (ob.doors || []).filter((o) => o.wall === 'wc_yard').map((o) => [o.d[0], o.d[1], 0, 2.0]));
    crossWall(last.d2, last.x1, L.x2, [], T);
  }
  // торец на улицу с воротами гаража и перегородки между помещениями (двери в перегородках — по схеме)
  const garage = L.rooms[0];
  crossWall(0.1, L.x1, L.x2, [[L.x1 + garage.gate_margin_m, L.x2 - garage.gate_margin_m, 0, 2.5]], T);
  for (const r of L.rooms.slice(1)) {
    const holes = (ob.doors || []).filter((o) => o.wall === 'cross' && Math.abs(o.at - r.d1) < 0.01).map((o) => [L.x1 + o.x[0], L.x1 + o.x[1], 0, DH]);
    crossWall(r.d1, L.x1, L.x2, holes);
  }
  // двери: полотно приоткрыто на 70° в ту сторону, куда открывается, и дуга открывания на полу
  const leafMat = mat(0x8a5a33, 0.6), arcMat = new THREE.LineBasicMaterial({ color: 0x8a5a33 });
  const door = (hx, hd, closed, swing, w, h = 2.0) => {
    // closed / swing — единичные векторы в плоскости (x, z): вдоль закрытого полотна и в сторону открывания
    const a = 70 * Math.PI / 180;
    const open = [closed[0] * Math.cos(a) + swing[0] * Math.sin(a), closed[1] * Math.cos(a) + swing[1] * Math.sin(a)];
    const pivot = new THREE.Group();
    pivot.position.set(hx, y0, Z(hd));
    pivot.rotation.y = Math.atan2(open[0], open[1]);
    const leaf = new THREE.Mesh(new THREE.BoxGeometry(0.04, h, w - 0.04), leafMat);
    leaf.position.set(0, h / 2, (w - 0.04) / 2);
    leaf.castShadow = true;
    const knob = new THREE.Mesh(new THREE.SphereGeometry(0.03), mat(0xc9a227, 0.3));
    knob.position.set(0.04, 1.0, w - 0.12);
    pivot.add(leaf); leaf.add(knob);
    group.add(pivot);
    const pts = [];
    for (let i = 0; i <= 20; i++) {
      const t = (i / 20) * a, v = [closed[0] * Math.cos(t) + swing[0] * Math.sin(t), closed[1] * Math.cos(t) + swing[1] * Math.sin(t)];
      pts.push(new THREE.Vector3(hx + v[0] * w, y0 + 0.02, Z(hd) + v[1] * w));
    }
    group.add(new THREE.Line(new THREE.BufferGeometry().setFromPoints(pts), arcMat));
  };
  for (const o of ob.doors || []) {
    const w = o.wall === 'cross' ? o.x[1] - o.x[0] : o.d[1] - o.d[0];
    if (o.wall === 'yard' || o.wall === 'wc_yard') {
      const xw = o.wall === 'yard' ? L.x1 + T : last.x1 + T;
      const xo = o.wall === 'yard' ? L.x1 : last.x1;
      const hd = o.hinge === 'back' ? o.d[1] : o.d[0];
      const closed = [0, o.hinge === 'back' ? 1 : -1]; // вдоль стены к другому косяку (z = −d)
      door(o.swing === 'in' ? xw : xo, hd, closed, [o.swing === 'in' ? 1 : -1, 0], w);
    } else {
      const hx = o.hinge === 'yard' ? L.x1 + o.x[0] : L.x1 + o.x[1];
      const closed = [o.hinge === 'yard' ? 1 : -1, 0];
      const swing = [0, o.swing === 'street' ? 1 : -1]; // к улице — это +z
      door(hx, o.at + (o.swing === 'street' ? -0.07 : 0.07), closed, swing, w);
    }
  }
  // ворота гаража (секционные)
  const gate = box(L.x2 - L.x1 - 2 * garage.gate_margin_m, 2.5, 0.05, mat(0x8e959b, 0.5), (L.x1 + L.x2) / 2, y0 + 1.25, Z(0.12));
  blockers.push({ x1: L.x1, x2: L.x2, d1: 0, d2: 0.25 }); // ворота гаража закрыты
  for (let i = 1; i < 6; i++) box(L.x2 - L.x1 - 2 * garage.gate_margin_m, 0.02, 0.06, mat(0x6b7278), gate.position.x, y0 + i * 0.42, Z(0.11));
  // тропинка из плит: от ворот во двор вдоль пристройки к дверям, мимо туалета и за ним — к калитке
  const tileMat = mat(0x8f8573, 0.95), tile = (x, d, w = 0.6, l = 0.6) => box(w, 0.06, l, tileMat, x, groundY + 0.05, Z(d));
  const px = L.x1 - 0.55, cgd = ob.passage?.gate ? (ob.passage.gate.d[0] + ob.passage.gate.d[1]) / 2 : L.plot.depth_m - 0.7;
  const mainGate = L.plot.main_gate_x_m;
  for (let x = mainGate[1] - 0.3; x < px; x += 0.75) tile(x, 1.2);                 // от ворот во двор к пристройке
  for (let d = 1.2; d <= yardEnd + 0.4; d += 0.75) tile(px, d);                       // вдоль пристройки
  for (let x = px + 0.75; x <= last.x1 - 0.4; x += 0.75) tile(x, yardEnd + 0.75);     // к туалету
  for (let d = yardEnd + 1.5; d <= cgd; d += 0.75) tile(last.x1 - 0.5, d);            // мимо двери туалета
  for (let x = last.x1 - 0.5; x <= L.x2 - 0.3; x += 0.75) tile(x, cgd);               // за туалетом к калитке
  // калитка в правом заборе за туалетом: столбы и приоткрытое наружу полотно
  const cg = ob.passage?.gate;
  if (cg) {
    const post = mat(0x5a3a1e, 0.6);
    for (const dd of cg.d) box(0.1, 1.9, 0.1, post, L.x2, groundY + 0.95, Z(dd));
    const gw = cg.d[1] - cg.d[0];
    const gp = new THREE.Group();
    gp.position.set(L.x2, groundY + 0.05, Z(cg.d[0]));
    gp.rotation.y = Math.atan2(Math.sin(1.1), -Math.cos(1.1)); // открыта наружу на ~63°
    const gl = new THREE.Mesh(new THREE.BoxGeometry(0.05, 1.7, gw - 0.05), mat(0x7a4e2a, 0.6));
    gl.position.set(0, 0.85, (gw - 0.05) / 2);
    gp.add(gl);
    group.add(gp);
  }
  // барбекю: мангал и две печи под казаны у забора, стол со стороны двора
  if (bbq) {
    const brick = mat(0xb5563f), metal = mat(0x2b2f33, 0.4), wood = mat(0xb98552, 0.7), kazan = mat(0x1f2326, 0.3);
    const xIn = L.x2 - T;
    box(0.5, 0.9, 1.2, metal, xIn - 0.3, y0 + 0.45, Z(bbq.d1 + 0.8));
    box(0.8, 0.8, 0.8, brick, xIn - 0.45, y0 + 0.4, Z(bbq.d1 + 2.0));
    box(1.0, 0.85, 1.0, brick, xIn - 0.55, y0 + 0.43, Z(bbq.d1 + 3.25));
    for (const [r, x, d, y] of [[0.25, xIn - 0.45, bbq.d1 + 2.0, 0.8], [0.35, xIn - 0.55, bbq.d1 + 3.25, 0.85]]) {
      const k = new THREE.Mesh(new THREE.SphereGeometry(r, 20, 10, 0, Math.PI * 2, Math.PI / 2, Math.PI / 2), kazan);
      k.position.set(x, y0 + y + 0.02, Z(d));
      group.add(k);
    }
    const td = ob.bbq_table_d || [bbq.d1 + 1.2, bbq.d1 + 2.8];
    box(0.7, 0.9, td[1] - td[0], wood, L.x1 + 0.45, y0 + 0.45, Z((td[0] + td[1]) / 2));
  }
  // Стеллаж и автомобиль в гараже.
  const room = (id) => L.rooms.find((r) => r.id === id);
  if (room('garage')) box(0.5, 2.0, room('garage').length_m - 1.2, mat(0x9aa0a6), L.x2 - T - 0.25, y0 + 1.0, Z(room('garage').length_m / 2 + 0.3));
  if (L.car) {
    // машина (примерно, по паспортным размерам): кузов, кабина со стёклами, колёса, зеркала
    const c = L.car, cx = (c.x1 + c.x2) / 2, cd = (c.d1 + c.d2) / 2, W = c.width_m, Ln = c.length_m;
    const paint = new THREE.MeshStandardMaterial({ color: 0xa9aeb3, roughness: 0.4, metalness: 0.15 });
    const glass = new THREE.MeshStandardMaterial({ color: 0x55636f, roughness: 0.15, metalness: 0.1 });
    const tyre = mat(0x1d1f21, 0.9);
    box(W, 0.55, Ln, paint, cx, y0 + 0.2 + 0.275, Z(cd));
    box(W - 0.06, 0.12, Ln - 0.25, paint, cx, y0 + 0.8, Z(cd));
    // кабина: трапеция по длине (лобовое и заднее стекло наклонены)
    const cab = new THREE.Shape([[-1.25, 0], [1.05, 0], [0.45, 0.55], [-0.85, 0.55]].map(([a, b]) => new THREE.Vector2(a, b)));
    const cg = new THREE.ExtrudeGeometry(cab, { depth: W - 0.22, bevelEnabled: false });
    cg.translate(0, 0, -(W - 0.22) / 2);
    const cm = new THREE.Mesh(cg, glass); cm.rotation.y = Math.PI / 2; cm.position.set(cx, y0 + 0.86, Z(cd - 0.1)); cm.castShadow = true; group.add(cm);
    box(W - 0.3, 0.04, 1.25, paint, cx, y0 + 1.43, Z(cd - 0.3)); // крыша
    const wheel = new THREE.CylinderGeometry(0.31, 0.31, 0.2, 20); wheel.rotateZ(Math.PI / 2);
    for (const sx of [-1, 1]) for (const sd of [-1.3, 1.3]) { const w = new THREE.Mesh(wheel, tyre); w.position.set(cx + sx * (W / 2 - 0.08), y0 + 0.31, Z(cd + sd)); group.add(w); }
    for (const sx of [-1, 1]) box((c.mirrors_width_m - W) / 2, 0.1, 0.16, paint, cx + sx * (W / 2 + (c.mirrors_width_m - W) / 4), y0 + 0.98, Z(cd + 0.75));
    for (const sx of [-1, 1]) { box(0.3, 0.1, 0.03, mat(0xfff3c4, 0.3), cx + sx * (W / 2 - 0.25), y0 + 0.62, Z(c.d2 + 0.005)); box(0.3, 0.08, 0.03, mat(0xb3261e, 0.4), cx + sx * (W / 2 - 0.25), y0 + 0.66, Z(c.d1 - 0.005)); }
    labels.add(sprite([c.name, `≈ ${String(Ln).replace('.', ',')} × ${String(W).replace('.', ',')} м`], cx, y0 + 2.1, Z(cd), { small: true, color: '#3c4650' }));
  }
  // односкатная крыша к забору (слой «Крыша»): над всеми помещениями, над барбекю — навес
  const roofMat = new THREE.MeshStandardMaterial({ color: 0x5b6168, roughness: 0.6, side: THREE.DoubleSide });
  const slab = (xa, xb, da, db) => {
    const g = new THREE.BufferGeometry();
    const P = (x, d) => [x, y0 + H(x) + 0.12, Z(d)];
    g.setAttribute('position', new THREE.Float32BufferAttribute([...P(xa, da), ...P(xb, da), ...P(xb, db), ...P(xa, da), ...P(xb, db), ...P(xa, db)], 3));
    g.computeVertexNormals();
    const m = new THREE.Mesh(g, roofMat); m.castShadow = true; roofGroup.add(m);
  };
  slab(L.x1 - 0.4, L.x2 + 0.05, -0.3, yardEnd + 0.1);
  if (last.x1 > L.x1) slab(last.x1 - 0.3, L.x2 + 0.05, last.d1, last.d2 + 0.3);
  // подписи
  for (const r of L.rooms) labels.add(sprite([r.name], (r.x1 + r.x2) / 2, y0 + 3.6, Z((r.d1 + r.d2) / 2), { small: true, color: '#7a4e2a' }));
  if (ob.passage?.gate) labels.add(sprite(['Калитка'], L.x2 - 0.3, groundY + 2.6, Z((ob.passage.gate.d[0] + ob.passage.gate.d[1]) / 2), { small: true, color: '#7a4e2a' }));
}
