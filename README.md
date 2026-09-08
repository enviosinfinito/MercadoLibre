# SaaS Commerce Control Plane

Multi-tenant Laravel app for Mercado Libre / Amazon order economics: FIFO COGS,
expected P&L, connectors, and ops tooling.

## Requirements

- Docker + Docker Compose
- (Optional) Node 22+ if you run Vite on the host

## Quick start (Docker)

```bash
cp .env.example .env
# Generate app key once containers can run PHP, or on the host:
# php artisan key:generate

docker compose up -d --build
```

Wait until `mysql` is healthy, then inside the app container (or with host PHP
pointing at compose services):

```bash
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --force
docker compose exec app php artisan storage:link
```

If the `app` service image needs Composer/npm during build, the project `vendor/`
and `node_modules/` on the mounted volume are used as-is. To install on the host:

```bash
composer install
npm ci && npm run build
```

## URLs

| Service | URL | Notes |
|---------|-----|--------|
| App (nginx) | http://localhost:8082 | Main UI (`APP_PORT`, default 8082) |
| MySQL | localhost:3307 | `saas` / `secret` (root: `rootsecret`; `FORWARD_DB_PORT`) |
| Mailpit UI | http://localhost:8025 | Captured mail |
| MinIO console | http://localhost:9001 | S3-compatible storage (`minio` / `minio123`) |
| MinIO API | http://localhost:9000 | Bucket `saas` |
| Redis | localhost:6380 | `FORWARD_REDIS_PORT` (default 6380) |
| Reverb WS | localhost:8081 | Realtime (see `REVERB_*`) |
| Vite (profile) | http://localhost:5173 | `docker compose --profile frontend up` |
| Horizon | http://localhost:8082/horizon | Platform admins |
| Pulse | http://localhost:8082/pulse | Platform admins |

## Demo credentials

After `db:seed`:

- **Email:** `admin@saas.test`
- **Password:** `password`

The user is a platform admin (`is_platform_admin`) with workspace `demo`.

## Environment

Copy `.env.example` → `.env`. Important SaaS variables:

- `DB_*` — **MySQL 8** (`saas` / `secret` in compose; production also MySQL)
- `REDIS_*` — queues, cache, sessions, Horizon
- `AWS_*` — S3 disk (MinIO in local compose)
- `MELI_*` — Mercado Libre OAuth / API
- `AMAZON_*` — LWA + SQS (`amazon:sqs-poll`)
- `REVERB_*` / `VITE_REVERB_*` — websockets
- `STRIPE_*` — billing stub (usage metering via `RecordUsage`)

The `s3` filesystem disk reads `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`,
`AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_URL`, `AWS_ENDPOINT`, and
`AWS_USE_PATH_STYLE_ENDPOINT`.

## Amazon SQS (stub)

```bash
php artisan amazon:sqs-poll --stub --max=5
# or HTTP (auth required): POST /amazon/sqs-poll
```

Dispatches `ProcessAmazonSqsMessageJob` for `ORDER_CHANGE`-like payloads.

## Tests

PHPUnit (sqlite `:memory:` via `phpunit.xml`):

```bash
php artisan test
```

Suites: `Unit`, `Feature`, `FinanceGolden` (25+ expected-profit cases).

## Ops notes

### Queues (Horizon)

**Local (Docker Compose):** el worker es el servicio `horizon` (`restart: unless-stopped`, healthcheck `horizon:status`, apagado con `SIGTERM` / 90s).

```bash
docker compose up -d redis horizon
```

- UI: http://localhost:8082/horizon (platform admins)
- No mezclar con `composer run dev` (`queue:listen`): competiría con Horizon sobre las mismas colas Redis
- Si el contenedor queda unhealthy: `docker compose up -d --force-recreate horizon`

**Producción (VPS):** plantilla supervisord en [`deploy/supervisor/`](deploy/supervisor/) (`autostart` + `autorestart`). Tras deploy:

```bash
php artisan migrate --force
php artisan horizon:terminate
php artisan horizon:status
```

### Other

- Scheduler: `scheduler` service runs `schedule:run` every minute (incluye `horizon:snapshot` cada 5 min)
- ML webhooks: `POST /webhooks/mercadolibre` (CSRF excluded)
- Webhook ensure: `EnsureWebhookSubscriptions` marks `connection_capabilities.webhooks`
