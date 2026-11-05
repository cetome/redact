<?php

namespace App\Assessment;

use App\Models\Product;
use App\Support\StoredPicture;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The product picture: PNG, JPEG or WebP, cleaned and re-encoded (App\Support\StoredPicture),
 * at most 1600 pixels on its longest side, on the private disk.
 */
class ProductImage
{
    public const MAX_KB = StoredPicture::MAX_KB;

    private const MAX_SIDE = 1600;

    public function __construct(private readonly StoredPicture $pictures) {}

    public function store(Product $product, UploadedFile $file): void
    {
        $this->pictures->store($product, 'image_path', 'products/'.$product->id, $file, self::MAX_SIDE);
    }

    public function delete(Product $product): void
    {
        $this->pictures->delete($product, 'image_path');
    }

    /** Absolute path of the picture, for the Word report. */
    public function path(Product $product): ?string
    {
        return $product->image_path && Storage::disk('local')->exists($product->image_path)
            ? Storage::disk('local')->path($product->image_path)
            : null;
    }
}
