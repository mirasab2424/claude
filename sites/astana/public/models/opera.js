/* Астана Опера. Основание — контур OpenStreetMap из city.json.
   Фасад (−X) обращён к площади со стороны Кунаева; портик и квадрига — по фото Visit Astana
   и каталогу cultural.kz. Высоты и размеры деталей — примерно, по пропорциям на фото. */
MODELS.opera = function (THREE, ctx) {
  const { solid, extrude, add, ring, L, W, color = 0xffc94d } = ctx;
  const group = new THREE.Group();
  const stone = solid(0xf0dcb0, .12);
  const ivory = solid(0xffe8bb, .12);
  const cornice = solid(0xbc924c, .10);
  const roof = solid(0x766448, .06);
  const shadow = solid(0x514230, .08);
  const glazing = solid(0x655337, .10);
  const bronze = solid(color, .32);
  const box = new THREE.BoxGeometry(1, 1, 1);
  const shaft = new THREE.CylinderGeometry(.43, .5, 1, 12);
  const round = new THREE.CylinderGeometry(.5, .5, 1, 8);
  const ball = new THREE.SphereGeometry(.5, 8, 5);
  const front = Math.min(...ring.map(([x]) => x));
  const back = Math.max(...ring.map(([x]) => x));
  const sx = L / 173, sz = W / 111.7;

  // Один пакет экземпляров на пару геометрия/материал.
  const batches = [];
  const part = new THREE.Object3D();
  function piece(geo, mat, x, y, z, w, h, d, rotation = null) {
    let batch = batches.find(item => item.geo === geo && item.mat === mat);
    if (!batch) {
      batch = { geo, mat, matrices: [] };
      batches.push(batch);
    }
    part.position.set(x, y, z);
    part.scale.set(w, h, d);
    if (rotation) part.quaternion.copy(rotation);
    else part.quaternion.identity();
    part.updateMatrix();
    batch.matrices.push(part.matrix.clone());
  }
  const block = (mat, x, y, z, w, h, d) => piece(box, mat, x, y, z, w, h, d);
  function volume(x0, x1, y0, y1, z, width) {
    const x = (x0 + x1) / 2, length = x1 - x0;
    block(stone, x, (y0 + y1) / 2, z, length, y1 - y0, width);
    block(ivory, x, y1 - 1.4, z, length + 1.0, .6, width + 1.0);
    block(cornice, x, y1, z, length + 1.6, 1.1, width + 1.6);
    block(roof, x, y1 + .65, z, length + .65, .32, width + .65);
  }
  function column(x, z, y, h, diameter) {
    block(cornice, x, y + .35, z, diameter * 1.36, .7, diameter * 1.36);
    piece(round, ivory, x, y + .96, z, diameter * 1.16, .6, diameter * 1.16);
    piece(shaft, ivory, x, y + h / 2, z, diameter, h - 2.4, diameter);
    piece(round, cornice, x, y + h - 1.05, z, diameter * 1.18, .65, diameter * 1.18);
    block(ivory, x, y + h - .35, z, diameter * 1.36, .7, diameter * 1.36);
  }

  extrude(group, ring, 5.6, cornice);
  extrude(group, ring, .45, ivory, 5.6);
  for (let i = 0; i < 12; i++) {
    const x0 = front - 8.4 * sx + i * .7 * sx;
    block(i % 2 ? stone : ivory, (x0 + front + 3) / 2, (i + 1) * .25, 0,
      front + 3 - x0, (i + 1) * .5, 59 * sz);
  }

  // За отдельно стоящими колоннами остаётся глубокая тень портика.
  const porch = front + 2.2 * sx;
  const facade = front + 15 * sx;
  volume(facade, -21 * sx, 6.05, 29.2, 0, 65 * sz);
  volume(-22 * sx, 11 * sx, 6.05, 28, 0, 53.5 * sz);
  volume(10.6 * sx, 52 * sx, 6.05, 28.6, 0, 107 * sz);
  volume(52 * sx, back - 5.5 * sx, 6.05, 28.1, 1.5 * sz, 85 * sz);
  volume(back - 7 * sx, back - .5 * sx, 6.05, 24, 1 * sz, 36 * sz);
  volume(-47 * sx, 9 * sx, 29, 36.4, 0, 41 * sz);
  volume(14 * sx, 56 * sx, 28, 50, 0, 57 * sz);

  block(shadow, facade - .07, 17, 0, .25, 21, 62 * sz);
  for (let i = 0; i < 8; i++) column(porch, (i - 3.5) * 8.05 * sz, 6.05, 21.7, 3.25 * sz);
  for (let i = -3; i <= 3; i++) {
    const z = i * 8.05 * sz;
    block(cornice, facade - .32, 14.5, z, .7, 16, 5.6 * sz);
    block(glazing, facade - .72, 15, z, .18, 13.6, 4.6 * sz);
    block(ivory, facade - .85, 19.4, z, .15, .46, 4.7 * sz);
    block(cornice, facade - .87, 12.9, z, .2, 10, .24);
  }
  block(stone, (porch + facade) / 2, 29.3, 0, facade - porch + 5.6, 3.1, 66.4 * sz);
  block(ivory, (porch + facade) / 2, 30.9, 0, facade - porch + 6.4, .8, 67.3 * sz);

  function pediment(mat, x, y, half, height, depth) {
    const shape = new THREE.Shape([new THREE.Vector2(-half, 0), new THREE.Vector2(half, 0), new THREE.Vector2(0, height)]);
    const mesh = add(group, new THREE.ExtrudeGeometry(shape, { depth, bevelEnabled: false }), mat, x, y, 0);
    mesh.rotation.y = Math.PI / 2;
  }
  pediment(cornice, porch - 3.4, 31.1, 34 * sz, 10.4, facade - porch + 4);
  pediment(stone, porch - 3.55, 31.65, 30.5 * sz, 8.6, .24);
  for (const s of [-1, 1]) {
    const cap = add(group, box, roof, (porch + facade) / 2 - 1.4, 36.5, s * 17 * sz);
    cap.scale.set(facade - porch + 6.5, .75, Math.hypot(34 * sz, 10.4));
    cap.rotation.x = s * Math.atan2(10.4, 34 * sz);
  }
  block(cornice, porch - 3.7, 34.8, 0, .38, 4.5, 1.15);
  for (const s of [-1, 1]) {
    const relief = add(group, box, cornice, porch - 3.74, 35.4, s * 4.1 * sz);
    relief.scale.set(.4, 1.4, 8.3 * sz);
    relief.rotation.x = s * .24;
  }

  // Длинные фасады: колонны перед стеной, пилястры и оконные проёмы между ними.
  for (const side of [-1, 1]) {
    for (let i = 0; i < 7; i++) column((-67 + i * 7) * sx, side * 33.1 * sz, 6.05, 21.7, 2.55 * sz);
    for (const [a, b, edge, n] of [[-68, -22, 32.5, 6], [-21, 9, 26.75, 4], [11, 52, 53.5, 6], [53, 81, 42.5, 4]]) {
      const z = side * edge * sz;
      for (let i = 0; i < n; i++) {
        const x = (a + (i + .5) * (b - a) / n) * sx;
        block(cornice, x, 17.5, z + side * .14, 4.5 * sx, 14.1, .7);
        block(glazing, x, 17.7, z + side * .55, 3.4 * sx, 12.8, .18);
        block(ivory, x, 16.25, z + side * .7, 3.6 * sx, .46, .18);
        block(ivory, x - 3.02 * sx, 17.8, z + side * .4, 1.08 * sx, 20.7, 1.2);
      }
    }
  }

  // Квадрига: четыре силуэта коней, колесница с колёсами и фигура возницы.
  function limb(a, b, width) {
    const start = new THREE.Vector3(...a), end = new THREE.Vector3(...b);
    const delta = end.clone().sub(start), middle = start.addScaledVector(delta, .5);
    const rot = new THREE.Quaternion().setFromUnitVectors(new THREE.Vector3(0, 1, 0), delta.clone().normalize());
    piece(round, bronze, middle.x, middle.y, middle.z, width, delta.length(), width, rot);
  }
  const qx = porch + 8.4 * sx, qy = 41.5;
  block(roof, qx, qy - .4, 0, 14.5 * sx, 1.25, 14.5 * sz);
  block(bronze, qx + 4.2, qy + 1.3, 0, 3.2, 2.2, 4.4 * sz);
  const axle = new THREE.Quaternion().setFromAxisAngle(new THREE.Vector3(1, 0, 0), Math.PI / 2);
  for (const s of [-1, 1]) piece(round, bronze, qx + 4.55, qy + 1.02, s * 2.45 * sz, 2.1, .36, 2.1, axle);
  for (let i = 0; i < 4; i++) {
    const z = (i - 1.5) * 2.65 * sz;
    const x = qx - 1 - (i === 1 || i === 2 ? .8 : 0);
    piece(ball, bronze, x, qy + 2.1, z, 4.1, 1.8, 1.55);
    limb([x - 1.25, qy + 2.2, z], [x - 2.25, qy + 4.1, z], 1.1);
    piece(ball, bronze, x - 2.6, qy + 4.05, z, 1.63, 1, .93);
    for (const s of [-1, 1]) {
      limb([x + 1.2, qy + 1.95, z + s * .42], [x + 1.62, qy + .13, z + s * .55], .39);
      const knee = [x - 2.4, qy + 1.49 + s * .23, z + s * .52];
      limb([x - 1.3, qy + 2, z + s * .38], knee, .4);
      limb(knee, [x - 2.88, qy + 1.08 + s * .23, z + s * .52], .35);
    }
    limb([x + 1.63, qy + 2.4, z], [x + 2.8, qy + .92, z], .44);
  }
  piece(shaft, bronze, qx + 4.4, qy + 3.5, 0, 1.2, 2.8, 1.49);
  piece(ball, bronze, qx + 4.28, qy + 5.3, 0, .99, 1.1, 1.04);
  for (const s of [-1, 1]) limb([qx + 4.4, qy + 4.35, s * .5], [qx + 3, qy + 4.85, s * 1.52], .38);

  for (const { geo, mat, matrices } of batches) {
    const mesh = new THREE.InstancedMesh(geo, mat, matrices.length);
    matrices.forEach((matrix, index) => mesh.setMatrixAt(index, matrix));
    group.add(mesh);
  }
  return group;
};
