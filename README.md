# PRM Hosting

Blueprint extension for Pterodactyl: Plesk-style hosting bridge for nginx egg servers.

## Features

- Primary domain + Certbot issue/renew hooks
- WordPress install helper (DB create + `wp-config.php`)
- Cron get/put
- Site firewall (nginx allow/deny whitelist·blacklist)
- Log browser (nginx access/error, PHP-FPM, Laravel) — Next + Ptero client (`/hosting-logs`)
- Site firewall UI — Next + Ptero client (`/hosting-firewall`)
- PHP Composer manager — Ptero client (`/hosting-composer`); install/update job on Restart
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
- `GET|PUT firewall` — writes `/.prmhosting/firewall.json` + `nginx/snippets/prm-firewall.conf`
- `GET logs` · `GET logs/sources` — tail+parse container logs (Plesk-style)
- `GET|PUT composer` · `POST composer/job` — lock/json status, mode, Packagist outdated, queue install/update/require/remove

Native Client API covers files, databases, backups, and power.
