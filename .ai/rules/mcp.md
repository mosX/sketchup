---
paths:
  - 'app/Mcp/**,routes/ai.php'
---

# Mcp

## Keep native MCP tools behind the versioned API routes
Woodworking uses Laravel MCP Streamable HTTP at /api/mcp/woodworking with Sanctum. Preserve existing woodworking tool names and input schemas. Dispatch only fixed /api/v1 routes in-process so API authorization, project scopes, validation, and atomic batch dry runs remain authoritative.
