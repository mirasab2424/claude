#!/usr/bin/env python3
"""Собирает данные карты из сырых выгрузок OpenStreetMap.

Вход:
  raw/*.json                      — выгрузки Overpass (tools/fetch_osm.sh): здания, дороги, вода, парки
  private/personal.json           — личные места (не в git); если файла нет, личных мест не будет
  ../miras/public/data/*.json     — размеры дома на участке (проект «Наш дом»)
Выход:
  public/data/city.json           — город: здания, дороги, вода, парки, контуры залов и ориентиров
  public/data/places.private.json — личные места с контурами (не в git)

Координаты — метры от точки LAT0/LON0: x на восток, y на север (в Three.js: X = x, Z = −y).
Запуск: python3 tools/build_data.py  (из папки sites/astana)
"""
import glob
import json
import math
import os
import zlib

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
RAW = os.path.join(ROOT, 'raw')
PRIVATE = os.path.join(ROOT, 'private', 'personal.json')
OUT = os.path.join(ROOT, 'public', 'data')
MIRAS = os.path.join(ROOT, '..', 'miras', 'public', 'data')

LAT0, LON0 = 51.1285, 71.4400
KX = 111320 * math.cos(math.radians(LAT0))
KY = 111200

# Залы и ориентиры: id объектов OpenStreetMap
VENUES = {'opera': ('relation', 11873912), 'ballet': ('way', 499328449), 'concert': ('way', 1460537088),
          'pyramid': ('way', 166197368), 'shabyt': ('relation', 6798127)}
LANDMARKS = {'baiterek': ('way', 230401645), 'khan': ('way', 460703779), 'akorda': ('way', 166198046),
             'hazret': ('way', 240860325)}
FIXED_HEIGHT = {('way', 166198046): 32, ('way', 240860325): 22}  # Акорда и Хазрет Султан: корпус без купола


def P(p):
    return ((p['lon'] - LON0) * KX, (p['lat'] - LAT0) * KY)


def area(r):
    return sum(r[i][0] * r[(i + 1) % len(r)][1] - r[(i + 1) % len(r)][0] * r[i][1] for i in range(len(r))) / 2


def dp(pts, tol):
    """Упрощение линии (Дуглас — Пекер)."""
    if len(pts) < 3:
        return pts
    a, b = pts[0], pts[-1]
    dx, dy = b[0] - a[0], b[1] - a[1]
    L = math.hypot(dx, dy) or 1e-9
    m, mi = 0, 0
    for i in range(1, len(pts) - 1):
        d = abs((pts[i][0] - a[0]) * dy - (pts[i][1] - a[1]) * dx) / L
        if d > m:
            m, mi = d, i
    if m <= tol:
        return [a, b]
    return dp(pts[:mi + 1], tol)[:-1] + dp(pts[mi:], tol)


def ring(pts, tol):
    if pts[0] == pts[-1]:
        pts = pts[:-1]
    if len(pts) < 3:
        return None
    j = max(range(len(pts)), key=lambda i: (pts[i][0] - pts[0][0]) ** 2 + (pts[i][1] - pts[0][1]) ** 2)
    r = dp(pts[:j + 1], tol)[:-1] + dp(pts[j:] + [pts[0]], tol)[:-1]
    return r if len(r) >= 3 else None


def enc(pts, s=2):
    """Дельта-кодирование: [x0, y0, dx1, dy1, …] в единицах 1/s метра."""
    q = [(round(x * s), round(y * s)) for x, y in pts]
    out = [q[0][0], q[0][1]]
    for i in range(1, len(q)):
        out += [q[i][0] - q[i - 1][0], q[i][1] - q[i - 1][1]]
    return out


def stitch(ways):
    ways = [list(w) for w in ways if len(w) > 1]
    rings = []
    while ways:
        cur = ways.pop(0)
        while cur[0] != cur[-1]:
            for i, w in enumerate(ways):
                if w[0] == cur[-1]:
                    cur += w[1:]; ways.pop(i); break
                if w[-1] == cur[-1]:
                    cur += w[-2::-1]; ways.pop(i); break
            else:
                break
        if cur[0] == cur[-1] and len(cur) > 3:
            rings.append(cur)
    return rings


def outer_rings(e):
    if e['type'] == 'way':
        g = [P(p) for p in e.get('geometry', [])]
        return [g] if len(g) > 3 else []
    ms = [m for m in e.get('members', []) if m['type'] == 'way' and m.get('geometry') and m.get('role') in ('outer', 'outline', '')]
    return stitch([[P(p) for p in m['geometry']] for m in ms])


