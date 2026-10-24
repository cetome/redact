<?php

namespace Tests\Feature\Workflow;

use App\Billing\Plan;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * A product created without a name is "Product #1", "Product #2"… (owner, 2026-10-23).
 */
class DefaultProductNameTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    public function test_products_without_a_name_are_numbered_in_the_organisation(): void
    {
        $owner = $this->owner();
        $organisation = $owner->organisations()->first();
        // Several products: an organisation without the free plan's one-product limit.
        $organisation->forceFill(['plan' => Plan::ENTERPRISE])->save();
        $this->signIn($owner);

        foreach ([1, 2] as $_) {
            $this->post(route('products.store'), ['organisation_id' => $organisation->id, 'name' => ''])->assertRedirect()->assertSessionHasNoErrors();
        }
        // A number already taken by hand is skipped.
        Product::create(['organisation_id' => $organisation->id, 'owner_id' => $owner->id, 'name' => 'Product #3']);
        $this->post(route('products.store'), ['organisation_id' => $organisation->id])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(
            ['Product #1', 'Product #2', 'Product #3', 'Product #4'],
            Product::where('organisation_id', $organisation->id)->pluck('name')->all(),
        );
    }
}
