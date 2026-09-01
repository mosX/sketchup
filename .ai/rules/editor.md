---
paths:
  - 'resources/js/pages/Editor.vue,resources/js/editor/useEditorHistory.js'
  - 'resources/js/pages/Editor.vue,resources/js/editor/instanceCopies.js'
---

# Editor

## Record editor changes as reversible commands
Use the shared editor history for part-draft snapshots and persisted assembly mutations. Flush pending part edits before save or undo, restore the relevant part/mode with snapshots, and make API-backed undo/redo use current instance IDs after recreation. Keep Ctrl+Z/Ctrl+Shift+Z out of focused text fields so native text editing still works.

## Create instance copies from immutable blueprints
Represent duplicate, mirrored copy, and linear-array members as position/rotation/mirrored blueprints. Create each blueprint through the normal instance API, roll back every fulfilled creation if any request fails, and keep the mutable returned instance IDs inside one undo/redo command so recreated copies remain reversible. Shift+D duplicates the selected assembly instance at the same transform; the user can then move it with G.
