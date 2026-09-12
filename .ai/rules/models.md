---
paths:
  - 'app/Services/ProjectTemplateManager.php,app/Models/ProjectTemplate.php'
---

# Models

## Scale templates in project coordinates
Parametric templates use width=X, depth=Y, height=Z. Scale instance positions about the captured assembly minimum bounds. For non-uniform scaling, split a reused part definition into orientation-specific variants so rotated instances receive physically consistent local dimensions.
