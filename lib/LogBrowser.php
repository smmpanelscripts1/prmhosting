<?php

namespace PrmHosting;

use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Throwable;

/**
 * Plesk-style site log browser: nginx access/error + optional app logs via Wings files.
 */
class LogBrowser
{
    public const SOURCES = [
        'nginx-access' => [
            'label' => 'nginx erişim',
            'path' => '/logs/access.log',
            'kind' => 'access',
        ],
        'nginx-error' => [
            'label' => 'nginx hata',
            'path' => '/logs/error.log',
            'kind' => 'error',
        ],
        'php-fpm' => [
            'label' => 'PHP-FPM',
            'path' => '/logs/php-fpm.log',
            'kind' => 'error',
        ],
        'laravel' => [
            'label' => 'Laravel',
            'path' => '/www/storage/logs/laravel.log',
            'kind' => 'app',
        ],
        'cron' => [
            'label' => 'Cron',
            'path' => '/logs/cron.log',
            'kind' => 'app',
        ],
    ];

    public static function catalog(Server $server): array
    {
        $files = app(DaemonFileRepository::class)->setServer($server);
        $out = [];
        foreach (self::SOURCES as $id => $meta) {
            $exists = false;
            $size = null;
            try {
                $dir = dirname($meta['path']);
                $base = basename($meta['path']);
                $listing = $files->getDirectory($dir === '\\' ? '/' : $dir);
                foreach ((array) $listing as $row) {
                    $name = is_array($row) ? (string) ($row['name'] ?? '') : '';
                    if ($name === $base) {
                        $exists = true;
                        $size = is_array($row) && isset($row['size']) ? (int) $row['size'] : null;
                        break;
                    }
                }
            } catch (Throwable $e) {
                $exists = false;
            }
            $out[] = [
                'id' => $id,
                'label' => $meta['label'],
                'path' => $meta['path'],
                'kind' => $meta['kind'],
                'exists' => $exists,
                'size' => $size,
            ];
        }

        // Discover rotated / dated laravel logs.
        try {
            $listing = $files->getDirectory('/www/storage/logs');
            foreach ((array) $listing as $row) {
                $name = is_array($row) ? (string) ($row['name'] ?? '') : '';
                if ($name === '' || $name === 'laravel.log' || !preg_match('/\.log$/i', $name)) {
                    continue;
                }
                $id = 'app:' . $name;
                $out[] = [
                    'id' => $id,
                    'label' => 'App · ' . $name,
                    'path' => '/www/storage/logs/' . $name,
                    'kind' => 'app',
                    'exists' => true,
                    'size' => is_array($row) && isset($row['size']) ? (int) $row['size'] : null,
                ];
            }
        } catch (Throwable $e) {
            // no storage/logs
        }

        return $out;
    }

    public static function query(Server $server, array $opts = []): array
    {
        $source = (string) ($opts['source'] ?? 'all');
        $limit = min(500, max(20, (int) ($opts['limit'] ?? 150)));
        $ip = trim((string) ($opts['ip'] ?? ''));
        $code = trim((string) ($opts['code'] ?? ''));
        $q = trim((string) ($opts['q'] ?? ''));
        $type = strtolower(trim((string) ($opts['type'] ?? ''))); // access|error|app|''
        $bytesCap = min(2_000_000, max(64_000, (int) ($opts['bytes'] ?? 512_000)));

        $catalog = self::catalog($server);
        $targets = [];
        if ($source === '' || $source === 'all') {
            foreach ($catalog as $c) {
                if (!empty($c['exists'])) {
                    $targets[] = $c;
                }
            }
        } else {
            foreach ($catalog as $c) {
                if ($c['id'] === $source && !empty($c['exists'])) {
                    $targets[] = $c;
                }
            }
            // Allow explicit path-like source ids even if catalog miss.
            if ($targets === [] && isset(self::SOURCES[$source])) {
                $meta = self::SOURCES[$source];
                $targets[] = [
                    'id' => $source,
                    'label' => $meta['label'],
                    'path' => $meta['path'],
                    'kind' => $meta['kind'],
                    'exists' => true,
                ];
            }
        }

        $entries = [];
        foreach ($targets as $t) {
            $raw = self::tailFile($server, $t['path'], $bytesCap);
            if ($raw === '') {
                continue;
            }
            $parsed = self::parseBlob($raw, $t);
            foreach ($parsed as $row) {
                $entries[] = $row;
            }
        }

        // Newest first
        usort($entries, static function ($a, $b) {
            return ((int) ($b['ts'] ?? 0)) <=> ((int) ($a['ts'] ?? 0));
        });

        $filtered = [];
        foreach ($entries as $row) {
            if ($type !== '' && strtolower((string) ($row['type'] ?? '')) !== $type) {
                continue;
            }
            if ($ip !== '' && stripos((string) ($row['ip'] ?? ''), $ip) === false) {
                continue;
            }
            if ($code !== '' && (string) ($row['code'] ?? '') !== $code) {
                continue;
            }
            if ($q !== '') {
                $hay = strtolower(
                    (string) ($row['message'] ?? '') . ' ' .
                    (string) ($row['detail'] ?? '') . ' ' .
                    (string) ($row['ua'] ?? '') . ' ' .
                    (string) ($row['referer'] ?? '')
                );
                if (!str_contains($hay, strtolower($q))) {
                    continue;
                }
            }
            $filtered[] = $row;
            if (count($filtered) >= $limit) {
                break;
            }
        }

        return [
            'sources' => $catalog,
            'source' => $source,
            'filters' => [
                'ip' => $ip,
                'code' => $code,
                'q' => $q,
                'type' => $type,
                'limit' => $limit,
            ],
            'entries' => $filtered,
            'count' => count($filtered),
            'note' => 'Son ' . number_format($bytesCap / 1024, 0) . ' KB kuyruk okunur; tam arşiv için Dosyalar → /logs.',
        ];
    }

