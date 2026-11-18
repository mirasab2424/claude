import * as THREE from 'three';

export function addYardExtras({ group, groundY, plot, L, house, night }) {
  const material = (color) => new THREE.MeshStandardMaterial({ color, roughness: 0.85 });
  const wood = material(0xb07a45), bark = material(0x795739), dark = material(0x3c4046);
  const green = material(0x52734d), leaves = material(0x6a8c52), red = material(0xa45543);
  const brick = material(0x996b56), mortar = material(0xc5b7a5), stone = material(0x79736a);
  const linen = material(0xf1ece2), blue = material(0x487588), straw = material(0xba934c);
  const glow = new THREE.MeshStandardMaterial({ color: 0xffedcc, emissive: 0xffc773, emissiveIntensity: 0.25 });
  night.glow.push(glow);

  const cube = new THREE.BoxGeometry(), cylinder = new THREE.CylinderGeometry(0.5, 0.5, 1, 12);
  const sphere = new THREE.IcosahedronGeometry(0.5, 1), dummy = new THREE.Object3D();
  const batches = [];
  const add = (geometry, mat, x, d, z, w, l, h, angle = 0) => {
    dummy.position.set(x, groundY + z, -d);
    dummy.scale.set(w, h, l);
    dummy.rotation.x = angle;
    dummy.updateMatrix();
    let batch = batches.find((b) => b.mat === mat && b.geometry === geometry);
    if (!batch) { batch = { mat, geometry, matrices: [] }; batches.push(batch); }
    batch.matrices.push(dummy.matrix.clone());
  };
  const box = (m, x, d, z, w, l, h, angle) => add(cube, m, x, d, z, w, l, h, angle);
  const post = (m, x, d, h, w = 0.09) => box(m, x, d, h / 2, w, w, h);
  const round = (m, x, d, z, w, h, angle) => add(cylinder, m, x, d, z, w, w, h, angle);

  // Периметры существующих шести грядок повторяют расположение в site.glb.
  for (let i = 0; i < 6; i++) {
    const x = 0.8 + 1.6 * i;
    for (const dx of [0, 1]) box(wood, x + dx, 31, 0.17, 0.065, 8.44, 0.22);
    for (const d of [26.8, 35.2]) box(wood, x + 0.5, d, 0.17, 1, 0.065, 0.22);
  }
  for (const x of [2.9, 6.1]) {
    for (const d of [29.4, 31, 32.6]) post(bark, x, d, 1.55, 0.065);
    for (const h of [0.64, 0.91, 1.18, 1.5]) box(wood, x, 31, h, 0.035, 3.24, 0.04);
  }
  round(blue, 0.48, 26.52, 0.44, 0.66, 0.88);
  for (const h of [0.19, 0.69, 0.89]) round(dark, 0.48, 26.52, h, 0.68, 0.038);
  round(green, 1.03, 26.42, 0.17, 0.31, 0.28);
  box(green, 1.03, 26.26, 0.27, 0.05, 0.3, 0.05, 0.45);
  box(dark, 1.03, 26.44, 0.33, 0.05, 0.23, 0.045);
  box(bark, 0.59, 35.55, 0.31, 0.89, 0.56, 0.62);
  for (let i = 0; i < 4; i++) box(wood, 0.59, 35.25, 0.13 + i * 0.135, 0.87, 0.045, 0.102);
  post(bark, 9.3, 33.75, 1.77);
  box(red, 9.3, 33.75, 1.25, 1.05, 0.15, 0.14);
  box(red, 9.3, 33.75, 1.2, 0.38, 0.19, 0.53);
  add(sphere, straw, 9.3, 33.75, 1.66, 0.27, 0.27, 0.31);
  round(straw, 9.3, 33.75, 1.8, 0.48, 0.07);

  // Горка в стороне от песочницы и плоскости качелей.
  for (const x of [9.18, 10.08]) for (const d of [21.74, 22.55]) post(wood, x, d, 1.49);
  box(wood, 9.63, 22.15, 1.15, 0.98, 0.91, 0.11);
  const span = 1.18, rise = 1.02, tilt = -Math.atan2(rise, span);
  box(blue, 9.63, 23.14, 0.64, 0.63, Math.hypot(rise, span), 0.07, tilt);
  for (const x of [9.32, 9.94]) box(blue, x, 23.14, 0.7, 0.055, Math.hypot(rise, span), 0.12, tilt);
  for (let i = 1; i <= 5; i++) box(bark, 9.63, 21.23 + i * 0.088, i * 0.19, 0.67, 0.16, 0.07);
  for (const x of [7.26, 8.94]) box(wood, x, 21.1, 0.33, 0.17, 1.8, 0.065);
  for (const d of [20.26, 21.94]) box(wood, 8.1, d, 0.33, 1.5, 0.17, 0.065);
  for (const x of [6.88, 7.71]) for (const d of [22.66, 23.56]) post(bark, x, d, 1.28);
  box(wood, 7.295, 23.11, 0.23, 0.95, 1, 0.09);
  for (const direction of [-1, 1]) box(red, 7.295, 23.11 + direction * 0.254, 1.34, 1.1, 0.58, 0.065, direction * 0.37);
  box(wood, 7.295, 23.57, 0.72, 0.95, 0.07, 0.95);

  // Шестиугольная беседка из исходной модели: лампы идут по кромке крыши.
  const gazebo = { x: 3.4, d: 22.8, radius: 1.86, h: 2.52 };
  const corners = Array.from({ length: 6 }, (_, i) => {
    const a = i * Math.PI / 3;
    return [gazebo.x + gazebo.radius * Math.cos(a), gazebo.d + gazebo.radius * Math.sin(a)];
  });
  for (let i = 0; i < corners.length; i++) {
    const [ax, ad] = corners[i], [bx, bd] = corners[(i + 1) % corners.length];
    for (let j = 0; j < 6; j++) {
      const p = j / 6, x = THREE.MathUtils.lerp(ax, bx, p), d = THREE.MathUtils.lerp(ad, bd, p);
      const h = gazebo.h - 0.12 * Math.sin(p * Math.PI);
      round(dark, x, d, h + 0.035, 0.033, 0.07);
      add(sphere, glow, x, d, h - 0.026, 0.068, 0.068, 0.07);
    }
  }
  round(dark, gazebo.x, gazebo.d, 2.36, 0.035, 0.45);
  box(dark, gazebo.x, gazebo.d, 2.14, 0.26, 0.26, 0.04);
  box(glow, gazebo.x, gazebo.d, 1.995, 0.19, 0.19, 0.24);
  box(dark, gazebo.x, gazebo.d, 1.85, 0.25, 0.25, 0.045);
  const gazeboLight = new THREE.PointLight(0xffc773, 2.2, 5, 1.6);
  gazeboLight.position.set(gazebo.x, groundY + 1.92, -gazebo.d);
  night.group.add(gazeboLight);

  const td = 18.46;
  round(wood, 6.05, td, 0.76, 0.97, 0.07);
  round(dark, 6.05, td, 0.38, 0.09, 0.72);
  for (const x of [5.14, 6.96]) {
    box(wood, x, td, 0.45, 0.42, 0.44, 0.07);
    box(wood, x + Math.sign(x - 6.05) * 0.19, td, 0.68, 0.06, 0.44, 0.47);
    for (const a of [-0.15, 0.15]) for (const b of [-0.15, 0.15]) post(dark, x + a, td + b, 0.42, 0.043);
  }
  for (const x of [8.18, 9.68]) for (const d of [18.1, 18.73]) post(dark, x, d, 1.11, 0.045);
  for (const d of [18.1, 18.31, 18.52, 18.73]) box(mortar, 8.93, d, 1.11, 1.5, 0.025, 0.025);
  box(linen, 8.55, 18.31, 0.902, 0.45, 0.028, 0.4);
  box(blue, 9.29, 18.52, 0.94, 0.39, 0.028, 0.32);

  const [gateA, gateB] = plot.main_gate_x_m;
  const brickPost = (x, height) => {
    post(mortar, x, 0, height, 0.35);
    for (let y = 0.08; y < height; y += 0.155) {
      box(brick, x, 0, y, 0.367, 0.367, 0.133);
      box(brick, x, 0, y, 0.367, 0.37, 0.012);
    }
    box(stone, x, 0, height + 0.071, 0.44, 0.44, 0.145);
  };
  for (let x = 0.25; x < gateA - 0.7; x += 2.8) brickPost(x, 1.54);
  for (const x of [gateA - 0.21, Math.min(gateB + 0.21, (L?.x1 ?? plot.width_m) - 0.3)]) {
    brickPost(x, 1.77);
    for (const h of [1.95, 2.17]) box(dark, x, 0, h, 0.22, 0.22, 0.055);
    box(glow, x, 0, 2.06, 0.15, 0.15, 0.19);
  }

  box(bark, 1.07, 19.34, 0.43, 0.98, 1.16, 0.86);
  box(dark, 1.07, 18.748, 0.25, 0.36, 0.035, 0.5);
  for (const sign of [-1, 1]) box(red, 1.07, 19.34 + sign * 0.31, 0.99, 1.16, 0.68, 0.07, sign * 0.4);
  if (house) {
    const x = house.x2 + 0.15, d = house.dFacade + 8;
    round(dark, x, d, 0.56, 0.52, 0.21, Math.PI / 2);
    round(green, x, d, 0.56, 0.44, 0.25, Math.PI / 2);
    box(green, x, d + 0.21, 0.32, 0.05, 0.055, 0.48);
  }
  for (const [x, d, w] of [[1.28, 3.8, 0.74], [1.06, 4.48, 0.6], [10.25, 32.1, 0.68], [10.3, 34.1, 0.56]]) {
    add(sphere, stone, x - 0.25, d + 0.29, 0.13, 0.45, 0.35, 0.31);
    add(sphere, green, x, d, w * 0.34, w, w * 0.85, w * 0.76);
    add(sphere, leaves, x + 0.21, d + 0.05, w * 0.49, w * 0.69, w * 0.73, w * 0.64);
  }

  for (const { mat, geometry, matrices } of batches) {
    const mesh = new THREE.InstancedMesh(geometry, mat, matrices.length);
    matrices.forEach((matrix, i) => mesh.setMatrixAt(i, matrix));
    group.add(mesh);
  }
}
