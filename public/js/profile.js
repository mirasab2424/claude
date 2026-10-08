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

        data.metrics.forEach((m) => {
            const canvas = document.querySelector(`[data-metric="${m.id}"]`);
            if (!canvas) return;
            const datasets = [{
                label: m.name,
                data: m.points.map((p) => p.y),
                borderColor: v('--accent'),
                backgroundColor: v('--accent'),
                borderWidth: 2, pointRadius: 3, pointHoverRadius: 5, tension: 0.25,
            }];
            if (m.target !== null) {
                datasets.push({
                    label: 'Цель', data: m.points.map(() => m.target),
                    borderColor: COLORS.text, borderDash: [4, 4], borderWidth: 1, pointRadius: 0,
                });
            }
            new Chart(canvas, {
                type: 'line',
                data: { labels: m.points.map((p) => new Date(p.x).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' })), datasets },
                options: {
                    plugins: { legend: { display: m.target !== null, position: 'top', align: 'end' } },
                    scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 6 } }, y: { grid: { color: COLORS.grid } } },
                },
            });
        });
    }

    // Тепловая карта в стиле GitHub: недели — столбцы, дни недели — строки.
    const heat = document.getElementById('heatmap');
    if (heat) {
        const days = data.heatmap;
        // Шкала не ниже 3, чтобы единичная задача не выглядела «максимумом».
        const max = Math.max(3, ...days.map((d) => d.count));
        const firstDow = (new Date(days[0].date).getDay() + 6) % 7; // понедельник = 0
        const frag = document.createDocumentFragment();
        for (let i = 0; i < firstDow; i++) {
            const pad = document.createElement('span');
            pad.className = 'hm-cell hm-cell_pad';
            frag.appendChild(pad);
        }
        days.forEach((d) => {
            const cell = document.createElement('span');
            const level = d.count === 0 ? 0 : Math.min(4, Math.ceil((d.count / max) * 4));
            cell.className = 'hm-cell';
            cell.dataset.level = level;
            const date = new Date(d.date).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' });
            cell.title = `${date}: ${d.count ? 'выполнено ' + d.count : 'нет выполненных'}`;
            frag.appendChild(cell);
        });
        heat.appendChild(frag);
        heat.scrollLeft = heat.scrollWidth;
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
