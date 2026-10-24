<?php

namespace Tests\Feature\Workflow;

use App\Assessment\VersionFactory;
use App\Enums\ProductRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

class IdentificationTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    public function test_identifiers_and_route_are_saved_and_copied_to_a_new_version(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);

        $this->signIn($owner)->put(route('versions.identification', $version), [
            'identifiers' => [
                ['kind' => 'type', 'value' => 'SL-100', 'notes' => ''],
                ['kind' => 'trade_name', 'value' => 'Smart Lock Pro', 'notes' => 'EU'],
                ['kind' => 'hvin', 'value' => '   '],
            ],
        ])->assertRedirect();

        $version->refresh();
        $this->assertSame(['SL-100', 'Smart Lock Pro'], $version->identifiers->pluck('value')->all());

        $next = app(VersionFactory::class)->create($version->product, '2.0', $owner, $version);
        $this->assertSame(['SL-100', 'Smart Lock Pro'], $next->identifiers->pluck('value')->all());
    }

    public function test_bad_identifier_kinds_are_refused(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);

        $this->signIn($owner)->put(route('versions.identification', $version), [
            'identifiers' => [['kind' => 'nope', 'value' => 'x']],
        ])->assertSessionHasErrors(['identifiers.0.kind']);
    }

    public function test_picture_is_reencoded_private_and_only_for_the_team(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        $version = $this->product($owner);
        $product = $version->product;
        $reviewer = $this->member($product, ProductRole::Reviewer);
        $stranger = User::factory()->create();

        $this->signIn($owner)->post(route('products.image.store', $product), [
            'image' => UploadedFile::fake()->image('lock.jpg', 2400, 1200),
        ])->assertRedirect();

        $product->refresh();
        $this->assertNotNull($product->image_path);
        [$w, $h] = getimagesizefromstring(Storage::disk('local')->get($product->image_path));
        $this->assertSame([1600, 800], [$w, $h]);

        $this->signIn($reviewer)->get(route('products.image', $product))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->signIn($stranger)->get(route('products.image', $product))->assertNotFound();
        $this->signIn($reviewer)->post(route('products.image.store', $product), [
            'image' => UploadedFile::fake()->image('x.png'),
        ])->assertNotFound();

        // A file pretending to be an image is refused.
        $this->signIn($owner)->post(route('products.image.store', $product), [
            'image' => UploadedFile::fake()->createWithContent('fake.png', '<svg onload="alert(1)"></svg>'),
        ])->assertSessionHasErrors('image');
    }
}
