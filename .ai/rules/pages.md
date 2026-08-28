---
paths:
  - 'resources/js/components/ThreeViewport.vue,resources/js/pages/Editor.vue'
---

# Pages

## Keep woodworking operations directly manipulable in the scene
Cut planes and groove helpers must remain selectable and draggable in the 3D viewport. Keep the inspector fields as the precise numeric fallback, and preserve scene angle controls plus Shift/Alt arrow shortcuts when extending operations.

## Treat selected operations as edit previews
Only the selected operation shows its cut plane and translucent offcut. After saving the part, clear the selected operation so all editing helpers disappear while the resulting geometry remains; re-enter editing by selecting an operation in the stack.

## Use Blender-style modal transforms for assembly instances
In assembly mode, G then X/Y/Z moves the selected instance on a world axis; R then X/Y/Z rotates it. Preview locally in Three.js, commit once on left click or Enter, cancel on Escape or right click, and retain inspector inputs for exact values.

## Use Z-up editor coordinates
Assembly coordinates use X and Y on the floor plane and Z as height. Three.js is Y-up, so map editor (x, y, z) to scene (x, z, y) consistently for instance positions, rotations, transform guides, and pointer projection; keep persisted API fields in editor coordinates.
