# Fatya development requirements

Reference: `C:/Users/ABODY/Desktop/فاتيا/مرجع انشاء مشروع فاتيا.txt` (read before implementation). A portable copy is kept in `docs/project-requirements.txt`; use it if the original path is unavailable.

The user authorized migration to the latest stable Laravel and compatible MariaDB, preserving all current design and functionality. Keep Vue 3 + Vite components for the dashboard and customer PWA; no separate hand-built HTML pages. Web rendering still uses HTML. Both interfaces use the same backend API. Prioritize performance, persistent shared data, secure authentication and authorization, and meaningful tests. Never promise protection against all unknown vulnerabilities.

Existing GitHub Pages deployment is a browser-local static preview. Production publishing and hosting changes require the user's deployment instruction. No production credentials in Git.
