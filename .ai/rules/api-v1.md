---
paths:
  - 'app/Services/ProjectManufacturingReport.php,app/Http/Controllers/Api/V1/ProjectAnalysisController.php'
---

# Api V1

## Treat cutting maps as preliminary
Project analysis exposes BOM plus first-fit plans using 6000 mm linear stock, 2440x1220 mm sheets, 3.2 mm kerf, and 10 mm margins. Present these as preliminary until configurable stock, grain, defects, and machining allowances are implemented.

## Validate configurable cutting inputs
Project analysis accepts validated kerf, edge margin, stock length, and sheet dimensions. Pass these settings into every packing calculation and echo the effective values in manufacturing assumptions.
