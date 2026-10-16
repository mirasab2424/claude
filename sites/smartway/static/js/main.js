// SMART WAY: меню, выпадающий список курсов, заявка в WhatsApp, мягкое появление блоков.
(function () {
  document.documentElement.classList.add('js');

  // Мобильное меню
  const burger = document.querySelector('.burger');
  const nav = document.getElementById('nav');
  const setMenu = (open) => {
    burger.setAttribute('aria-expanded', String(open));
    burger.setAttribute('aria-label', open ? 'Закрыть меню' : 'Открыть меню');
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

  // Заявка: форма собирает сообщение и открывает WhatsApp
  const dialog = document.getElementById('lead');
  const form = document.getElementById('lead-form');
  const course = document.getElementById('lead-course');
  const openLead = (courseName) => {
    if (courseName && course) {
      const opts = [...course.options];
      const match = opts.find((o) => courseName.startsWith(o.value)) || opts.find((o) => o.value.split(' ')[0] === courseName.split(' ')[0]);
      if (match) course.value = match.value;
    }
    if (dialog?.showModal) { dialog.showModal(); document.getElementById('lead-name')?.focus(); }
    else { location.href = 'https://wa.me/' + window.SMART.whatsapp; }
  };
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-lead]');
    if (btn) { e.preventDefault(); openLead(btn.dataset.course || ''); }
  });
  dialog?.querySelector('[data-close]')?.addEventListener('click', () => dialog.close());
  dialog?.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); });
  form?.addEventListener('submit', (e) => {
    e.preventDefault();
    const f = new FormData(form);
    const lines = [
      'Здравствуйте! Хочу записаться на пробный урок в SMART WAY.',
      'Имя: ' + (f.get('name') || '').trim(),
      f.get('child') ? 'Ребёнок: ' + f.get('child').trim() : '',
      'Направление: ' + f.get('course'),
      'Филиал: ' + f.get('branch'),
    ].filter(Boolean);
    const url = 'https://wa.me/' + window.SMART.whatsapp + '?text=' + encodeURIComponent(lines.join('\n'));
    window.open(url, '_blank', 'noopener') || (location.href = url);
    dialog.close();
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
