# Fatya backend

Laravel 13 API with MariaDB. Frontend components and build tooling live at the repository root.

See [project setup](../README.md) and [deployment and SQLite migration](../DEPLOYMENT.md).

Copy `.env.example` to `.env`, configure a dedicated MariaDB database and private administrator password, then run `composer install`, `php artisan key:generate`, `php artisan migrate` and `php artisan fatya:seed`.

Serve only `public/`. Keep `APP_KEY` stable to preserve encrypted inventory. Never publish `.env`, database backups, logs, session files or the source tree through the web root.
