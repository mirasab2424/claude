/* Дворец мира и согласия (Тауелсиздик, 57): пирамида 62 × 62 × 62 м на насыпи, светящаяся вершина-витраж. */
MODELS.pyramid = function (THREE, ctx) {
  const { solid, add, L } = ctx, g = new THREE.Group();
  const m = solid(0xff8a3d, .4), s = L, r = s / Math.SQRT2;
  const mound = add(g, new THREE.CylinderGeometry(r * 1.25, r * 1.9, 14, 4), solid(0x8a3f12, .25), 0, 7, 0); mound.rotation.y = Math.PI / 4;
  const body = add(g, new THREE.ConeGeometry(r, s, 4, 5), m, 0, 14 + s / 2, 0); body.rotation.y = Math.PI / 4;
  const lat = new THREE.Mesh(new THREE.ConeGeometry(r * 1.005, s * 1.005, 4, 5), new THREE.MeshBasicMaterial({ color: 0xfff0dc, wireframe: true, transparent: true, opacity: .5 }));
  lat.position.copy(body.position); lat.rotation.y = Math.PI / 4; g.add(lat);
  const apex = add(g, new THREE.ConeGeometry(r * .3, s * .3, 4), solid(0xfff2a8, 1), 0, 14 + s * .855, 0); apex.rotation.y = Math.PI / 4; apex.scale.setScalar(1.03);
  return g;
};
