<?php

namespace PrmHosting;

use Illuminate\Support\Facades\DB;
use Throwable;

class Settings
{
    public const KEY = 'prmhosting::config';

    public static function defaults(): array
    {
        return [
            'nest_id' => 6,
            'egg_id' => 17,
            'docker_image' => 'ghcr.io/ym0t/pterodactyl-nginx-egg:8.4-latest',
            'node_id' => 1,
            'owner_id' => 1,
            'startup' => './start-modules.sh',
            'packages' => [
                [
                    'id' => 'starter',
                    'name' => 'Starter',
                    'memory' => 512,
                    'swap' => 0,
                    'disk' => 5120,
                    'cpu' => 100,
                    'io' => 500,
                    'databases' => 1,
                    'allocations' => 1,
                    'backups' => 1,
                    'wordpress' => false,
                ],
                [
                    'id' => 'business',
                    'name' => 'Business',
                    'memory' => 1024,
                    'swap' => 0,
                    'disk' => 20480,
                    'cpu' => 200,
                    'io' => 500,
                    'databases' => 3,
                    'allocations' => 1,
                    'backups' => 3,
                    'wordpress' => true,
                ],
                [
                    'id' => 'pro',
                    'name' => 'Pro',
                    'memory' => 2048,
                    'swap' => 0,
                    'disk' => 51200,
                    'cpu' => 400,
                    'io' => 500,
                    'databases' => 5,
                    'allocations' => 2,
                    'backups' => 7,
                    'wordpress' => true,
                ],
            ],
        ];
    }

    public static function get(): array
    {
        $defaults = self::defaults();
        try {
            $row = DB::table('settings')->where('key', self::KEY)->first();
            if (!$row) {
                return $defaults;
            }
            try {
                $parsed = unserialize($row->value);
            } catch (Throwable $e) {
                $parsed = json_decode((string) $row->value, true);
            }
            if (!is_array($parsed)) {
                return $defaults;
            }

            return array_replace_recursive($defaults, $parsed);
        } catch (Throwable $e) {
            return $defaults;
        }
    }

    public static function put(array $config): void
    {
        $merged = array_replace_recursive(self::defaults(), $config);
        DB::table('settings')->updateOrInsert(
            ['key' => self::KEY],
            ['value' => serialize($merged)]
        );
    }

    public static function package(string $id): ?array
    {
        foreach (self::get()['packages'] as $pkg) {
            if (($pkg['id'] ?? '') === $id) {
                return $pkg;
            }
        }

        return null;
    }
}
