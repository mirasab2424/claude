/* Астана Опера (ул. Кунаева, 1): неоклассика — стилобат, портик с колоннами и фронтоном,
   квадрига над фронтоном, колоннады по длинным сторонам, сценическая коробка. Главный фасад — со стороны −X. */
MODELS.opera = function (THREE, ctx) {
  const { solid, white, edges, add, extrude, L, W } = ctx, g = new THREE.Group();
  const c = 0xffc94d, m = solid(0xf0d28a, .12), dark = solid(0xd9a93a, .35);
  edges(extrude(g, ctx.ring, 6, dark), white);                         // стилобат по настоящему контуру
  add(g, new THREE.BoxGeometry(L * .9, 22, W * .74), m, 0, 17, 0);
  add(g, new THREE.BoxGeometry(L * .94, 3, W * .84), dark, 0, 29.5, 0); // карниз
  add(g, new THREE.BoxGeometry(L * .26, 16, W * .5), m, L * .14, 39, 0); // сценическая коробка
  add(g, new THREE.BoxGeometry(L * .3, 6, W * .56), m, -L * .16, 34, 0); // крыша зала
  const col = new THREE.CylinderGeometry(1.7, 1.9, 22, 10);
  for (let i = 0; i < 8; i++) add(g, col, m, -L * .5 + 3, 17, (i - 3.5) * W * .085); // портик
  for (let x = -L * .42; x < L * .44; x += 8.5) { add(g, col, m, x, 17, W * .4); add(g, col, m, x, 17, -W * .4); }
  const tri = new THREE.Shape([new THREE.Vector2(-W * .36, 0), new THREE.Vector2(W * .36, 0), new THREE.Vector2(0, 11)]);
  const ped = add(g, new THREE.ExtrudeGeometry(tri, { depth: 14, bevelEnabled: false }), dark, -L * .5, 31, 0);
  ped.rotation.y = Math.PI / 2; ped.position.x = -L * .5 + 1;
  add(g, new THREE.BoxGeometry(5, 6, 9), solid(c, .7), -L * .5 + 7, 45, 0); // квадрига
  add(g, new THREE.BoxGeometry(10, 2, W * .7), dark, -L * .5 - 4, 5, 0);    // ступени
  return g;
};
