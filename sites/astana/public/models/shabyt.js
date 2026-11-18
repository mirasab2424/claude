/* Университет искусств и органный зал «Шабыт» (Тауелсиздик, 50): кольцо-«чаша» с наклонным верхом и рёбрами по фасаду. */
MODELS.shabyt = function (THREE, ctx) {
  const { solid, add, L } = ctx, g = new THREE.Group();
  const m = solid(0xb07bff, .22), R = L / 2;
  const pts = [[.46, 0], [.8, 0], [1, 34], [.56, 34], [.46, 0]].map(p => new THREE.Vector2(p[0] * R, p[1]));
  const geo = new THREE.LatheGeometry(pts, 56), pa = geo.attributes.position;
  for (let i = 0; i < pa.count; i++) if (pa.getY(i) > 1) pa.setY(i, pa.getY(i) + pa.getX(i) * .16);
  geo.computeVertexNormals(); add(g, geo, m);
  const rib = new THREE.BoxGeometry(.8, 36, 1.2), ribM = solid(0xd9c2ff, .15);
  for (let i = 0; i < 40; i++) {
    const a = i / 40 * Math.PI * 2, x = Math.cos(a) * R * .905, z = Math.sin(a) * R * .905;
    const b = add(g, rib, ribM, x, 17 + x * .08, z); b.rotation.y = -a; b.rotation.order = 'YXZ'; b.rotation.z = -.3;
  }
  return g;
};
