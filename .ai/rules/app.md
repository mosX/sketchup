---
paths:
  - 'app/**'
---

# App

## Assembly groups preserve woodworking definitions
`AssemblyGroup` is a nested organizational node for `PartInstance` records. Deleting a group without `delete_contents=true` reparents child groups and instances to the deleted group's parent; explicit content deletion removes descendant instances but never their reusable `PartDefinition` records. Group IDs must always be scoped to the current project.
