#!/usr/bin/env python3
"""
Собирает статический сайт SMART WAY в dist/.

    pip install jinja2
    python3 sites/smartway/build.py          # → sites/smartway/dist/

Шаблоны — templates/*.html (base.html — общий каркас), данные — data.py.
После сборки проверяет, что все внутренние ссылки и картинки существуют.
"""
import re
import shutil
import sys
from datetime import date
from pathlib import Path

from jinja2 import Environment, FileSystemLoader, StrictUndefined

ROOT = Path(__file__).resolve().parent
sys.path.insert(0, str(ROOT))
import data  # noqa: E402

DIST = ROOT / "dist"

# slug шаблона → (title, description, путь в меню)
PAGES = {
    "index": ("Английский, математика, НИШ и продлёнка в Астане", "Образовательный центр SMART WAY в Астане: английский язык, IELTS, математика, подготовка к НИШ и к школе, продлёнка 1–4 класс. Kundelik под контролем, отчёт родителям каждый месяц."),
    "english": ("Английский язык для детей и подростков", "Английский в SMART WAY: 1 уровень за 3 месяца, группы 6–7 человек, Speaking club, интенсивы с носителями. Группа от 25 000 ₸/мес."),
    "ielts": ("Подготовка к IELTS", "Подготовка к IELTS 6.5+ в Астане: все модули, преподаватель с IELTS 8.0. Группа 55 000 ₸, индивидуально 75 000 ₸."),
    "math": ("Математика, геометрия и физика", "Математика в SMART WAY: диагностика, устранение пробелов, опережающее обучение. 99% учеников выходят на «4» и «5». От 25 000 ₸/мес."),
    "nis": ("Подготовка к поступлению в НИШ (5–6 класс)", "Системная подготовка к НИШ: группы до 7–8 учеников, пробные МОСК и БТС, контроль прогресса. 5 класс — 85 000 ₸/мес, 6 класс — 100 000 ₸/мес."),
    "school": ("Подготовка к школе", "Подготовка к школе в SMART WAY: чтение, письмо, счёт, нейрогимнастика. 5 раз в неделю, от 40 000 ₸/мес."),
    "prodlenka": ("Продлёнка для 1–4 класса", "Продлёнка в SMART WAY: домашние задания, закрепление программы, контроль Kundelik. 5 раз в неделю, от 40 000 ₸/мес. Книги в подарок."),
    "teachers": ("Преподаватели", "Преподаватели SMART WAY: дипломированные специалисты с опытом от 3 лет — английский, математика, физика, казахский язык."),
    "prices": ("Цены на обучение", "Все цены SMART WAY на одной странице: английский, IELTS, математика, комбо, НИШ, подготовка к школе и продлёнка."),
    "about": ("О центре", "Как устроено обучение в SMART WAY: контроль Kundelik, ежемесячные отчёты, звонок руководителя и открытый день для родителей."),
    "contacts": ("Контакты и филиалы", "SMART WAY: два филиала на улице Ахмет Байтурсынулы (39/1 и 18), Астана. Телефон и WhatsApp +7 778 929 4886."),
    "404": ("Страница не найдена", "Страница не найдена."),
}

NAV = [
    ("Курсы", None),
    ("Преподаватели", "teachers.html"),
    ("Цены", "prices.html"),
    ("О центре", "about.html"),
    ("Контакты", "contacts.html"),
]


def build() -> None:
    if DIST.exists():
        shutil.rmtree(DIST)
    shutil.copytree(ROOT / "static", DIST)

    env = Environment(loader=FileSystemLoader(ROOT / "templates"), undefined=StrictUndefined, autoescape=True,
                      trim_blocks=True, lstrip_blocks=True)
    env.filters["wa"] = lambda text: "https://wa.me/{}?text={}".format(
        data.SITE["whatsapp"], __import__("urllib.parse").parse.quote(text))
    common = {name: getattr(data, name) for name in dir(data) if name.isupper()}
    version = date.today().strftime("%Y%m%d")

    for slug, (title, description) in PAGES.items():
        html = env.get_template(f"{slug}.html").render(
            **common, page=slug, title=title, description=description, nav=NAV, version=version,
            url=f"{data.SITE['base_url']}/{'' if slug == 'index' else slug + '.html'}",
        )
        (DIST / f"{slug}.html").write_text(html, encoding="utf-8")

    sitemap = "\n".join(
        f"  <url><loc>{data.SITE['base_url']}/{'' if s == 'index' else s + '.html'}</loc></url>"
        for s in PAGES if s != "404")
    (DIST / "sitemap.xml").write_text(
        f'<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n{sitemap}\n</urlset>\n',
        encoding="utf-8")
    (DIST / "robots.txt").write_text(f"User-agent: *\nAllow: /\nSitemap: {data.SITE['base_url']}/sitemap.xml\n", encoding="utf-8")
    (DIST / ".htaccess").write_text("ErrorDocument 404 /404.html\nOptions -Indexes\n", encoding="utf-8")

    check_links()
    print(f"Готово: {len(PAGES)} страниц в {DIST}")


def check_links() -> None:
    """Все локальные href/src должны указывать на существующие файлы."""
    broken = []
    for page in DIST.glob("*.html"):
        for ref in re.findall(r'(?:href|src)="([^"#?]+)', page.read_text(encoding="utf-8")):
            if re.match(r"^(https?:|mailto:|tel:|data:|//)", ref):
                continue
            if not (DIST / ref.lstrip("/")).exists():
                broken.append(f"{page.name}: {ref}")
    if broken:
        sys.exit("Битые ссылки:\n  " + "\n  ".join(sorted(set(broken))))


if __name__ == "__main__":
    build()
