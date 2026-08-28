---
paths:
  - 'routes/api.php,app/Http/Controllers/Api/V1/**,app/Actions/Projects/**'
---

# Projects

## Keep the agent API versioned and atomic
Agent integrations use /api/v1. Batch project mutations require expected_revision, run in one database transaction, support dry_run, and advance the project revision once per committed batch. Temporary part and instance references are only valid later in the same batch.
