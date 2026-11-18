/* Центральный концертный зал «Казахстан» (Мангилик Ел, 10/1): зал в окружении наклонных стеклянных «лепестков». */
MODELS.concert = function (THREE, ctx) {
  const { solid, white, edges, add, extrude, L, W } = ctx, g = new THREE.Group();
  const m = solid(0x2fe0a0, .45), dark = solid(0x108a66, .3);
  edges(extrude(g, ctx.ring, 8, dark), white, 30);
  const core = add(g, new THREE.CylinderGeometry(.3, .42, 1, 28), dark, 0, 17, 0); core.scale.set(L, 18, W);
  const petal = new THREE.SphereGeometry(1, 18, 14, Math.PI / 2 - .62, 1.24, .06, Math.PI / 2 - .06);
  const N = 9;
  for (let i = 0; i < N; i++) {
    const a = i / N * Math.PI * 2 + .2, hgt = 30 + 16 * (.5 + .5 * Math.cos(a * 2 + .6)) + (i % 2 ? 6 : 0);
    const p = add(g, petal, m, Math.cos(a) * L * .2, 6, Math.sin(a) * W * .2);
    p.scale.set(34, hgt, L * .3 * (.75 + .25 * Math.abs(Math.cos(a)))); p.rotation.y = Math.PI / 2 - a; p.rotation.order = 'YXZ'; p.rotation.x = -.16;
    edges(p, white, 40);
  }
  return g;
};
