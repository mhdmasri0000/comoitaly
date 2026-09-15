# COM-O Laravel API

Laravel implementation workspace for the COM-O mobile commerce API. The API is mounted under `/api` and configured for MySQL only.

## Local setup

1. Create the MySQL database `como_app`.
2. Copy `.env.example` to `.env` and set the MySQL credentials.
3. Run `composer install`.
4. Run the agreed database migrations when the schema is approved.
5. Start the API with `php artisan serve --port=3000`.

The current application slice includes health, Sanctum-based registration/login/logout, user profile, public categories, and product endpoints. Order, cart, notification, admin, analytics, and the final database schema remain pending the source implementation and migration decision described in `LARAVEL_MIGRATION_BRIEF.md`.

No SQLite connection or migration command is used by this project configuration.
