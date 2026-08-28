---
paths:
  - 'resources/js/components/ThreeViewport.vue,resources/js/pages/Editor.vue,app/Http/Requests/ValidatesPartOperations.php'
---

# Http Requests

## Model partial saw cuts as kerf volumes
Cross and rip cuts carry cut_depth and cut_direction (top_down or bottom_up). A depth equal to part thickness remains a separating half-space cut; a shallower cut removes only the kerf volume within that depth band, and the keep-side control is relevant only for full-depth cuts.
