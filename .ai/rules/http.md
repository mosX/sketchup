---
paths:
  - 'app/Http/**'
---

# Http

## Canonical drill operation contract
The `drill` part operation uses `face`, `center_u`, `center_v`, `diameter`, `depth`, and boolean `through`. U/V are local to the selected face; the full circle must remain inside that face, and depth may not exceed its normal dimension. Keep API capabilities and `schema_version` synchronized when this contract changes.
