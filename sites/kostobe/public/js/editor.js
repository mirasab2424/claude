// Редактор вопросов теста. Вопросы хранятся в массиве state и при сохранении уходят на сервер одним JSON.
// Тексты — {ru, kz, en}; варианты ответов общие для всех языков, переводится только их текст.
(function () {
  const form = document.getElementById('editor');
  if (!form) return;
  const list = document.getElementById('qlist');
  const field = document.getElementById('questions-field');
  const TYPES = JSON.parse(document.getElementById('qtypes').textContent);
  const LANGS = ['ru', 'kz', 'en'];
  const LNAME = { ru: 'русском', kz: 'казахском', en: 'английском' };
  const KEYS = 'ABCDEFGHIJKLMNOP';
  let state = JSON.parse(document.getElementById('qdata').textContent || '[]');
  let lang = 'ru';
  let dirty = false;

  const uid = () => 'n' + Math.random().toString(36).slice(2, 8);
  const L = (v = '') => ({ ru: v, kz: v, en: v });
  const E = () => ({ ru: '', kz: '', en: '' });
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const has = (v, l) => !!(v && String(v[l] ?? '').trim());
  const any = (v) => LANGS.some((l) => has(v, l));
  const ref = (v) => { for (const l of LANGS) if (l !== lang && has(v, l)) return v[l]; return ''; };

  function blank(type, prev) {
    const d = { explain: prev?.data?.explain || E() };
    const keepOpts = prev && ['single', 'multiple'].includes(prev.type) && ['single', 'multiple'].includes(type);
    switch (type) {
      case 'single': case 'multiple':
        d.options = keepOpts ? prev.data.options.map((o, i) => ({ ...o, correct: type === 'single' ? o.correct && prev.data.options.findIndex((x) => x.correct) === i : o.correct }))
          : [0, 1, 2, 3].map(() => ({ id: uid(), text: E(), correct: false }));
        if (type === 'multiple') d.partial = prev?.data?.partial ?? true;
        break;
      case 'truefalse': d.answer = true; break;
      case 'text': d.answers = { ru: [], kz: [], en: [] }; break;
      case 'number': d.answer = ''; d.tolerance = 0; d.unit = E(); break;
      case 'order': d.items = [0, 1, 2].map(() => ({ id: uid(), text: E() })); break;
      case 'match': d.pairs = [0, 1, 2].map(() => ({ id: uid(), left: E(), right: E() })); break;
    }
    return d;
  }

  // Те же проверки, что на сервере: подсказываем до сохранения
  function errors(q) {
    const e = [];
    const d = q.data;
    if (!any(q.text)) e.push('нет текста вопроса');
    if (q.type === 'single' || q.type === 'multiple') {
      const filled = d.options.filter((o) => any(o.text));
      if (filled.length < 2) e.push('нужно минимум 2 варианта');
      if (!filled.some((o) => o.correct)) e.push('отметьте правильный ответ');
    }
    if (q.type === 'text' && !LANGS.some((l) => (d.answers[l] || []).some((s) => s.trim()))) e.push('укажите правильный ответ');
    if (q.type === 'number' && (String(d.answer).trim() === '' || isNaN(+String(d.answer).replace(',', '.').replace(/\s/g, '')))) e.push('правильный ответ — число');
    if (q.type === 'order' && d.items.filter((o) => any(o.text)).length < 2) e.push('нужно минимум 2 пункта');
    if (q.type === 'match' && d.pairs.filter((p) => any(p.left) && any(p.right)).length < 2) e.push('нужно минимум 2 пары');
    return e;
  }
  function missing(q, l) {
    const d = q.data;
    const need = [q.text];
    (d.options || []).forEach((o) => any(o.text) && need.push(o.text));
    (d.items || []).forEach((o) => any(o.text) && need.push(o.text));
    (d.pairs || []).forEach((p) => { need.push(p.left, p.right); });
    if (need.some((v) => !has(v, l))) return true;
    return q.type === 'text' && !(d.answers[l] || []).some((s) => s.trim());
  }

  const inp = (path, value, ph = '', cls = '') => `<input data-p="${path}" value="${esc(value)}" placeholder="${esc(ph)}" class="${cls}">`;
  const ta = (path, value, ph = '', rows = 2) => `<textarea data-p="${path}" rows="${rows}" placeholder="${esc(ph)}">${esc(value)}</textarea>`;
  const lin = (path, v, ph) => inp(path + '.' + lang, v?.[lang] ?? '', ref(v) || ph);

  function body(q, i) {
    const d = q.data;
    const p = `${i}.data`;
    let h = `<label class="f"><span>Текст вопроса · ${lang.toUpperCase()}</span>${ta(`${i}.text.${lang}`, q.text[lang] ?? '', ref(q.text) || 'Например: Сколько будет 2 + 2?', 2)}</label>`;
    if (lang !== 'ru' && ref(q.text)) h += `<p class="ref">Оригинал: <b>${esc(ref(q.text))}</b></p>`;
    switch (q.type) {
      case 'single': case 'multiple':
        h += `<div class="subhead"><span>Варианты ответа — отметьте ${q.type === 'single' ? 'правильный' : 'все правильные'}</span></div>`;
        d.options.forEach((o, j) => {
          h += `<div class="optrow"><label class="right" title="Правильный ответ"><input type="${q.type === 'single' ? 'radio' : 'checkbox'}" name="c${i}" data-correct="${i}.${j}" ${o.correct ? 'checked' : ''}></label>
            <span class="key">${KEYS[j] || j + 1}</span>${lin(`${p}.options.${j}.text`, o.text, 'Вариант ' + (KEYS[j] || j + 1))}
            <button type="button" class="ib ib--del" data-del-opt="${i}.options.${j}" title="Удалить вариант">✕</button></div>`;
        });
        h += `<button type="button" class="addline" data-add-opt="${i}.options">+ вариант</button>`;
        if (q.type === 'multiple') h += `<label class="checks"><label><input type="checkbox" data-bool="${p}.partial" ${d.partial ? 'checked' : ''}> Частичный балл: за каждый верный выбор, минус за неверный</label></label>`;
        break;
      case 'truefalse':
        h += `<div class="subhead"><span>Правильный ответ</span></div><div class="tf">
          <label><input type="radio" name="tf${i}" data-tf="${i}" value="1" ${d.answer ? 'checked' : ''}> Верно</label>
          <label><input type="radio" name="tf${i}" data-tf="${i}" value="0" ${!d.answer ? 'checked' : ''}> Неверно</label></div>`;
        break;
      case 'text':
        h += `<label class="f"><span>Правильные ответы · ${lang.toUpperCase()} — по одному в строке</span>
          <textarea data-lines="${p}.answers.${lang}" rows="2" placeholder="${esc(((LANGS.map((l) => d.answers[l] || []).find((a) => a.length)) || []).join('\n') || 'сумма')}">${esc((d.answers[lang] || []).join('\n'))}</textarea>
          <small>Регистр, «ё/е», лишние пробелы и точка в конце не важны. Засчитывается ответ на любом из языков.</small></label>`;
        break;
      case 'number':
        h += `<div class="grid3"><label class="f"><span>Правильный ответ</span>${inp(`${p}.answer`, d.answer, '24')}</label>
          <label class="f"><span>Допустимая погрешность ±</span>${inp(`${p}.tolerance`, d.tolerance, '0')}</label>
          <label class="f"><span>Единица · ${lang.toUpperCase()}</span>${lin(`${p}.unit`, d.unit, 'см, кг… (необязательно)')}</label></div>`;
        break;
      case 'order':
        h += `<div class="subhead"><span>Пункты в правильном порядке — ученик увидит их перемешанными</span></div>`;
        d.items.forEach((o, j) => {
          h += `<div class="optrow"><span class="key">${j + 1}</span><span class="qcard__btns"><button type="button" class="ib" data-mv="${i}.items.${j}.-1" title="Выше">↑</button></span>
            ${lin(`${p}.items.${j}.text`, o.text, 'Пункт ' + (j + 1))}<button type="button" class="ib ib--del" data-del-opt="${i}.items.${j}" title="Удалить">✕</button></div>`;
        });
        h += `<button type="button" class="addline" data-add-opt="${i}.items">+ пункт</button>`;
        break;
      case 'match':
        h += `<div class="subhead"><span>Пары: слева — пункт, справа — его пара. Правая колонка у ученика перемешается</span></div>`;
        d.pairs.forEach((o, j) => {
          h += `<div class="pairrow"><span class="key">${j + 1}</span>${lin(`${p}.pairs.${j}.left`, o.left, 'Пункт')}${lin(`${p}.pairs.${j}.right`, o.right, 'Пара')}
            <button type="button" class="ib ib--del" data-del-opt="${i}.pairs.${j}" title="Удалить">✕</button></div>`;
        });
        h += `<button type="button" class="addline" data-add-opt="${i}.pairs">+ пара</button>`;
        break;
    }
    h += `<label class="f"><span>Пояснение после теста · ${lang.toUpperCase()} (необязательно)</span>${ta(`${p}.explain.${lang}`, d.explain?.[lang] ?? '', ref(d.explain) || 'Почему ответ такой — ученик увидит в разборе', 1)}</label>`;
    return h;
  }

  function render() {
    list.innerHTML = state.length ? state.map((q, i) => {
      const err = errors(q);
      const miss = LANGS.filter((l) => l !== lang && missing(q, l));
      return `<div class="qcard ${err.length ? 'has-err' : ''}" data-i="${i}">
        <div class="qcard__bar">
          <span class="qcard__n">${i + 1}.</span>
          <select data-type="${i}" aria-label="Вид задания">${Object.entries(TYPES).map(([k, v]) => `<option value="${k}" ${k === q.type ? 'selected' : ''}>${esc(v)}</option>`).join('')}</select>
          <span class="pts">Баллы ${inp(`${i}.points`, q.points, '1')}</span>
          ${missing(q, lang) ? `<span class="qcard__miss">нет перевода на ${LNAME[lang]}</span>` : ''}
          <span class="qcard__btns">
            <button type="button" class="ib" data-q="up" title="Выше">↑</button><button type="button" class="ib" data-q="down" title="Ниже">↓</button>
            <button type="button" class="ib" data-q="dup" title="Копия">⧉</button><button type="button" class="ib ib--del" data-q="del" title="Удалить вопрос">✕</button>
          </span>
        </div>
        <div class="qcard__body">${err.length ? `<p class="qcard__err">⚠ ${err.join('; ')}</p>` : ''}${body(q, i)}</div>
      </div>`;
    }).join('') : '<p class="empty-q">Вопросов пока нет. Добавьте первый — кнопки ниже.</p>';
    document.getElementById('q-count').textContent = state.length ? `(${state.length}, баллов: ${state.reduce((s, q) => s + (+String(q.points).replace(',', '.') || 0), 0)})` : '';
    document.querySelectorAll('.langtabs button').forEach((b) => {
      b.setAttribute('aria-selected', String(b.dataset.lang === lang));
      const m = state.some((q) => missing(q, b.dataset.lang));
      b.innerHTML = b.dataset.lang.toUpperCase() + (m ? '<i class="dot" title="Есть непереведённые тексты"></i>' : '');
    });
    document.getElementById('lang-hint').textContent = lang === 'ru'
      ? 'Сейчас вы правите русскую версию. Переключите KZ или EN сверху, чтобы перевести тексты; серым показан оригинал.'
      : `Перевод на ${LNAME[lang]}. Правильные ответы и баллы общие для всех языков — переводите только тексты.`;
  }

  // После правки текста обновляем только подсказки карточки: полная перерисовка съела бы клик по кнопке
  function decorate(card) {
    if (!card) return;
    const q = state[+card.dataset.i];
    const err = errors(q);
    card.classList.toggle('has-err', err.length > 0);
    let box = card.querySelector('.qcard__err');
    if (err.length) {
      if (!box) { box = document.createElement('p'); box.className = 'qcard__err'; card.querySelector('.qcard__body').prepend(box); }
      box.textContent = '⚠ ' + err.join('; ');
    } else box?.remove();
    const miss = card.querySelector('.qcard__miss');
    if (miss && !missing(q, lang)) miss.remove();
    document.querySelectorAll('.langtabs button').forEach((b) => {
      const m = state.some((x) => missing(x, b.dataset.lang));
      b.querySelector('.dot')?.remove();
      if (m) b.insertAdjacentHTML('beforeend', '<i class="dot" title="Есть непереведённые тексты"></i>');
    });
    document.getElementById('q-count').textContent = `(${state.length}, баллов: ${state.reduce((s, x) => s + (+String(x.points).replace(',', '.') || 0), 0)})`;
  }

  // Путь вида "3.data.options.1.text.kz" → установка значения в state
  const setPath = (path, value) => {
    const keys = path.split('.');
    let o = state;
    keys.slice(0, -1).forEach((k) => { o = o[k] ??= {}; });
    o[keys.at(-1)] = value;
  };
  const getPath = (path) => path.split('.').reduce((o, k) => o?.[k], state);
  const touch = () => { dirty = true; document.getElementById('dirty-note').textContent = 'Есть несохранённые изменения'; };

  list.addEventListener('input', (e) => {
    const t = e.target;
    if (t.dataset.p) setPath(t.dataset.p, t.value);
    else if (t.dataset.lines) setPath(t.dataset.lines, t.value.split('\n'));
    else return;
    touch();
  });
  list.addEventListener('change', (e) => {
    const t = e.target;
    if (t.dataset.type) {
      const i = +t.dataset.type; const q = state[i];
      state[i] = { ...q, type: t.value, data: blank(t.value, q) };
    } else if (t.dataset.correct) {
      const [i, j] = t.dataset.correct.split('.').map(Number);
      const q = state[i];
      if (q.type === 'single') q.data.options.forEach((o, k) => { o.correct = k === j; });
      else q.data.options[j].correct = t.checked;
    } else if (t.dataset.tf) state[+t.dataset.tf].data.answer = t.value === '1';
    else if (t.dataset.bool) setPath(t.dataset.bool, t.checked);
    else { decorate(t.closest('.qcard')); return; }
    touch(); render();
  });
  list.addEventListener('click', (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    const card = b.closest('.qcard');
    const i = card ? +card.dataset.i : -1;
    if (b.dataset.q === 'up' && i > 0) [state[i - 1], state[i]] = [state[i], state[i - 1]];
    else if (b.dataset.q === 'down' && i < state.length - 1) [state[i + 1], state[i]] = [state[i], state[i + 1]];
    else if (b.dataset.q === 'dup') {
      const c = JSON.parse(JSON.stringify(state[i])); c.id = 0;
      ['options', 'items', 'pairs'].forEach((k) => (c.data[k] || []).forEach((o) => { o.id = uid(); }));
      state.splice(i + 1, 0, c);
    } else if (b.dataset.q === 'del') { if (!confirm('Удалить вопрос ' + (i + 1) + '?')) return; state.splice(i, 1); }
    else if (b.dataset.addOpt) {
      const arr = getPath(b.dataset.addOpt.replace(/^(\d+)\./, '$1.data.'));
      const k = b.dataset.addOpt.split('.').pop();
      arr.push(k === 'pairs' ? { id: uid(), left: E(), right: E() } : k === 'items' ? { id: uid(), text: E() } : { id: uid(), text: E(), correct: false });
    } else if (b.dataset.delOpt) {
      const parts = b.dataset.delOpt.split('.');
      const arr = getPath(parts[0] + '.data.' + parts[1]);
      if (arr.length <= 2 && !confirm('Останется меньше двух — всё равно удалить?')) return;
      arr.splice(+parts[2], 1);
    } else if (b.dataset.mv) {
      const [qi, k, j] = b.dataset.mv.split('.');
      const arr = state[+qi].data[k]; const n = +j;
      if (n > 0) [arr[n - 1], arr[n]] = [arr[n], arr[n - 1]];
    } else return;
    touch(); render();
  });

  document.querySelector('.addq').addEventListener('click', (e) => {
    const t = e.target.closest('[data-add]')?.dataset.add;
    if (!t) return;
    state.push({ id: 0, type: t, text: E(), points: 1, data: blank(t) });
    lang = 'ru'; touch(); render();
    list.lastElementChild?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    list.lastElementChild?.querySelector('textarea')?.focus({ preventScroll: true });
  });
  document.querySelector('.langtabs').addEventListener('click', (e) => {
    const l = e.target.closest('[data-lang]')?.dataset.lang;
    if (l) { lang = l; render(); }
  });

  form.addEventListener('input', (e) => { if (!list.contains(e.target)) touch(); });
  form.addEventListener('submit', () => { field.value = JSON.stringify(state); dirty = false; });
  addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  render();
})();
