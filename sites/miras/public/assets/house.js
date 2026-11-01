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

/** Геометрия варианта лестницы — только из данных: ответвление холла и принятая ширина марша. */
export function stairVariant(h) {
  const s = h.stair_to_attic;
  const hall = h.rooms.find((r) => r.id === 'hall');
  const bath = h.rooms.find((r) => r.id === 'bathroom');
  if (!s || !hall) return null;
  const pts = hall.floor_polygon_mm.value;
  const x1 = Math.min(...pts.map((p) => p[0]));                    // упор в стену санузла
  const branchY = pts.filter((p) => p[0] === x1).map((p) => p[1]);
  const y1 = Math.min(...branchY), y2 = Math.max(...branchY);
  const x2 = x1 + s.space_available_mm.branch_length_x.value;       // длина ответвления
  const w = s.design_check_not_a_design.assumed_width_mm;
  return {
    run1: [[x2, y1], [x1 + w, y1], [x1 + w, y1 + w], [x2, y1 + w]],
    landing: [[x1, y1], [x1 + w, y1], [x1 + w, y1 + w], [x1, y1 + w]],
    run2: [[x1, y1 + w], [x1 + w, y1 + w], [x1 + w, y2], [x1, y2]],
    x1, x2, y1, y2, w, bathroom: bath,
  };
}
