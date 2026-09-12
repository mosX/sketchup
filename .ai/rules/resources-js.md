---
paths:
  - 'resources/js/**'
---

# Resources Js

## Isolation does not mutate saved visibility
Assembly `Solo` and `Open as product` are temporary viewport scopes. They must not rewrite `is_visible` on any group. Opening a group shows that root even when it or an ancestor is hidden globally, while descendant visibility and group locks remain effective.

## Keep ghost transparency viewport-only
Assembly ghost mode is temporary viewport state for groups and individual instances. Group ghosting applies to all descendant instances, must not mutate persisted is_visible, and changing ghost state must update mesh materials without rebuilding scene geometry.
