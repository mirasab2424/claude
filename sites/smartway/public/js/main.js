// SMART WAY: меню, выпадающий список курсов, заявка (сохраняется на сайте, затем можно написать в WhatsApp), мягкое появление блоков.
(function () {
  document.documentElement.classList.add('js');
  const S = window.SMART || { t: {} };

  // Мобильное меню
  const burger = document.querySelector('.burger');
  const nav = document.getElementById('nav');
  const setMenu = (open) => {
    if (!burger) return;
    burger.setAttribute('aria-expanded', String(open));
    burger.setAttribute('aria-label', open ? S.t.menu_close : S.t.menu_open);
    nav.classList.toggle('is-open', open);
  };
  burger?.addEventListener('click', () => setMenu(burger.getAttribute('aria-expanded') !== 'true'));
  nav?.addEventListener('click', (e) => { if (e.target.closest('a')) setMenu(false); });

  // Выпадающее меню «Курсы» (десктоп): по клику и по наведению
  const group = document.querySelector('.nav__group');
  const toggle = group?.querySelector('.nav__toggle');
  const setDrop = (open) => { group.classList.toggle('is-open', open); toggle.setAttribute('aria-expanded', String(open)); };
  toggle?.addEventListener('click', () => setDrop(!group.classList.contains('is-open')));
  group?.addEventListener('mouseenter', () => matchMedia('(hover: hover)').matches && setDrop(true));
  group?.addEventListener('mouseleave', () => matchMedia('(hover: hover)').matches && setDrop(false));
  document.addEventListener('click', (e) => { if (group && !group.contains(e.target)) setDrop(false); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { group && setDrop(false); setMenu(false); } });

  // Заявка
  const dialog = document.getElementById('lead');
  const form = document.getElementById('lead-form');
  const course = document.getElementById('lead-course');
  const stepForm = form?.querySelector('[data-step="form"]');
  const stepDone = form?.querySelector('[data-step="done"]');
  const errorBox = form?.querySelector('.lead__error');
  const submit = form?.querySelector('[type="submit"]');
  const waUrl = (text) => 'https://wa.me/' + S.whatsapp + '?text=' + encodeURIComponent(text);

  const openLead = (courseName) => {
    if (courseName && course) {
      const opts = [...course.options];
      const match = opts.find((o) => o.value === courseName) || opts.find((o) => courseName.startsWith(o.value));
      if (match) course.value = match.value;
    }
    stepForm.hidden = false; stepDone.hidden = true; errorBox.hidden = true;
    if (dialog?.showModal) { dialog.showModal(); document.getElementById('lead-name')?.focus(); }
    else { location.href = waUrl(S.t.wa_message); }
  };
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-lead]');
    if (btn) { e.preventDefault(); openLead(btn.dataset.course || ''); }
  });
  dialog?.querySelector('[data-close]')?.addEventListener('click', () => dialog.close());
  dialog?.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); });

  form?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = new FormData(form);
    const name = form.elements.name, phone = form.elements.phone;
    const digits = String(f.get('phone') || '').replace(/\D/g, '');
    name.setAttribute('aria-invalid', String(!String(f.get('name') || '').trim()));
    phone.setAttribute('aria-invalid', String(digits.length < 10 || digits.length > 15));
    const bad = form.querySelector('[aria-invalid="true"]');
    if (bad) { bad.focus(); return; }

    const text = [
      S.t.wa_message,
      S.t.name + ': ' + String(f.get('name')).trim(),
      S.t.phone + ': ' + String(f.get('phone')).trim(),
      f.get('child') ? S.t.child + ': ' + String(f.get('child')).trim() : '',
      S.t.course + ': ' + f.get('course'),
      S.t.branch + ': ' + f.get('branch'),
    ].filter(Boolean).join('\n');

    submit.disabled = true; submit.textContent = S.t.sending; errorBox.hidden = true;
    try {
      const res = await fetch('/lead', { method: 'POST', body: f, headers: { Accept: 'application/json' } });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) throw new Error(data.error || res.status);
      stepDone.querySelector('[data-wa]').href = waUrl(text);
      stepForm.hidden = true; stepDone.hidden = false;
      form.reset();
    } catch (err) {
      errorBox.hidden = false;
      errorBox.innerHTML = '';
      errorBox.append(document.createTextNode(errorBox.dataset.text || (errorBox.dataset.text = errorBox.textContent) ), ' ');
      const a = document.createElement('a'); a.href = waUrl(text); a.target = '_blank'; a.rel = 'noopener'; a.textContent = 'WhatsApp →';
      errorBox.append(a);
    } finally {
      submit.disabled = false; submit.textContent = S.t.submit;
    }
  });

  // Мягкое появление: блоки всегда видны, только слегка «подъезжают»
  if ('IntersectionObserver' in window && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
    const items = document.querySelectorAll('.course, .control li, .steps li, .price, .teacher, .mini-teacher, .photo, .branch, .timeline__item, .features li');
    const io = new IntersectionObserver((entries) => entries.forEach((en) => {
      if (en.isIntersecting) { en.target.classList.add('is-in'); io.unobserve(en.target); }
    }), { rootMargin: '0px 0px -40px 0px' });
    items.forEach((el) => {
      if (el.getBoundingClientRect().top > innerHeight) { el.classList.add('reveal'); io.observe(el); }
    });
  }
})();
