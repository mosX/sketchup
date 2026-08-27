---
paths:
  - resources/js/components/ThreeViewport.vue
---

# Components

## Preview the selected operation's removed stock
In part mode, render the volume removed by the selected enabled operation as a translucent offcut with a dashed outline. Compute it from the stock state immediately before that operation so stacked cuts remain understandable.

## Reserve left click for scene selection
Do not bind plain left-drag to camera rotation. Rotate with middle-button drag or Alt+left-drag, keep right-drag for panning, and leave plain left click/drag available for parts, cut planes, and viewport tools.

## Show angle editor on demand at the cut plane
The angle editor stays closed until requested. Show its compact trigger only while hovering the active cut plane, anchor it to the plane's screen-space upper-right corner, and keep Shift+A as the keyboard toggle.
