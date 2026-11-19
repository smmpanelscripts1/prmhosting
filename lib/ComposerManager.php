<?php

namespace PrmHosting;

use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Throwable;

/**
 * Plesk-style Composer manager for nginx-egg sites (files + job on restart).
 */
class ComposerManager
{
    public const WWW = '/www';
    public const META = '/.prmhosting/composer-meta.json';
    public const JOB = '/.prmhosting/composer-job.json';
    public const STATUS = '/.prmhosting/composer-status.json';

    public static function status(Server $server): array
    {
        $env = HostingService::envMap($server);
        $meta = self::readJson($server, self::META) ?: [];
        $mode = (($meta['mode'] ?? '') === 'development') ? 'development' : 'production';
        $enabled = in_array((string) ($env['COMPOSER_STATUS'] ?? '1'), ['1', 'true', 'True'], true);

        $composerJson = self::readJson($server, self::WWW . '/composer.json');
        $composerLock = self::readJson($server, self::WWW . '/composer.lock');
        $job = self::readJson($server, self::JOB);
        $last = self::readJson($server, self::STATUS);

        $packages = self::packagesFromLock($composerLock, $composerJson);
        // Enrich a few outdated hints via Packagist (best-effort, capped).
        $packages = self::enrichPackagist($packages, 12);

        return [
            'enabled' => $enabled,
            'mode' => $mode,
            'www_dir' => self::WWW,
            'has_composer_json' => is_array($composerJson),
            'has_composer_lock' => is_array($composerLock),
            'composer_json' => $composerJson,
            'packages' => $packages,
            'require' => is_array($composerJson['require'] ?? null) ? $composerJson['require'] : [],
            'require_dev' => is_array($composerJson['require-dev'] ?? null) ? $composerJson['require-dev'] : [],
            'modules_env' => (string) ($env['COMPOSER_MODULES'] ?? ''),
            'pending_job' => $job,
            'last_status' => $last,
            'note' => 'Yükle / Güncelle işi kaydedilir; nginx egg Composer modülü sunucu Restart’ta çalışır.',
        ];
    }

    public static function setMode(Server $server, string $mode): array
    {
        $mode = $mode === 'development' ? 'development' : 'production';
        $meta = self::readJson($server, self::META) ?: [];
        $meta['mode'] = $mode;
        $meta['updated_at'] = time();
        self::writeJson($server, self::META, $meta);

        return self::status($server);
    }

    public static function setEnabled(Server $server, bool $enabled): array
    {
        HostingService::setEnv($server, 'COMPOSER_STATUS', $enabled ? '1' : '0');

        return self::status($server->fresh(['variables']));
    }

    public static function putComposerJson(Server $server, $json): array
    {
        if (is_string($json)) {
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException('Geçersiz composer.json');
            }
            $json = $decoded;
        }
        if (!is_array($json)) {
            throw new \InvalidArgumentException('composer.json dizi olmalı');
        }
        self::writeJson($server, self::WWW . '/composer.json', $json);

