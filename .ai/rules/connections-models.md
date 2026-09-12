---
paths:
  - 'app/Actions/Connections/**,app/Models/ProjectConnection.php'
---

# Connections Models

## Generate connection machining atomically
Connection machining appends applied operations to both linked parts in one transaction and increments the project revision once. Before instance-specific machining, clone any PartDefinition used by multiple instances and reassign only the connected instance. Persist generated operation references and treat non-pending statuses as idempotent.
