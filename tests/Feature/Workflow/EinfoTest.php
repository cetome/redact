<?php

namespace Tests\Feature\Workflow;

use App\Enums\ProductRole;
use App\Models\EinfoEntry;
use App\Models\InventoryItem;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * The E.Info step, one object at a time (demo pack: E.Info.UPD-1.Product for the product once
 * UPD-1 is walked, E.Info.ACC-1.Asset for each asset once ACC-1 is walked for it).
 */
class EinfoTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    public function test_saving_one_object_leaves_the_entries_of_the_others_alone(): void
    {
        [$owner, $version, $first, $second] = $this->documentedProduct();

        $this->signIn($owner)->put(route('versions.einfo.update', [$version, 'demo-1']), [
            'object' => $first->id,
            'entries' => [$first->id => ['E.Info.ACC-1.Asset' => 'Front door key, paired app only.']],
        ])->assertRedirect(route('versions.einfo.object', [$version, 'demo-1', $first->id]));

        // The card of the second object posts only its own fields.
        $this->signIn($owner)->put(route('versions.einfo.update', [$version, 'demo-1']), [
            'object' => $second->id,
            'entries' => [$second->id => ['E.Info.ACC-1.Asset' => 'Back door key.']],
        ])->assertRedirect();

        $texts = $version->einfoEntries()->get()->mapWithKeys(fn (EinfoEntry $e) => [$e->inventory_item_id ?? 'product' => $e->text])->all();
        $this->assertSame([$first->id => 'Front door key, paired app only.', $second->id => 'Back door key.'], $texts);

        // Clearing a field of one object clears only that one.
        $this->signIn($owner)->put(route('versions.einfo.update', [$version, 'demo-1']), [
            'object' => $first->id,
            'entries' => [$first->id => ['E.Info.ACC-1.Asset' => '']],
        ])->assertRedirect();
        $this->assertNull($version->einfoEntries()->where('inventory_item_id', $first->id)->value('text'));
        $this->assertSame('Back door key.', $version->einfoEntries()->where('inventory_item_id', $second->id)->value('text'));
    }

    public function test_save_and_next_goes_to_the_next_object_with_missing_fields(): void
    {
        [$owner, $version, $first, $second] = $this->documentedProduct();
        $url = route('versions.einfo', [$version, 'demo-1']);

        // The product is documented already: from the first asset, the next one to write is the second.
        EinfoEntry::create(['product_version_id' => $version->id, 'einfo' => 'E.Info.UPD-1.Product', 'inventory_item_id' => null, 'text' => 'Over the air.']);
        $this->signIn($owner)->put(route('versions.einfo.update', [$version, 'demo-1']), [
            'object' => $first->id, 'next' => '1', 'q' => '', 'show' => 'missing',
            'entries' => [$first->id => ['E.Info.ACC-1.Asset' => 'Paired app only.']],
        ])->assertRedirect(route('versions.einfo.missing.object', [$version, 'demo-1', $second->id]));

        // Everything documented: "next" is the following object, and the last one stays.
        EinfoEntry::create(['product_version_id' => $version->id, 'einfo' => 'E.Info.ACC-1.Asset', 'inventory_item_id' => $second->id, 'text' => 'Back door.']);
        $this->signIn($owner)->put(route('versions.einfo.update', [$version, 'demo-1']), [
            'object' => 'product', 'next' => '1', 'entries' => ['product' => ['E.Info.UPD-1.Product' => 'Over the air.']],
        ])->assertRedirect($url.'/'.$first->id);
        $this->signIn($owner)->put(route('versions.einfo.update', [$version, 'demo-1']), [
            'object' => $second->id, 'next' => '1', 'entries' => [$second->id => ['E.Info.ACC-1.Asset' => 'Back door.']],
        ])->assertRedirect($url.'/'.$second->id);
    }

    public function test_the_page_shows_one_object_with_search_and_missing_filter(): void
    {
        [$owner, $version, $first, $second] = $this->documentedProduct();
        EinfoEntry::create(['product_version_id' => $version->id, 'einfo' => 'E.Info.ACC-1.Asset', 'inventory_item_id' => $first->id, 'text' => 'Paired app only.']);
        $url = route('versions.einfo', [$version, 'demo-1']);
        $this->signIn($owner);

        // Default: the first object, every object listed, only its fields in the form.
        $this->get($url)->assertOk()
            ->assertSee('name="entries[product][E.Info.UPD-1.Product]"', false)
            ->assertDontSee('name="entries['.$first->id.']', false)
            ->assertSee('href="'.$url.'/'.$first->id.'"', false)
            ->assertSee('href="'.$url.'/'.$second->id.'"', false)
            ->assertSee('data-shortcut="j"', false);

        // Linkable object.
        $this->get($url.'/'.$second->id)->assertOk()
            ->assertSee('name="entries['.$second->id.'][E.Info.ACC-1.Asset]"', false)
            ->assertDontSee('name="entries[product]', false);

        // The search runs in the browser (einfo.js) on what the list carries: no address holds it.
        $this->get($url)->assertOk()->assertSee('data-einfo-search', false)->assertSee('paired app only', false)
            ->assertSee('data-scope="'.$version->id.'/demo-1"', false);

        // Missing only: an object fully documented is not opened by default.
        $this->get($url.'/missing')->assertOk()
            ->assertSee('name="entries[product][E.Info.UPD-1.Product]"', false);
        $this->get($url.'/missing/nope')->assertNotFound();
        $this->get($url.'/missing/'.$first->id)->assertOk()
            ->assertSee(trans_choice('einfo.documented_hidden', 1, ['count' => 1]));

        // The object select of narrow screens: each option carries its address, and without script it posts.
        $this->get($url)->assertSee('data-href="'.$url.'/'.$second->id.'"', false);
        $this->post(route('versions.einfo.pick', [$version, 'demo-1']), ['object' => $second->id, 'show' => 'missing'])
            ->assertRedirect($url.'/missing/'.$second->id);
    }

    public function test_a_reviewer_sees_the_card_read_only(): void
    {
        [$owner, $version] = $this->documentedProduct();
        $reviewer = $this->member($version->product, ProductRole::Reviewer);

        $this->signIn($reviewer)->get(route('versions.einfo', [$version, 'demo-1']))->assertOk()
            ->assertSee('<fieldset disabled', false)
            ->assertDontSee(__('einfo.save_next'));
        $this->signIn($reviewer)->put(route('versions.einfo.update', [$version, 'demo-1']), [
            'object' => 'product', 'entries' => ['product' => ['E.Info.UPD-1.Product' => 'x']],
        ])->assertNotFound();
    }

    /** @return array{0: User, 1: ProductVersion, 2: InventoryItem, 3: InventoryItem} */
    private function documentedProduct(): array
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        $version->scopingAnswers()->create(['question' => 'connected', 'value' => true]);
        $first = $version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-01', 'name' => 'Front door key', 'attributes' => ['type' => 'security'], 'position' => 1]);
        $second = $version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-02', 'name' => 'Back door key', 'attributes' => ['type' => 'security'], 'position' => 2]);

        foreach ([$first, $second] as $asset) {
            $this->signIn($owner)->put(route('versions.evaluate.update', [$version, 'demo-1', 'ACC-1']), [
                'target' => $asset->id, 'answers' => ['No', 'Yes'], 'justification' => 'Paired app.',
            ])->assertRedirect();
        }
        $this->signIn($owner)->put(route('versions.evaluate.update', [$version, 'demo-1', 'UPD-1']), [
            'target' => 'product', 'answers' => ['Yes'],
        ])->assertRedirect();

        return [$owner, $version, $first, $second];
    }
}
