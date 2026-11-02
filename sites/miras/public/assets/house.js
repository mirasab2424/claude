// Данные дома: всё читается из data/measurements.json, здесь только оформление чисел и подписей.
// Правила округления: комнаты и проёмы — до 0,1 м, дом — до 0,5 м, всегда с «≈».

export async function loadHouse() {
  const r = await fetch('data/measurements.json', { cache: 'no-cache' });
  if (!r.ok) throw new Error('measurements.json: ' + r.status);
  return r.json();
}

const nf = (v, digits) => v.toLocaleString('ru-RU', { minimumFractionDigits: digits, maximumFractionDigits: digits });
const round = (mm, stepM) => Math.round(mm / 1000 / stepM) * stepM;

/** Размер комнаты или проёма: 2850 → «≈ 2,9 м». */
export const m1 = (mm) => mm == null ? '—' : '≈ ' + nf(round(mm, 0.1), 1) + ' м';
export const m1n = (mm) => nf(round(mm, 0.1), 1);
/** Размер дома: 12372 → «≈ 12,5 м». */
export const m05 = (mm) => '≈ ' + nf(round(mm, 0.5), 1) + ' м';
/** Погрешность: 70 → «±7 см», 400 → «±0,4 м». */
export const tol = (mm) => mm == null ? '' : (mm < 100 ? '±' + Math.round(mm / 10) + ' см' : '±' + nf(Math.round(mm / 100) / 10, 1) + ' м');
/** Площадь: выведена из приблизительных размеров, поэтому до целых м². */
export const area = (m2) => '≈ ' + Math.round(m2) + ' м²';

export const STATUS = {
  measured: { label: 'замер рулеткой', cls: 'st-measured' },
  estimated: { label: 'оценка по видео', cls: 'st-estimated' },
  assumed: { label: 'предположение', cls: 'st-assumed' },
  observed_position_not_measured: { label: 'видно, место не измерено', cls: 'st-observed' },
};
export const statusBadge = (s) => {
  const st = STATUS[s] || { label: s, cls: '' };
  return `<span class="badge ${st.cls}">${st.label}</span>`;
};
export const CONFIDENCE = { high: 'высокая', medium: 'средняя', low: 'низкая' };

/** Значение с погрешностью и статусом: { value, status, tolerance_mm }. */
export function val(v, fmt = m1) {
  if (!v) return '<span class="none">нет данных</span>';
  return `<span class="${(STATUS[v.status] || {}).cls || ''}">${fmt(v.value)}</span> <small class="tol">${tol(v.tolerance_mm)}</small>`;
}

/** Описательное имя без пометки в скобках: «Малая комната у улицы (мешки)» → имя и «мешки». */
export function roomName(room) {
  const m = /^(.*?)\s*\((.+)\)\s*$/.exec(room.name_ru || room.id);
  return m ? { name: m[1], hint: m[2] } : { name: room.name_ru || room.id, hint: null };
}

export const WALLS = {
  street_porch: 'фасад выступа (к улице)',
  street_main_entry_side: 'фасад к улице',
  back: 'задняя стена',
  entry_side_wall: 'боковая стена со стороны входа',
  far_wall: 'дальняя боковая стена',
  gable_far: 'дальний фронтон',
  gable_entry_side: 'фронтон со стороны входа',
  street: 'у улицы', far: 'дальняя стена', entry_side: 'со стороны входа',
};
export const wallName = (w) => w == null ? null : (WALLS[w] || (/^[xy]=/.test(w) ? 'перегородка' : w));

