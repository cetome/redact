<?php

namespace Tests\Feature\Workflow;

use App\Declaration\DeclarationData;
use App\Enums\ProductRole;
use App\Models\Document;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\User;
use App\Reports\ReportData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsProducts;
use Tests\Feature\Guest\GuestHelpers;
use Tests\TestCase;

/**
 * The product card (x-product.card) on Product settings, the Product step and the Overview:
 * product-level models, the product version (hardware, software) of a version, the picture and the
 * documents; opening a product leads to its latest version's Overview.
 */
class ProductPageTest extends TestCase
{
    use BuildsProducts, GuestHelpers, RefreshDatabase;

    public function test_models_are_added_and_removed_at_product_level_from_settings(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        $product = $version->product;

        $this->signIn($owner)->put(route('products.update', $product), [
            'name' => 'Smart lock',
            'models_sent' => '1',
            'models' => [
                ['value' => 'SL-100', 'notes' => 'EU variant'],
                ['value' => '  '],
                ['value' => 'SL-200', 'notes' => ''],
                ['value' => 'sl-100'],
            ],
        ])->assertRedirect();

        $models = $product->models()->get();
        $this->assertSame(['SL-100', 'SL-200'], $models->pluck('value')->all());
        $this->assertSame(['EU variant', null], $models->pluck('notes')->all());
        $this->assertTrue($models->every(fn ($m) => $m->product_version_id === null && $m->kind === ProductIdentifier::MODEL));
        $this->assertSame(0, $version->identifiers()->count(), 'Models are not version identifiers.');

        // Removing every row clears them (the marker says the block was in the form).
        $this->put(route('products.update', $product), ['name' => 'Smart lock', 'models_sent' => '1'])->assertRedirect();
        $this->assertSame(0, $product->models()->count());

        // A form without the block (another client) leaves them alone.
        ProductIdentifier::create(['product_id' => $product->id, 'kind' => 'model', 'value' => 'SL-300', 'position' => 0]);
        $this->put(route('products.update', $product), ['name' => 'Smart lock 2'])->assertRedirect();
        $this->assertSame(['SL-300'], $product->models()->pluck('value')->all());
    }

    public function test_the_product_step_saves_models_and_the_product_version(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);

        $this->signIn($owner)->put(route('versions.update', $version), [
            'description' => 'A smart lock.',
            'models_sent' => '1',
            'models' => [['value' => 'SL-100', 'notes' => '']],
            'hardware_version' => ' rev C ',
            'software_version' => '3.4.1',
        ])->assertRedirect();

        $version->refresh();
        $this->assertSame('rev C', $version->hardware_version);
        $this->assertSame('3.4.1', $version->software_version);
        $this->assertSame(['SL-100'], $version->product->models()->pluck('value')->all());

        // The step shows the product version in the card and the models in the Product identification
        // only (owner, 2026-10-23: one place), with no kind the card already holds.
        $this->get(route('versions.edit', $version))->assertOk()
            ->assertSee('a-pcard', false)->assertSee('rev C')->assertSee('3.4.1')->assertSee('SL-100')
            ->assertSee(route('products.image.store', $version->product), false)
            ->assertSee('<option value="model">', false)
            ->assertDontSee('a-pcard-models', false)
            ->assertDontSee('<option value="reference">', false)
            ->assertDontSee('<option value="hvin">', false);

        // The Product identification saves the model rows on the product, the others on the version.
        $this->put(route('versions.identification', $version), [
            'identifiers' => [['kind' => 'model', 'value' => 'SL-100'], ['kind' => 'model', 'value' => 'SL-100X'], ['kind' => 'brand', 'value' => 'Lockly']],
        ])->assertSessionHasNoErrors();
        $this->assertSame(['SL-100', 'SL-100X'], $version->product->models()->pluck('value')->all());
        $this->assertSame(['brand'], $version->identifiers()->pluck('kind')->all());

