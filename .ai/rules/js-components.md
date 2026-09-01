---
paths:
  - 'resources/js/editor/geometry/**,resources/js/workers/csg.worker.js,resources/js/components/ThreeViewport.vue'
---

# Js Components

## Run exact CSG in a cancellable Web Worker
Keep face-coordinate and cutter construction in editor/geometry modules shared by previews and the worker. Exact part/offcut booleans run in csg.worker.js; cancel and recreate the worker when a newer viewport rebuild supersedes pending work. In assembly mode calculate one geometry per part definition and reuse it for all instances; keep plunge-route interaction on a non-CSG merged preview.
