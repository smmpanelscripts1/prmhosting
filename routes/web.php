<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Pterodactyl\Http\Middleware\AdminAuthenticate;

$lib = '/blueprint_extensions/prmhosting/lib';
if (!is_dir($lib)) {
    $lib = dirname(__DIR__) . '/lib';
}
require_once $lib . '/Settings.php';

Route::middleware([AdminAuthenticate::class])->group(function () {
    Route::post('/admin/save', function (Request $request) {
        $cfg = \PrmHosting\Settings::get();
        $cfg['nest_id'] = (int) $request->input('nest_id', $cfg['nest_id']);
        $cfg['egg_id'] = (int) $request->input('egg_id', $cfg['egg_id']);
        $cfg['docker_image'] = trim((string) $request->input('docker_image', $cfg['docker_image']));
        $cfg['node_id'] = (int) $request->input('node_id', $cfg['node_id']);
        $cfg['owner_id'] = (int) $request->input('owner_id', $cfg['owner_id']);
        $cfg['startup'] = trim((string) $request->input('startup', $cfg['startup']));

        $packagesRaw = (string) $request->input('packages_json', '');
        if ($packagesRaw !== '') {
            $decoded = json_decode($packagesRaw, true);
            if (is_array($decoded)) {
                $cfg['packages'] = $decoded;
            }
        }

        \PrmHosting\Settings::put($cfg);

        return redirect('/admin/extensions/prmhosting')->with('success', 'Hosting ayarları kaydedildi.');
    });
});

Route::get('/packages', function () {
    return response()->json([
        'config' => [
            'nest_id' => \PrmHosting\Settings::get()['nest_id'],
            'egg_id' => \PrmHosting\Settings::get()['egg_id'],
            'docker_image' => \PrmHosting\Settings::get()['docker_image'],
        ],
        'packages' => \PrmHosting\Settings::get()['packages'],
    ]);
});
