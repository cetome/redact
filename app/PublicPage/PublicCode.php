<?php

namespace App\PublicPage;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * The product's public address /verify/{code}: six characters of Crockford's base 32 (no I, L,
 * O or U, so nobody misreads a printed label), random, never sequential. Given on the first
 * publication and kept for good: the QR codes already printed keep working.
 */
final class PublicCode
{
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const LENGTH = 6;

    /** The route pattern of a code. */
    public const PATTERN = '[0-9A-HJKMNP-TV-Z]{6}';

    public static function for(Product $product): string
    {
        if ($product->public_code) {
            return $product->public_code;
        }

        return DB::transaction(function () use ($product) {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->getKey());
            if (! $locked->public_code) {
                do {
                    $code = self::random();
                } while (Product::query()->where('public_code', $code)->exists());
                $locked->forceFill(['public_code' => $code])->saveQuietly();
            }
            $product->public_code = $locked->public_code;

            return (string) $locked->public_code;
        });
    }

    public static function url(Product $product): ?string
    {
        return $product->public_code ? route('verify.show', $product->public_code) : null;
    }

    private static function random(): string
    {
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }
}
