// План этажа в SVG из measurements.json. Улица снизу, сторона входа слева — как видно, если стоять на улице лицом к дому.
// Геометрия рисуется в координатах данных (y — от улицы вглубь) и переворачивается целиком; подписи ставятся уже в экранных координатах.
import { m05, m1n, tol, roomName, windowWall, doorWall, stairVariant, ELECTRIC } from './house.js?v=22';

const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

export function planSVG(h, opts = {}) {
  const o = { windows: true, doors: true, electric: false, stair: false, compact: false, ...opts };
  const fp = h.exterior.footprint_mm.value;
  const W = Math.max(...fp.map((p) => p[0])), D = Math.max(...fp.map((p) => p[1]));
  const Y = (y) => D - y; // экранная координата
  const pad = o.compact ? 700 : 1700;
  const vb = [-pad, -pad, W + pad * 2, D + pad * 2 + 600];
  const pts = (arr) => arr.map((p) => p.join(',')).join(' ');
  const fs = o.compact ? 360 : 300;
  let geo = '', txt = '';
  const text = (x, y, cls, size, body, extra = '') => { txt += `<text x="${x}" y="${y}" text-anchor="middle" class="${cls}" font-size="${size}" ${extra}>${body}</text>`; };

  geo += `<polygon points="${pts(fp)}" class="mass"/>`;
  for (const r of h.rooms) {
    geo += `<polygon points="${pts(r.floor_polygon_mm.value)}" class="room${r.confidence === 'low' ? ' room--low' : ''}" data-room="${esc(r.id)}"><title>${esc(roomName(r).name)}</title></polygon>`;
  }
  if (o.windows) {
    for (const w of h.windows.filter((w) => w.level === 'ground')) {
      const g = windowWall(h, w);
      if (!g) continue;
      const a = w.from_mm.value, b = w.to_mm.value, t0 = g.dir > 0 ? g.at : g.at - g.t;
      const rect = g.axis === 'y' ? [a, t0, b - a, g.t] : [t0, a, g.t, b - a];
      geo += `<rect x="${rect[0]}" y="${rect[1]}" width="${rect[2]}" height="${rect[3]}" class="win"><title>${esc(w.id)}: ширина ≈ ${m1n(w.width_mm.value)} м ${tol(w.width_mm.tolerance_mm)}</title></rect>`;
      if (!o.compact) {
        const cx = g.axis === 'y' ? (a + b) / 2 : (g.dir > 0 ? g.at - 520 : g.at + 520);
        const cy = g.axis === 'y' ? Y(g.at) + (g.dir > 0 ? 470 : -230) : Y((a + b) / 2) + 100;
        text(cx, cy, 't-win', fs * 0.8, esc(w.id.replace('_small', '')));
      }
    }
  }
  if (o.doors) {
    for (const d of h.doors) {
      const g = doorWall(h, d);
      const a = d.from_mm.value, b = d.to_mm.value;
      const rect = g.axis === 'y' ? [a, g.at, b - a, g.t] : [g.at, a, g.t, b - a];
      const rough = (d.width_mm.tolerance_mm || 0) >= 300;
      const sv = o.stair && stairVariant(h), conflict = !!(sv?.overDoor && sv.overDoor.door.id === d.id && sv.overDoor.minTop - sv.overDoor.doorTop < 300);
      geo += `<rect x="${rect[0]}" y="${rect[1]}" width="${rect[2]}" height="${rect[3]}" class="door${rough ? ' door--rough' : ''}${conflict ? ' door--conflict' : ''}"><title>${esc(d.id)}: ширина ≈ ${m1n(d.width_mm.value)} м ${tol(d.width_mm.tolerance_mm)}</title></rect>`;
      if (!o.compact) {
        const cx = g.axis === 'y' ? (a + b) / 2 : g.at + g.t / 2;
        const cy = g.axis === 'y' ? Y(g.at + g.t / 2) + 100 : Y((a + b) / 2) + 100;
        text(cx, cy, `t-door${conflict ? ' t-conflict' : ''}`, fs * 0.7, esc(d.id.replace('_entrance', '')), g.axis === 'x' ? `transform="rotate(-90 ${cx} ${cy - 100})"` : '');
      }
    }
  }
  if (o.stair) {
    const st = stairVariant(h);
    if (st) {
      for (const step of st.steps) geo += `<polygon points="${pts(step.pts)}" class="stair"/>`;
      geo += `<path d="M ${st.x2 - st.w / 2} ${st.y1 + st.w - 120} V ${st.y1 + st.w / 2} H ${st.x1 + st.w / 2} V ${st.y2 - 250}" class="stair-arrow"/>`;
      const lx = (st.x1 + st.w + st.x2) / 2;
      text(lx, Y(st.y1 + st.w) + fs * 1.4, 't-stair halo', fs * 0.85, 'лестница:');
      text(lx, Y(st.y1 + st.w) + fs * 2.4, 't-stair halo', fs * 0.85, 'предварительно');
    }
  }
  // подписи комнат
  for (const r of h.rooms) {
    const poly = r.floor_polygon_mm.value;
    const xs = poly.map((p) => p[0]), ys = poly.map((p) => p[1]);
    let cx = (Math.min(...xs) + Math.max(...xs)) / 2, cy = (Math.min(...ys) + Math.max(...ys)) / 2;
    let width = Math.max(...xs) - Math.min(...xs);
    if (poly.length > 4) { // Г-образное: подпись в широкой части, центр из самого многоугольника
      const xr = Math.max(...xs), xl = Math.max(...xs.filter((x) => x < xr));
      cx = (xl + xr) / 2;
      cy = (Math.min(...ys) + Math.min(...poly.filter((p) => p[0] === Math.min(...xs)).map((p) => p[1]))) / 2;
      width = xr - xl;
    }
    const n = roomName(r);
    const small = width < 1500;
    const f = small ? fs * 0.72 : fs;
    const lh = f * 1.15;
    const lines = o.compact && width < 2500 ? [] : splitLines(n.name, Math.max(6, Math.floor((width - 200) / (f * 0.6))));
    const bx = r.bbox_size_mm;
    const items = o.electric ? h.electrical.filter((e) => e.room === r.id) : [];
    const count = items.reduce((a, e) => a + (e.count || 1), 0);
    const block = lines.length * lh + lh + (count ? lh * 1.3 : 0);
    let y = Y(cy) - block / 2 + f * 0.85;
    for (const l of lines) { text(cx, y, 't-room halo', f, esc(l)); y += lh; }
    text(cx, y, 't-size halo', f * 0.95, `≈ ${m1n(bx.x.value)} × ${m1n(bx.y.value)} м`);
    if (count) {
      y += lh * 0.4;
      txt += `<g class="el-mark"><title>${esc(items.map((e) => (ELECTRIC[e.kind] || e.kind) + (e.count > 1 ? ' ×' + e.count : '')).join(', '))} — места не измерены</title><rect x="${cx - f * 1.6}" y="${y}" width="${f * 3.2}" height="${f * 1.2}" rx="${f * 0.6}"/><text x="${cx}" y="${y + f * 0.88}" text-anchor="middle" font-size="${f * 0.8}">⚡ ${count}</text></g>`;
    }
  }
  // улица и размеры дома
  text(W / 2, D + (o.compact ? 560 : pad - 150), 't-street', fs, 'улица ▼');
  if (!o.compact) {
    const e = h.exterior;
    txt += dimH(0, W, -700, `${m05(e.total_width_x.value)} ${tol(e.total_width_x.tolerance_mm)}`, fs, true);
    txt += dimV(W + 700, 0, D, `${m05(e.total_depth_y.value)} ${tol(e.total_depth_y.tolerance_mm)}`, fs, false);
    txt += dimV(-700, 0, Y(e.porch_projection_y.value), `${m05(e.main_body_depth_y.value)} ${tol(e.main_body_depth_y.tolerance_mm)}`, fs, true);
    const porch = fp.filter((p) => p[1] === 0).map((p) => p[0]);
    txt += dimH(Math.min(...porch), Math.max(...porch), D + 700, `${m05(e.porch_width_x.value)} ${tol(e.porch_width_x.tolerance_mm)}`, fs, false);
  }
  return `<svg class="plan" viewBox="${vb.join(' ')}" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="План этажа по видео, размеры примерные. Улица снизу.">
    <defs>
      <pattern id="hatch-low" width="220" height="220" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="220" height="220" fill="var(--room-low)"/><line x1="0" y1="0" x2="0" y2="220" stroke="var(--room-low-line)" stroke-width="70"/></pattern>
      <pattern id="hatch-stair" width="160" height="160" patternUnits="userSpaceOnUse" patternTransform="rotate(-45)"><line x1="0" y1="0" x2="0" y2="160" stroke="var(--stair)" stroke-width="40"/></pattern>
    </defs>
    <g transform="translate(0 ${D}) scale(1 -1)">${geo}</g>${txt}</svg>`;
}

