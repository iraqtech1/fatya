# Fatya development requirements

Reference: `docs/project-requirements.txt` (read before implementation). This portable copy preserves the user's project requirements; the original reference file remains on the original workstation and must be preserved.

The user authorized migration to the latest stable Laravel and compatible MariaDB, preserving all current design and functionality. Keep Vue 3 + Vite components for the dashboard and customer PWA; no separate hand-built HTML pages. Web rendering still uses HTML. Both interfaces use the same backend API. Prioritize performance, persistent shared data, secure authentication and authorization, and meaningful tests. Never promise protection against all unknown vulnerabilities.

Existing GitHub Pages deployment is a browser-local static preview. Production publishing and hosting changes require the user's deployment instruction. No production credentials in Git.

For continuation on another workstation, read `docs/project-handoff-ar.txt` for completed work, validation and priorities, and `docs/company-setup-ar.txt` for Windows setup. These documents describe the implementation checkpoint; inspect current Git history and working-tree changes before continuing. Real payment-gateway integration remains deferred until the user supplies its details. Preserve the design and encrypted data keys.
