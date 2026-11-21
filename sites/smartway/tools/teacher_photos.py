"""Фото преподавателей одной серией: вырезает человека с фона и ставит на фон в цветах сайта.

    pip install "rembg[cpu]" pillow
    python sites/smartway/tools/teacher_photos.py фото1.jpg фото2.jpg ... --out sites/smartway/public/img/teachers

Имя результата = имя исходного файла (.jpg), поэтому называйте исходники как в default.json:
isaev-nuradin.jpg, zhumabai-dilnaz.jpg и т. д. Кадр 600 × 800, голова — половина ширины,
над головой немного места (если макушка в исходнике целая), фон по очереди бирюзовый и розовый.
"""
import argparse
import os

from PIL import Image, ImageDraw, ImageEnhance, ImageFilter, ImageOps
from rembg import new_session, remove

OW, OH = 600, 800
BG = [((226, 242, 243), (186, 222, 227)), ((254, 234, 241), (246, 200, 217))]


def background(i):
    c1, c2 = BG[i % 2]
    bg = Image.new('RGB', (OW, OH))
    d = ImageDraw.Draw(bg)
    for y in range(OH):
        t = y / OH
        d.line([(0, y), (OW, y)], fill=tuple(round(c1[j] + (c2[j] - c1[j]) * t) for j in range(3)))
    glow = Image.new('L', (OW, OH), 0)
    ImageDraw.Draw(glow).ellipse((-OW * 0.1, OH * 0.05, OW * 1.1, OH * 0.75), fill=120)
    return Image.composite(Image.new('RGB', (OW, OH), (255, 255, 255)), bg, glow.filter(ImageFilter.GaussianBlur(60)))


def portrait(path, i, session):
    im = ImageOps.exif_transpose(Image.open(path)).convert('RGB')
    if im.width < 1200:  # маленькие исходники: маска по увеличенной копии даёт мягче край волос
        im = im.resize((im.width * 2, im.height * 2), Image.LANCZOS)
    cut = remove(im, session=session, alpha_matting=True, alpha_matting_foreground_threshold=235,
                 alpha_matting_background_threshold=15, alpha_matting_erode_size=8).convert('RGBA')
    r, g, b, a = cut.split()
    rgb = Image.merge('RGB', (r, g, b))
    rgb = ImageEnhance.Color(Image.blend(rgb, ImageOps.autocontrast(rgb, cutoff=1), 0.5)).enhance(1.06)
    cut = Image.merge('RGBA', (*rgb.split(), a))
    w, h = cut.size
    m = a.point(lambda v: 255 if v > 128 else 0).filter(ImageFilter.MedianFilter(5))
    x0, y0, x1, y1 = m.getbbox()
    row = y0 + int((y1 - y0) * 0.10)
    xs = [x for x in range(w) if m.getpixel((x, row))] or [x0, x1]
    head, cx = max(xs) - min(xs), (min(xs) + max(xs)) / 2
    top = 0 if y0 <= 2 else round(OH * 0.06)  # макушка срезана в исходнике — прижимаем к краю
    k = max((OH - top) / (y1 - y0), OW * 0.50 / head)
    cut = cut.resize((round(w * k), round(h * k)), Image.LANCZOS)
    cut = Image.merge('RGBA', (*cut.convert('RGB').filter(ImageFilter.UnsharpMask(1.6, 60, 2)).split(), cut.split()[3]))
    px, py = round(OW / 2 - cx * k), round(top - y0 * k)
    bg = background(i)
    sh = Image.new('L', (OW, OH), 0)
    sh.paste(cut.split()[3], (px + 14, py + 10))
    sh = sh.filter(ImageFilter.GaussianBlur(18)).point(lambda v: int(v * 0.28))
    bg = Image.composite(Image.new('RGB', (OW, OH), (40, 50, 60)), bg, sh)
    bg.paste(cut, (px, py), cut)
    return bg


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('photos', nargs='+')
    ap.add_argument('--out', required=True)
    args = ap.parse_args()
    os.makedirs(args.out, exist_ok=True)
    session = new_session('u2net_human_seg')
    for i, p in enumerate(sorted(args.photos)):
        name = os.path.splitext(os.path.basename(p))[0] + '.jpg'
        portrait(p, i, session).save(os.path.join(args.out, name), quality=88, optimize=True, progressive=True)
        print('готово:', name)


if __name__ == '__main__':
    main()