        // A new version starts with neither (never prefilled) and still shows the product's models.
        $this->travel(1)->minutes();
        $this->post(route('versions.store', $version->product), ['label' => '2.0', 'from' => $version->id])->assertRedirect();
        $next = $version->product->versions()->first();
        $this->assertSame('2.0', $next->label);
        $this->assertNull($next->hardware_version);
        $this->assertNull($next->software_version);
    }

    public function test_reports_and_the_declaration_show_models_and_product_version(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        ProductIdentifier::create(['product_id' => $version->product_id, 'kind' => 'model', 'value' => 'SL-100', 'notes' => 'EU', 'position' => 0]);
        $version->identifiers()->create(['kind' => 'brand', 'value' => 'Acme', 'position' => 0]);
        $version->update(['hardware_version' => 'rev C', 'software_version' => '3.4.1']);

        $data = ReportData::for($version->fresh(), $owner);
        $this->assertSame('SL-100 (EU)', $data->product['models']);
        $this->assertSame('rev C', $data->version['hardware_version']);
        $this->assertSame('3.4.1', $data->version['software_version']);

        $declaration = DeclarationData::for($version->fresh());
        $this->assertSame(['model', 'brand', 'hardware_version', 'software_version'], array_column($declaration->identifiers(), 'kind'));
        $this->assertSame('SL-100', $declaration->typeDesignation());

        $this->signIn($owner)->get(route('reports.download', ['version' => $version, 'kind' => 'assessment', 'format' => 'xlsx']))->assertOk();
    }

    public function test_settings_show_the_card_with_picture_documents_and_latest_version(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        $first = $this->product($owner);
        $this->travel(1)->minutes();
        $this->signIn($owner)->post(route('versions.store', $first->product), ['label' => '2.0'])->assertRedirect();
        $latest = $first->product->versions()->first();
        $this->assertSame('2.0', $latest->label);

        $this->from(route('products.edit', $first->product))
            ->post(route('versions.documents.store', $latest), ['document' => UploadedFile::fake()->createWithContent('datasheet.md', '# Lock')])
            ->assertRedirect(route('products.edit', $first->product));

        $this->get(route('products.edit', $first->product))->assertOk()
            ->assertSee('a-pcard-pic', false)
            ->assertSee(route('products.image.store', $first->product), false)
            ->assertSee(__('products.card.version_label', ['label' => '2.0']))
            ->assertSee('datasheet.md')
            ->assertSee(__('ai.documents.delete_tooltip'))
            ->assertSee('action="'.route('versions.import.store', $latest).'"', false)
            ->assertSee(__('products.delete'));

        // The product version typed on the settings page goes to the latest version.
        $this->put(route('products.update', $first->product), [
            'name' => 'Smart lock', 'hardware_version' => 'B2', 'software_version' => '',
        ])->assertRedirect();
        $this->assertSame('B2', $latest->fresh()->hardware_version);
        $this->assertNull($first->fresh()->hardware_version);
    }

    public function test_documents_are_uploaded_and_deleted_only_by_editors(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        $version = $this->product($owner);
        $reviewer = $this->member($version->product, ProductRole::Reviewer);
        $stranger = User::factory()->create();

        $this->signIn($owner)->post(route('versions.documents.store', $version), [
            'document' => UploadedFile::fake()->createWithContent('manual.txt', 'Manual'),
        ])->assertRedirect();
        $document = Document::sole();

        // A reviewer sees the list but has no upload or delete, and is refused (404) when trying.
        $this->signIn($reviewer)->get(route('versions.show', $version))->assertOk()
            ->assertSee('manual.txt')
            ->assertDontSee('action="'.route('versions.import.store', $version).'"', false)
            ->assertDontSee(__('ai.documents.delete_tooltip'));
        $this->post(route('versions.documents.store', $version), ['document' => UploadedFile::fake()->createWithContent('x.txt', 'x')])->assertNotFound();
        $this->delete(route('versions.documents.destroy', [$version, $document]))->assertNotFound();

        $this->signIn($stranger)->delete(route('versions.documents.destroy', [$version, $document]))->assertNotFound();
        $this->assertSame(1, Document::count());

        // The owner deletes it (the form asks for confirmation first).
        $this->signIn($owner)->get(route('versions.show', $version))->assertOk()
            ->assertSee(__('ai.documents.confirm_delete', ['name' => 'manual.txt']));
        $this->delete(route('versions.documents.destroy', [$version, $document]))->assertRedirect();
        $this->assertSame(0, Document::count());
    }

    public function test_a_guest_sees_the_locked_documents_state(): void
    {
        $this->setUpGuest();
        Storage::fake('local');
        $guest = $this->startSandbox();
        $version = $this->guestProduct($guest);

        $this->get(route('versions.edit', $version))->assertOk()
            ->assertSee(__('ai.documents.locked'))
            ->assertSee(route('guest.claim'), false)
            ->assertDontSee('action="'.route('versions.import.store', $version).'"', false);
        $this->post(route('versions.documents.store', $version), [
            'document' => UploadedFile::fake()->createWithContent('notes.txt', 'x'),
        ])->assertRedirect(route('guest.claim'));
        $this->assertSame(0, $version->documents()->count());
    }

    public function test_opening_a_product_leads_to_the_overview_of_its_latest_version(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        $product = $version->product;
        $contributor = $this->member($product, ProductRole::Contributor);
        $this->travel(1)->minutes();
        $this->signIn($owner)->post(route('versions.store', $product), ['label' => '2.0', 'from' => $version->id]);
        $latest = $product->versions()->first();

        $this->get(route('products.show', $product))->assertRedirect(route('versions.show', $latest));
        $this->get(route('dashboard'))->assertOk()->assertSee(route('versions.show', $latest), false);

        // The Overview carries the product: card, versions (this one marked), team, settings.
        $this->get(route('versions.show', $version))->assertOk()
            ->assertSee('a-pcard', false)
            ->assertSee(__('products.versions'))->assertSee('2.0')->assertSee(__('products.this_version'))
            ->assertSee($contributor->name)
            ->assertSee(route('products.edit', $product), false)
            ->assertSee('new-version', false);

        // Strangers still get a 404.
        $this->signIn(User::factory()->create())->get(route('products.show', $product))->assertNotFound();
    }

    public function test_a_product_without_version_keeps_a_small_page(): void
    {
        $owner = $this->owner();
        $product = Product::create(['organisation_id' => $owner->organisations()->first()->id, 'owner_id' => $owner->id, 'name' => 'Bare lock']);

        $this->signIn($owner)->get(route('products.show', $product))->assertOk()
            ->assertSee('Bare lock')->assertSee(__('products.start_version'))
            ->assertSee(route('versions.store', $product), false)
            ->assertSee(__('products.card.no_version'));
    }
}
