# Horizon via supervisord (producción VPS)

Plantilla para mantener `php artisan horizon` siempre arriba cuando la app no corre en Docker Compose.

## Requisitos

- Redis accesible
- `QUEUE_CONNECTION=redis` en `.env`
- `APP_ENV=production` (activa los `maxProcesses` de producción en `config/horizon.php`)
- PHP CLI con extensiones `pcntl` y Redis (o predis)

## Instalación

1. Ajusta `command`, `directory`, `user` y `stdout_logfile` en [`horizon.conf`](horizon.conf) a las rutas reales del servidor.
2. Instala y habilita Supervisor en el host.
3. Copia la config:

```bash
sudo cp deploy/supervisor/horizon.conf /etc/supervisor/conf.d/horizon.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status horizon
```

## Post-deploy

```bash
php artisan migrate --force
php artisan horizon:terminate
php artisan horizon:status
```

`horizon:terminate` pide un apagado limpio; `autorestart=true` vuelve a levantar el master.

## Notas

- Un solo master Horizon por entorno. No escalar réplicas del programa `horizon`.
- En local usa el servicio Docker `horizon` de `docker-compose.yml`, no este archivo.
- Dashboard: `/horizon` (solo platform admins).
