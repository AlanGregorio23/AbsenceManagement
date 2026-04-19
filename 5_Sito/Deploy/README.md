# Docker Deployment

Run these commands from this folder:

```powershell
cd 5_Sito/Deploy
copy .env.docker.example .env.docker
```

Generate the Laravel key:

```powershell
docker compose run --rm --no-deps app php artisan key:generate --show
```

Copy the generated value into `APP_KEY=` inside `.env.docker`.

Start the stack:

```powershell
docker compose up -d --build
```

Open:

```text
http://localhost:8080
```

To load demo data:

```powershell
docker compose exec app php artisan db:seed --force
```
