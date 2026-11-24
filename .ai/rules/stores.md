---
paths:
  - 'resources/js/components/ThreeViewport.vue,resources/js/pages/Editor.vue,resources/js/editor/assemblyMove.js,resources/js/stores/projects.js'
---

# Stores

## Move selected assembly nodes as an atomic translation
Use physical key codes for assembly transform shortcuts in non-Latin layouts. G translates all descendants of a selected node by one world-axis delta. Preview only changes meshes, and commit uses one revision-checked API command batch with one undo entry. A locked descendant or a batch above the API limit blocks the whole move.
