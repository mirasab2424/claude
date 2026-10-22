// Прохождение теста: прогресс, таймер, порядок пунктов стрелками, черновик ответов в браузере.
(function () {
  const form = document.getElementById('take');
  if (!form) return;
  const qs = [...form.querySelectorAll('.q')];
  const key = 'kost-draft-' + form.dataset.token;
  let sending = false;

  const answered = (q) => {
    switch (q.dataset.type) {
      case 'single': case 'multiple': case 'truefalse': return !!q.querySelector('input:checked');
      case 'text': case 'number': return q.querySelector('input.answer').value.trim() !== '';
      case 'order': return q.classList.contains('touched');
      case 'match': return [...q.querySelectorAll('select')].every((s) => s.value !== '');
    }
    return false;
  };
  const counter = document.getElementById('answered');
  const bar = document.getElementById('bar');
  const update = () => {
    let n = 0;
    qs.forEach((q) => { const a = answered(q); q.classList.toggle('is-answered', a); n += a ? 1 : 0; });
    counter.textContent = counter.dataset.tpl.replace('%a', n);
    bar.style.width = (n / qs.length * 100) + '%';
    return n;
  };

  // Черновик: если страницу случайно перезагрузили, ответы вернутся
  const save = () => {
    const data = { fields: [...new FormData(form)], order: {} };
    qs.filter((q) => q.dataset.type === 'order').forEach((q) => {
      data.order[q.dataset.q] = { ids: [...q.querySelectorAll('input[type=hidden]')].map((i) => i.value), touched: q.classList.contains('touched') };
    });
    try { localStorage.setItem(key, JSON.stringify(data)); } catch (e) {}
  };
  const restore = () => {
    let data;
    try { data = JSON.parse(localStorage.getItem(key) || 'null'); } catch (e) { data = null; }
    if (!data) return;
    Object.entries(data.order || {}).forEach(([id, o]) => {
      const list = form.querySelector(`.q[data-q="${id}"] .order`);
      if (!list) return;
      o.ids.forEach((v) => { const li = list.querySelector(`input[value="${CSS.escape(v)}"]`)?.closest('li'); if (li) list.append(li); });
      if (o.touched) list.closest('.q').classList.add('touched');
    });
    (data.fields || []).forEach(([name, value]) => {
      form.querySelectorAll(`[name="${CSS.escape(name)}"]`).forEach((el) => {
        if (el.type === 'radio' || el.type === 'checkbox') { if (el.value === value) el.checked = true; }
        else if (el.type !== 'hidden') el.value = value;
      });
    });
  };

  form.addEventListener('click', (e) => {
    const b = e.target.closest('[data-move]');
    if (!b) return;
    const li = b.closest('li');
    const dir = +b.dataset.move;
    const sib = dir < 0 ? li.previousElementSibling : li.nextElementSibling;
    if (sib) dir < 0 ? li.parentNode.insertBefore(li, sib) : li.parentNode.insertBefore(sib, li);
    li.closest('.q').classList.add('touched');
    li.classList.add('moved'); setTimeout(() => li.classList.remove('moved'), 300);
    b.focus();
    update(); save();
  });
  form.addEventListener('input', () => { update(); save(); });
  form.addEventListener('change', () => { update(); save(); });
  // Enter в текстовом ответе не отправляет тест случайно
  form.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.matches('input.answer')) e.preventDefault(); });

  form.addEventListener('submit', (e) => {
    if (sending) return;
    const left = qs.length - update();
    if (left > 0 && !confirm(form.dataset.confirm.replace('%n', left))) { e.preventDefault(); return; }
    sending = true;
    try { localStorage.removeItem(key); } catch (err) {}
    form.querySelector('[type=submit]').disabled = true;
  });

  // Таймер: по окончании времени ответы отправляются сами
  const timer = document.getElementById('timer');
  if (timer) {
    const end = Date.now() + (+timer.dataset.left) * 1000;
    const tick = () => {
      const s = Math.max(0, Math.round((end - Date.now()) / 1000));
      timer.textContent = String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
      timer.classList.toggle('is-low', s <= 60);
      if (s === 0 && !sending) {
        sending = true;
        timer.textContent = form.dataset.timeUp;
        try { localStorage.removeItem(key); } catch (err) {}
        HTMLFormElement.prototype.submit.call(form);
        return;
      }
      setTimeout(tick, 500);
    };
    tick();
  }

  restore();
  update();
})();
