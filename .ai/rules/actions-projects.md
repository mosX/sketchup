---
paths:
  - 'resources/js/editor/script*.js,app/Actions/Projects/RunProjectScript.php'
---

# Actions Projects

## Keep project scripts isolated and replace only owned results
Script API identifiers and examples use English. Run user JavaScript only in a disposable worker created by an opaque sandboxed iframe with inherited deny-network CSP; never evaluate in the application window. Server validates creation-only commands and applies/replaces them transactionally. Preserve manually changed or externally referenced output by refusing replacement until explicit detach. Preview rolls back all writes; generated result IDs and fingerprints stay server-owned.
