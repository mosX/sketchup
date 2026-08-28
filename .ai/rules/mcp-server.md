---
paths:
  - 'mcp-server/**'
---

# Mcp Server

## Keep MCP as a thin Laravel API adapter
The MCP process never reads the database. It receives SKETCHUP_API_TOKEN and SKETCHUP_API_URL through the environment and exposes semantic tools backed only by /api/v1 endpoints; Laravel remains responsible for validation, authorization, transactions, and project-scoped access.