function splitLines(text, max) {
  const out = [];
  let line = '';
  for (const w of text.split(' ')) {
    if ((line + ' ' + w).trim().length > max && line) { out.push(line); line = w; } else line = (line + ' ' + w).trim();
  }
  if (line) out.push(line);
  return out;
}

function dimH(x1, x2, y, label, fs, above) {
  return `<g class="dim"><line x1="${x1}" y1="${y}" x2="${x2}" y2="${y}"/><line x1="${x1}" y1="${y - 150}" x2="${x1}" y2="${y + 150}"/><line x1="${x2}" y1="${y - 150}" x2="${x2}" y2="${y + 150}"/><text x="${(x1 + x2) / 2}" y="${y + (above ? -170 : 420)}" text-anchor="middle" font-size="${fs}">${esc(label)}</text></g>`;
}
function dimV(x, y1, y2, label, fs, left) {
  const cx = x + (left ? -230 : 360), cy = (y1 + y2) / 2;
  return `<g class="dim"><line x1="${x}" y1="${y1}" x2="${x}" y2="${y2}"/><line x1="${x - 150}" y1="${y1}" x2="${x + 150}" y2="${y1}"/><line x1="${x - 150}" y1="${y2}" x2="${x + 150}" y2="${y2}"/><text x="${cx}" y="${cy}" text-anchor="middle" font-size="${fs}" transform="rotate(${left ? -90 : 90} ${cx} ${cy})">${esc(label)}</text></g>`;
}

