# Absence Management

Web application for managing student absences, late arrivals, leave requests, medical certificates, and monthly reports.

The project is built with Laravel 12, Inertia.js, React, and Tailwind CSS.

## Main Features

- Role-based authentication for students, teachers, laboratory heads, and administrators.
- Creation and management of absences, late arrivals, and leave requests.
- Request validation with checks for dates, times, overlaps, and attachments.
- Guardian signature through a dedicated link.
- Medical certificate management with expiration tracking.
- Leave request workflow with approval, rejection, documentation, and forwarding to management.
- Monthly PDF reports with upload of the signed report.
- Configurable internal notifications and email messages.
- Operational logs, error logs, exports, and automatic log cleanup.
- Configuration for absence rules, late arrival rules, login security, and school holidays.

## Repository Structure

```text
1_QdC/                    Task notebook
2_Abstract/                Project abstract
3_Documentazione/          Technical and user documentation
4_Diari/                   Work diaries
5_Sito/GestioneAssenze/    Laravel application
5_Sito/Deploy/             Docker deployment configuration
6_Database/                Database-related material
7_Allegati/                Project attachments
8_Manuali/                 User and installation manuals
```

Run technical commands from:

```powershell
cd 5_Sito/GestioneAssenze
```

## Requirements

- PHP 8.2 or higher
- Composer
- Node.js and npm
- MySQL/MariaDB for the local environment
- PHP extensions required by Laravel, including `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `tokenizer`, `xml`, `ctype`, and `json`

Automated tests use an in-memory SQLite database, as configured in `phpunit.xml`.

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

## Development

Start the full development stack with the Laravel server, queue worker, and Vite:

```powershell
composer run dev
```

Alternatively, start each service separately:

```powershell
php artisan serve
npm run dev
php artisan queue:listen --tries=1 --timeout=0
```

By default, the application is available at:

```text
http://127.0.0.1:8000
```

## Production Build

```powershell
npm run build
```

To prepare cache and autoload files for delivery or production:

```powershell
composer install --no-dev --optimize-autoloader
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Docker Deployment

The Docker configuration is located in:

```powershell
cd 5_Sito/Deploy
```

First, create the Docker environment file:

```powershell
copy .env.docker.example .env.docker
```

Generate a stable Laravel key:

```powershell
docker compose run --rm --no-deps app php artisan key:generate --show
```

Copy the printed value into `APP_KEY=` in the `.env.docker` file.

Start the local deployment:

```powershell
docker compose up -d --build
```

The application will be available at:

```text
http://localhost:8080
```

The compose setup starts:

- `app`: Laravel with Nginx and PHP-FPM;
- `queue`: worker for `QUEUE_CONNECTION=database`;
- `scheduler`: Laravel scheduler;
- `db`: MariaDB.

On first startup, the `app` container automatically runs:

```powershell
php artisan migrate --force
```

To load demo data as well:

```powershell
docker compose exec app php artisan db:seed --force
```

Useful commands:

```powershell
docker compose logs -f app
docker compose exec app php artisan migrate:status
docker compose down
```

In production, change at least `APP_URL`, `APP_KEY`, `DB_PASSWORD`, `MARIADB_PASSWORD`, `MARIADB_ROOT_PASSWORD`, and the email configuration in `.env.docker`.

## Demo Credentials

After running `php artisan migrate --seed`, these users are available:

| Role | Email | Password |
|---|---|---|
| Administrator | `admin@example.com` | `Admin$00` |
| Student | `alan.gregorio@example.com` | `Admin$00` |
| Teacher | `paolo.rossi@example.com` | `Admin$00` |
| Laboratory head | `luca.galli@example.com` | `Admin$00` |

These credentials are for development and demonstration only. Do not use them in production.

## Tests and Quality Checks

Run all tests:

```powershell
php artisan test
```

Check PHP code style without modifying files:

```powershell
vendor\bin\pint --test
```

Automatically fix PHP code style:

```powershell
vendor\bin\pint
```

Build the frontend:

```powershell
npm run build
```

## Scheduled Commands

The project defines several automated tasks in `routes/console.php`, including:

- automatic marking of arbitrary absences;
- automatic marking of arbitrary late arrivals;
- resending expired signature links;
- recording absences derived from leave requests;
- generating monthly reports;
- cleaning logs according to the configured retention period;
- automatically updating the guardian for students who have reached legal age.

In production, the Laravel scheduler must be enabled:

```powershell
php artisan schedule:run
```

This command is normally executed every minute by the operating system or hosting service.

## Operational Notes

- User-uploaded attachments are stored on the `local` disk.
- In the local environment, emails are configured with `MAIL_MAILER=log`.
- Queues use `QUEUE_CONNECTION=database`, so an active worker is required to process jobs.
- Tests use an in-memory SQLite database and do not modify the local MySQL database.
- Before delivery, it is recommended to run `php artisan test`, `vendor\bin\pint --test`, and `npm run build`.

## Project Documentation

Formal documentation, diaries, and manuals are stored in:

- `3_Documentazione/`
- `4_Diari/`
- `8_Manuali/`

The main application code is located in `5_Sito/GestioneAssenze/`.
