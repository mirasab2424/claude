// 3D-логотип Success: модель и анимация из design/logo.blend (экспорт в public/models/logo.glb).
import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';

const MODEL_URL = document.querySelector('meta[name="logo-model"]')?.content || '/models/logo.glb';

export function mountLogo(container, { interactive = true } = {}) {
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 0.85;
    container.appendChild(renderer.domElement);

    const scene = new THREE.Scene();
    // Мягкое «студийное» окружение вместо HDRI из Blender: без него металлический зелёный выглядит чёрным.
    const pmrem = new THREE.PMREMGenerator(renderer);
    scene.environment = pmrem.fromScene(new RoomEnvironment(), 0.04).texture;
    const key = new THREE.DirectionalLight(0xffffff, 1.4);
    key.position.set(4, 6, 8);
    scene.add(key);

    const camera = new THREE.PerspectiveCamera(30, 1, 0.1, 200);
    const pivot = new THREE.Group();
    scene.add(pivot);

    let mixer = null;
    let action = null;
    let duration = 0;

    new GLTFLoader().load(MODEL_URL, (gltf) => {
        const model = gltf.scene;
        // Blender экспортирует анимацию каждой детали отдельным клипом — склеиваем в один.
        const clip = gltf.animations.length
            ? new THREE.AnimationClip('logo', -1, gltf.animations.flatMap((c) => c.tracks))
            : null;
        mixer = new THREE.AnimationMixer(model);
        if (clip) {
            duration = clip.duration;
            action = mixer.clipAction(clip);
            action.setLoop(THREE.LoopOnce, 1);
            action.clampWhenFinished = true;
            action.play();
        }

        // Кадрируем по собранному логотипу (последний кадр), а не по разлетевшимся деталям.
        mixer.setTime(duration);
        model.updateMatrixWorld(true);
        const box = new THREE.Box3().setFromObject(model);
        const center = box.getCenter(new THREE.Vector3());
        const size = box.getSize(new THREE.Vector3());
        model.position.sub(center);
        pivot.add(model);

        const radius = Math.max(size.x, size.y) / 2;
        const distance = radius / Math.sin(THREE.MathUtils.degToRad(camera.fov / 2)) * 1.05;
        camera.position.set(distance * 0.18, distance * 0.08, distance);
        camera.lookAt(0, 0, 0);

        if (!reduceMotion && action) {
            action.reset().play();
            mixer.setTime(0);
        }
    }, undefined, () => fallback(container));

    const replay = () => action?.reset().play();

    const target = { x: 0, y: 0 };
    if (interactive) {
        window.addEventListener('pointermove', (e) => {
            target.y = (e.clientX / window.innerWidth - 0.5) * 0.9;
            target.x = (e.clientY / window.innerHeight - 0.5) * 0.4;
        });
        container.addEventListener('click', replay);
        container.style.cursor = 'pointer';
        container.title = 'Нажмите, чтобы проиграть анимацию заново';
    }

    const resize = () => {
        const { clientWidth: w, clientHeight: h } = container;
        renderer.setSize(w, h, false);
        camera.aspect = w / h;
        camera.updateProjectionMatrix();
    };
    new ResizeObserver(resize).observe(container);
    resize();

    const clock = new THREE.Clock();
    renderer.setAnimationLoop(() => {
        const dt = clock.getDelta();
        mixer?.update(dt);
        const sway = reduceMotion ? 0 : Math.sin(clock.elapsedTime / 2.6) * 0.3;
        pivot.rotation.y += (target.y + sway - pivot.rotation.y) * 0.05;
        pivot.rotation.x += (target.x - pivot.rotation.x) * 0.05;
        renderer.render(scene, camera);
    });

    return { replay };
}

function fallback(el) {
    el.innerHTML = '<img src="/img/logo.png" alt="Success" style="height:100%;margin:auto;display:block">';
}

document.querySelectorAll('[data-logo3d]').forEach((el) => {
    try {
        mountLogo(el, { interactive: el.dataset.logo3d !== 'static' });
    } catch (e) {
        // Без WebGL показываем плоский логотип.
        fallback(el);
    }
});
