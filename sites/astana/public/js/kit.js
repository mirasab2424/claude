/* Общие помощники для моделей зданий. Подключается до models/*.js и до js/app.js.
   Модель — функция MODELS[id](THREE, ctx), возвращает THREE.Group в своих координатах:
   метры, Y вверх, начало — центр контура здания на земле, X — длинная ось здания.
   Страница сама ставит группу на место (центр и угол из data/city.json). */
window.MODELS = window.MODELS || {};
window.AstanaKit = (function () {
  /* Материалы, которые выглядят по-разному в тёмной и светлой теме: themed хранит [материал, тёмные, светлые]. */
  function makeKit(THREE) {
    const themed = [];
    const th = (o, dark, light) => { themed.push([o, dark, light]); return o; };
    const ADD = THREE.AdditiveBlending, NRM = THREE.NormalBlending;
    const bm = (dark, light) => th(new THREE.MeshBasicMaterial({ transparent: true, depthWrite: false, side: THREE.DoubleSide }), dark, light);
    const kit = {
      themed, th, bm, ADD, NRM,
      solid: (color, e = .38) => new THREE.MeshStandardMaterial({ color, emissive: color, emissiveIntensity: e, roughness: .55, metalness: .1, side: THREE.DoubleSide }),
      white: new THREE.LineBasicMaterial({ color: 0xffffff, transparent: true, opacity: .45 }),
      ghost: bm({ color: 0x3fa8ff, opacity: .42, blending: ADD }, { color: 0x3d8fe8, opacity: .55, blending: NRM }),
      ghostLine: th(new THREE.LineBasicMaterial({ transparent: true, depthWrite: false }), { color: 0x9ad8ff, opacity: .55, blending: ADD }, { color: 0x1560c0, opacity: .7, blending: NRM }),
      add: (g, geo, mat, x = 0, y = 0, z = 0) => { const m = new THREE.Mesh(geo, mat); m.position.set(x, y, z); g.add(m); return m; },
      /* Линии рёбер рядом с мешем (меш уже должен быть добавлен в группу). */
      edges: (mesh, mat, ang = 20) => {
        const e = new THREE.LineSegments(new THREE.EdgesGeometry(mesh.geometry, ang), mat);
        e.position.copy(mesh.position); e.rotation.copy(mesh.rotation); e.scale.copy(mesh.scale); mesh.parent.add(e); return e;
      },
      /* Выдавить контур [[x, y], …] (y — «на север», в сцене Z = −y) на высоту h и добавить в группу g. */
      extrude: (g, ring, h, mat, y = 0) => {
        const geo = new THREE.ExtrudeGeometry(new THREE.Shape(ring.map(p => new THREE.Vector2(p[0], p[1]))), { depth: h, bevelEnabled: false });
        geo.rotateX(-Math.PI / 2);
        const m = new THREE.Mesh(geo, mat); m.position.y = y; g.add(m); return m;
      },
      setTheme(light) {
        for (const [o, d, l] of themed) { const p = light ? l : d; for (const k in p) { if (k === 'color') o.color.set(p[k]); else o[k] = p[k]; } o.needsUpdate = true; }
      },
    };
    return kit;
  }
  /* Контур здания из city.json в координаты модели (центр d.c, поворот d.ang). */
  function localRing(d) {
    const ca = Math.cos(d.ang), sa = Math.sin(d.ang);
    return d.ring.map(([x, y]) => {
      const X = x - d.c[0], Z = -(y - d.c[1]);
      return [+(X * ca - Z * sa).toFixed(2), -(X * sa + Z * ca)];
    });
  }
  /* ctx для одной модели: помощники + размеры и контур здания + цвет. */
  function ctxFor(kit, d, extra) {
    return Object.assign({}, kit, { L: d.L, W: d.W, ring: localRing(d), d }, extra || {});
  }
  /* Поставить группу модели на место здания. */
  function place(g, d) { g.position.set(d.c[0], 0, -d.c[1]); g.rotation.y = d.ang; return g; }
  return { makeKit, localRing, ctxFor, place };
})();
