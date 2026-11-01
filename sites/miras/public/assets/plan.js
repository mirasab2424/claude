// План этажа в SVG из measurements.json. Улица снизу, сторона входа слева — как видно, если стоять на улице лицом к дому.
// Геометрия рисуется в координатах данных (y — от улицы вглубь) и переворачивается целиком; подписи ставятся уже в экранных координатах.
import { m05, m1n, tol, roomName, windowWall, doorWall, stairVariant, ELECTRIC } from './house.js';

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
      const conflict = o.stair && d.id === 'D7';
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
      for (const part of [st.run1, st.landing, st.run2]) geo += `<polygon points="${pts(part)}" class="stair"/>`;
      const c = h.stair_to_attic.design_check_not_a_design;
      for (let i = 1; i < c.treads_in_first_run; i++) {
        const x = st.x2 - i * c.assumed_tread_mm;
        if (x > st.x1 + st.w) geo += `<line x1="${x}" y1="${st.y1}" x2="${x}" y2="${st.y1 + st.w}" class="stair-step"/>`;
      }
      geo += `<path d="M ${st.x2 - 200} ${st.y1 + st.w / 2} H ${st.x1 + st.w / 2} V ${st.y2 - 250}" class="stair-arrow"/>`;
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
