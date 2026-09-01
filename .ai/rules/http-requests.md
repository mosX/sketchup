---
paths:
  - 'resources/js/components/ThreeViewport.vue,resources/js/pages/Editor.vue,app/Http/Requests/ValidatesPartOperations.php'
---

# Http Requests

## Model partial saw cuts as kerf volumes
Cross and rip cuts carry cut_depth and cut_direction (top_down or bottom_up). A depth equal to part thickness remains a separating half-space cut; a shallower cut removes only the kerf volume within that depth band, and the keep-side control is relevant only for full-depth cuts.

## Model grooves in selected-face coordinates
Canonical groove operations use face (top/bottom/left/right/start/end), center_u, center_v, path_angle, groove_length, width, and depth. Interpret U/V in the selected face's local frame and depth along its inward normal. Keep legacy direction/offset/start/end grooves readable by normalizing them before geometry work.

## Represent edge roundovers by semantic edge and radius
Use edge_roundover with one of the 12 stock edge identifiers and a positive radius. Limit radius to half the smaller of the two stock dimensions meeting at that edge. Preview and apply the roundover as removed corner volume outside the tangent quarter-cylinder, and keep edge selection available directly in the viewport.
