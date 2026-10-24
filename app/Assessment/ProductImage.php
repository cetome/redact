<?php

namespace App\Assessment;

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The product picture. Only PNG, JPEG and WebP are accepted, recognised by
 * their content. The picture is decoded and re-encoded with GD: this drops
 * any metadata (EXIF location, camera serial...) and any payload hidden after
 * the image data, and caps its size. Stored on the private disk.
 */
class ProductImage
{
    public const MAX_KB = 5120;

    private const MAX_SIDE = 1600;

    public function store(Product $product, UploadedFile $file): void
    {
        $info = @getimagesize($file->getRealPath());
        $type = $info[2] ?? null;
        if (! in_array($type, [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            throw ValidationException::withMessages(['image' => __('identification.image_invalid')]);
        }

        $source = match ($type) {
            IMAGETYPE_PNG => @imagecreatefrompng($file->getRealPath()),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file->getRealPath()),
            IMAGETYPE_WEBP => @imagecreatefromwebp($file->getRealPath()),
        };
        if (! $source) {
            throw ValidationException::withMessages(['image' => __('identification.image_invalid')]);
        }

        [$width, $height] = [imagesx($source), imagesy($source)];
        $scale = min(1, self::MAX_SIDE / max($width, $height));
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));

        $image = imagecreatetruecolor($w, $h);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 255, 255, 127));
        imagecopyresampled($image, $source, 0, 0, 0, 0, $w, $h, $width, $height);

        ob_start();
        imagepng($image, null, 6);
        $png = (string) ob_get_clean();

        $this->delete($product);
        $path = 'products/'.$product->id.'/picture-'.bin2hex(random_bytes(6)).'.png';
        Storage::disk('local')->put($path, $png);
        $product->forceFill(['image_path' => $path])->save();
    }

    public function delete(Product $product): void
    {
        if ($product->image_path) {
            Storage::disk('local')->delete($product->image_path);
            $product->forceFill(['image_path' => null])->save();
        }
    }

    /** Absolute path of the picture, for the Word report. */
    public function path(Product $product): ?string
    {
        return $product->image_path && Storage::disk('local')->exists($product->image_path)
            ? Storage::disk('local')->path($product->image_path)
            : null;
    }
}
