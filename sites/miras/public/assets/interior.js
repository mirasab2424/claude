import * as THREE from 'three';

const mm = (value) => value / 1000;
const trim = 45;

function contains(polygon, x, y) {
  return polygon.reduce((inside, [ax, ay], i) => {
    const [bx, by] = polygon[(i + 1) % polygon.length];
    return (ay > y) !== (by > y) && x < ax + (bx - ax) * (y - ay) / (by - ay)
      ? !inside : inside;
  }, false);
}

export function addInterior({ h, root, openings, H, night }) {
  const group = new THREE.Group();
  group.name = 'Интерьер первого этажа';
  root.add(group);

  const material = (color) => new THREE.MeshStandardMaterial({ color, roughness: 0.85 });
  const baseboard = material(0xe3d9cc);
  const wood = material(0x8a5a33);
  const metal = material(0x2f3338);
  const fabric = material(0xd4c1aa);
  const weave = material(0x9d7967);
  const rugBorder = material(0x67483d);
  const shade = new THREE.MeshStandardMaterial({
    color: 0xffedcc, roughness: 0.6, emissive: 0xffc773, emissiveIntensity: 0.25,
  });
  night.glow.push(shade);

  const batches = new Map();
  const transform = new THREE.Object3D();
  const box = (mat, x, y, z, width, height, depth, angle = 0) => {
    transform.position.set(mm(x), mm(z), -mm(y));
    transform.rotation.y = angle;
    transform.scale.set(mm(width), mm(height), mm(depth));
    transform.updateMatrix();
    if (!batches.has(mat)) batches.set(mat, []);
    batches.get(mat).push(transform.matrix.clone());
  };
  const wallBox = (mat, axis, u, v, z, width, height, depth, angle = 0) => {
    if (axis === 'y') box(mat, u, v, z, width, height, depth, angle);
    else box(mat, v, u, z, width, height, depth, Math.PI / 2 + angle);
  };

  const areas = [];
  const roomEdges = new Map();
  for (const room of h.rooms) {
    const poly = room.floor_polygon_mm.value;
    let area2 = 0, cx = 0, cy = 0;
    const edges = poly.map(([x, y], i) => {
      const [nx, ny] = poly[(i + 1) % poly.length];
      const cross = x * ny - nx * y;
      area2 += cross;
      cx += (x + nx) * cross;
      cy += (y + ny) * cross;
      const axis = y === ny ? 'y' : 'x';
      const [a, b] = axis === 'y' ? [x, nx] : [y, ny];
      return { axis, a: Math.min(a, b), b: Math.max(a, b), at: axis === 'y' ? y : x,
        inward: Math.sign(b - a) * (axis === 'y' ? 1 : -1) };
    });
    roomEdges.set(room.id, edges);

    for (const edge of edges) {
      const doors = openings.filter((o) => o.kind === 'door' && o.d.between.includes(room.id)
        && o.axis === edge.axis && Math.abs(o.at - edge.at) <= o.t / 2 + 1
        && o.a < edge.b && o.b > edge.a).sort((a, b) => a.a - b.a);
      let start = edge.a;
      const segment = (end) => {
        if (end > start) wallBox(baseboard, edge.axis, (start + end) / 2, edge.at + 6 * edge.inward, 38, end - start, 70, 12);
      };
      for (const door of doors) {
        segment(Math.max(start, door.a - trim));
        start = Math.max(start, door.b + trim);
      }
      segment(edge.b);
    }

    cx /= 3 * area2;
    cy /= 3 * area2;
    box(metal, cx, cy, H - 22, 320, 24, 320);
    box(shade, cx, cy, H - 50, 290, 32, 290);
    areas.push({ room, area: Math.abs(area2), cx, cy });
  }

  for (const opening of openings) {
    const { kind, axis, a, b, at, hi, t } = opening;
    if (kind === 'win') {
      if (!roomEdges.has(opening.w.room)) continue;
      const { lo, out } = opening;
      const face = at + out * (t / 2 + 48);
      wallBox(metal, axis, (a + b) / 2, face, hi + 95, b - a + 300, 18, 18);
      for (const edge of [a, b]) {
        const side = edge === a ? -1 : 1;
        for (let fold = 0; fold < 4; fold++) {
          wallBox(fabric, axis, edge + side * (25 + fold * 30), face + out * (fold % 2 ? 14 : 0),
            (lo + hi) / 2 + 30, 35, hi - lo + 100, 24);
        }
      }
      continue;
    }
    if (opening.d.between.includes('outside')) continue;

    for (const side of [-1, 1]) {
      const face = at + side * (t / 2 + 6);
      for (const u of [a - trim / 2, b + trim / 2]) wallBox(wood, axis, u, face, hi / 2, trim, hi, 12);
      wallBox(wood, axis, (a + b) / 2, face, hi + trim / 2, b - a + 2 * trim, trim, 12);
    }

    const width = b - a - 18;
    const angle = THREE.MathUtils.degToRad(75);
    const rooms = h.rooms.filter((r) => opening.d.between.includes(r.id)).sort((a, b) => (a.id === 'hall') - (b.id === 'hall'));
    let swing;
    // Подбираем сторону и петлю, чтобы открытая створка находилась внутри комнаты.
    for (const room of rooms) {
      for (const edge of roomEdges.get(room.id)) {
        if (edge.axis !== axis || Math.abs(edge.at - at) > t / 2 + 1 || a >= edge.b || b <= edge.a) continue;
        for (const direction of [1, -1]) {
          const u = direction === 1 ? a + 9 : b - 9;
          const v = edge.at + edge.inward * 24;
          const du = direction * Math.cos(angle);
          const dv = edge.inward * Math.sin(angle);
          const tipU = u + width * du, tipV = v + width * dv;
          const poly = room.floor_polygon_mm.value;
          if (axis === 'y' ? contains(poly, tipU, tipV) : contains(poly, tipV, tipU)) {
            swing = { u, v, du, dv };
            break;
          }
        }
        if (swing) break;
      }
      if (swing) break;
    }
    if (!swing) continue;
    const { u, v, du, dv } = swing;
    const rotation = Math.atan2(axis === 'y' ? dv : -dv, du);
    wallBox(wood, axis, u + width * du / 2, v + width * dv / 2, hi / 2, width, hi - 24, 38, rotation);
    for (const height of [250, hi - 250]) wallBox(metal, axis, u, v, height, 18, 82, 18);
    const grip = width - 72;
    wallBox(metal, axis, u + grip * du, v + grip * dv, hi / 2, 68, 24, 104, rotation);
  }

  for (const { room, cx, cy } of areas.sort((a, b) => b.area - a.area).slice(0, 2)) {
    const width = room.bbox_size_mm.x.value * 0.48, depth = room.bbox_size_mm.y.value * 0.43;
    box(rugBorder, cx, cy, 8, width, 8, depth);
    box(weave, cx, cy, 10, width - 120, 8, depth - 120);
  }

  const unitBox = new THREE.BoxGeometry(1, 1, 1);
  for (const [mat, matrices] of batches) {
    const mesh = new THREE.InstancedMesh(unitBox, mat, matrices.length);
    matrices.forEach((matrix, i) => mesh.setMatrixAt(i, matrix));
    group.add(mesh);
  }
}
