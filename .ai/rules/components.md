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

## Coalesce CSG rebuilds and reuse cutters
Schedule reactive viewport rebuilds with a short trailing delay so slider events collapse into one CSG pass. Within each rebuild, construct each operation cutter once and clone it for subtraction/preview, then dispose the cache on the next rebuild and unmount. Only cross/rip cuts need a separate offcut CSG preview; groove and router cutters already visualize removed volume.

## Snap modal moves and accept numeric deltas
During G-axis movement, snap the selected bounding-box min/center/max to nearby instance anchors within 15 mm and show the target in the transform HUD; Ctrl temporarily disables snapping. After G/R then X/Y/Z, digit, minus, decimal point, and Backspace keys build an exact delta, and Enter commits it without snapping.

## Do not rebuild geometry on instance selection
Changing selectedInstanceId must only update selection materials/edges on existing assembly meshes. Keep it out of the deep scene-rebuild watcher so clicking parts never clears the viewport or restarts CSG work.
