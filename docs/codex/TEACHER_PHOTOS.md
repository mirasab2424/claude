# Задача для Codex: фото преподавателей SMART WAY в хорошем качестве

Сначала прочитай `AGENTS.md`.

Сейчас фото в `sites/smartway/public/img/teachers/` сделаны из постов Instagram центра @smartschool_ast:
маленькие (~460 px), со срезанными макушками. Нужны исходники получше.

1. Найди в публичном Instagram центра @smartschool_ast посты-представления этих преподавателей и скачай
   изображения в максимальном размере (обычно 1080 px):
   Исаев Нурадин → `isaev-nuradin.jpg`, Жумабай Дильназ → `zhumabai-dilnaz.jpg`,
   Искендырова Айганым → `iskendyrova-aiganym.jpg`, Айтимбетова Анель → `aitimbetova-anel.jpg`,
   Хабыл Акмейир → `khabyl-akmeyir.jpg`.
   Если Instagram без входа недоступен — не обходи это, напиши, какие посты нашёл, и остановись.
   Других людей и чужие аккаунты не брать.
2. Положи исходники во временную папку ВНЕ репозитория (в git их не класть).
3. Обработай скриптом серии:
   `pip install "rembg[cpu]" pillow`
   `python sites/smartway/tools/teacher_photos.py /tmp/teachers_src/*.jpg --out sites/smartway/public/img/teachers`
4. Сравни с текущими фото: новые должны быть чётче и не хуже по кадру. Если хуже — не заменяй.
5. Pull request в `main` с новыми 5 файлами `.jpg` (600 × 800). Код и `default.json` не менять.
