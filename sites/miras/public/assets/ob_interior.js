import * as THREE from 'three';

export function addOutbuildingInterior({ L, groundY, group, night }) {
  const floor = groundY + 0.12;
  const wall = L.ob.wall_m;
  const material = (color) => new THREE.MeshStandardMaterial({ color, roughness: 0.8 });
  const wood = material(0xb07a45), oak = material(0x8a5a33), white = material(0xf1ece2);
  const metal = material(0x777e84), dark = material(0x2f3338), red = material(0x873b32);
  const green = material(0x78845e), stone = material(0x55514c);
  const glow = new THREE.MeshStandardMaterial({ color: 0xffedcc, emissive: 0xffc773, emissiveIntensity: 0.25 });
  night.glow.push(glow);

  const cube = new THREE.BoxGeometry(1, 1, 1);
  const cylinder = new THREE.CylinderGeometry(0.5, 0.5, 1, 12);
  const ring = new THREE.TorusGeometry(0.44, 0.06, 6, 20);
  const seat = ring.clone().rotateX(Math.PI / 2);
  const instance = new THREE.Object3D();
  const batches = [];
  const add = (geometry, mat, x, d, height, w, l, h, rotation = 0) => {
    instance.position.set(x, floor + height, -d);
    instance.rotation.set(0, 0, rotation);
    instance.scale.set(w, h, l);
    instance.updateMatrix();
    let batch = batches.find((b) => b.geometry === geometry && b.mat === mat);
    if (!batch) { batch = { geometry, mat, matrices: [] }; batches.push(batch); }
    batch.matrices.push(instance.matrix.clone());
  };
  const box = (mat, x, d, z, w, l, h) => add(cube, mat, x, d, z, w, l, h);
  const round = (mat, x, d, z, diameter, height) => add(cylinder, mat, x, d, z, diameter, diameter, height);
  const table = (x, d, w, l, h, mat = wood) => {
    box(mat, x, d, h - 0.035, w, l, 0.07);
    for (const i of [-1, 1]) for (const j of [-1, 1]) {
      box(oak, x + i * (w / 2 - 0.09), d + j * (l / 2 - 0.09), (h - 0.07) / 2, 0.08, 0.08, h - 0.07);
    }
  };
  const shelf = (x, d, length, depth, heights) => {
    for (const h of heights) box(wood, x, d, h, depth, length, 0.05);
    const top = heights.at(-1) + 0.1;
    for (const j of [-1, 1]) box(dark, x, d + j * (length / 2 - 0.04), top / 2, depth, 0.06, top);
  };

  for (const r of L.rooms) {
    if (r.open_to_yard) continue;
    const cx = (r.x1 + r.x2) / 2, cd = (r.d1 + r.d2) / 2, fence = r.x2 - wall;
    const ceiling = THREE.MathUtils.lerp(L.ob.wall_height_m, L.ob.roof.fence_side_height_m, (cx - L.x1) / (L.x2 - L.x1));
    box(dark, cx, cd, ceiling - 0.045, 0.32, 0.2, 0.04);
    box(glow, cx, cd, ceiling - 0.085, 0.29, 0.17, 0.04);

    // Мебель у правой стены оставляет свободными входы и проходы в соседние комнаты.
    switch (r.id) {
      case 'kitchen': {
        const x = fence - 0.32;
        for (const [i, offset] of [-0.85, -0.25, 0.35].entries()) {
          const d = cd + offset;
          box(white, x, d, 0.43, 0.6, 0.57, 0.86);
          box(dark, x - 0.31, d, 0.7, 0.025, 0.23, 0.025);
          box(wood, x + 0.1, d, 1.85, 0.37, 0.57, 0.55);
          if (i === 0) {
            box(metal, x, d, 0.89, 0.64, 0.59, 0.055);
            box(dark, x, d, 0.92, 0.38, 0.42, 0.012);
            box(metal, x + 0.23, d, 1.02, 0.035, 0.035, 0.22);
            box(metal, x + 0.17, d, 1.12, 0.14, 0.035, 0.035);
          } else if (i === 1) {
            box(dark, x, d, 0.89, 0.64, 0.59, 0.055);
            for (const a of [-0.15, 0.15]) for (const b of [-0.13, 0.13]) round(metal, x + a, d + b, 0.922, 0.13, 0.012);
          } else box(wood, x, d, 0.89, 0.64, 0.59, 0.055);
        }
        box(white, x, r.d2 - 0.48, 0.96, 0.64, 0.61, 1.92);
        box(metal, x - 0.331, r.d2 - 0.48, 1.03, 0.025, 0.59, 0.025);
        box(dark, x - 0.35, r.d2 - 0.29, 1.2, 0.035, 0.035, 0.25);
        break;
      }
      case 'dining':
        box(red, cx, cd, 0.013, 2.55, 4.45, 0.025);
        table(cx, cd, 1, 3.8, 0.76);
        for (const sign of [-1, 1]) table(cx + sign * 0.86, cd, 0.34, 3.65, 0.45);
        box(oak, fence - 0.24, cd, 0.52, 0.46, 1.7, 1.04);
        for (const offset of [-0.54, 0, 0.54]) box(wood, fence - 0.49, cd + offset, 0.54, 0.035, 0.5, 0.88);
        break;
      case 'workshop':
        table(fence - 0.45, cd - 0.7, 0.75, 1.8, 0.91);
        box(metal, fence - 0.74, cd - 1.27, 1, 0.23, 0.22, 0.18);
        for (const offset of [-0.08, 0.08]) box(dark, fence - 0.74 + offset, cd - 1.27, 1.11, 0.07, 0.24, 0.05);
        shelf(fence - 0.25, cd + 0.77, 0.95, 0.42, [0.22, 0.82, 1.4, 1.95]);
        for (const h of [0.94, 1.52]) for (const d of [0.5, 0.75, 1]) box(red, fence - 0.28, cd + d, h, 0.23, 0.17, 0.14);
        box(green, cx + 0.45, r.d2 - 0.55, 0.45, 0.63, 0.66, 0.9);
        box(metal, cx + 0.45, r.d2 - 0.55, 0.92, 0.8, 0.76, 0.04);
        box(dark, cx + 0.45, r.d2 - 0.55, 1.02, 0.04, 0.25, 0.2);
        for (let i = 0; i < 4; i++) box(wood, fence - 0.09, r.d2 - 0.26 - i * 0.12, 0.85, 0.1, 0.085, 1.7);
        break;
      case 'anteroom':
        table(fence - 0.3, cd, 0.42, 2, 0.46);
        table(fence - 1.18, cd + 0.24, 0.65, 0.85, 0.7);
        table(fence - 1.85, cd + 0.2, 0.34, 0.84, 0.46);
        box(oak, fence - 0.06, cd, 1.68, 0.08, 1.38, 0.14);
        for (const offset of [-0.5, -0.25, 0, 0.25, 0.5]) box(metal, fence - 0.13, cd + offset, 1.67, 0.09, 0.028, 0.06);
        break;
      case 'sauna':
        table(fence - 0.38, cd, 0.65, r.length_m - 0.22, 1.02);
        table(fence - 0.94, cd + 0.1, 0.42, r.length_m - 0.45, 0.53);
        box(dark, r.x1 + wall + 0.45, r.d2 - 0.46, 0.44, 0.58, 0.58, 0.88);
        for (let i = 0; i < 7; i++) {
          const angle = i * Math.PI * 2 / 7;
          round(stone, r.x1 + wall + 0.45 + 0.18 * Math.cos(angle), r.d2 - 0.46 + 0.18 * Math.sin(angle), 0.91, 0.15, 0.12);
        }
        break;
      case 'wc':
        box(white, fence - 0.2, r.d2 - 0.47, 0.64, 0.3, 0.46, 0.45);
        round(white, fence - 0.47, r.d2 - 0.47, 0.22, 0.28, 0.44);
        round(white, fence - 0.55, r.d2 - 0.47, 0.43, 0.43, 0.16);
        add(seat, dark, fence - 0.55, r.d2 - 0.47, 0.516, 0.31, 0.05, 0.05);
        box(white, fence - 0.26, r.d1 + 0.42, 0.82, 0.45, 0.5, 0.16);
        box(metal, fence - 0.11, r.d1 + 0.42, 0.96, 0.035, 0.04, 0.15);
        break;
      case 'pantry':
        shelf(fence - 0.25, cd, 1.65, 0.46, [0.23, 0.73, 1.23, 1.73]);
        for (const h of [0.73, 1.23, 1.73]) for (const offset of [-0.56, -0.36, -0.16, 0.04, 0.24]) {
          round(green, fence - 0.25, cd + offset, h + 0.12, 0.14, 0.19);
          round(metal, fence - 0.25, cd + offset, h + 0.22, 0.15, 0.025);
        }
        for (const offset of [-0.5, 0, 0.5]) box(oak, fence - 0.25, cd + offset, 0.39, 0.36, 0.42, 0.27);
        break;
      case 'garage': {
        const rear = r.d2 - 0.34;
        const clear = L.car ? rear - 0.26 > L.car.d2 : true;
        if (!clear) break;
        table(cx + 0.65, rear, 1.55, 0.48, 0.9);
        for (const h of [0.1, 0.28, 0.46]) {
          round(dark, fence - 0.91, rear - 0.57, h, 0.57, 0.16);
          round(metal, fence - 0.91, rear - 0.57, h + 0.08, 0.31, 0.012);
        }
        const d = rear - 0.36, x = cx - 0.22;
        if (!L.car || d - 0.08 > L.car.d2) {
          for (const off of [-0.33, 0.33]) add(ring, dark, x + off, d, 0.27, 0.54, 0.54, 0.54);
          add(cube, red, x - 0.12, d, 0.46, 0.56, 0.03, 0.032, 0.7);
          add(cube, red, x + 0.12, d, 0.43, 0.5, 0.03, 0.032, -0.59);
          box(dark, x - 0.15, d, 0.68, 0.16, 0.1, 0.05);
          box(metal, x + 0.33, d, 0.53, 0.035, 0.035, 0.5);
          box(dark, x + 0.33, d, 0.78, 0.06, 0.32, 0.035);
        }
        break;
      }
    }
  }

  for (const { geometry, mat, matrices } of batches) {
    const mesh = new THREE.InstancedMesh(geometry, mat, matrices.length);
    matrices.forEach((matrix, i) => mesh.setMatrixAt(i, matrix));
    group.add(mesh);
  }
}
