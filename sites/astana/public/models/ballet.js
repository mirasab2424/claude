/* Театр «Астана Балет» (Улы Дала, 43/1): длинный остеклённый объём в «вуали» из вертикальных ламелей,
   над ним сценическая коробка. Основание — настоящий контур из OpenStreetMap. */
MODELS.ballet = function (THREE, ctx) {
  const { solid, white, edges, add, extrude, L, W } = ctx, g = new THREE.Group();
  const m = solid(0xff5fa2, .4), lite = solid(0xffa9cc, .12);
  edges(extrude(g, ctx.ring, 20, m), white, 30);
  add(g, new THREE.BoxGeometry(L * .2, 14, W * .5), lite, L * .05, 27, 0);
  add(g, new THREE.BoxGeometry(L * .5, 3, W * .62), lite, 0, 21.5, 0);
  const fin = new THREE.BoxGeometry(.9, 24, 2.4);
  for (let x = -L / 2; x <= L / 2; x += 6) { add(g, fin, lite, x, 12, W / 2 + 1.5); add(g, fin, lite, x, 12, -W / 2 - 1.5); }
  const fin2 = new THREE.BoxGeometry(2.4, 24, .9);
  for (let z = -W / 2; z <= W / 2; z += 6) { add(g, fin2, lite, L / 2 + 1.5, 12, z); add(g, fin2, lite, -L / 2 - 1.5, 12, z); }
  return g;
};