/**
 * План мансарды — вариант дизайна (design.json → attic), положенный на оценку крыши из measurements.json.
 * Подкосные стенки считаются по высоте ската, лестничный проём — из варианта лестницы.
 */
export function atticSVG(h, design) {
  const att = design?.attic;
  if (!att) return '';
  const fp = h.exterior.footprint_mm.value;
  const W = Math.max(...fp.map((p) => p[0])), D = Math.max(...fp.map((p) => p[1]));
  const H = h.exterior.wall_height_blocks.value, y0 = h.exterior.porch_projection_y.value;
  const rh = h.stair_to_attic.roof_headroom, ridge = rh.ridge_above_wall_top_mm.value, ridgeY = rh.ridge_y_mm.value;
  const F = h.stair_to_attic.design_check_not_a_design.assumed_floor_to_attic_mm.value;
  const k = (F + att.knee_wall_height_mm - H) / ridge;
  const yKf = Math.round(y0 + k * (ridgeY - y0)), yKb = Math.round(D - k * (D - ridgeY));
  const KY = (v, back) => v === 'knee' ? (back ? yKb : yKf) : v;
  const Y = (y) => D - y;
  const pad = 1500, fs = 300;
  const tExt = h.wall_thickness.exterior_mm.value;
  let g = '', t = '';
  const text = (x, y, cls, size, body) => { t += `<text x="${x}" y="${y}" text-anchor="middle" class="${cls}" font-size="${size}">${body}</text>`; };
  // контур этажа под крышей и низкие зоны под скатами
  g += `<rect x="0" y="${y0}" width="${W}" height="${D - y0}" class="mass"/>`;
  g += `<rect x="${tExt}" y="${y0}" width="${W - 2 * tExt}" height="${yKf - y0}" class="low-zone"/>`;
  g += `<rect x="${tExt}" y="${yKb}" width="${W - 2 * tExt}" height="${D - yKb}" class="low-zone"/>`;
  const palette = ['#e8f3ec', '#e6eef8', '#f6efe2', '#efe9fb', '#fbe9ec', '#eaf6f6'];
  att.rooms.forEach((r, i) => {
    for (const [x1, x2, a, b] of r.rects_mm) {
      const ya = KY(a, 0), yb = KY(b, 1);
      g += `<rect x="${x1}" y="${ya}" width="${x2 - x1}" height="${yb - ya}" fill="${palette[i % palette.length]}" class="attic-room"><title>${esc(r.name)}</title></rect>`;
    }
    const [x1, x2, a, b] = r.rects_mm[0];
    const ya = KY(a, 0), yb = KY(b, 1);
    const area = r.rects_mm.reduce((s, [p, q, c, d]) => s + (q - p) * (KY(d, 1) - KY(c, 0)), 0) / 1e6;
    text((x1 + x2) / 2, Y((ya + yb) / 2) - 40, 't-room', fs * 1.05, esc(r.name));
    text((x1 + x2) / 2, Y((ya + yb) / 2) + fs, 't-size', fs * 0.85, `≈ ${Math.round(area)} м²`);
  });
  // перегородки с дверями
  for (const p of att.partitions) {
    const from = KY(p.from, 0), to = KY(p.to, 1), doors = (p.doors || []).slice().sort((a, b) => a[0] - b[0]);
    let s = Math.min(from, to);
    const e = Math.max(from, to);
    const seg = (a, b) => {
      if (b <= a) return;
      g += p.axis === 'x' ? `<rect x="${p.at - 50}" y="${a}" width="100" height="${b - a}" class="part"/>` : `<rect x="${a}" y="${p.at - 50}" width="${b - a}" height="100" class="part"/>`;
    };
    for (const [da, db] of doors) { seg(s, da); s = db; }
    seg(s, e);
  }
  // подкосные стенки и конёк
  for (const yk of [yKf, yKb]) g += `<line x1="${tExt}" y1="${yk}" x2="${W - tExt}" y2="${yk}" class="knee"/>`;
  g += `<line x1="0" y1="${ridgeY}" x2="${W}" y2="${ridgeY}" class="ridge"/>`;
  // фронтоны и окна в них
  g += `<rect x="0" y="${y0}" width="${tExt}" height="${D - y0}" class="wall-ext"/><rect x="${W - tExt}" y="${y0}" width="${tExt}" height="${D - y0}" class="wall-ext"/>`;
  for (const w of h.windows.filter((x) => x.level === 'attic')) {
    const x = w.wall === 'gable_far' ? W - tExt : 0;
    g += `<rect x="${x}" y="${w.from_mm.value}" width="${tExt}" height="${w.to_mm.value - w.from_mm.value}" class="win"><title>${esc(w.id)}</title></rect>`;
  }
  // окна в скате (вариант)
  for (const rw of att.roof_windows || []) {
    const front = rw.slope === 'front';
    const a = front ? y0 + (ridgeY - y0) * 0.45 : D - (D - ridgeY) * 0.68, b = front ? y0 + (ridgeY - y0) * 0.68 : D - (D - ridgeY) * 0.45;
    g += `<rect x="${rw.x[0]}" y="${a}" width="${rw.x[1] - rw.x[0]}" height="${b - a}" class="roof-win"><title>Окно в скате — вариант</title></rect>`;
  }
  // проём лестницы
  const st = stairVariant(h);
  if (st) {
    g += `<polygon points="${st.opening.map((p) => p.join(',')).join(' ')}" class="stair"/>`;
    const ox = (Math.min(...st.opening.map((p) => p[0])) + Math.max(...st.opening.map((p) => p[0]))) / 2;
    text(ox, Y(st.y1 + st.w / 2) + 100, 't-stair halo', fs * 0.8, 'лестница');
  }
  text(W / 2, Y(ridgeY) - 90, 't-note', fs * 0.7, 'конёк крыши');
  text(W / 2, Y((y0 + yKf) / 2) + 100, 't-note', fs * 0.7, `под скатом ниже ${m1n(att.knee_wall_height_mm)} м`);
  text(W / 2, Y((yKb + D) / 2) + 100, 't-note', fs * 0.7, `под скатом ниже ${m1n(att.knee_wall_height_mm)} м`);
  text(W / 2, Y(y0) + 700, 't-street', fs, 'улица ▼');
  return `<svg class="plan" viewBox="${-pad} ${-pad} ${W + pad * 2} ${D - y0 + pad * 2}" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="План мансарды — вариант дизайна">
    <defs><pattern id="hatch-low" width="220" height="220" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><line x1="0" y1="0" x2="0" y2="220" stroke="#cfc6b6" stroke-width="40"/></pattern></defs>
    <style>.low-zone{fill:url(#hatch-low)} .attic-room{stroke:none} .part{fill:#5b6168} .knee{stroke:#7a6a55;stroke-width:60;stroke-dasharray:240 160} .ridge{stroke:#7d3b2f;stroke-width:40;stroke-dasharray:400 200} .wall-ext{fill:#2f3439} .roof-win{fill:#9fd0ec;stroke:#3b6d8c;stroke-width:40} .t-note{fill:#7a6a55}</style>
    <g transform="translate(0 ${D}) scale(1 -1)">${g}</g>${t}</svg>`;
}

