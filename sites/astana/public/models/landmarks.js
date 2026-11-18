/* Ориентиры города тем же прозрачным голубым, что и город: Байтерек, Хан Шатыр, Акорда, мечеть Хазрет Султан.
   В отличие от залов возвращает группу в координатах города (ctx.lm — данные из city.json → lm). */
MODELS.landmarks = function (THREE, ctx) {
  const { add, edges, ghost, ghostLine, bm } = ctx, root = new THREE.Group(), L = ctx.lm;
  const grp = (d, keepAngle = true) => { const g = new THREE.Group(); g.position.set(d.c[0], 0, -d.c[1]); if (keepAngle) g.rotation.y = d.ang; root.add(g); return g; };
  let g = grp(L.baiterek, false); // Байтерек: ствол, крона, шар
  edges(add(g, new THREE.CylinderGeometry(4, 8, 78, 12, 1, true), ghost, 0, 39, 0), ghostLine);
  edges(add(g, new THREE.CylinderGeometry(17, 4, 22, 12, 1, true), ghost, 0, 87, 0), ghostLine);
  add(g, new THREE.SphereGeometry(11, 20, 14), bm({ color: 0xbfe6ff, opacity: .75, blending: ctx.ADD }, { color: 0xf0c040, opacity: .95, blending: ctx.NRM }), 0, 94, 0);
  g = grp(L.khan, false); // Хан Шатыр: наклонный шатёр
  const kg = new THREE.ConeGeometry(L.khan.L / 2, 130, 40, 6, true), ka = kg.attributes.position;
  for (let i = 0; i < ka.count; i++) { const y = ka.getY(i) + 65; ka.setY(i, y); ka.setX(i, ka.getX(i) + y * .22); }
  edges(add(g, kg, ghost), ghostLine, 8);
  add(g, new THREE.CylinderGeometry(.6, .6, 24, 6), ghost, 130 * .22, 142, 0);
  g = grp(L.akorda); // Акорда: купол и шпиль
  const dome = new THREE.SphereGeometry(19, 24, 12, 0, Math.PI * 2, 0, Math.PI / 2);
  add(g, dome, ghost, 0, 32, 0).scale.y = 1.25; add(g, new THREE.CylinderGeometry(.5, 1.4, 34, 8), ghost, 0, 72, 0);
  g = grp(L.hazret); // Хазрет Султан: купол и четыре минарета
  add(g, dome, ghost, 0, 22, 0).scale.set(1.1, 1.3, 1.1);
  for (const sx of [-1, 1]) for (const sz of [-1, 1]) add(g, new THREE.CylinderGeometry(1.4, 2.8, 74, 8), ghost, sx * L.hazret.L * .46, 37, sz * L.hazret.W * .46);
  return root;
};