def obb(r):
    """Повёрнутый описанный прямоугольник: центр, угол длинной оси, длина, ширина."""
    best = None
    for i in range(len(r)):
        a, b = r[i], r[(i + 1) % len(r)]
        th = math.atan2(b[1] - a[1], b[0] - a[0]); c, s = math.cos(th), math.sin(th)
        u = [p[0] * c + p[1] * s for p in r]; v = [-p[0] * s + p[1] * c for p in r]
        A = (max(u) - min(u)) * (max(v) - min(v))
        if best is None or A < best[0]:
            cu, cv = (max(u) + min(u)) / 2, (max(v) + min(v)) / 2
            L, W = max(u) - min(u), max(v) - min(v)
            if W > L:
                th += math.pi / 2; L, W = W, L
            best = (A, cu * c - cv * s, cu * s + cv * c, th, L, W)
    return dict(c=[round(best[1], 1), round(best[2], 1)], ang=round(best[3], 4), L=round(best[4], 1), W=round(best[5], 1))


def height(e, A):
    """Высота: из тега height, иначе по этажам, иначе примерно по типу здания (стабильно для одного id)."""
    t = e.get('tags', {})
    try:
        return float(t['height'].replace('m', '').strip())
    except (KeyError, ValueError):
        pass
    try:
        return max(3, float(t['building:levels']) * 3.2 + 1)
    except (KeyError, ValueError):
        pass
    b = t.get('building', 'yes'); j = (zlib.crc32(str(e['id']).encode()) % 100) / 100
    if b in ('house', 'detached', 'residential', 'semidetached_house'):
        return 4 + 2 * j
    if b in ('garage', 'garages', 'shed', 'hut', 'roof', 'service', 'kiosk'):
        return 3
    if b == 'apartments':
        return 18 + 14 * j
    if b in ('industrial', 'warehouse', 'retail', 'commercial'):
        return 7 + 4 * j
    if b == 'construction':
        return 6 + 20 * j
    return (4 + 2 * j) if A < 300 else (8 + 8 * j if A < 2500 else 12 + 6 * j)


def shape(e, tol=0.3):
    r = max(outer_rings(e), key=lambda r: abs(area(r)))
    r = ring(r, tol)
    if area(r) < 0:
        r = r[::-1]
    d = obb(r)
    d['ring'] = [[round(x, 1), round(y, 1)] for x, y in r]
    return d


