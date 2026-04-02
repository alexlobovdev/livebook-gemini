# gemini service

Отдельный сервис интеграции с Gemini для CRM.

## Назначение
- Принимать задания генерации через API от CRM.
- Выполнять запросы в Gemini в worker-очереди..
- Публиковать события статуса/результата в Redis stream.

## API
- `POST /api/v1/jobs`
- `GET /api/v1/jobs/{jobId}`
- `POST /api/v1/jobs/{jobId}/retry`
- `GET /api/v1/health`

Все эндпоинты требуют `Authorization: Bearer <GEMINI_SERVICE_TOKEN>`.

## Queue и события
- Queue worker: `redis` queue `gemini-process` (по умолчанию).
- Redis events stream: `gemini:events` (по умолчанию).

## Быстрый старт
1. Создать `.env` из `.env.example`.
2. Заполнить `GEMINI_SERVICE_TOKEN`, `GEMINI_API_KEY` и Redis/DB параметры.
3. Выполнить миграции:
```bash
php artisan migrate
```
4. Запустить API:
```bash
php artisan serve --host=0.0.0.0 --port=8080
```
5. Запустить worker:
```bash
php artisan queue:work redis --queue=gemini-process --tries=3 --timeout=1800
```

## Документация
- [docs/SERVICE_ARCHITECTURE.md](docs/SERVICE_ARCHITECTURE.md)

## CI/CD
- CI workflow: `.github/workflows/ci.yml`
  - `composer validate`
  - `composer install`
  - php lint по `app/config/routes/migrations`
  - `php artisan migrate` + `php artisan route:list`
- Deploy workflow: `.github/workflows/deploy.yml`
  - Trigger: push в `main` и manual dispatch
  - Деплой на VPS по SSH в директорию `/opt/gemini`
  - Команды: `git pull`, `docker compose up -d --build`, `composer install`, `php artisan migrate`, `php artisan config:cache`

### Секреты GitHub Actions
- `VPS_HOST`
- `VPS_USER`
- `VPS_SSH_KEY` (private key в base64)

### Production файлы
- `docker-compose.prod.yml`
- `.env.production.example`

### HTTPS в production
- `docker-compose.prod.yml` поднимает `traefik` и автоматически получает TLS-сертификат Let's Encrypt.
- Обязательные переменные в `.env`: `APP_URL`, `GEMINI_DOMAIN`, `TRAEFIK_ACME_EMAIL`.
- Для домена `gemini.crm.liveboook.ru` A/AAAA запись должна указывать на IP VPS с этим compose.

### Логи в Docker
- В production `LOG_CHANNEL=stderr`, поэтому Laravel/PHP-логи идут в `docker logs`.
- Для worker включен `queue:work --verbose`, чтобы видеть обработку задач в stdout/stderr.
- `REDIS_QUEUE_RETRY_AFTER` должен быть больше `GEMINI_JOB_TIMEOUT_SECONDS` (по умолчанию 2100 > 1800), чтобы долгие задачи не пере-брались очередью раньше времени.
- Быстрый просмотр:
  - `docker compose --env-file .env -f docker-compose.prod.yml logs -f app worker nginx`