/**
 * План участка: дом (по measurements.json, привязка из design.json) и пристройка вдоль правого забора (схема владельца).
 * Координаты в метрах участка; улица снизу.
 */
export function plotSVG(h, design, outbuildingLayout) {
  const plot = design?.plot, L = outbuildingLayout(design);
  if (!plot || !L) return '';
  const W = plot.width_m, D = plot.depth_m, pad = 3.2;
  const Y = (d) => D - d; // экран: улица снизу
  const f = (n) => n.toLocaleString('ru-RU', { maximumFractionDigits: 1 });
  let g = '', t = '';
  const text = (x, y, cls, size, body) => { t += `<text x="${x}" y="${y}" text-anchor="middle" class="${cls}" font-size="${size}">${body}</text>`; };
  g += `<rect x="0" y="0" width="${W}" height="${D}" class="plot"/>`;
  // дом: контур из measurements.json, привязка — design.site_anchor_m
  const ax = design.site_anchor_m.x, ad = -design.site_anchor_m.z;
  const fp = h.exterior.footprint_mm.value.map(([x, y]) => [ax + x / 1000, Y(ad + y / 1000)]);
  g += `<polygon points="${fp.map((p) => p.join(',')).join(' ')}" class="house"/>`;
  const hx = fp.map((p) => p[0]), hy = fp.map((p) => p[1]);
  text((Math.min(...hx) + Math.max(...hx)) / 2, (Math.min(...hy) + Math.max(...hy)) / 2, 't-house', 1.1, 'Дом');
  // пристройка
  for (const r of L.rooms) {
    g += `<rect x="${r.x1}" y="${Y(r.d2)}" width="${r.x2 - r.x1}" height="${r.length_m}" class="ob-room${r.open_to_yard ? ' ob-open' : ''}"><title>${esc(r.name)}: ${f(r.x2 - r.x1)} × ${f(r.length_m)} м</title></rect>`;
    // в гараже подпись — в свободной полосе у двора, чтобы не закрывать машину
    const lx2 = r.id === 'garage' && L.car ? L.car.x1 : r.x2, lx = (r.x1 + lx2) / 2;
    const fs = Math.min(r.length_m < 2.6 ? 0.55 : 0.7, (lx2 - r.x1 - (lx2 < r.x2 ? 0.3 : 0)) * 1.7 / r.name.length);
    text(lx, Y((r.d1 + r.d2) / 2) - 0.05, 't-ob', fs, esc(r.name));
    text(lx, Y((r.d1 + r.d2) / 2) + fs * 1.05, 't-ob-size', fs * 0.85, `${f(r.x2 - r.x1)}×${f(r.length_m)} м`);
  }
  if (L.car) {
    const c = L.car, cx = (c.x1 + c.x2) / 2;
    g += `<rect x="${c.x1}" y="${Y(c.d2)}" width="${c.width_m}" height="${c.length_m}" rx="0.35" class="car"><title>${esc(c.name)}, ${esc(c.color)}: ≈ ${f(c.length_m)} × ${f(c.width_m)} м</title></rect>`;
    g += `<rect x="${c.x1 + 0.15}" y="${Y(c.d2 - 1.2)}" width="${c.width_m - 0.3}" height="2.1" rx="0.2" class="car-cab"/>`;
    text(cx, Y((c.d1 + c.d2) / 2) + 0.15, 't-car', 0.42, `${f(c.length_m)}×${f(c.width_m)}`);
    text(cx, Y(c.d2) - 0.25, 't-ob-size', 0.4, `до стены ${f(c.front_m)} м`);
  }
  const last = L.rooms.at(-1), ob = L.ob;
  // тропинка: вдоль пристройки со стороны двора, у туалета и за ним — к калитке
  const yardEnd = L.rooms.find((r) => r.id === 'sauna')?.d2 ?? last.d1, pw = ob.passage?.path_m ?? 1;
  g += `<rect x="${L.x1 - pw}" y="${Y(yardEnd + 0.5)}" width="${pw}" height="${yardEnd - 0.4}" class="ob-path"/>`;
  g += `<rect x="${plot.main_gate_x_m[1] - 0.6}" y="${Y(1.6)}" width="${L.x1 - plot.main_gate_x_m[1] + 0.6}" height="${pw * 0.8}" class="ob-path"/>`;
  g += `<rect x="${L.x1}" y="0" width="${last.x1 - L.x1}" height="${D - yardEnd}" class="ob-path"/>`;
  g += `<rect x="${last.x1}" y="0" width="${L.x2 - last.x1}" height="${D - last.d2}" class="ob-path"/>`;
  text((L.x1 + last.x1) / 2, Y((yardEnd + D) / 2), 't-ob-size', 0.5, 'проход');
  text(L.x1 - pw / 2, Y(20), 't-ob-size', 0.45, 'тропинка');
  // двери: проём и дуга открывания (куда открывается створка)
  const arc = (hx, hd, closed, swing, w) => {
    const P = (v) => [hx + v[0] * w, Y(hd + v[1] * w)];
    const a = P(closed), b = P(swing);
    g += `<path d="M ${hx} ${Y(hd)} L ${b[0]} ${b[1]} A ${w} ${w} 0 0 ${(closed[0] * swing[1] - closed[1] * swing[0]) > 0 ? 0 : 1} ${a[0]} ${a[1]}" class="door-arc"/>`;
  };
  for (const o of ob.doors || []) {
    if (o.wall === 'cross') {
      const w = o.x[1] - o.x[0], hx = o.hinge === 'yard' ? L.x1 + o.x[0] : L.x1 + o.x[1];
      g += `<rect x="${L.x1 + o.x[0]}" y="${Y(o.at) - 0.08}" width="${w}" height="0.16" class="door-gap"/>`;
      arc(hx, o.at, [o.hinge === 'yard' ? 1 : -1, 0], [0, o.swing === 'street' ? -1 : 1], w);
    } else {
      const w = o.d[1] - o.d[0], xw = o.wall === 'yard' ? L.x1 : last.x1;
      g += `<rect x="${xw - 0.08}" y="${Y(o.d[1])}" width="0.16" height="${w}" class="door-gap"/>`;
      arc(xw, o.hinge === 'back' ? o.d[1] : o.d[0], [0, o.hinge === 'back' ? -1 : 1], [o.swing === 'in' ? 1 : -1, 0], w);
    }
  }
  for (const wn of ob.windows || []) g += `<rect x="${L.x1 - 0.1}" y="${Y(wn.d[1])}" width="0.2" height="${wn.d[1] - wn.d[0]}" class="win-ob"/>`;
  const cg = ob.passage?.gate;
  if (cg) {
    g += `<rect x="${L.x2 - 0.1}" y="${Y(cg.d[1])}" width="0.2" height="${cg.d[1] - cg.d[0]}" class="door-gap"/>`;
    arc(L.x2, cg.d[0], [0, 1], [1, 0], cg.d[1] - cg.d[0]);
  }
  // ворота и калитка
  const g0 = L.x1 + L.rooms[0].gate_margin_m, g1 = L.x2 - L.rooms[0].gate_margin_m;
  g += `<line x1="${g0}" y1="${D}" x2="${g1}" y2="${D}" class="gate"/><line x1="${plot.main_gate_x_m[0]}" y1="${D}" x2="${plot.main_gate_x_m[1]}" y2="${D}" class="gate"/>`;
  text((g0 + g1) / 2, D + 1.1, 't-gate', 0.65, `ворота гаража ${f(g1 - g0)} м`);
  text((plot.main_gate_x_m[0] + plot.main_gate_x_m[1]) / 2, D + 1.1, 't-gate', 0.65, `ворота во двор`);
  if (cg) text(L.x2 + 1.4, Y((cg.d[0] + cg.d[1]) / 2) + 0.2, 't-gate', 0.55, 'калитка');
  // размеры участка и полосы
  text(W / 2, D + 2.4, 't-dim', 0.8, `≈ ${f(W)} м`);
  t += `<text x="${-1.2}" y="${D / 2}" text-anchor="middle" class="t-dim" font-size="0.8" transform="rotate(-90 ${-1.2} ${D / 2})">≈ ${f(D)} м</text>`;
  text((L.x1 + L.x2) / 2, -1.3, 't-dim', 0.7, `пристройка ${f(L.x2 - L.x1)} м`);
  const gapHouse = L.x1 - Math.max(...hx);
  text((Math.max(...hx) + L.x1) / 2, Y(ad + 9), 't-dim', 0.6, `двор ≈ ${f(gapHouse)} м`);
  text(W / 2, D + 3.2, 't-street', 0.75, 'улица ▼');
  return `<svg class="plan plot-plan" viewBox="${-pad} ${-pad} ${W + pad * 2} ${D + pad * 2 + 1.2}" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="План участка: дом и пристройка">
    <style>.plot{fill:#eef4e8;stroke:#7a6a55;stroke-width:.12;stroke-dasharray:.5 .3} .house{fill:#f6f2ea;stroke:#2f3439;stroke-width:.25} .ob-room{fill:#f3ead9;stroke:#5b6168;stroke-width:.12} .ob-open{fill:#fde3c8;stroke-dasharray:.4 .25} .ob-path{fill:#d6cdbd;stroke:#8f8573;stroke-width:.05;stroke-dasharray:.25 .2} .gate{stroke:#22a845;stroke-width:.35} .door-gap{fill:#fff} .door-arc{fill:rgba(138,90,51,.12);stroke:#8a5a33;stroke-width:.06} .win-ob{fill:#7cc4e8} .car{fill:#9aa0a6;stroke:#5b6168;stroke-width:.06} .car-cab{fill:#5b6570} .t-car{fill:#fff;font-weight:600} .t-house{font-weight:600} .t-ob{font-weight:600;fill:#3c2f22} .t-ob-size{fill:#7a6a55} .t-gate{fill:#178a36} .t-dim{fill:#5d6d78} .t-street{fill:#5d6d78;letter-spacing:.1em}</style>
    ${g}${t}</svg>`;
}
