---
paths:
  - 'app/Models/ProjectConnection.php,app/Http/Requests/*ProjectConnectionRequest.php,app/Http/Controllers/ProjectConnectionController.php'
---

# Http Controllers

## Treat connections as project-scoped instance links
A ProjectConnection links two distinct PartInstance records from the same project. Reject either-order duplicates, cascade links when an instance is deleted, and increment the project revision on create, update, and delete.
