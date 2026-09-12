---
paths:
  - 'resources/js/pages/Editor.vue,mcp-server/**,app/Http/Controllers/Api/V1/CapabilityController.php'
---

# Controllers Api V1

## Expose semantic machining generation
Connections expose pending, generated, or not_required machining status. UI and MCP trigger the Laravel machining endpoint, then refresh parts because generation may split a shared definition. Keep capability schema and MCP tool synchronized.
