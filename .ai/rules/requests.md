---
paths:
  - 'resources/js/pages/Editor.vue,app/Http/Requests/ValidatesPartOperations.php'
---

# Requests

## Separate operation drafts from applied history
New or reopened operations use status=draft and appear in the current list with 3D helpers. Applying the part persists them as status=applied, clears the active preview, and moves them into a collapsed processing history; selecting a history item returns it to draft editing.
