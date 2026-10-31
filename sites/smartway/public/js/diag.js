// Диагностика: прогресс, таймер, панель математических символов, черновик ответов в браузере.
(function () {
  const form = document.getElementById('diag');
  if (!form) return;
  const qs = [...form.querySelectorAll('.dq')];
  const key = 'smart-diag-' + form.dataset.token;
  const counter = document.getElementById('d-answered');
  const bar = document.getElementById('d-bar');
  let sending = false;

  const answered = (q) => q.dataset.type === 'choice' ? !!q.querySelector('input:checked') : q.querySelector('input').value.trim() !== '';
  const update = () => {
    let n = 0;
    qs.forEach((q) => { const a = answered(q); q.classList.toggle('is-answered', a); n += a ? 1 : 0; });
    counter.textContent = counter.dataset.tpl.replace('%a', n);
    bar.style.width = (n / qs.length * 100) + '%';
    return n;
  };
  const save = () => { try { localStorage.setItem(key, JSON.stringify([...new FormData(form)])); } catch (e) {} };
  try {
    (JSON.parse(localStorage.getItem(key) || '[]')).forEach(([name, value]) => {
      form.querySelectorAll(`[name="${CSS.escape(name)}"]`).forEach((el) => {
        if (el.type === 'radio') el.checked = el.value === value; else el.value = value;
      });
    });
  } catch (e) {}

  form.addEventListener('input', () => { update(); save(); });
  form.addEventListener('change', () => { update(); save(); });
  form.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.matches('.dq__input')) e.preventDefault(); });
  form.addEventListener('submit', (e) => {
    if (sending) return;
    const left = qs.length - update();
    if (left > 0 && !confirm(form.dataset.confirm.replace('%n', left))) { e.preventDefault(); return; }
    sending = true;
    try { localStorage.removeItem(key); } catch (err) {}
    form.querySelector('.diag-finish').disabled = true;
  });

  // Панель символов: √ ² ≤ ∞ … — вставляет в поле, где сейчас курсор
  const pad = document.getElementById('mathpad');
  let target = null;
  form.addEventListener('focusin', (e) => {
    if (e.target.matches('.dq__input.is-math')) { target = e.target; pad.hidden = false; }
    else if (!pad.contains(e.target)) pad.hidden = true;
  });
  form.addEventListener('focusout', () => setTimeout(() => {
    if (!pad.contains(document.activeElement) && !(document.activeElement?.matches?.('.dq__input.is-math'))) pad.hidden = true;
  }, 150));
  pad.addEventListener('mousedown', (e) => e.preventDefault()); // фокус остаётся в поле
  pad.addEventListener('click', (e) => {
    const b = e.target.closest('[data-ins]');
    if (!b || !target) return;
    const s = target.selectionStart ?? target.value.length;
    const t = target.selectionEnd ?? s;
    target.value = target.value.slice(0, s) + b.dataset.ins + target.value.slice(t);
    target.setSelectionRange(s + b.dataset.ins.length, s + b.dataset.ins.length);
    target.focus();
    update(); save();
  });

  // Прошедшее время
  const out = document.getElementById('d-time');
  const started = +form.dataset.started * 1000;
  const tick = () => {
    const s = Math.max(0, Math.round((Date.now() - started) / 1000));
    out.textContent = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
  };
  tick(); setInterval(tick, 1000);
  update();
})();
