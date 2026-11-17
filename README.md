# PRM Hosting

Blueprint extension for Pterodactyl: Plesk-style hosting bridge for nginx egg servers.

## Features

- Primary domain + Certbot issue/renew hooks
- WordPress install helper (DB create + `wp-config.php`)
- Cron get/put
- Stack status (PHP, SSL flags, limits)
- Admin package templates (nest/egg/image + limits)

## Install

```bash
blueprint -install prmhosting.blueprint
```

Requires Blueprint Framework on a compatible Pterodactyl panel.

## Client API (server-scoped)

Under `/api/client/extensions/prmhosting/servers/{server}/hosting/`:

- `GET stack`
- `GET|POST domains`
- `POST ssl/issue` · `POST ssl/renew`
- `POST wordpress/install` · `POST wordpress/reset`
- `GET|PUT cron`

Native Client API covers files, databases, backups, and power.
