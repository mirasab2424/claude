#!/bin/bash
# Локальные копии библиотек для tools/shot.cjs (на сайте они грузятся с CDN). Запуск из sites/astana.
set -e
mkdir -p tools/lib
for f in build/three.min.js examples/js/controls/OrbitControls.js; do
  curl -sSfL -o "tools/lib/$(basename $f)" "https://cdn.jsdelivr.net/npm/three@0.147.0/$f"
done
ls -l tools/lib
