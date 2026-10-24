<?php

namespace App\Support;

use App\Content\ContentPack;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

/**
 * The free workbooks of /templates: the original cetome REDact downloads, unaltered, read from the
 * content pack (ContentPack::freeTemplates()). The "Download all" archive is built once, on first
 * need, into storage/app/private/templates/ and reused until a workbook changes.
 */
final class FreeTemplates
{
    public const ALL = 'all';

    public function __construct(private readonly ContentPack $pack) {}

    /**
     * Key => file details, in pack order; image: its picture (path, width, height, version) or null.
     *
     * @return array<string, array{key: string, path: string, name: string, version: ?string, size: int, image: ?array{path: string, width: int, height: int, v: string}}>
     */
    public function all(): array
    {
        $pack = $this->pack->freeTemplates();
        $files = [];
        foreach ($pack['files'] as $key => $path) {
            $name = basename($path);
            $files[$key] = [
                'key' => $key,
                'path' => $path,
                'name' => $name,
                // "cetome REDact - E.DT and E.Just v1.03.xlsx" => "1.03"
                'version' => preg_match('/\bv(\d+(?:\.\d+)*)\.xlsx$/i', $name, $m) === 1 ? $m[1] : null,
                'size' => (int) filesize($path),
                'image' => isset($pack['images'][$key]) ? $this->image($pack['images'][$key]) : null,
            ];
        }

        return $files;
    }

    /** @return array{path: string, width: int, height: int, v: string}|null */
    private function image(string $path): ?array
    {
        $size = @getimagesize($path);

        return $size === false ? null : [
            'path' => $path,
            'width' => (int) $size[0],
            'height' => (int) $size[1],
            // Cache buster of the picture's address (served with a one-year cache).
            'v' => substr(hash('xxh3', filemtime($path).':'.filesize($path)), 0, 8),
        ];
    }

    /** @return array{key: string, path: string, name: string, version: ?string, size: int, image?: mixed}|null */
    public function find(string $key): ?array
    {
        if ($key === self::ALL) {
            return $this->zip();
        }

        return $this->all()[$key] ?? null;
    }

    /**
     * The archive of every workbook (built on first need, then cached on disk), or null without workbooks.
     *
     * @return array{key: string, path: string, name: string, version: ?string, size: int}|null
     */
    public function zip(): ?array
    {
        $files = $this->all();
        if ($files === []) {
            return null;
        }

        $name = $this->pack->freeTemplates()['zip'];
        $fingerprint = hash('sha256', $name.'|'.implode('|', array_map(
            fn (array $f) => $f['path'].':'.$f['size'].':'.filemtime($f['path']),
            $files,
        )));
        $dir = storage_path('app/private/templates');
        $path = $dir.'/free-'.substr($fingerprint, 0, 24).'.zip';

        if (! is_file($path)) {
            File::ensureDirectoryExists($dir);
            $tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
            $zip = new ZipArchive;
            if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('The templates archive could not be created.');
            }
            foreach ($files as $file) {
                $zip->addFile($file['path'], $file['name']);
            }
            $zip->close();
            rename($tmp, $path);

            // Older archives (a workbook changed since) are not needed any more.
            foreach (glob($dir.'/free-*.zip') ?: [] as $old) {
                if ($old !== $path) {
                    @unlink($old);
                }
            }
        }

        return [
            'key' => self::ALL,
            'path' => $path,
            'name' => $name,
            'version' => preg_match('/\bv(\d+(?:\.\d+)*)\.zip$/i', $name, $m) === 1 ? $m[1] : null,
            'size' => (int) filesize($path),
        ];
    }
}
