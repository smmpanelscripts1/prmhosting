<?php

namespace PrmHosting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pterodactyl\Models\DatabaseHost;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\ServerVariable;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Services\Databases\DatabaseManagementService;
use Throwable;

class HostingService
{
    public static function envMap(Server $server): array
    {
        // Avoid Server::variables eager-load join quirks ($this->id in closure).
        $rows = DB::table('egg_variables as ev')
            ->leftJoin('server_variables as sv', function ($join) use ($server) {
                $join->on('sv.variable_id', '=', 'ev.id')
                    ->where('sv.server_id', '=', $server->id);
            })
            ->where('ev.egg_id', $server->egg_id)
            ->select(['ev.env_variable', 'ev.default_value', 'sv.variable_value'])
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $key = (string) $row->env_variable;
            if ($key === '') {
                continue;
            }
            $out[$key] = (string) (($row->variable_value !== null && $row->variable_value !== '')
                ? $row->variable_value
                : ($row->default_value ?? ''));
        }

        return $out;
    }

    public static function setEnv(Server $server, string $envKey, string $value): void
    {
        $eggVar = DB::table('egg_variables')
            ->where('egg_id', $server->egg_id)
            ->where('env_variable', $envKey)
            ->first();
        if (!$eggVar) {
            return;
        }
        ServerVariable::query()->updateOrCreate(
            ['server_id' => $server->id, 'variable_id' => $eggVar->id],
            ['variable_value' => $value]
        );
    }

    public static function stack(Server $server): array
    {
        $env = self::envMap($server);
        $domain = (string) ($env['CERTBOT_DOMAIN'] ?? $env['PRIMARY_DOMAIN'] ?? '');
        $ssl = [
            'enabled' => in_array((string) ($env['CERTBOT_STATUS'] ?? '0'), ['1', 'true', 'True'], true),
            'domain' => $domain,
            'email' => (string) ($env['CERTBOT_EMAIL'] ?? ''),
            'staging' => in_array((string) ($env['CERTBOT_STAGING'] ?? '0'), ['1', 'true'], true),
            'status_file' => null,
        ];
        try {
            $files = app(DaemonFileRepository::class)->setServer($server);
            if ($domain !== '') {
                try {
                    $ssl['status_file'] = $files->getContent('/.prmhosting/ssl-status.json', 65536);
                } catch (Throwable $e) {
                    $ssl['status_file'] = null;
                }
            }
        } catch (Throwable $e) {
            // Wings offline — still return env snapshot.
        }

        return [
            'server' => [
                'id' => $server->id,
                'uuid' => $server->uuid,
                'uuidShort' => $server->uuidShort,
                'name' => $server->name,
                'status' => $server->status,
            ],
            'php_version' => (string) ($env['PHP_VERSION'] ?? ''),
            'php_enabled' => in_array((string) ($env['PHP_STATUS'] ?? '1'), ['1', 'true'], true),
            'wordpress_flag' => in_array((string) ($env['WORDPRESS'] ?? '0'), ['1', 'true'], true),
            'cron_enabled' => in_array((string) ($env['CRON_STATUS'] ?? '0'), ['1', 'true'], true),
            'cron_file' => (string) ($env['CRON_CONFIG_FILE'] ?? '/home/container/crontab'),
            'domain' => $domain,
            'ssl' => $ssl,
            'limits' => [
                'memory' => (int) $server->memory,
                'disk' => (int) $server->disk,
                'cpu' => (float) $server->cpu,
            ],
            'feature_limits' => [
                'databases' => (int) $server->database_limit,
                'backups' => (int) $server->backup_limit,
            ],
        ];
    }

    public static function setDomain(Server $server, string $domain, string $email = ''): array
    {
        $domain = strtolower(trim($domain));
        self::setEnv($server, 'CERTBOT_DOMAIN', $domain);
        self::setEnv($server, 'PRIMARY_DOMAIN', $domain);
        if ($email !== '') {
            self::setEnv($server, 'CERTBOT_EMAIL', $email);
        }
        self::setEnv($server, 'CERTBOT_STATUS', '1');
        self::writeMeta($server, [
            'domain' => $domain,
            'email' => $email,
            'updated_at' => time(),
        ]);

        return self::stack($server->fresh(['variables']));
    }

    public static function requestSsl(Server $server, bool $force = false): array
    {
        $env = self::envMap($server);
        $domain = (string) ($env['CERTBOT_DOMAIN'] ?? '');
        if ($domain === '') {
            throw new \InvalidArgumentException('Domain ayarlı değil.');
        }
        self::setEnv($server, 'CERTBOT_STATUS', '1');
        if ($force) {
            self::setEnv($server, 'CERTBOT_FORCE_RENEWAL', '1');
        }
        $payload = [
            'action' => $force ? 'renew' : 'issue',
            'domain' => $domain,
            'requested_at' => time(),
            'note' => 'Restart server to apply Certbot module (egg).',
        ];
        self::writeFile($server, '/.prmhosting/ssl-status.json', json_encode($payload, JSON_PRETTY_PRINT));

        return $payload;
    }

    public static function getCron(Server $server): array
    {
        $env = self::envMap($server);
        $path = (string) ($env['CRON_CONFIG_FILE'] ?? '/home/container/crontab');
        $path = self::normalizePath($path);
        $content = '';
        try {
            $content = app(DaemonFileRepository::class)->setServer($server)->getContent($path, 256000);
        } catch (Throwable $e) {
            $content = '';
        }

        return [
            'path' => $path,
            'enabled' => in_array((string) ($env['CRON_STATUS'] ?? '0'), ['1', 'true'], true),
            'content' => $content,
        ];
    }

    public static function putCron(Server $server, string $content, bool $enable = true): array
    {
        $env = self::envMap($server);
        $path = self::normalizePath((string) ($env['CRON_CONFIG_FILE'] ?? '/home/container/crontab'));
        self::writeFile($server, $path, $content);
        self::setEnv($server, 'CRON_STATUS', $enable ? '1' : '0');
        self::setEnv($server, 'CRON_CONFIG_FILE', $path);

        return self::getCron($server->fresh(['variables']));
    }

    public static function installWordpress(Server $server, ?string $dbName = null): array
    {
        $files = app(DaemonFileRepository::class)->setServer($server);
        $hasWp = false;
        try {
            $listing = $files->getDirectory('/www');
            foreach ($listing as $row) {
                $name = is_array($row) ? ($row['name'] ?? '') : '';
                if ($name === 'wp-config.php' || $name === 'wp-load.php') {
                    $hasWp = true;
                    break;
                }
            }
        } catch (Throwable $e) {
            // www may not exist yet
        }

        if (!$hasWp) {
            // Trigger by setting WORDPRESS=1 and writing marker; actual files need reinstall or manual pull.
            self::setEnv($server, 'WORDPRESS', '1');
            self::setEnv($server, 'PHP_STATUS', '1');
            self::writeFile($server, '/.prmhosting/wordpress-pending.json', json_encode([
                'pending' => true,
                'at' => time(),
                'hint' => 'Reinstall server or upload WP; config will be written when DB is ready.',
            ], JSON_PRETTY_PRINT));
        }

        $db = self::ensureDatabase($server, $dbName ?: 'wp');
        $cfg = self::buildWpConfig($db);
        try {
            self::writeFile($server, '/www/wp-config.php', $cfg);
        } catch (Throwable $e) {
            // Store for later
            self::writeFile($server, '/.prmhosting/wp-config.php.pending', $cfg);
        }

        return [
            'wordpress_detected' => $hasWp,
            'database' => $db,
            'wp_config_written' => true,
            'note' => $hasWp
                ? 'wp-config.php updated'
                : 'WORDPRESS=1 set; reinstall server to fetch WP core, or upload files. Config written/pending.',
        ];
    }

    public static function ensureDatabase(Server $server, string $shortName = 'wp'): array
    {
        $existing = $server->databases()->first();
        if ($existing) {
            return [
                'id' => $existing->id,
                'database' => $existing->database,
                'username' => $existing->username,
                'password' => decrypt($existing->password),
                'host' => optional($existing->host)->host,
                'port' => optional($existing->host)->port,
                'created' => false,
            ];
        }

        $host = DatabaseHost::query()->first();
        if (!$host) {
            throw new \RuntimeException('Database host tanımlı değil.');
        }
        if ((int) $server->database_limit < 1) {
            $server->database_limit = 1;
            $server->save();
        }

        $unique = DatabaseManagementService::generateUniqueDatabaseName(
            preg_replace('/[^a-z0-9_]/i', '', $shortName) ?: 'wp',
            $server->id
        );
        /** @var DatabaseManagementService $svc */
        $svc = app(DatabaseManagementService::class);
        $model = $svc->create($server, [
            'database' => $unique,
            'remote' => '%',
            'database_host_id' => $host->id,
        ]);

        return [
            'id' => $model->id,
            'database' => $model->database,
            'username' => $model->username,
            'password' => decrypt($model->password),
            'host' => $host->host,
            'port' => $host->port,
            'created' => true,
        ];
    }

    private static function buildWpConfig(array $db): string
    {
        $name = addslashes((string) $db['database']);
        $user = addslashes((string) $db['username']);
        $pass = addslashes((string) $db['password']);
        $host = addslashes((string) ($db['host'] ?: '127.0.0.1'));
        $port = (int) ($db['port'] ?: 3306);
        $hostPort = $port && $port !== 3306 ? "{$host}:{$port}" : $host;
        $keys = [];
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $k) {
            $keys[$k] = Str::random(64);
        }

        $salts = '';
        foreach ($keys as $k => $v) {
            $salts .= "define('{$k}', '{$v}');\n";
        }

        return "<?php\n"
            . "define('DB_NAME', '{$name}');\n"
            . "define('DB_USER', '{$user}');\n"
            . "define('DB_PASSWORD', '{$pass}');\n"
            . "define('DB_HOST', '{$hostPort}');\n"
            . "define('DB_CHARSET', 'utf8mb4');\n"
            . "define('DB_COLLATE', '');\n"
            . $salts
            . "\$table_prefix = 'wp_';\n"
            . "define('WP_DEBUG', false);\n"
            . "if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');\n"
            . "require_once ABSPATH . 'wp-settings.php';\n";
    }

    private static function writeMeta(Server $server, array $meta): void
    {
        try {
            $files = app(DaemonFileRepository::class)->setServer($server);
            try {
                $files->createDirectory('/.prmhosting', '/');
            } catch (Throwable $e) {
            }
            self::writeFile($server, '/.prmhosting/meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (Throwable $e) {
        }
    }

    private static function writeFile(Server $server, string $path, string $content): void
    {
        $path = self::normalizePath($path);
        $dir = dirname($path);
        $files = app(DaemonFileRepository::class)->setServer($server);
        if ($dir !== '/' && $dir !== '.' && $dir !== '') {
            try {
                $files->createDirectory(basename($dir), dirname($dir) === '\\' ? '/' : dirname($dir));
            } catch (Throwable $e) {
                try {
                    $files->createDirectory($dir, '/');
                } catch (Throwable $e2) {
                }
            }
        }
        $files->putContent($path, $content);
    }

    private static function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, '/home/container')) {
            $path = substr($path, strlen('/home/container')) ?: '/';
        }
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . ltrim($path, '/');
        }

        return $path;
    }
}
