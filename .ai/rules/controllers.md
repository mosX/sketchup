---
paths:
  - 'routes/api.php,app/Http/Controllers/ProjectTemplateController.php,mcp-server/**'
---

# Controllers

## Limit templates to global API keys
Project-template endpoints are available to browser sessions and global projects:write API keys. Project-scoped tokens must not list, capture, instantiate, or delete account-wide templates.
