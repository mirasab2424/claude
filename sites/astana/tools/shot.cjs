/* Скриншоты карты и отдельных моделей без интернета.
   Запуск из sites/astana:
     NODE_PATH=$(npm root -g) node tools/shot.cjs                 — карта: весь город и каждое место, тёмная и светлая тема
     NODE_PATH=$(npm root -g) node tools/shot.cjs --model opera   — одна модель с 4 сторон (dev/model.html)
     ... --views "#v0,#v3light"                                   — свои виды (#vN — место N, light — светлая тема)
     ... --no-private                                             — как в публичном репозитории, без личных мест
   Библиотеки Three.js берутся из tools/lib (tools/get-libs.sh), страницы — из public/ через встроенный сервер.
   Картинки — в shots/. В консоль пишутся ошибки страницы; при ошибках код выхода 1. */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path'), http = require('http');
const ROOT = path.resolve(__dirname, '..'), PUB = path.join(ROOT, 'public'), LIB = path.join(__dirname, 'lib'), OUT = path.join(ROOT, 'shots');
const arg = (k, d) => { const i = process.argv.indexOf(k); return i < 0 ? d : (process.argv[i + 1] || true); };
const SKELETON = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
  + '<style>body{margin:0;font:14px system-ui}[hidden]{display:none!important}</style></head><body>';
const TYPES = { '.html': 'text/html', '.js': 'text/javascript', '.json': 'application/json', '.png': 'image/png' };

const server = http.createServer((req, res) => {
  const u = decodeURIComponent(new URL(req.url, 'http://x').pathname);
  const f = path.join(PUB, u === '/' ? 'index.html' : u);
  if (!f.startsWith(PUB) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end(); }
  if (arg('--no-private') && f.endsWith('places.private.json')) { res.writeHead(404); return res.end(); }
  let body = fs.readFileSync(f);
  // index.html пишется без <!doctype>/<head>, как требует Artifact: добавляем тот же каркас
  if (f === path.join(PUB, 'index.html')) body = SKELETON + body;
  res.writeHead(200, { 'content-type': TYPES[path.extname(f)] || 'application/octet-stream' }); res.end(body);
});

(async () => {
  await new Promise(r => server.listen(0, '127.0.0.1', r));
  const base = `http://127.0.0.1:${server.address().port}/`;
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ args: ['--use-gl=swiftshader', '--enable-unsafe-swiftshader', '--ignore-gpu-blocklist'] });
  const page = await (await browser.newContext({ viewport: { width: +arg('--w', 1100), height: +arg('--h', 620) }, deviceScaleFactor: 1 })).newPage();
  let errors = 0;
  page.on('console', m => { if (m.type() === 'error') { errors++; console.log('ERR', m.text()); } });
  page.on('pageerror', e => { errors++; console.log('PAGEERR', e.message); });
  await page.route(/cdn\.jsdelivr\.net\/npm\/three@0\.147\.0\//, r => {
    const f = path.join(LIB, path.basename(new URL(r.request().url()).pathname));
    return fs.existsSync(f) ? r.fulfill({ path: f, contentType: 'text/javascript' }) : r.abort();
  });
  await page.route(/fonts\.(googleapis|gstatic)\.com/, r => r.fulfill({ body: '', contentType: 'text/css' })); // шрифты не нужны для проверки
  const model = arg('--model');
  const jobs = model
    ? ['az=0.5&pol=1.2', 'az=-2.2&pol=1.2', 'az=2.4&pol=0.6', 'az=-0.9&pol=1.3&light=1&k=1.3'].map((q, i) => [`dev/model.html?m=${model}&${q}`, `model-${model}-${i}`])
    : String(arg('--views', '#v0,#v0light,#v1,#v2,#v3,#v4,#v5,#v6,#v7,#v8,#v9')).split(',').map(h => ['index.html' + h, 'map-' + h.replace('#', '')]);
  for (const [url, name] of jobs) {
    await page.goto('about:blank'); await page.goto(base + url);
    const ok = await page.waitForFunction(() => document.title.startsWith('OK') || document.title.startsWith('ERR') || (document.querySelector('#load') && document.querySelector('#load').hidden), null, { timeout: +arg('--timeout', 240000) }).then(() => true, () => false);
    if (!ok) { errors++; console.log('TIMEOUT', url); }
    if (model && name.endsWith('-0')) console.log(await page.title());
    await page.waitForTimeout(model ? 300 : 2500);
    await page.screenshot({ path: path.join(OUT, name + '.png') });
  }
  await browser.close(); server.close();
  console.log(`${jobs.length} снимков в shots/, ошибок: ${errors}`);
  process.exit(errors ? 1 : 0);
})();
