---
paths:
  - 'app/Http/Requests/**,resources/js/**'
---

# Js

## Model plunge routing as a face-local swept cutter
Use operation type `plunge_route` with `face`, `route_mode` (`point` or `path`), `start_u`, `start_v`, `path_angle`, `travel_length`, `cutter_diameter`, and `depth`. U/V are local to the selected face; depth follows its inward normal. `travel_length` is the cutter-center travel (zero for point mode), and the full radius at both endpoints must remain within the face.

## Keep plunge-router profiles physically consistent
`plunge_route.cutter_profile` is `straight`, `dovetail`, or `v_groove`, with `cutter_angle` zero for straight, side-wall angle for dovetail, and included angle for V-groove. Treat `cutter_diameter` as the constant diameter for straight and maximum diameter for profiled cutters. Validate depth against the selected diameter/angle and use the widest resulting radius for face-bound checks.