export const ELECTRIC = {
  socket: 'розетка', socket_double: 'двойная розетка', switch: 'выключатель', switch_block_2: 'блок из 2 выключателей',
  light_pendant: 'подвес / лампа', light_cable: 'кабель под свет', junction_box: 'распаечная коробка',
  junction_box_rough: 'коробка (черновая)', electrical_panel: 'электрощиток', floor_outlet_or_hole: 'вывод / отверстие у пола',
  cable_outlet_low: 'вывод кабеля у пола',
};
export const PLUMBING = {
  red_pipe_loop: 'красная труба (петля)', red_blue_pipes_on_floor: 'красная и синяя трубы на полу',
  red_pipe_on_floor: 'красная труба по полу', red_pipe_riser: 'красный стояк', stack_pipe_vertical: 'вертикальная белая труба',
  water_outlets_sewer: 'выводы воды и канализация',
};

export const roomsById = (h) => Object.fromEntries(h.rooms.map((r) => [r.id, r]));
export const roomLabel = (h, id) => id === 'outside' ? 'улица' : id === 'attic' ? 'мансарда' : (roomsById(h)[id] ? roomName(roomsById(h)[id]).name : id);

/** Линия стены для окна: координата и толщина (внутрь дома). */
export function windowWall(h, w) {
  const fp = h.exterior.footprint_mm.value;
  const xs = fp.map((p) => p[0]), ys = fp.map((p) => p[1]);
  const t = h.wall_thickness.exterior_mm.value;
  const porchY = h.exterior.porch_projection_y.value;
  switch (w.wall) {
    case 'street_porch': return { axis: 'y', at: Math.min(...ys), dir: 1, t };
    case 'street_main_entry_side': return { axis: 'y', at: porchY, dir: 1, t };
    case 'back': return { axis: 'y', at: Math.max(...ys), dir: -1, t };
    case 'entry_side_wall': case 'gable_entry_side': return { axis: 'x', at: Math.min(...xs), dir: 1, t };
    case 'far_wall': case 'gable_far': return { axis: 'x', at: Math.max(...xs), dir: -1, t };
  }
  return null;
}

/** Линия проёма двери: «x=7395» — от линии до ближайшей комнаты (толщина стены). */
export function doorWall(h, d) {
  const [axis, n] = d.wall.split('=');
  const at = +n;
  let next = null;
  for (const r of h.rooms) {
    for (const p of r.floor_polygon_mm.value) {
      const c = axis === 'x' ? p[0] : p[1];
      if (c > at + 1 && c - at < 700 && (next === null || c < next)) next = c;
    }
  }
  return { axis, at, dir: 1, t: next ? next - at : h.wall_thickness.interior_mm.value };
}

/**
 * Геометрия варианта лестницы по эскизу владельца — только из данных: ответвление холла, принятая ширина марша,
 * число подъёмов и забежных ступеней. Ступени: { pts: [[x,y],…], top: высота верха, kind }.
 */
