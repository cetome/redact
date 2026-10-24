<?php

namespace App\Http\Controllers;

use App\Assessment\ProductImage;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductVersion;
use App\Support\ProductModels;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Product picture and product identifiers.
 */
class IdentificationController extends Controller
{
    /**
     * Replace the Product identification: the model rows are the product's (ProductModels), the
     * other rows the version's identifiers.
     */
    public function update(Request $request, ProductVersion $version): RedirectResponse
    {
        $this->allow('edit', $version);

        $data = $request->validate([
            'identifiers' => ['nullable', 'array', 'max:100'],
            'identifiers.*.kind' => ['required_with:identifiers.*.value', Rule::in(ProductIdentifier::KINDS)],
            'identifiers.*.value' => ['nullable', 'string', 'max:160'],
            'identifiers.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        $rows = collect($data['identifiers'] ?? []);
        [$models, $identifiers] = $rows->partition(fn ($row) => ($row['kind'] ?? null) === ProductIdentifier::MODEL);

        DB::transaction(function () use ($version, $models, $identifiers) {
            ProductModels::sync($version->product, $models->values()->all());
            $version->identifiers()->delete();
            $position = 0;
            foreach ($identifiers as $row) {
                if (trim((string) ($row['value'] ?? '')) === '') {
                    continue;
                }
                $version->identifiers()->create([
                    'kind' => $row['kind'],
                    'value' => trim($row['value']),
                    'notes' => $row['notes'] ?? null,
                    'position' => $position++,
                ]);
            }

        });

        return redirect()->to(route('versions.edit', $version).'#identification')->with('status', __('common.saved'));
    }

    public function storeImage(Request $request, Product $product, ProductImage $images): RedirectResponse
    {
        $this->allow('manage', $product);
        $request->validate([
            'image' => ['required', 'file', 'max:'.ProductImage::MAX_KB, 'mimes:png,jpg,jpeg,webp'],
        ]);

        $images->store($product, $request->file('image'));
        activity('product')->causedBy($request->user())->performedOn($product)->log('picture changed');

        return back()->with('status', __('identification.image_saved'));
    }

    public function destroyImage(Request $request, Product $product, ProductImage $images): RedirectResponse
    {
        $this->allow('manage', $product);

        $images->delete($product);
        activity('product')->causedBy($request->user())->performedOn($product)->log('picture removed');

        return back()->with('status', __('identification.image_removed'));
    }

    /** The picture, for people who can see the product. Never cached by shared caches. */
    public function showImage(Product $product): StreamedResponse
    {
        abort_unless($this->access($product)->canView() && $product->image_path, 404);
        abort_unless(Storage::disk('local')->exists($product->image_path), 404);

        return Storage::disk('local')->response($product->image_path, 'product.png', [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
