<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductVersion;
use Illuminate\Support\Facades\DB;

/**
 * The shared product card (x-product.card): the product's models (product level, one row per
 * model with an optional note) and the version of the product being assessed (hardware and
 * software/firmware version, on the product version). Saved by Product settings
 * (ProductController::update) and the Product step (VersionController::update).
 */
final class ProductModels
{
    /** @return array<string, list<string>> validation rules of the card's models and version fields */
    public static function rules(): array
    {
        return [
            'models' => ['nullable', 'array', 'max:50'],
            'models.*.value' => ['nullable', 'string', 'max:160'],
            'models.*.notes' => ['nullable', 'string', 'max:255'],
            'hardware_version' => ['nullable', 'string', 'max:80'],
            'software_version' => ['nullable', 'string', 'max:80'],
        ];
    }

    /**
     * Replace the product's models with the posted rows (blank rows are dropped, duplicates kept once).
     *
     * @param  array<int, array{value?: string|null, notes?: string|null}>  $rows
     */
    public static function sync(Product $product, array $rows): void
    {
        DB::transaction(function () use ($product, $rows) {
            $product->models()->delete();
            $position = 0;
            $seen = [];
            foreach ($rows as $row) {
                $value = trim((string) ($row['value'] ?? ''));
                if ($value === '' || isset($seen[mb_strtolower($value)])) {
                    continue;
                }
                $seen[mb_strtolower($value)] = true;
                $notes = trim((string) ($row['notes'] ?? ''));
                $product->models()->create([
                    'kind' => ProductIdentifier::MODEL,
                    'value' => $value,
                    'notes' => $notes !== '' ? $notes : null,
                    'position' => $position++,
                ]);
            }
        });
        $product->unsetRelation('models');
    }

    /** @param  array<string, mixed>  $data  validated input */
    public static function saveVersionNumbers(ProductVersion $version, array $data): void
    {
        $version->update([
            'hardware_version' => self::clean($data['hardware_version'] ?? null),
            'software_version' => self::clean($data['software_version'] ?? null),
        ]);
    }

    private static function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