export function stairVariant(h) {
  const s = h.stair_to_attic;
  const hall = h.rooms.find((r) => r.id === 'hall');
  if (!s || !hall) return null;
  const pts = hall.floor_polygon_mm.value;
  const x1 = Math.min(...pts.map((p) => p[0]));                    // упор в стену санузла
  const branchY = pts.filter((p) => p[0] === x1).map((p) => p[1]);
  const y1 = Math.min(...branchY), y2 = Math.max(...branchY);       // стена комнаты у фасада … конец ответвления
  const x2 = x1 + s.space_available_mm.branch_length_x.value;       // длина ответвления
  const c = s.design_check_not_a_design;
  const w = c.assumed_width_mm;
  const nW = s.owner_sketch?.winders_per_turn ?? 0;
  const risers = c.assumed_risers, rise = c.assumed_floor_to_attic_mm.value / risers;
  const treads = risers - 1;                                        // верхняя «ступень» — пол мансарды
  const L1 = (x2 - w) - (x1 + w), L2 = y2 - (y1 + w);               // прямые участки
  const straight = treads - 2 * nW;
  const n1 = Math.max(1, Math.round(straight * L1 / (L1 + L2))), n2 = straight - n1;
  const steps = [];
  const add = (p, kind) => steps.push({ pts: p, kind, top: (steps.length + 1) * rise });
  // веер забежных ступеней вокруг внутреннего угла (cx, cy) в квадрате w×w: лучи от a0 до a1
  const fan = (cx, cy, a0, a1, box) => {
    const hit = (a) => {
      const dx = Math.cos(a), dy = Math.sin(a);
      let t = Infinity;
      for (const [bx, by] of [[box[0], null], [box[1], null], [null, box[2]], [null, box[3]]]) {
        const k = bx != null ? (dx ? (bx - cx) / dx : Infinity) : (dy ? (by - cy) / dy : Infinity);
        if (k > 1e-6 && k < t) t = k;
      }
      return [cx + dx * t, cy + dy * t];
    };
    const corners = [[box[0], box[2]], [box[1], box[2]], [box[1], box[3]], [box[0], box[3]]];
    for (let i = 0; i < nW; i++) {
      const aa = a0 + (a1 - a0) * i / nW, ab = a0 + (a1 - a0) * (i + 1) / nW;
      const p = [[cx, cy], hit(aa)];
      for (const q of corners) {                                    // наружный угол, если он между лучами
        const a = Math.atan2(q[1] - cy, q[0] - cx);
        const norm = (v) => ((v - Math.min(aa, ab)) % (2 * Math.PI) + 2 * Math.PI) % (2 * Math.PI);
        if (Math.hypot(q[0] - cx, q[1] - cy) > 1 && norm(a) > 1e-6 && norm(a) < Math.abs(ab - aa) - 1e-6) p.push(q);
      }
      p.push(hit(ab));
      add(p, 'winder');
    }
  };
  // 1) старт: входим со стороны задних комнат (−y), поворот к санузлу (−x)
  fan(x2 - w, y1 + w, 0, -Math.PI / 2, [x2 - w, x2, y1, y1 + w]);
  // 2) прямой марш вдоль стены комнаты у фасада
  for (let i = 0; i < n1; i++) {
    const a = (x2 - w) - L1 * i / n1, b = (x2 - w) - L1 * (i + 1) / n1;
    add([[a, y1], [b, y1], [b, y1 + w], [a, y1 + w]], 'tread');
  }
  // 3) угол у санузла: поворот к задним комнатам (+y)
  fan(x1 + w, y1 + w, -Math.PI / 2, -Math.PI, [x1, x1 + w, y1, y1 + w]);
  // 4) второй марш вдоль стены санузла
  for (let i = 0; i < n2; i++) {
    const a = (y1 + w) + L2 * i / n2, b = (y1 + w) + L2 * (i + 1) / n2;
    add([[x1, a], [x1 + w, a], [x1 + w, b], [x1, b]], 'tread');
  }
  // проём в перекрытии: над ступенями, где до верха кладки меньше 2 м (2 м — принятый минимум над ступенью)
  const ceil = h.exterior.wall_height_blocks.value;
  const xe = Math.max(x1 + w, ...steps.filter((st) => ceil - st.top < 2000).flatMap((st) => st.pts.map((p) => p[0])));
  // дверь санузла: какие ступени над ней (по стене санузла x = x1)
  const d7 = h.doors.find((d) => d.id === 'D7');
  let overDoor = null;
  if (d7) {
    const a = d7.from_mm.value, b = d7.to_mm.value;
    const over = steps.filter((st) => {                              // участок ступени, лежащий на стене санузла
      const ys = st.pts.filter((p) => Math.abs(p[0] - x1) < 1).map((p) => p[1]);
      return ys.length > 1 && Math.min(...ys) < b && Math.max(...ys) > a;
    });
    overDoor = { door: d7, minTop: Math.min(...over.map((st) => st.top)), doorTop: d7.height_mm.value };
  }
  return {
    steps, x1, x2, y1, y2, w, rise, headroomMin: 2000, n1, n2, nW, going1: L1 / n1, going2: L2 / n2,
    opening: [[x1, y1], [xe, y1], [xe, y1 + w], [x1 + w, y1 + w], [x1 + w, y2], [x1, y2]],
    overDoor,
  };
}