    private static function tailFile(Server $server, string $path, int $bytesCap): string
    {
        try {
            $content = (string) app(DaemonFileRepository::class)->setServer($server)->getContent($path, $bytesCap);
        } catch (Throwable $e) {
            return '';
        }
        // If truncated mid-line, drop first partial line.
        if (strlen($content) >= $bytesCap - 16) {
            $pos = strpos($content, "\n");
            if ($pos !== false) {
                $content = substr($content, $pos + 1);
            }
        }

        return $content;
    }

    private static function parseBlob(string $raw, array $source): array
    {
        $kind = $source['kind'] ?? 'app';
        $lines = preg_split("/\r\n|\n|\r/", $raw) ?: [];
        $out = [];
        if ($kind === 'access') {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $row = self::parseAccessLine($line, $source);
                if ($row) {
                    $out[] = $row;
                }
            }

            return $out;
        }
        if ($kind === 'error') {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $out[] = self::parseErrorLine($line, $source);
            }

            return $out;
        }

        // App / Laravel: merge multiline stack traces.
        $buf = '';
        $header = null;
        $flush = static function () use (&$buf, &$header, &$out, $source) {
            if ($header === null && $buf === '') {
                return;
            }
            $detail = trim($buf);
            $row = $header ?: [
                'ts' => time(),
                'time' => date('Y-m-d H:i:s'),
                'type' => 'app',
                'ip' => '',
                'code' => '',
                'message' => mb_substr($detail, 0, 200),
                'size' => null,
                'size_label' => '',
                'referer' => '',
                'ua' => '',
                'source' => $source['label'] ?? 'app',
                'source_id' => $source['id'] ?? '',
                'raw' => $detail,
            ];
            if ($detail !== '' && $detail !== ($row['message'] ?? '')) {
                $row['detail'] = $detail;
                $row['raw'] = $detail;
            }
            $out[] = $row;
            $buf = '';
            $header = null;
        };

        foreach ($lines as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:?\d{2})?)\]\s+(\w+)\.(\w+):\s*(.*)$/', $line, $m)) {
                $flush();
                $ts = strtotime($m[1]) ?: time();
                $level = strtolower($m[3]);
                $msg = $m[4];
                $header = [
                    'ts' => $ts,
                    'time' => date('Y-m-d H:i:s', $ts),
                    'type' => in_array($level, ['error', 'critical', 'alert', 'emergency'], true) ? 'error' : 'app',
                    'ip' => '',
                    'code' => strtoupper($level),
                    'message' => mb_substr($msg, 0, 300),
                    'size' => null,
                    'size_label' => '',
                    'referer' => '',
                    'ua' => '',
                    'source' => $source['label'] ?? 'app',
                    'source_id' => $source['id'] ?? '',
                    'raw' => $line,
                    'detail' => '',
                ];
                $buf = $line . "\n";
                continue;
            }
            if ($header !== null) {
                $buf .= $line . "\n";
                continue;
            }
            // Generic app line
            $out[] = [
                'ts' => time(),
                'time' => '',
                'type' => 'app',
                'ip' => '',
                'code' => '',
                'message' => mb_substr($line, 0, 300),
                'size' => null,
                'size_label' => '',
                'referer' => '',
                'ua' => '',
                'source' => $source['label'] ?? 'app',
                'source_id' => $source['id'] ?? '',
                'raw' => $line,
            ];
        }
        $flush();

        return $out;
    }

    private static function parseAccessLine(string $line, array $source): ?array
    {
        // combined: IP - user [time] "REQ" status bytes "ref" "ua"
        $re = '/^(\S+)\s+\S+\s+(\S+)\s+\[([^\]]+)\]\s+"([^"]*)"\s+(\d{3})\s+(\S+)(?:\s+"([^"]*)"\s+"([^"]*)")?/';
        if (!preg_match($re, $line, $m)) {
            return [
                'ts' => time(),
                'time' => '',
                'type' => 'access',
                'ip' => '',
                'code' => '',
                'message' => mb_substr($line, 0, 300),
                'size' => null,
                'size_label' => '',
                'referer' => '',
                'ua' => '',
                'source' => $source['label'] ?? 'nginx',
                'source_id' => $source['id'] ?? '',
                'raw' => $line,
            ];
        }
        $ts = strtotime($m[3]) ?: time();
        $bytes = is_numeric($m[6]) ? (int) $m[6] : null;
        $code = (int) $m[5];

        return [
            'ts' => $ts,
            'time' => date('Y-m-d H:i:s', $ts),
            'type' => $code >= 400 ? 'error' : 'access',
            'ip' => $m[1],
            'code' => (string) $code,
            'message' => $m[4],
            'size' => $bytes,
            'size_label' => self::fmtBytes($bytes),
            'referer' => isset($m[7]) && $m[7] !== '-' ? $m[7] : '',
            'ua' => isset($m[8]) && $m[8] !== '-' ? $m[8] : '',
            'source' => $source['label'] ?? 'nginx erişim',
            'source_id' => $source['id'] ?? '',
            'raw' => $line,
        ];
    }

    private static function parseErrorLine(string $line, array $source): array
    {
        // nginx: 2024/01/01 12:00:00 [error] ...
        // php-fpm: [18-Nov-2026 18:34:10] NOTICE: ...
        $ts = time();
        $time = '';
        $level = 'info';
        $msg = $line;
        if (preg_match('/^(\d{4}\/\d{2}\/\d{2}\s+\d{2}:\d{2}:\d{2})\s+\[(\w+)\]\s*(.*)$/', $line, $m)) {
            $ts = strtotime(str_replace('/', '-', $m[1])) ?: time();
            $time = date('Y-m-d H:i:s', $ts);
            $level = strtolower($m[2]);
            $msg = $m[3];
        } elseif (preg_match('/^\[(\d{1,2}-[A-Za-z]{3}-\d{4}\s+\d{2}:\d{2}:\d{2})\]\s+(\w+):\s*(.*)$/', $line, $m)) {
            $ts = strtotime($m[1]) ?: time();
            $time = date('Y-m-d H:i:s', $ts);
            $level = strtolower($m[2]);
            $msg = $m[3];
        } elseif (preg_match('/\b(NOTICE|WARNING|ERROR|ALERT|CRITICAL)\b:\s*(.*)$/i', $line, $m)) {
            $level = strtolower($m[1]);
            $msg = $m[2];
        }
        $ip = '';
        if (preg_match('/client:\s*([0-9a-fA-F:.]+)/', $msg, $cm)) {
            $ip = $cm[1];
        }

        $isHard = in_array($level, ['error', 'crit', 'alert', 'emerg', 'fatal', 'critical'], true);

        return [
            'ts' => $ts,
            'time' => $time ?: date('Y-m-d H:i:s', $ts),
            'type' => $isHard ? 'error' : 'app',
            'ip' => $ip,
            'code' => strtoupper($level),
            'message' => mb_substr($msg, 0, 400),
            'size' => null,
            'size_label' => '',
            'referer' => '',
            'ua' => '',
            'source' => $source['label'] ?? 'nginx hata',
            'source_id' => $source['id'] ?? '',
            'raw' => $line,
        ];
    }

    private static function fmtBytes(?int $n): string
    {
        if ($n === null || $n < 0) {
            return '';
        }
        if ($n < 1024) {
            return $n . ' B';
        }
        if ($n < 1024 * 1024) {
            return round($n / 1024, 2) . ' K';
        }

        return round($n / (1024 * 1024), 2) . ' M';
    }
}
