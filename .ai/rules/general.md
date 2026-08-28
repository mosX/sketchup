---
paths:
  - vite.config.js
---

# General

## Serve Vite over the project HTTPS domain
The application is opened through OSPanel at https://sketchup. Vite dev assets and HMR must also use HTTPS/WSS with the OSPanel project certificate; an HTTP hot URL is blocked as mixed content and leaves a white page.
