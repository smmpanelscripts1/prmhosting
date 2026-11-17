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
});
