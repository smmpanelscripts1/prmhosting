<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Pterodactyl\Http\Middleware\Api\Client\Server\AuthenticateServerAccess;
use Pterodactyl\Models\Server;

$lib = '/blueprint_extensions/prmhosting/lib';
if (!is_dir($lib)) {
    $lib = dirname(__DIR__) . '/lib';
}
require_once $lib . '/Settings.php';
require_once $lib . '/HostingService.php';
require_once $lib . '/LogBrowser.php';

Route::middleware([AuthenticateServerAccess::class])->group(function () {
    Route::get('/servers/{server}/hosting/stack', function (Server $server) {
        return response()->json(\PrmHosting\HostingService::stack($server));
    });

    Route::get('/servers/{server}/hosting/domains', function (Server $server) {
        $stack = \PrmHosting\HostingService::stack($server);

        return response()->json([
            'primary' => $stack['domain'],
            'ssl' => $stack['ssl'],
        ]);
    });

    Route::post('/servers/{server}/hosting/domains', function (Request $request, Server $server) {
        $domain = trim((string) $request->input('domain', ''));
        $email = trim((string) $request->input('email', ''));
        if ($domain === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain)) {
            return response()->json(['error' => 'Geçerli bir domain girin.'], 422);
        }
        try {
            $stack = \PrmHosting\HostingService::setDomain($server, $domain, $email);

            return response()->json(['ok' => true, 'stack' => $stack]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    });

    Route::post('/servers/{server}/hosting/ssl/issue', function (Server $server) {
        try {
            return response()->json(['ok' => true, 'ssl' => \PrmHosting\HostingService::requestSsl($server, false)]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    });

    Route::post('/servers/{server}/hosting/ssl/renew', function (Server $server) {
        try {
            return response()->json(['ok' => true, 'ssl' => \PrmHosting\HostingService::requestSsl($server, true)]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    });

    Route::post('/servers/{server}/hosting/wordpress/install', function (Request $request, Server $server) {
        try {
            $result = \PrmHosting\HostingService::installWordpress(
                $server,
                $request->input('database') ? (string) $request->input('database') : null
            );

            return response()->json(['ok' => true] + $result);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    });

    Route::post('/servers/{server}/hosting/wordpress/reset', function (Server $server) {
        try {
            \PrmHosting\HostingService::setEnv($server, 'WORDPRESS', '0');
            $files = app(\Pterodactyl\Repositories\Wings\DaemonFileRepository::class)->setServer($server);
            try {
                $files->putContent('/.prmhosting/wordpress-pending.json', json_encode(['reset' => true, 'at' => time()]));
            } catch (\Throwable $e) {
            }

            return response()->json(['ok' => true, 'wordpress_flag' => false]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    });

    Route::get('/servers/{server}/hosting/cron', function (Server $server) {
        try {
            return response()->json(\PrmHosting\HostingService::getCron($server));
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    });

    Route::put('/servers/{server}/hosting/cron', function (Request $request, Server $server) {
        $content = (string) $request->input('content', '');
        $enable = $request->boolean('enabled', true);
        try {
            return response()->json([
                'ok' => true,
                'cron' => \PrmHosting\HostingService::putCron($server, $content, $enable),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    });

    Route::get('/servers/{server}/hosting/firewall', function (Server $server) {
        try {
            return response()->json(\PrmHosting\HostingService::getFirewall($server));
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    });

    Route::put('/servers/{server}/hosting/firewall', function (Request $request, Server $server) {
        $rules = $request->input('rules', []);
        if (is_string($rules)) {
            $parts = preg_split('/[\r\n,;]+/', $rules) ?: [];
            $rules = array_map(static fn ($ip) => ['ip' => trim((string) $ip), 'note' => ''], $parts);
        }
        try {
            $fw = \PrmHosting\HostingService::putFirewall($server, [
                'enabled' => $request->boolean('enabled', false),
                'mode' => (string) $request->input('mode', 'allow_all'),
                'rules' => is_array($rules) ? $rules : [],
            ]);

            return response()->json(['ok' => true, 'firewall' => $fw]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    });

    Route::get('/servers/{server}/hosting/logs', function (Request $request, Server $server) {
        try {
            return response()->json(\PrmHosting\LogBrowser::query($server, [
                'source' => (string) $request->query('source', 'all'),
                'ip' => (string) $request->query('ip', ''),
                'code' => (string) $request->query('code', ''),
                'q' => (string) $request->query('q', ''),
                'type' => (string) $request->query('type', ''),
                'limit' => (int) $request->query('limit', 150),
                'bytes' => (int) $request->query('bytes', 512000),
            ]));
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    });

    Route::get('/servers/{server}/hosting/logs/sources', function (Server $server) {
        try {
            return response()->json(['sources' => \PrmHosting\LogBrowser::catalog($server)]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    });
});
