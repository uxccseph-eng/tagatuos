# Tagatuos

A Laravel 13 starter for shared hosting, with the supplied Kontadurya landing-page design and a CSRF-protected business form. Created using Composer's `create-project` command. Installed framework: **13.36.0**, the latest stable release available when set up on October 16, 2026. `composer.lock` records the exact dependencies.

## 1. Prerequisites

Install **PHP 8.3 or newer**, **Composer 2**, and optionally MySQL locally. A MySQL server is required when you start using database-backed features. The homepage and demo form work without a database connection.

- Windows: download PHP from [PHP for Windows](https://windows.php.net/download/) and follow the [PHP installation guide](https://www.php.net/manual/en/install.windows.php). Install [Composer](https://getcomposer.org/doc/00-intro.md#installation-windows) using Composer-Setup.exe, selecting your PHP executable.
- macOS/Linux: follow [Laravel's installation guide](https://laravel.com/docs/13.x/installation) for your operating system, then the [Composer installation instructions](https://getcomposer.org/download/).
- Hosting: select PHP 8.3+ for the website and CLI. Required extensions include Ctype, cURL, DOM, Fileinfo, Filter, Hash, Mbstring, OpenSSL, PCRE, PDO, Session, Tokenizer, XML, and **pdo_mysql**. ZIP is useful for Composer downloads. Use `php -m` to check extensions.

Verify your installation in a new terminal:

```sh
php -v
composer --version
php -m
```

For this workspace, a verified portable PHP 8.4 runtime and Composer were installed at `C:\Users\s\AppData\Local\CodexTools\php` without changing system PATH. In PowerShell, enable them for the current terminal:

```powershell
$runtimeDir = Join-Path $env:LOCALAPPDATA 'CodexTools\php'
$env:PATH = "$runtimeDir;$env:PATH"
function composer { & "$env:LOCALAPPDATA\CodexTools\php\php.exe" "$env:LOCALAPPDATA\CodexTools\php\composer.phar" @args }
php -v
composer --version
```

## 2. Install and run this prepared project

Open a terminal in this project (it already has its dependencies and a generated local application key):

```powershell
cd C:\Users\s\Documents\ChatGPT\tagatuos
composer install
php -r "file_exists('.env') || copy('.env.example', '.env');"
```

On a **new copy only**, generate the application key if `APP_KEY` is empty:

```sh
php artisan key:generate
```

Start the local PHP server:

```sh
php artisan serve --host=127.0.0.1 --port=8000
```

Open **http://localhost:8000**. Enter a business type and press **Generate**. The response is plain text: `Generated successfully`. Use the browser Back button to return. Stop the server with Ctrl+C. `APP_URL=http://localhost` remains as requested; the browser's actual request determines form and asset URLs when using port 8000.

No Node.js, npm, Vite, manifest, or frontend build is needed. `composer run dev` also starts the PHP server directly.

### Creating a fresh stock scaffold

For a separate new project, run this from its parent directory:

```sh
composer create-project laravel/laravel tagatuos --prefer-dist --no-scripts
cd tagatuos
```

Omitting a version constraint lets Composer select the latest stable compatible release. Use PHP 8.3+ so it can select Laravel 13. `--no-scripts` prevents the stock automatic SQLite migration; it does not skip PHP dependency installation.

This command creates Laravel's stock template. Apply the prepared files from this repository to obtain this CDN-only setup: replace `composer.json` and `composer.lock`, `.env.example`, and the key files below; remove `package.json`, `vite.config.js`, `.npmrc`, the stock welcome view, and the stock `resources/css/app.css` and `resources/js/app.js`. Then run:

```sh
composer install
php -r "file_exists('.env') || copy('.env.example', '.env');"
php artisan key:generate
php artisan serve
```

## 3. Environment and MySQL

Both the local `.env` and committed `.env.example` use:

```dotenv
APP_NAME=Tagatuos
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database_name
DB_USERNAME=your_database_user
DB_PASSWORD=your_database_password

SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
```

The actual local `.env` has a generated key. `.env.example` intentionally leaves it empty. `.env` is ignored by Git.

Create a MySQL database and a user with privileges on that database. Replace the placeholders in `.env`. On shared hosting, use the exact database hostname from the hosting panel; it may differ from `localhost`. Quote passwords containing spaces or `#`, for example `DB_PASSWORD="your password#123"`.

After changing `.env`, clear cached configuration:

```sh
php artisan config:clear
```

Once MySQL credentials are valid, install Laravel's standard tables if you need them:

```sh
php artisan migrate
```

These tables are optional for the current demo. Nothing in the controller reads or writes a database. File sessions/cache and synchronous queues avoid requiring database tables, Redis, or a background worker.

## 4. Application structure

All application folders and source files are shown below. The `.git` internals, generated cache/log/session files, and individual Composer packages inside `vendor/` are omitted. Storage/cache folders contain `.gitignore` placeholders so writable directories survive cloning.

```text
tagatuos/
|-- .editorconfig
|-- .env                       # Local secrets; ignored by Git
|-- .env.example
|-- .gitattributes
|-- .gitignore
|-- AGENTS.md                  # Generated development guidelines
|-- CLAUDE.md                  # Generated development guidelines
|-- README.md
|-- artisan
|-- boost.json                 # Development tooling configuration
|-- composer.json
|-- composer.lock
|-- phpunit.xml
|-- app/
|   |-- Http/Controllers/
|   |   |-- Controller.php
|   |   `-- HomeController.php
|   |-- Models/User.php
|   `-- Providers/AppServiceProvider.php
|-- bootstrap/
|   |-- app.php
|   |-- providers.php
|   `-- cache/
|-- config/
|   |-- app.php
|   |-- auth.php
|   |-- cache.php
|   |-- database.php
|   |-- filesystems.php
|   |-- logging.php
|   |-- mail.php
|   |-- queue.php
|   |-- services.php
|   `-- session.php
|-- database/
|   |-- .gitignore
|   |-- factories/UserFactory.php
|   |-- migrations/
|   |   |-- 0001_01_01_000000_create_users_table.php
|   |   |-- 0001_01_01_000001_create_cache_table.php
|   |   `-- 0001_01_01_000002_create_jobs_table.php
|   `-- seeders/DatabaseSeeder.php
|-- public/                    # The only web-accessible directory
|   |-- .htaccess
|   |-- favicon.ico
|   |-- index.php
|   |-- robots.txt
|   |-- css/app.css
|   `-- js/app.js
|-- resources/
|   `-- views/home.blade.php
|-- routes/
|   |-- console.php
|   `-- web.php
|-- storage/
|   |-- app/
|   |   |-- private/
|   |   `-- public/
|   |-- framework/
|   |   |-- cache/
|   |   |   |-- data/
|   |   |   `-- locks/
|   |   |-- sessions/
|   |   |-- testing/
|   |   `-- views/
|   `-- logs/
|-- tests/
|   |-- TestCase.php
|   |-- Feature/
|   |   |-- ExampleTest.php
|   |   `-- HomeTest.php
|   `-- Unit/ExampleTest.php
`-- vendor/                    # Generated by composer install; ignored by Git
```

`public/css/app.css` and `public/js/app.js` are ordinary static files, loaded with `asset()`. Tailwind loads from the browser CDN used by the supplied design. Google Fonts provides the typography and icons. The Blade template uses named routes, escaped old input, a visible label, accessible validation errors, `@csrf`, and a POST form. The controller validates a required string of at most 255 characters.

**Production styling limitation:** the requested Tailwind browser CDN performs compilation in the browser. Tailwind explicitly describes Play CDN as development-only. This starter honors the requested CDN setup, but cannot honestly claim production-grade Tailwind delivery. Before a public production launch, replace it with precompiled CSS generated on a development/build machine (a standalone Tailwind CLI can avoid Node.js), serve that CSS through `asset()`, and remove the CDN script. Hosting itself still needs no Node.js. The present setup also depends on visitors reaching the external CDN.

## 5. Shared-hosting deployment (DreamHost-style)

### Preferred: point the domain at public/

1. Enable SSH/SFTP, create the MySQL database/user in the hosting panel, and select PHP 8.3+.
2. Upload the application to, for example, `/home/youruser/tagatuos`.
3. Set the domain's web/document-root directory to **`/home/youruser/tagatuos/public`**. Keep Laravel's original `public/index.php` and `.htaccess`. Apache must support rewrite rules and `.htaccess` overrides.
4. Keep `.env`, `vendor`, `storage`, and the application source outside the document root. Do not point the domain at the project root.
5. In the private project root, create `.env` from `.env.example`, fill the MySQL credentials, and apply these production settings:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example
SESSION_SECURE_COOKIE=true
LOG_LEVEL=warning
```

Enable HTTPS for the domain before using secure cookies. Keep `SESSION_DRIVER=file`, `CACHE_STORE=file`, and `QUEUE_CONNECTION=sync` for this starter.

From SSH, inside the private project root:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
php artisan key:generate --force
chmod 600 .env
chmod -R u+rwX storage bootstrap/cache
```

Generate the production key **once for a new deployment**. Preserve it during updates; changing it invalidates existing encrypted data and sessions. `chmod` above assumes PHP runs as your account user, as is common on shared hosting. If your host uses another user/group, ask for its required ownership/group permissions; avoid permission 777.

If you need the standard database tables, configure MySQL and then run:

```sh
php artisan migrate --force
```

Cache production configuration, routes, and Blade templates **on the destination server after its `.env` is configured**:

```sh
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Use a fresh deployment without development-generated cache files. For an update, clear stale compiled configuration/routes/views with `php artisan optimize:clear` before rebuilding caches. Rebuild them after `.env` changes. Do not use `php artisan serve` as a production web server; the host's Apache/PHP handles requests.

DreamHost's CLI PHP may differ from the website PHP. If necessary, select it explicitly, for example `/usr/local/php83/bin/php artisan route:list`, and invoke Composer with the same PHP version. Confirm available paths in the host's documentation.

If the server has no Composer, run the production Composer install in a separate local release copy targeting PHP 8.3, then upload that copy **including `vendor/`**. Do not upload your local `.env` or cached files. Run `composer check-platform-reqs --no-dev` on the server if Composer is available, or have the host verify the PHP version/extensions.

The current app needs no writable public storage symlink, cron, queue worker, npm process, or Node.js service. Add those only when a future feature needs them.

### Alternative: a fixed public_html directory

For a host that cannot change the document root, use sibling directories:

```text
/home/youruser/
|-- tagatuos/                   # Private application
`-- public_html/                # Only the contents of tagatuos/public/
```

Copy **all contents** of `tagatuos/public/`, including hidden `.htaccess`, into `public_html/`. In the copied `public_html/index.php`, change these three paths:

```php
__DIR__.'/../storage/framework/maintenance.php'
// becomes:
__DIR__.'/../tagatuos/storage/framework/maintenance.php'

__DIR__.'/../vendor/autoload.php'
// becomes:
__DIR__.'/../tagatuos/vendor/autoload.php'

__DIR__.'/../bootstrap/app.php'
// becomes:
__DIR__.'/../tagatuos/bootstrap/app.php'
```

Also update the private `tagatuos/bootstrap/app.php`: replace the initial `return Application::configure(...)` with `$app = Application::configure(...)`, keep the existing configuration chain through `->create();`, and append:

```php
$app->usePublicPath(dirname(__DIR__, 2).'/public_html');

return $app;
```

These paths assume exactly the sibling layout above. This keeps Artisan's public path aligned with the web directory. Do not copy the entire Laravel application into `public_html`. Prefer the normal `public/` document root whenever the host supports it.

## 6. Verification and troubleshooting

```sh
php artisan test --compact
php artisan route:list --except-vendor
composer validate --strict
composer audit
```

Feature tests cover the homepage, required/string/length validation, preserved invalid input, the exact dummy response, missing-token rejection, and matching-token acceptance. The CSRF tests explicitly leave the testing environment so Laravel's usual test bypass cannot hide a disabled CSRF check. Standard web middleware remains enabled.

- **419 Page Expired:** reload the homepage and submit again; check writable session storage, cookies, the domain, and HTTPS/secure-cookie settings. Keep `@csrf` enabled.
- **500 error:** inspect private `storage/logs/laravel.log`, confirm `APP_KEY`, PHP extensions, and permissions. Keep production debugging disabled.
- **Database error:** replace all placeholder credentials and verify the host's database hostname. The demo itself does not need migrations.
- **Routes return 404:** confirm the domain points at `public/` and `.htaccess` rewrite support is enabled.
- **Unstyled page:** check CDN access and the browser console. There is no Vite manifest to generate.

## References

- [Laravel 13 release requirements](https://laravel.com/docs/13.x/releases)
- [Laravel deployment and directory permissions](https://laravel.com/docs/13.x/deployment)
- [Tailwind Play CDN and its development-only guidance](https://tailwindcss.com/docs/installation/play-cdn)
- [DreamHost supported technologies](https://help.dreamhost.com/hc/en-us/articles/217141627-Supported-and-unsupported-technologies)
- [DreamHost command-line PHP](https://help.dreamhost.com/hc/en-us/articles/214202238-Command-line-PHP-overview)

## 7. Key files

- [Routes](routes/web.php)
- [Controller](app/Http/Controllers/HomeController.php)
- [Complete Blade homepage](resources/views/home.blade.php)
- [Static styles](public/css/app.css)
- [Preview interactions](public/js/app.js)

The homepage preserves the supplied Kontadurya branding, hero, spatial ledger preview, feature cards, workflow, free-tier pricing, FAQ, and footer. Mobile navigation, sample ledger tabs, preview confirmation, and informational dialogs work without a build step. Calls to action lead to the existing starter form. Accounting, account registration, and filing features remain previews.