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

## Keep the hover-only angle trigger reachable
Anchor the angle trigger just inside the plane's projected upper-right corner. Use a short delayed hover release and keep it visible while the pointer is over the trigger or menu, so crossing from the WebGL canvas to the HTML control cannot make it disappear.

## Keep the open cut editor stationary
The closed trigger follows the selected cut plane, but once opened the cut editor must stay fixed at the viewport's upper-right corner. Do not project the open panel from changing geometry; angle and depth sliders must remain stationary while CSG previews rebuild.
