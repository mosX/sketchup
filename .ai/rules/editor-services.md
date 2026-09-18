---
paths:
  - 'resources/js/components/ThreeViewport.vue,resources/js/editor/assemblyPlacement.js,app/Services/ProjectDiagnostics.php'
---

# Editor Services

## Assembly positions are stock centers in Z-up coordinates
Instance position is the stock center (X length, Y width, Z thickness/up). Do not add thickness/2 in assembly rendering or diagnostics. Newly inserted UI stock may explicitly use position_z=thickness/2 to rest on the floor. Part editing previews retain their separate floor offset. Exact placement uses unmachined stock faces/bounds, not CSG-created faces.