        return self::status($server);
    }

    public static function queueJob(Server $server, string $action, array $opts = []): array
    {
        $action = strtolower($action);
        if (!in_array($action, ['install', 'update', 'require', 'remove'], true)) {
            throw new \InvalidArgumentException('Geçersiz aksiyon');
        }
        HostingService::setEnv($server, 'COMPOSER_STATUS', '1');
        $packages = [];
        if (isset($opts['packages'])) {
            if (is_string($opts['packages'])) {
                $packages = preg_split('/[\s,;]+/', $opts['packages']) ?: [];
            } elseif (is_array($opts['packages'])) {
                $packages = $opts['packages'];
            }
        }
        $packages = array_values(array_filter(array_map('trim', $packages), static fn ($p) => $p !== ''));
        if (in_array($action, ['require', 'remove'], true) && $packages === []) {
            throw new \InvalidArgumentException('Paket adı gerekli');
        }

        $job = [
            'action' => $action,
            'packages' => $packages,
            'queued_at' => time(),
            'note' => 'Restart sunucuyu — egg Composer modülü işi çalıştırır.',
        ];
        self::writeJson($server, self::JOB, $job);
        self::writeJson($server, self::STATUS, [
            'state' => 'queued',
            'action' => $action,
            'at' => time(),
        ]);

        $out = self::status($server->fresh(['variables']));
        $out['restart_hint'] = 'Composer işi kuyruğa alındı. Sunucuyu Restart et.';

        return $out;
    }

    private static function packagesFromLock(?array $lock, ?array $json): array
    {
        $require = is_array($json['require'] ?? null) ? $json['require'] : [];
        $requireDev = is_array($json['require-dev'] ?? null) ? $json['require-dev'] : [];
        $out = [];
        $lists = [
            'prod' => is_array($lock['packages'] ?? null) ? $lock['packages'] : [],
            'dev' => is_array($lock['packages-dev'] ?? null) ? $lock['packages-dev'] : [],
        ];
        foreach ($lists as $bucket => $pkgs) {
            foreach ($pkgs as $pkg) {
                if (!is_array($pkg)) {
                    continue;
                }
                $name = (string) ($pkg['name'] ?? '');
                if ($name === '' || str_starts_with($name, 'php') && !str_contains($name, '/')) {
                    continue;
                }
                $out[] = [
                    'name' => $name,
                    'version' => (string) ($pkg['version'] ?? ''),
                    'description' => (string) ($pkg['description'] ?? ''),
                    'bucket' => $bucket,
                    'homepage' => (string) ($pkg['homepage'] ?? ''),
                    'source' => (string) (($pkg['source']['url'] ?? $pkg['dist']['url'] ?? '')),
                    'latest' => null,
                    'outdated' => false,
                    'in_require' => array_key_exists($name, $require),
                    'in_require_dev' => array_key_exists($name, $requireDev),
                ];
            }
        }
        usort($out, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        // If no lock, show require keys only.
        if ($out === [] && ($require || $requireDev)) {
            foreach ($require as $name => $ver) {
                if ($name === 'php' || str_starts_with((string) $name, 'ext-')) {
                    continue;
                }
                $out[] = [
                    'name' => (string) $name,
                    'version' => (string) $ver,
                    'description' => '(lock yok — constraint)',
                    'bucket' => 'prod',
                    'homepage' => '',
                    'source' => '',
                    'latest' => null,
                    'outdated' => false,
                    'in_require' => true,
                    'in_require_dev' => false,
                ];
            }
            foreach ($requireDev as $name => $ver) {
                $out[] = [
                    'name' => (string) $name,
                    'version' => (string) $ver,
                    'description' => '(lock yok — constraint)',
                    'bucket' => 'dev',
                    'homepage' => '',
                    'source' => '',
                    'latest' => null,
                    'outdated' => false,
                    'in_require' => false,
                    'in_require_dev' => true,
                ];
            }
        }

        return $out;
    }

    private static function enrichPackagist(array $packages, int $max): array
    {
        $n = 0;
        foreach ($packages as &$pkg) {
            if ($n >= $max) {
                break;
            }
            $name = $pkg['name'] ?? '';
            if ($name === '' || !str_contains($name, '/')) {
                continue;
            }
            $n++;
            try {
                $url = 'https://repo.packagist.org/p2/' . $name . '.json';
                $ctx = stream_context_create(['http' => ['timeout' => 2, 'header' => "User-Agent: prmhosting\r\n"]]);
                $raw = @file_get_contents($url, false, $ctx);
                if ($raw === false) {
                    continue;
                }
                $data = json_decode($raw, true);
                $versions = $data['packages'][$name] ?? [];
                if (!is_array($versions) || $versions === []) {
                    continue;
                }
                $latest = (string) ($versions[0]['version'] ?? '');
                if ($latest === '') {
                    continue;
                }
                $pkg['latest'] = $latest;
                $cur = ltrim((string) ($pkg['version'] ?? ''), 'v');
                $lat = ltrim($latest, 'v');
                $pkg['outdated'] = $cur !== '' && $lat !== '' && version_compare($cur, $lat, '<');
            } catch (Throwable $e) {
                // ignore
            }
        }
        unset($pkg);

        return $packages;
    }

    private static function readJson(Server $server, string $path): ?array
    {
        try {
            $raw = app(DaemonFileRepository::class)->setServer($server)->getContent($path, 2_000_000);
            $decoded = json_decode((string) $raw, true);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function writeJson(Server $server, string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        // Reuse HostingService private writer via reflection-free duplicate:
        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, '/home/container')) {
            $path = substr($path, strlen('/home/container')) ?: '/';
        }
        $files = app(DaemonFileRepository::class)->setServer($server);
        $dir = dirname($path);
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
        $files->putContent($path, $json);
    }
}
