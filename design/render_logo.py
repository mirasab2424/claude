# Рендер последнего кадра logo.blend (камера из файла, прозрачный фон) → PNG для шапки и favicon.
# python -I design/render_logo.py design/logo.blend /tmp/logo-render.png
import bpy, sys
src, out = sys.argv[-2], sys.argv[-1]
bpy.ops.wm.open_mainfile(filepath=src)
sc = bpy.context.scene
sc.frame_set(sc.frame_end)
sc.render.engine = 'CYCLES'
sc.cycles.device = 'CPU'
sc.cycles.samples = 64
sc.render.film_transparent = True
sc.render.resolution_x = 2160
sc.render.resolution_y = 2160
sc.render.resolution_percentage = 100
if hasattr(sc.render.image_settings, 'media_type'):
    sc.render.image_settings.media_type = 'IMAGE'
sc.render.image_settings.file_format = 'PNG'
sc.render.image_settings.color_mode = 'RGBA'
sc.render.filepath = out
bpy.ops.render.render(write_still=True)
print('rendered', out)
