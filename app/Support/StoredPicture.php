<?php

namespace App\Support;

use enshrined\svgSanitize\Sanitizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A picture kept on the private disk: a product's, an organisation's logo, a person's. Only PNG,
 * JPEG and WebP are accepted, recognised by their content. The picture is decoded and re-encoded
 * with GD as PNG: this drops any metadata (EXIF location, camera serial...) and any payload hidden
 * after the image data, and caps its size.
 */
class StoredPicture
{
    /** The largest upload accepted, in kilobytes. */
    public const MAX_KB = 5120;

    /** The largest SVG accepted (logos), in kilobytes. */
    public const SVG_MAX_KB = 1024;

    /**
     * Stores $file as the picture of $model (in $column, under $folder), replacing the one before.
     *
     * @param  string  $field  the form field, for the error message
     */
    public function store(Model $model, string $column, string $folder, UploadedFile $file, int $maxSide, string $field = 'image'): void
    {
        $png = self::png($file, $maxSide, $field);
        $this->delete($model, $column);
        $path = trim($folder, '/').'/picture-'.bin2hex(random_bytes(6)).'.png';
        Storage::disk('local')->put($path, $png);
        $model->forceFill([$column => $path])->save();
    }

    /**
     * Stores an SVG as the picture of $model (logos: sharp at any size). Cleaned first
     * (enshrined/svg-sanitize: no script, no event handler, no reference outside the file), refused
     * when nothing usable is left; always served with a sandbox policy (response()).
     */
    public function storeSvg(Model $model, string $column, string $folder, UploadedFile $file, string $field = 'image'): void
    {
        $sanitizer = new Sanitizer;
        $sanitizer->removeRemoteReferences(true);
        $sanitizer->minify(true);
        $clean = $sanitizer->sanitize((string) file_get_contents($file->getRealPath()));
        if (! is_string($clean) || ! str_contains($clean, '<svg')) {
            throw ValidationException::withMessages([$field => __('identification.image_invalid')]);
        }

        $this->delete($model, $column);
        $path = trim($folder, '/').'/picture-'.bin2hex(random_bytes(6)).'.svg';
        Storage::disk('local')->put($path, $clean);
        $model->forceFill([$column => $path])->save();
    }

    /** The upload is an SVG (by its name and its content), not a bitmap. */
    public static function isSvg(UploadedFile $file): bool
    {
        $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 1024);

        return strtolower($file->getClientOriginalExtension()) === 'svg' && (str_contains($head, '<svg') || str_contains($head, '<?xml'));
    }

    public function delete(Model $model, string $column): void
    {
        $path = $model->getAttribute($column);
        if (is_string($path) && $path !== '') {
            Storage::disk('local')->delete($path);
            $model->forceFill([$column => null])->save();
        }
    }

    /** The PNG bytes of the picture, at most $maxSide pixels on its longest side. */
    public static function png(UploadedFile $file, int $maxSide, string $field = 'image'): string
    {
        $info = @getimagesize($file->getRealPath());
        $type = $info[2] ?? null;
        if (! in_array($type, [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            throw ValidationException::withMessages([$field => __('identification.image_invalid')]);
        }

        $source = match ($type) {
            IMAGETYPE_PNG => @imagecreatefrompng($file->getRealPath()),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file->getRealPath()),
            IMAGETYPE_WEBP => @imagecreatefromwebp($file->getRealPath()),
        };
        if (! $source) {
            throw ValidationException::withMessages([$field => __('identification.image_invalid')]);
        }

        [$width, $height] = [imagesx($source), imagesy($source)];
        $scale = min(1, $maxSide / max($width, $height));
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));

        $image = imagecreatetruecolor($w, $h);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 255, 255, 127));
        imagecopyresampled($image, $source, 0, 0, 0, 0, $w, $h, $width, $height);

        ob_start();
        imagepng($image, null, 6);

        return (string) ob_get_clean();
    }

    /** The private response serving a stored picture (never run as a page). */
    public static function response(string $path, string $name, string $cache = 'private, max-age=300'): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($path), 404);

        $svg = str_ends_with($path, '.svg');

        return Storage::disk('local')->response($path, $svg ? preg_replace('/\.png$/', '.svg', $name) : $name, [
            'Content-Type' => $svg ? 'image/svg+xml' : 'image/png',
            'Cache-Control' => $cache,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
