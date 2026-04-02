# Gemini Service Architecture

## Scope
- Единственная точка прямых HTTP-запросов в Gemini API.
- API-прием заданий от CRM.
- Асинхронная обработка в очереди.
- Публикация событий статусов в Redis Stream.

## Runtime
- Laravel (PHP), отдельный репозиторий/машина.
- Queue: `redis`, queue name `gemini-process`.
- Events stream: `gemini:events`.

## API
- `POST /api/v1/jobs`
- `GET /api/v1/jobs/{jobId}`
- `POST /api/v1/jobs/{jobId}/retry`
- `GET /api/v1/health`

Auth: `Authorization: Bearer <GEMINI_SERVICE_TOKEN>`.

## Data flow
1. CRM отправляет job в `POST /api/v1/jobs` с `X-Idempotency-Key`.
2. Сервис создает запись `gemini_jobs`, возвращает `202`.
3. Worker выполняет job и вызывает Gemini API.
4. Сервис публикует `job.processing/job.completed/job.failed` в `gemini:events`.
5. CRM читает stream-события, fallback-ом использует `GET /api/v1/jobs/{id}`.
