#!/bin/bash
# Скачивает сырые данные OpenStreetMap для карты в raw/ (≈70 МБ). Запуск из sites/astana.
# Здания — плитками 0,03° × 0,044° (город внутри объездной с запасом) и доп. районы из private/personal.json;
# плитку, которую сервер не отдал (504), делит на 4 части. Уже скачанные файлы пропускает.
# Затем: python3 tools/build_data.py
U=${OVERPASS:-https://maps.mail.ru/osm/tools/overpass/api/interpreter}   # overpass-api.de из облака часто недоступен
mkdir -p raw
q() { # файл запрос → 0, если скачан
  local f=raw/$1; [ -s "$f" ] && return 0
  for a in 1 2 3 4 5; do
    code=$(curl -sS -m 240 -o "$f.part" -w "%{http_code}" --data-urlencode "data=$2" "$U" 2>/dev/null)
    if [ "$code" = 200 ] && tail -c 5 "$f.part" | grep -q '}'; then mv "$f.part" "$f"; echo "ok $1"; return 0; fi
    sleep 6
  done
  rm -f "$f.part"; return 1
}
bld() { echo "[out:json][timeout:180];(way[\"building\"]($1);relation[\"building\"]($1););out geom qt;"; }
tile() { # имя s w n e
  q "$1.json" "$(bld "$2,$3,$4,$5")" && return
  echo "делю $1"; local mlat mlon; mlat=$(python3 -c "print(round(($2+$4)/2,4))"); mlon=$(python3 -c "print(round(($3+$5)/2,4))")
  q "${1}q0.json" "$(bld "$2,$3,$mlat,$mlon")"; q "${1}q1.json" "$(bld "$2,$mlon,$mlat,$5")"
  q "${1}q2.json" "$(bld "$mlat,$3,$4,$mlon")"; q "${1}q3.json" "$(bld "$mlat,$mlon,$4,$5")"
}
for r in 0 1 2 3 4 5 6 7 8; do for c in 0 1 2 3 4 5 6 7 8; do
  s=$(python3 -c "print(round(51.015+$r*0.03,3))"); w=$(python3 -c "print(round(71.267+$c*0.044,3))")
  n=$(python3 -c "print(round($s+0.03,3))"); e=$(python3 -c "print(round($w+0.044,3))")
  tile "n_${r}_${c}" $s $w $n $e
done; done
# дополнительные районы из private/personal.json → "extra_areas": [[s, w, n, e], …] (плитки n_x0, n_x1, …)
if [ -f private/personal.json ]; then
  i=0; python3 -c "import json;[print(*a) for a in json.load(open('private/personal.json')).get('extra_areas',[])]" | while read s w n e; do
    tile "n_x$i" $s $w $n $e
    q "m_x$i.json" "[out:json][timeout:180];way[\"highway\"~\"^(residential|unclassified)$\"]($s,$w,$n,$e);out geom qt;"; i=$((i+1))
  done
fi
lines='way["highway"~"^(motorway|trunk|primary|secondary|tertiary)$"]'
i=0
for bb in "51.015,71.267,51.150,71.465" "51.015,71.465,51.150,71.663" "51.150,71.267,51.285,71.465" "51.150,71.465,51.285,71.663" "51.000,71.663,51.150,71.860"; do
  q "m_$i.json" "[out:json][timeout:180];($lines($bb);nwr[\"natural\"=\"water\"]($bb);way[\"waterway\"=\"river\"]($bb);way[\"leisure\"=\"park\"]($bb););out geom qt;"; i=$((i+1))
done
echo готово
