---
paths:
  - 'resources/js/pages/Editor.vue,resources/js/components/ThreeViewport.vue,resources/js/editor/assemblyTree.js'
---

# Js Editor

## Navigate assembly groups one level at a time
The assembly sidebar shows immediate child groups before direct instances. A scene pick resolves to the immediate child at the current level; double-click enters that child group. Group selection highlights descendants without selecting an instance. Opened-group context dims outside meshes and excludes them from selection; temporary context never changes saved visibility or rebuilds CSG.
