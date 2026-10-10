// Графики, тепловая карта и карта мест на публичной странице профиля.
(function () {
    const data = window.__PROFILE__;
    if (!data) return;

    const css = getComputedStyle(document.documentElement);
    const v = (name) => css.getPropertyValue(name).trim();
    const COLORS = {
        done: v('--good'),
        failed: v('--critical'),
        created: v('--neutral'),
        text: v('--text-secondary'),
        grid: v('--grid'),
        surface: v('--surface-1'),
    };

    const escapeHtml = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    if (window.Chart) {
        Chart.defaults.color = COLORS.text;
        Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
        Chart.defaults.borderColor = COLORS.grid;
        Chart.defaults.plugins.legend.labels.boxWidth = 10;
        Chart.defaults.plugins.legend.labels.boxHeight = 10;
        Chart.defaults.plugins.legend.labels.useBorderRadius = true;
        Chart.defaults.plugins.legend.labels.borderRadius = 2;
        Chart.defaults.maintainAspectRatio = false;
        Chart.defaults.interaction = { mode: 'index', intersect: false };

        const bar = (label, values, color) => ({
            label, data: values, backgroundColor: color,
            borderRadius: { topLeft: 4, topRight: 4 }, borderSkipped: 'start',
            maxBarThickness: 18, borderColor: COLORS.surface, borderWidth: { right: 2 },
        });
        const axes = { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: COLORS.grid } } };

        const t = data.timeline;
        new Chart(document.getElementById('chart-timeline'), {
            type: 'bar',
            data: {
                labels: t.labels,
                datasets: [bar('Выполнено', t.done, COLORS.done), bar('Провалено', t.failed, COLORS.failed), bar('Поставлено', t.created, COLORS.created)],
            },
            options: { scales: axes, plugins: { legend: { position: 'top', align: 'start' } } },
        });

        const cats = data.categories;
        new Chart(document.getElementById('chart-categories'), {
            type: 'doughnut',
            data: {
                labels: cats.map((c) => c.name),
                datasets: [{ data: cats.map((c) => c.total), backgroundColor: cats.map((c) => c.color), borderColor: COLORS.surface, borderWidth: 2 }],
            },
            options: {
                cutout: '62%',
                interaction: { mode: 'nearest', intersect: true },
                plugins: {
                    legend: { position: 'right' },
                    tooltip: { callbacks: { label: (ctx) => ` ${cats[ctx.dataIndex].total} целей, выполнено ${cats[ctx.dataIndex].done} (${cats[ctx.dataIndex].rate}%)` } },
                },
            },
        });

        const hz = data.horizons;
        new Chart(document.getElementById('chart-horizons'), {
            type: 'bar',
            data: {
                labels: hz.map((h) => h.label),
                datasets: [bar('Всего', hz.map((h) => h.total), COLORS.created), bar('Выполнено', hz.map((h) => h.done), COLORS.done)],
            },
            options: { scales: axes, plugins: { legend: { position: 'top', align: 'start' } } },
        });

        const fmtDuration = (sec) => {
            const s = Math.round(Math.abs(sec));
            const h = Math.floor(s / 3600), mnt = Math.floor((s % 3600) / 60), r = String(s % 60).padStart(2, '0');
            return (sec < 0 ? '−' : '') + (h ? `${h}:${String(mnt).padStart(2, '0')}:${r}` : `${mnt}:${r}`);
        };
        const fmtDate = (ts, withDay = true) => new Date(ts).toLocaleDateString('ru-RU',
            withDay ? { day: 'numeric', month: 'short', year: 'numeric' } : { month: 'short', year: '2-digit' });

        data.metrics.forEach((m) => {
            const canvas = document.querySelector(`[data-metric="${m.id}"]`);
            if (!canvas) return;
            const fmt = (y) => (m.duration ? fmtDuration(y) : String(Math.round(y * 100) / 100).replace('.', ',')) + (m.unit ? ' ' + m.unit : '');
            const points = m.points.map((p) => ({ x: new Date(p.x).getTime(), y: p.y, note: p.note }));
            const datasets = [{
                label: m.name,
                data: points,
                borderColor: v('--accent'),
                backgroundColor: v('--accent'),
                borderWidth: 2, pointRadius: 3, pointHoverRadius: 5, tension: 0.2,
            }];
            if (m.target !== null && points.length) {
                datasets.push({
                    label: 'Цель', data: [{ x: points[0].x, y: m.target }, { x: points[points.length - 1].x, y: m.target }],
                    borderColor: COLORS.text, borderDash: [4, 4], borderWidth: 1, pointRadius: 0,
                });
            }
            // Ось X — настоящее время, поэтому паузы между замерами видны как пропуски.
            new Chart(canvas, {
                type: 'line',
                data: { datasets },
                options: {
                    interaction: { mode: 'nearest', intersect: false },
                    plugins: {
                        legend: { display: m.target !== null, position: 'top', align: 'end' },
                        tooltip: {
                            callbacks: {
                                title: (items) => fmtDate(items[0].parsed.x),
                                label: (ctx) => ' ' + fmt(ctx.parsed.y) + (ctx.raw.note ? ` · ${ctx.raw.note}` : ''),
                            },
                        },
                    },
                    scales: {
                        x: { type: 'linear', grid: { display: false }, ticks: { maxTicksLimit: 5, callback: (val) => fmtDate(val, false) } },
                        y: { grid: { color: COLORS.grid }, ticks: { maxTicksLimit: 5, callback: (val) => (m.duration ? fmtDuration(val) : val) } },
                    },
                },
            });
        });
    }

    // Тепловая карта в стиле GitHub: недели — столбцы, дни недели — строки. Вкладки — по годам.
    const heat = document.getElementById('heatmap');
    const yearsBox = document.getElementById('heatmap-years');
    if (heat && data.heatmap) {
        const years = Object.keys(data.heatmap).sort().reverse();
        const caption = document.getElementById('heatmap-caption');
        const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

        const render = (year) => {
            const counts = data.heatmap[year] || {};
            const values = Object.values(counts);
            // Шкала не ниже 3, чтобы единичная задача не выглядела «максимумом».
            const max = Math.max(3, ...values);
            const start = new Date(Number(year), 0, 1);
            const today = new Date();
            const end = Number(year) === today.getFullYear() ? today : new Date(Number(year), 11, 31);
            const frag = document.createDocumentFragment();
            for (let i = 0; i < (start.getDay() + 6) % 7; i++) {
                const pad = document.createElement('span');
                pad.className = 'hm-cell hm-cell_pad';
                frag.appendChild(pad);
            }
            for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
                const count = counts[iso(d)] || 0;
                const cell = document.createElement('span');
                cell.className = 'hm-cell';
                cell.dataset.level = count === 0 ? 0 : Math.min(4, Math.ceil((count / max) * 4));
                cell.title = `${d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' })}: ${count ? 'выполнено ' + count : 'нет выполненных'}`;
                frag.appendChild(cell);
            }
            heat.replaceChildren(frag);
            heat.scrollLeft = heat.scrollWidth;
            const days = values.length;
            caption.textContent = days
                ? `${year}: активных дней — ${days}, выполнено задач — ${values.reduce((a, b) => a + b, 0)}`
                : `${year}: пока без выполненных задач`;
            yearsBox.querySelectorAll('button').forEach((b) => b.setAttribute('aria-selected', String(b.dataset.year === year)));
        };

        years.forEach((year) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.role = 'tab';
            btn.dataset.year = year;
            btn.textContent = year;
            btn.addEventListener('click', () => render(year));
            yearsBox.appendChild(btn);
        });
        // По умолчанию — самый свежий год, в котором есть активность.
        render(years.find((y) => Object.keys(data.heatmap[y]).length) || years[0]);
    }

    // Карта мест.
    const mapEl = document.getElementById('map');
    if (mapEl && window.L && data.places.length) {
        const map = L.map(mapEl, { scrollWheelZoom: false });
        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
            attribution: '&copy; OpenStreetMap, &copy; CARTO', maxZoom: 19,
        }).addTo(map);
        const bounds = [];
        data.places.forEach((p) => {
            const marker = L.circleMarker([p.lat, p.lng], {
                radius: 8, color: COLORS.surface, weight: 2, fillColor: p.color, fillOpacity: 1,
            }).addTo(map);
            const stars = p.rating ? '★'.repeat(p.rating) + '☆'.repeat(5 - p.rating) : '';
            marker.bindPopup(
                `<div class="popup">` +
                (p.photo ? `<img src="${escapeHtml(p.photo)}" alt="">` : '') +
                `<b>${escapeHtml(p.title)}</b>` +
                (p.category ? `<div>${escapeHtml(p.category)}</div>` : '') +
                (p.date ? `<div class="muted">${escapeHtml(p.date)}</div>` : '') +
                (stars ? `<div class="stars">${stars}</div>` : '') +
                (p.description ? `<p>${escapeHtml(p.description)}</p>` : '') +
                `</div>`
            );
            bounds.push([p.lat, p.lng]);
        });
        if (bounds.length === 1) map.setView(bounds[0], 10);
        else map.fitBounds(bounds, { padding: [30, 30] });
    }
})();
