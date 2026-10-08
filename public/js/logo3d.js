// 3D-логотип Success: процедурная копия модели из logo.blend с анимацией сборки.
import * as THREE from 'three';

const ease = (t) => (t <= 0 ? 0 : t >= 1 ? 1 : 1 - Math.pow(1 - t, 3));
const phase = (t, start, dur) => ease((t - start) / dur);

export function mountLogo(container, { interactive = true, autoRotate = true } = {}) {
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    container.appendChild(renderer.domElement);

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(32, 1, 0.1, 100);
    camera.position.set(0, 0, 19);

    scene.add(new THREE.AmbientLight(0xffffff, 0.55));
    const key = new THREE.DirectionalLight(0xffffff, 1.6);
    key.position.set(4, 6, 8);
    scene.add(key);
    const rim = new THREE.DirectionalLight(0x86efac, 0.8);
    rim.position.set(-6, -2, -4);
    scene.add(rim);

    const green = new THREE.MeshStandardMaterial({ color: 0x22c55e, roughness: 0.35, metalness: 0.1 });
    const greenDark = new THREE.MeshStandardMaterial({ color: 0x16a34a, roughness: 0.35, metalness: 0.1 });
    const grey = new THREE.MeshStandardMaterial({ color: 0x8a8f8c, roughness: 0.55, metalness: 0.25 });
    const black = new THREE.MeshStandardMaterial({ color: 0x0b0f0d, roughness: 0.3, metalness: 0.6 });

    const logo = new THREE.Group();
    scene.add(logo);

    // Координаты взяты с кадра видео (px / 100), центр логотипа — в начале координат.
    const P = (x, y) => [(x - 500) / 100, -(y - 450) / 100];
    const depth = 0.4;

    const box = (x1, y1, x2, y2, mat, z = 0) => {
        const [ax, ay] = P(x1, y1);
        const [bx, by] = P(x2, y2);
        const mesh = new THREE.Mesh(new THREE.BoxGeometry(Math.abs(bx - ax), Math.abs(by - ay), depth), mat);
        mesh.position.set((ax + bx) / 2, (ay + by) / 2, z);
        return mesh;
    };

    // Разомкнутое кольцо из четырёх сегментов со скошенными концами.
    const ringCenter = P(440, 600);
    const ringSegments = [[-0.2, 1.35], [1.62, 2.75], [3.05, 4.15], [4.42, 5.75]].map(([a0, a1], i) => {
        const shape = new THREE.Shape();
        const R = 2.15, r = 1.75;
        shape.absarc(0, 0, R, a0, a1, false);
        shape.lineTo(Math.cos(a1 + 0.08) * r, Math.sin(a1 + 0.08) * r);
        shape.absarc(0, 0, r, a1 + 0.08, a0 + 0.08, true);
        shape.closePath();
        const geo = new THREE.ExtrudeGeometry(shape, { depth, bevelEnabled: true, bevelThickness: 0.04, bevelSize: 0.04, bevelSegments: 2, curveSegments: 32 });
        geo.translate(0, 0, -depth / 2);
        const mesh = new THREE.Mesh(geo, grey);
        const pivot = new THREE.Group();
        pivot.position.set(ringCenter[0], ringCenter[1], -0.25);
        pivot.add(mesh);
        pivot.userData.from = { x: (i % 2 ? 1 : -1) * 6, y: (i < 2 ? 1 : -1) * 4, rot: (i + 1) * 1.7 };
        logo.add(pivot);
        return pivot;
    });

    // Высокая зелёная планка растёт снизу вверх — поэтому якорь у основания.
    const grow = (mesh) => {
        const g = new THREE.Group();
        const h = mesh.geometry.parameters.height;
        g.position.set(mesh.position.x, mesh.position.y - h / 2, mesh.position.z);
        mesh.position.set(0, h / 2, 0);
        g.add(mesh);
        logo.add(g);
        return g;
    };
    const bar1 = grow(box(505, 20, 551, 840, green, 0.15));
    const bar2 = grow(box(583, 200, 623, 775, greenDark, 0.15));

    const prongs = [[20, 64, 318], [105, 145, 318], [188, 228, 318]].map(([y1, y2, x1]) => {
        const m = box(x1, y1, 506, y2, green, 0.15);
        logo.add(m);
        m.userData.home = m.position.clone();
        return m;
    });

    const blackTip = box(590, 100, 660, 200, black, 0.15);
    logo.add(blackTip);
    const greyProngs = [112, 156, 200].map((y) => {
        const m = box(652, y, 722, y + 26, grey, 0.1);
        logo.add(m);
        m.userData.home = m.position.clone();
        return m;
    });

    // Чёрные «орбиты» — тонкие торы, которые крутятся вокруг деталей.
    const orbits = [
        { c: P(455, 760), r: 1.5, tilt: [1.2, 0.2, 0], speed: 0.6 },
        { c: P(700, 210), r: 1.15, tilt: [0.3, 1.1, 0.4], speed: -0.8 },
        { c: P(250, 420), r: 0.95, tilt: [1.4, -0.6, 0], speed: 1.0 },
        { c: P(580, 620), r: 0.85, tilt: [0.2, 1.3, 0.9], speed: -1.2 },
    ].map((o) => {
        const g = new THREE.Group();
        g.position.set(o.c[0], o.c[1], 0);
        g.rotation.set(...o.tilt);
        const torus = new THREE.Mesh(new THREE.TorusGeometry(o.r, 0.07, 12, 96), black);
        g.add(torus);
        g.userData = o;
        logo.add(g);
        return g;
    });

    // Таймлайн сборки ~5.5 с, как в оригинальном ролике.
    let start = performance.now();
    const replay = () => { start = performance.now(); };
    const update = (now) => {
        const t = reduceMotion ? 99 : (now - start) / 1000;

        ringSegments.forEach((seg, i) => {
            const k = phase(t, 0.1 + i * 0.12, 1.0);
            const f = seg.userData.from;
            seg.children[0].position.set(f.x * (1 - k), f.y * (1 - k), 0);
            seg.rotation.z = f.rot * (1 - k);
        });
        bar1.scale.y = Math.max(0.001, phase(t, 1.0, 0.9));
        bar2.scale.y = Math.max(0.001, phase(t, 1.5, 0.9));
        prongs.forEach((m, i) => {
            const k = phase(t, 2.2 + i * 0.15, 0.7);
            m.position.x = m.userData.home.x - 5 * (1 - k);
            m.position.y = m.userData.home.y - 2 * (1 - k);
            m.visible = k > 0.001;
        });
        const tipK = phase(t, 3.0, 0.5);
        blackTip.scale.setScalar(Math.max(0.001, tipK));
        greyProngs.forEach((m, i) => {
            const k = phase(t, 3.4 + i * 0.12, 0.5);
            m.position.x = m.userData.home.x + 2 * (1 - k);
            m.scale.setScalar(Math.max(0.001, k));
        });
        orbits.forEach((o, i) => {
            const k = phase(t, 4.0 + i * 0.2, 0.8);
            o.scale.setScalar(Math.max(0.001, k));
            o.rotation.z += 0.01 * o.userData.speed;
            o.rotation.x = o.userData.tilt[0] + Math.sin(now / 1500 + i) * 0.25;
        });
    };

    // Наклон за курсором + лёгкое покачивание.
    const target = { x: 0, y: 0 };
    if (interactive) {
        window.addEventListener('pointermove', (e) => {
            target.y = (e.clientX / window.innerWidth - 0.5) * 0.9;
            target.x = (e.clientY / window.innerHeight - 0.5) * 0.5;
        });
        container.addEventListener('click', replay);
        container.style.cursor = 'pointer';
        container.title = 'Нажмите, чтобы собрать заново';
    }

    const resize = () => {
        const { clientWidth: w, clientHeight: h } = container;
        renderer.setSize(w, h, false);
        camera.aspect = w / h;
        camera.updateProjectionMatrix();
    };
    new ResizeObserver(resize).observe(container);
    resize();

    const loop = (now) => {
        update(now);
        const sway = autoRotate && !reduceMotion ? Math.sin(now / 2600) * 0.35 : 0;
        logo.rotation.y += (target.y + sway - logo.rotation.y) * 0.05;
        logo.rotation.x += (target.x - logo.rotation.x) * 0.05;
        renderer.render(scene, camera);
        requestAnimationFrame(loop);
    };
    requestAnimationFrame(loop);

    return { replay };
}

document.querySelectorAll('[data-logo3d]').forEach((el) => {
    try {
        mountLogo(el, { interactive: el.dataset.logo3d !== 'static' });
    } catch (e) {
        // Без WebGL показываем плоский SVG-логотип.
        el.innerHTML = '<img src="/img/logo.svg" alt="Success" style="height:100%;margin:auto;display:block">';
    }
});
