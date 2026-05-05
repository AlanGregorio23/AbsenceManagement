# Absence Management

Web application for managing student absences, delays, leave requests, medical certificates, and monthly reports.

The project is developed with Laravel 12, Inertia.js, React, and Tailwind CSS.

## Main Features

- Authentication with roles: student, teacher, laboratory manager, and administrator.
- Creation and management of absences, delays, and leave requests.
- Request validation with checks on dates, hours, overlaps, and attachments.
- Guardian signature through a dedicated link.
- Management of medical certificates and deadlines.
- Leave request workflow with approval, rejection, documentation requests, and forwarding to management.
- Monthly PDF reports with upload of the signed report.
- Configurable internal notifications and emails.
- Operational logs, error logs, export, and automatic log cleanup.
- Configuration of absence rules, delay rules, login security, and school holidays.

## Repository Structure

```text
1_QdC/                    Task notebook
2_Abstract/                Project abstract
3_Documentation/           Technical and user documentation
4_Diaries/                 Work diaries
5_Sito/GestioneAssenze/    Laravel application
6_Database/                Database-related material
7_Attachments/             Project attachments
8_Manuals/                 User/installation manuals
```

Technical commands must be executed from:

```powershell
cd 5_Sito/GestioneAssenze
```

## Requirements

- PHP 8.2 or higher
- Composer
- Node.js and npm
- MySQL/MariaDB for the local environment
- PHP extensions required by Laravel, including `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `tokenizer`, `xml`, `ctype`, `json`

Automatic tests use in-memory SQLite, as configured in `phpunit.xml`.

## Installation

1. Enter the application folder:

```powershell
cd 5_Sito/GestioneAssenze
```

2. Install PHP dependencies:

```powershell
composer install
```

3. Install frontend dependencies:

```powershell
npm install
```

4. Create the `.env` file:

```powershell
copy .env.example .env
```

5. Generate the application key:

```powershell
php artisan key:generate
```

6. Configure the database in the `.env` file.

Local example:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gestioneassenze
DB_USERNAME=root
DB_PASSWORD=
```

7. Create the `gestioneassenze` database in MySQL/MariaDB.

8. Run migrations and seeders:

```powershell
php artisan migrate --seed
```

## Development Startup

Full startup with Laravel server, queue worker, and Vite:

```powershell
composer run dev
```

Alternatively, start each service separately:

```powershell
php artisan serve
npm run dev
php artisan queue:listen --tries=1 --timeout=0
```

The application will be available by default at:

```text
http://127.0.0.1:8000
```

## Production Build

```powershell
npm run build
```

To prepare cache and autoload for delivery/production:

```powershell
composer install --no-dev --optimize-autoloader
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Docker Deployment

The Docker configuration is located here:

```powershell
cd 5_Sito/GestioneAssenze/docker
```

First, prepare the Docker environment file:

```powershell
copy .env.docker.example .env.docker
```

If `APP_KEY` is left empty, Docker automatically generates it on the first startup and saves it in the shared application volume.

Start the local deployment:

```powershell
docker compose up -d --build
```

The application will be available at:

```text
http://localhost:8080
```

The compose file starts:

- `init`: Laravel bootstrap container that runs migrations before the other services;
- `app`: Laravel with Nginx and PHP-FPM;
- `queue`: worker for `QUEUE_CONNECTION=database`;
- `scheduler`: Laravel scheduler;
- `db`: MariaDB.

On startup, the `init` container automatically runs:

```powershell
php artisan migrate --force
```

Only after the migrations are completed, `app`, `queue`, and `scheduler` are started. This prevents services using `CACHE_STORE=database`, `SESSION_DRIVER=database`, and `QUEUE_CONNECTION=database` from starting while the required tables are still missing. If `APP_KEY` is not set, it is generated automatically during the Docker bootstrap and reused by all Laravel services.

To also load demo data:

```powershell
docker compose exec app php artisan db:seed --force
```

Useful commands:

```powershell
docker compose logs -f app
docker compose exec app php artisan migrate:status
docker compose down
```

In production, at least change `APP_URL`, `APP_KEY`, `DB_PASSWORD`, `MARIADB_PASSWORD`, `MARIADB_ROOT_PASSWORD`, and the email configuration in `.env.docker`.

## Demo Credentials

After running `php artisan migrate --seed`, the following users are available:

| Role | Email | Password |
|---|---|---|
| Administrator | `admin@cpt.local` | `Trevano26!` |
| Student | `alan.gregorio@example.com` | `Trevano26!` |
| Teacher | `paolo.rossi@example.com` | `Trevano26!` |
| Laboratory manager | `luca.galli@example.com` | `Trevano26!` |

These credentials are only for development and demonstration. Do not use them in production.

## Tests and Quality Checks

Run all tests:

```powershell
php artisan test
```

Check PHP style without modifying files:

```powershell
vendor\bin\pint --test
```

Automatically fix PHP style:

```powershell
vendor\bin\pint
```

Build the frontend:

```powershell
npm run build
```

## Scheduled Commands

The project defines several automatic tasks in `routes/console.php`, including:

- automatic marking of arbitrary absences;
- automatic marking of arbitrary delays;
- resending expired signature links;
- registration of absences derived from leave requests;
- generation of monthly reports;
- log cleanup according to the configured retention period;
- automatic guardian update for adult students.

In production, the Laravel scheduler must be enabled:

```powershell
php artisan schedule:run
```

Normally, this command is executed every minute by the operating system or hosting service.

## Operational Notes

- User-uploaded attachments are stored on the `local` disk.
- Emails in the local environment are configured with `MAIL_MAILER=log`.
- Queues use `QUEUE_CONNECTION=database`, so an active worker is required to process jobs.
- Tests use an in-memory SQLite database and do not modify the local MySQL database.
- Before delivery, it is recommended to run `php artisan test`, `vendor\bin\pint --test`, and `npm run build`.

## Project Documentation

Formal documentation, diaries, and manuals are located in:

- `3_Documentation/`
- `4_Diaries/`
- `8_Manuals/`

The main application code is located in `5_Sito/GestioneAssenze/`.
