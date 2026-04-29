# Gestione Assenze

Applicazione web per la gestione di assenze, ritardi, congedi, certificati medici e report mensili degli allievi.

Il progetto e sviluppato con Laravel 12, Inertia.js, React e Tailwind CSS.

## Funzionalita principali

- Autenticazione con ruoli: studente, docente, capo laboratorio e amministratore.
- Inserimento e gestione di assenze, ritardi e congedi.
- Validazione delle richieste con controlli su date, ore, sovrapposizioni e allegati.
- Firma del tutore tramite link dedicato.
- Gestione certificati medici e scadenze.
- Workflow dei congedi con approvazione, rifiuto, documentazione e inoltro alla direzione.
- Report mensili in PDF con upload del report firmato.
- Notifiche interne ed email configurabili.
- Log operativi, log errori, export e pulizia automatica dei log.
- Configurazione di regole assenze, ritardi, sicurezza login e vacanze scolastiche.

## Struttura del repository

```text
1_QdC/                    Quaderno dei compiti
2_Abstract/                Abstract del progetto
3_Documentazione/          Documentazione tecnica e utente
4_Diari/                   Diari di lavoro
5_Sito/GestioneAssenze/    Applicazione Laravel
6_Database/                Materiale relativo al database
7_Allegati/                Allegati di progetto
8_Manuali/                 Manuali utente/installazione
```

I comandi tecnici vanno eseguiti da:

```powershell
cd 5_Sito/GestioneAssenze
```

## Requisiti

- PHP 8.2 o superiore
- Composer
- Node.js e npm
- MySQL/MariaDB per l'ambiente locale
- Estensioni PHP richieste da Laravel, incluse `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `tokenizer`, `xml`, `ctype`, `json`

Per i test automatici viene usato SQLite in memoria, come configurato in `phpunit.xml`.

## Installazione

1. Entrare nella cartella dell'applicazione:

```powershell
cd 5_Sito/GestioneAssenze
```

2. Installare le dipendenze PHP:

```powershell
composer install
```

3. Installare le dipendenze frontend:

```powershell
npm install
```

4. Creare il file `.env`:

```powershell
copy .env.example .env
```

5. Generare la chiave applicativa:

```powershell
php artisan key:generate
```

6. Configurare il database nel file `.env`.

Esempio locale:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gestioneassenze
DB_USERNAME=root
DB_PASSWORD=
```

7. Creare il database `gestioneassenze` in MySQL/MariaDB.

8. Eseguire migration e seeder:

```powershell
php artisan migrate --seed
```

## Avvio in sviluppo

Avvio completo con server Laravel, queue worker e Vite:

```powershell
composer run dev
```

In alternativa, avvio separato:

```powershell
php artisan serve
npm run dev
php artisan queue:listen --tries=1 --timeout=0
```

L'applicazione sara disponibile di default su:

```text
http://127.0.0.1:8000
```

## Build produzione

```powershell
npm run build
```

Per preparare cache e autoload in ambiente di consegna/produzione:

```powershell
composer install --no-dev --optimize-autoloader
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Deploy con Docker

La configurazione Docker si trova qui:

```powershell
cd 5_Sito/GestioneAssenze/docker
```

Prima preparare il file ambiente Docker:

```powershell
copy .env.docker.example .env.docker
```

Se `APP_KEY` resta vuoto, Docker la genera automaticamente al primo avvio e la salva nel volume condiviso dell'applicazione.

Avviare il deploy locale:

```powershell
docker compose up -d --build
```

L'applicazione sara disponibile su:

```text
http://localhost:8080
```

Il compose avvia:

- `init`: bootstrap Laravel che esegue le migration prima degli altri servizi;
- `app`: Laravel con Nginx e PHP-FPM;
- `queue`: worker per `QUEUE_CONNECTION=database`;
- `scheduler`: scheduler Laravel;
- `db`: MariaDB.

Alla partenza il container `init` esegue automaticamente:

```powershell
php artisan migrate --force
```

Solo dopo il completamento delle migration vengono avviati `app`, `queue` e `scheduler`, cosi i servizi che usano `CACHE_STORE=database`, `SESSION_DRIVER=database` e `QUEUE_CONNECTION=database` non partono con le tabelle ancora mancanti. Se `APP_KEY` non e impostata, viene generata automaticamente durante il bootstrap Docker e riutilizzata da tutti i servizi Laravel.

Per caricare anche i dati demo:

```powershell
docker compose exec app php artisan db:seed --force
```

Comandi utili:

```powershell
docker compose logs -f app
docker compose exec app php artisan migrate:status
docker compose down
```

In produzione cambiare almeno `APP_URL`, `APP_KEY`, `DB_PASSWORD`, `MARIADB_PASSWORD`, `MARIADB_ROOT_PASSWORD` e la configurazione email in `.env.docker`.

## Credenziali demo

Dopo `php artisan migrate --seed` sono disponibili questi utenti:

| Ruolo | Email | Password |
|---|---|---|
| Amministratore | `admin@cpt.local` | `Trevano26!` |
| Studente | `alan.gregorio@example.com` | `Trevano26!` |
| Docente | `paolo.rossi@example.com` | `Trevano26!` |
| Capo laboratorio | `luca.galli@example.com` | `Trevano26!` |

Le credenziali sono solo per sviluppo e dimostrazione. Non usarle in produzione.

## Test e controllo qualita

Eseguire tutti i test:

```powershell
php artisan test
```

Controllare lo stile PHP senza modificare i file:

```powershell
vendor\bin\pint --test
```

Correggere automaticamente lo stile PHP:

```powershell
vendor\bin\pint
```

Compilare il frontend:

```powershell
npm run build
```

## Comandi schedulati

Il progetto definisce diversi task automatici in `routes/console.php`, tra cui:

- marcatura automatica di assenze arbitrarie;
- marcatura automatica di ritardi arbitrari;
- reinvio dei link firma scaduti;
- registrazione delle assenze derivate da congedi;
- generazione dei report mensili;
- pulizia dei log secondo retention configurata;
- aggiornamento automatico del tutore per studenti maggiorenni.

In produzione va attivato lo scheduler Laravel:

```powershell
php artisan schedule:run
```

Normalmente questo comando viene eseguito ogni minuto dal sistema operativo o dal servizio di hosting.

## Note operative

- Gli allegati caricati dagli utenti vengono salvati nel disco `local`.
- Le email in ambiente locale sono configurate su `MAIL_MAILER=log`.
- Le code usano `QUEUE_CONNECTION=database`, quindi serve un worker attivo per processare i job.
- I test usano database SQLite in memoria e non modificano il database locale MySQL.
- Prima della consegna conviene sempre eseguire `php artisan test`, `vendor\bin\pint --test` e `npm run build`.

## Documentazione di progetto

La documentazione formale, i diari e i manuali sono nelle cartelle:

- `3_Documentazione/`
- `4_Diari/`
- `8_Manuali/`

Il codice applicativo principale si trova in `5_Sito/GestioneAssenze/`.