def main():
    seen = {}
    for f in sorted(glob.glob(os.path.join(RAW, 'b_*.json')) + glob.glob(os.path.join(RAW, 'n_*.json'))):
        for e in json.load(open(f))['elements']:
            seen[(e['type'], e['id'])] = e

    personal = json.load(open(PRIVATE)) if os.path.exists(PRIVATE) else {'places': []}
    private_ids = {tuple(p['osm']) for p in personal['places'] if 'osm' in p}

    # не рисовать в общей массе то, что строится отдельной моделью
    # личные здания остаются в общей массе (иначе по «дыре» видно, где они); их номер в списке уходит
    # в places.private.json, и страница прячет их, когда рисует цветную модель
    skip = {('way', 917478479)} | set(VENUES.values()) | {LANDMARKS['baiterek'], LANDMARKS['khan']}
    index = {}
    for k in VENUES.values():
        for m in seen[k].get('members', []):
            skip.add(('way', m['ref']))
    relout = {('way', m['ref']) for (t, _), e in seen.items() if t == 'relation'
              for m in e.get('members', []) if m.get('role') in ('outer', 'outline')}

    B = []
    for k, e in seen.items():
        if k in skip or k in relout or 'building' not in e.get('tags', {}):
            continue
        for r in outer_rings(e):
            r = ring(r, 0.6)
            if not r:
                continue
            A = abs(area(r))
            if A < 18:
                continue
            if area(r) < 0:
                r = r[::-1]
            h = FIXED_HEIGHT.get(k) or height(e, A)
            if k in private_ids:
                index.setdefault(k, []).append(len(B))
            B.append([round(h)] + enc(r))

    out = {'origin': {'lat': LAT0, 'lon': LON0, 'note': 'x — метры на восток, y — на север; в Three.js Z = −y'},
           'b': B, 'venues': {}, 'lm': {}}
    for n, k in VENUES.items():
        out['venues'][n] = shape(seen[k])
    for n, k in LANDMARKS.items():
        out['lm'][n] = shape(seen[k])

    M = {}
    for f in glob.glob(os.path.join(RAW, 'misc.json')) + glob.glob(os.path.join(RAW, 'm_*.json')):
        for e in json.load(open(f))['elements']:
            M[(e['type'], e['id'])] = e
    roads, water, parks, river = [], [], [], []
    W = {'motorway': 3, 'trunk': 3, 'primary': 3, 'secondary': 2, 'tertiary': 1}
    for e in M.values():
        t = e.get('tags', {})
        if 'highway' in t and e['type'] == 'way':
            roads.append([W.get(t['highway'], 0)] + enc(dp([P(p) for p in e['geometry']], 1.5), 1))
        elif t.get('natural') == 'water':
            for r in outer_rings(e):
                r = ring(r, 2.0)
                if r and abs(area(r)) > 1500:
                    water.append(enc(r, 1))
        elif t.get('waterway') == 'river' and e['type'] == 'way':
            river.append(enc(dp([P(p) for p in e['geometry']], 3), 1))
        elif t.get('leisure') == 'park':
            for r in outer_rings(e):
                r = ring(r, 2.5)
                if r and abs(area(r)) > 4000:
                    parks.append(enc(r, 1))
    out.update(roads=roads, water=water, parks=parks, river=river)
    os.makedirs(OUT, exist_ok=True)
    s = json.dumps(out, separators=(',', ':'), ensure_ascii=False)
    open(os.path.join(OUT, 'city.json'), 'w').write(s)
    print(f'city.json: {len(B)} зданий, {len(roads)} дорог, {len(water)} водоёмов, {len(parks)} парков, {len(s) // 1024} КБ')

    # личные места
    places = []
    for p in personal['places']:
        q = {k: v for k, v in p.items() if k not in ('osm', 'plot', 'plot_note')}
        q['tour'] = False
        if 'osm' in p:
            e = seen.get(tuple(p['osm']))
            if not e:
                print('нет в выгрузке:', p['id'], p['osm']); continue
            d = shape(e)
            q.update(kind='footprint', c=d['c'], ang=d['ang'], L=d['L'], ring=d['ring'], hb=round(height(e, d['L'] * d['W'])),
                     hide=index.get(tuple(p['osm']), []))
        elif 'plot' in p:
            q.update(kind='plot', **plot_house(p['plot']))
        places.append(q)
    if personal['places']:
        s = json.dumps({'places': places}, separators=(',', ':'), ensure_ascii=False)
        open(os.path.join(OUT, 'places.private.json'), 'w').write(s)
        print(f'places.private.json: {len(places)} мест')


def plot_house(corners):
    """Участок по углам + дом и пристройка из проекта «Наш дом» (метры участка)."""
    pm = [P({'lat': la, 'lon': lo}) for la, lo in corners]
    wv = (pm[2][0] - pm[1][0], pm[2][1] - pm[1][1]); wl = math.hypot(*wv); wv = (wv[0] / wl, wv[1] / wl)
    hm = json.load(open(os.path.join(MIRAS, 'measurements.json')))
    ds = json.load(open(os.path.join(MIRAS, 'design.json')))
    ex = hm['exterior']; rh = hm['stair_to_attic']['roof_headroom']; ob = ds['outbuilding']
    return dict(
        pts=[[round(x, 1), round(y, 1)] for x, y in pm],
        c=[round(sum(p[0] for p in pm) / 4, 1), round(sum(p[1] for p in pm) / 4, 1)], L=36, hb=9,
        o=[round(pm[1][0], 1), round(pm[1][1], 1)], w=[round(wv[0], 4), round(wv[1], 4)], u=[round(wv[1], 4), round(-wv[0], 4)],
        plotW=ds['plot']['width_m'],
        house=dict(at=[ds['site_anchor_m']['x'], -ds['site_anchor_m']['z']],
                   fp=[[x / 1000, y / 1000] for x, y in ex['footprint_mm']['value']],
                   w=ex['total_width_x']['value'] / 1000, d=ex['total_depth_y']['value'] / 1000,
                   y0=ex['porch_projection_y']['value'] / 1000, wall=ex['wall_height_blocks']['value'] / 1000 + 0.4,
                   ridge=rh['ridge_above_wall_top_mm']['value'] / 1000, ridgeY=rh['ridge_y_mm']['value'] / 1000),
        out=dict(w=ob['width_m'], h=ob['wall_height_m'], len=sum(r['length_m'] for r in ob['rooms']), **{'from': 1.0}))


if __name__ == '__main__':
    main()
