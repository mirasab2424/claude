import * as THREE from 'three';

export function addOutbuildingFacade({ L, groundY, group, roofGroup, night }) {
  const { ob, rooms, x1, x2 } = L;
  const floor = groundY + 0.12;
  const solid = (color) => new THREE.MeshStandardMaterial({ color, roughness: 0.75 });
  const graphite = solid(0x3c4046), frame = solid(0x2f3338), plinth = solid(0x55514c);
  const wood = solid(0xb07a45), gate = solid(0x92989d), metal = solid(0x676d74);
  const glow = new THREE.MeshStandardMaterial({ color: 0xffedcc, emissive: 0xffc773, emissiveIntensity: 0.25 });
  night.glow.push(glow);
  const cube = new THREE.BoxGeometry(1, 1, 1), pipe = new THREE.CylinderGeometry(0.5, 0.5, 1, 10);
  const dummy = new THREE.Object3D();
  const batches = [];
  const put = (parent, geometry, mat, x, d, h, w, depth, height, tilt = 0) => {
    dummy.position.set(x, floor + h, -d);
    dummy.scale.set(w, height, depth);
    dummy.rotation.z = tilt;
    dummy.updateMatrix();
    let batch = batches.find((b) => b.parent === parent && b.geometry === geometry && b.mat === mat);
    if (!batch) { batch = { parent, geometry, mat, matrices: [] }; batches.push(batch); }
    batch.matrices.push(dummy.matrix.clone());
  };
  const box = (mat, x, d, h, w, depth, height) => put(group, cube, mat, x, d, h, w, depth, height);
  const roof = (mat, x, d, h, w, depth, height, tilt) => put(roofGroup, cube, mat, x, d, h, w, depth, height, tilt);

  const bbq = rooms.find((r) => r.open_to_yard);
  const last = rooms.at(-1);
  const yardEnd = rooms.find((r) => r.id === 'sauna')?.d2 ?? last.d1;
  const doors = (ob.doors || []).filter((o) => o.wall === 'yard' || o.wall === 'wc_yard');
  const yardDoors = doors.filter((o) => o.wall === 'yard');
  const intervals = yardDoors.map((o) => o.d);
  if (bbq) intervals.push([bbq.d1, bbq.d2]);
  let start = 0;
  for (const [a, b] of [...intervals, [yardEnd, yardEnd]].sort((a, b) => a[0] - b[0])) {
    if (a > start) box(plinth, x1 - 0.018, (start + a) / 2, 0.15, 0.038, a - start, 0.3);
    start = Math.max(start, b);
  }
  for (const { d: [a, b] } of ob.windows || []) {
    const mid = (a + b) / 2;
    for (const d of [a, mid, b]) box(frame, x1 - 0.014, d, 1.5, 0.055, 0.045, 1.24);
    for (const h of [0.9, 1.5, 2.1]) box(frame, x1 - 0.017, mid, h, 0.058, b - a, 0.05);
    box(metal, x1 - 0.065, mid, 0.867, 0.16, b - a + 0.13, 0.035);
  }
  for (const o of doors) {
    const [a, b] = o.d, width = b - a;
    const x = o.wall === 'wc_yard' ? last.x1 : x1;
    const h = o.wall === 'wc_yard' ? 2 : 2.05;
    const mid = (a + b) / 2, lampD = o.hinge === 'back' ? a - 0.19 : b + 0.19;
    for (const d of [a - 0.025, b + 0.025]) box(graphite, x - 0.017, d, h / 2, 0.04, 0.05, h);
    box(graphite, x - 0.017, mid, h + 0.027, 0.04, width + 0.1, 0.054);
    box(graphite, x - 0.27, mid, h + 0.29, 0.55, width + 0.24, 0.065);
    for (const d of [a - 0.05, b + 0.05]) box(metal, x - 0.15, d, h + 0.2, 0.29, 0.045, 0.08);
    box(frame, x - 0.065, lampD, 1.89, 0.13, 0.13, 0.25);
    box(glow, x - 0.137, lampD, 1.89, 0.025, 0.09, 0.18);
  }

  const slope = (ob.roof.fence_side_height_m - ob.wall_height_m) / (x2 - x1);
  const height = (x) => ob.wall_height_m + slope * (x - x1) + 0.12;
  const angle = Math.atan(slope);
  const skin = (left, right, front, back) => {
    const midX = (left + right) / 2, midD = (front + back) / 2;
    const incline = (right - left) * Math.hypot(1, slope);
    for (const d of [front, back]) roof(graphite, midX, d, height(midX) - 0.064, incline, 0.065, 0.16, angle);
    for (const x of [left, right]) roof(graphite, x, midD, height(x) - 0.064, 0.06, back - front, 0.16);
    for (let d = front + 0.28; d < back - 0.15; d += 0.56) roof(metal, midX, d, height(midX) + 0.025, incline, 0.023, 0.045, angle);
    roof(metal, right + 0.055, midD, height(right) - 0.13, 0.15, back - front, 0.085);
    for (const d of [front + 0.36, back - 0.36]) {
      const h = height(x2) - 0.18;
      put(roofGroup, pipe, metal, x2 + 0.095, d, h / 2, 0.085, 0.085, h);
      roof(metal, right - 0.015, d, h + 0.055, 0.25, 0.085, 0.085);
    }
  };
  skin(x1 - 0.4, x2 + 0.05, -0.3, yardEnd + 0.1);
  if (last.x1 > x1) skin(last.x1 - 0.3, x2 + 0.05, last.d1, last.d2 + 0.3);

  if (bbq) {
    for (const d of [bbq.d1 + 0.075, bbq.d2 - 0.075]) {
      for (let x = x1 + 0.23; x < x2 - ob.wall_m; x += 0.21) box(wood, x, d, 1.43, 0.085, 0.045, 2.76);
    }
    const x = x2 - ob.wall_m - 0.3, d = bbq.d1 + 0.8;
    const hood = new THREE.CylinderGeometry(0.23, 0.62, 0.42, 4).rotateY(Math.PI / 4);
    put(group, hood, graphite, x, d, 1.84, 0.9, 1.65, 1);
    const h = height(x);
    put(group, pipe, graphite, x, d, (h + 2.03) / 2, 0.2, 0.2, h - 2.03);
    put(roofGroup, pipe, graphite, x, d, h + 0.24, 0.2, 0.2, 0.48);
    roof(metal, x, d, h + 0.49, 0.35, 0.35, 0.045);
  }

  const margin = rooms[0].gate_margin_m;
  const width = x2 - x1 - 2 * margin, mid = (x1 + x2) / 2;
  for (let i = 0; i < 6; i++) box(gate, mid, 0.082, (i + 0.5) * 2.5 / 6, width - 0.042, 0.04, 2.5 / 6 - 0.024);
  for (const x of [x1 + margin, x2 - margin]) box(frame, x, -0.008, 1.25, 0.072, 0.055, 2.5);
  box(frame, mid, -0.008, 2.53, width + 0.074, 0.055, 0.074);
  box(frame, mid, 0.025, 0.66, 0.22, 0.062, 0.05);

  for (const { parent, geometry, mat, matrices } of batches) {
    const mesh = new THREE.InstancedMesh(geometry, mat, matrices.length);
    matrices.forEach((matrix, i) => mesh.setMatrixAt(i, matrix));
    parent.add(mesh);
  }
}
