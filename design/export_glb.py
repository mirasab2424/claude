# Экспорт модели и анимации из logo.blend в public/models/logo.glb для сайта.
# pip install bpy  →  python -I design/export_glb.py design/logo.blend public/models/logo.glb
import bpy, sys
src, out = sys.argv[-2], sys.argv[-1]
bpy.ops.wm.open_mainfile(filepath=src)
sc = bpy.context.scene
# Камеру и свет не берём: на сайте свой ракурс и освещение.
for o in list(bpy.data.objects):
    if o.type in {'CAMERA', 'LIGHT'}:
        bpy.data.objects.remove(o, do_unlink=True)
bpy.ops.export_scene.gltf(
    filepath=out,
    export_format='GLB',
    export_animations=True,
    export_animation_mode='SCENE',   # все объекты в одном клипе, как в ролике
    export_frame_range=True,
    export_force_sampling=True,
    export_optimize_animation_size=True,
    export_apply=True,
    export_yup=True,
    export_cameras=False,
    export_lights=False,
    export_draco_mesh_compression_enable=False,
)
print('exported', out)
