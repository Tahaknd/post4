<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;

/**
 * A throwaway public/ directory with a Vite manifest, so tests render the real root view without
 * depending on `npm run build` (public/build is gitignored). The entries mirror vite.config.js.
 */
final class FakeViteBuild
{
    public const string CSS = 'assets/app-test0001.css';

    public const string JS = 'assets/app-test0001.js';

    /** Points the application's public path at a temporary directory holding build/manifest.json. */
    public static function install(): string
    {
        $public = sys_get_temp_dir().'/post4-vite-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($public.'/build');

        File::put($public.'/build/manifest.json', json_encode([
            'resources/css/app.css' => [
                'file' => self::CSS,
                'src' => 'resources/css/app.css',
                'isEntry' => true,
            ],
            'resources/js/app.js' => [
                'file' => self::JS,
                'src' => 'resources/js/app.js',
                'isEntry' => true,
                'css' => [self::CSS],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        app()->usePublicPath($public);

        return $public;
    }

    public static function remove(string $public): void
    {
        File::deleteDirectory($public);
    }
}
