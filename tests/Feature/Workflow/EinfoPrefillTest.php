<?php

namespace Tests\Feature\Workflow;

use App\Ai\Provenance;
use App\Assessment\Assessment;
use App\Assessment\EinfoPrefill;
use App\Content\ContentPack;
use App\Content\VersionContext;
use App\Enums\Origin;
use App\Enums\ProductRole;
use App\Models\EinfoEntry;
use App\Models\FieldOrigin;
use App\Models\InventoryItem;
use App\Models\InventoryLink;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * E.Info texts the content pack fills in from the inventory (demo pack: einfo-prefill.json gives
 * E.Info.ACC-1.Asset the asset's description). Shown, marked "From inventory", stored only when a
 * person saves; counted as missing until then.
 */
class EinfoPrefillTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    private const CODE = 'E.Info.ACC-1.Asset';

    public function test_the_prefilled_text_is_shown_marked_and_counts_as_missing_until_saved(): void
    {
        [$owner, $version, $asset] = $this->walkedAsset('Front door key, paired with the app only.');

        $this->signIn($owner)->get($this->url($version, $asset))->assertOk()
            ->assertSee('Front door key, paired with the app only.</textarea>', false)
            ->assertSee(__('ai.origin.from_inventory.label'))
            ->assertSee(__('einfo.prefill.unsaved'))
            ->assertSee(trans_choice('einfo.prefill.pending', 1, ['count' => 1]))
            ->assertSee(__('einfo.prefill.accept'));

        $this->assertSame(0, $version->einfoEntries()->count(), 'nothing is stored by showing the page');
        $this->assertSame(0, Assessment::for($version->fresh())->einfoProgress('demo-1')['done']);
    }

    public function test_saved_unchanged_it_keeps_the_inventory_origin_and_an_edit_makes_it_human_edited(): void
    {
        [$owner, $version, $asset] = $this->walkedAsset('Front door key.');

        $this->signIn($owner)->put(route('versions.einfo.update', [$version, 'demo-1']), [
            'object' => $asset->id, 'entries' => [$asset->id => [self::CODE => 'Front door key.']],
        ])->assertRedirect();

        $entry = $version->einfoEntries()->sole();
        $this->assertSame('Front door key.', $entry->text);
        $this->assertSame(Origin::FromInventory, $this->origin($entry));
        $this->assertSame(1, Assessment::for($version->fresh())->einfoProgress('demo-1')['done']);
        $this->signIn($owner)->get($this->url($version, $asset))->assertOk()
            ->assertSee(__('ai.origin.from_inventory.label'))
            ->assertDontSee(__('einfo.prefill.unsaved'));

        $this->signIn($owner)->put(route('versions.einfo.update', [$version, 'demo-1']), [
            'object' => $asset->id, 'entries' => [$asset->id => [self::CODE => 'Front door key, paired app only.']],
        ])->assertRedirect();
        $this->assertSame(Origin::HumanEdited, $this->origin($entry->fresh()));
        $this->signIn($owner)->get($this->url($version, $asset))->assertOk()
            ->assertSee(__('ai.origin.human_edited.label'))
            ->assertSee(__('ai.origin.from_inventory.tooltip_edited'));
    }

    public function test_accept_fills_the_empty_fields_of_the_object(): void
    {
        [$owner, $version, $asset] = $this->walkedAsset('Back door key.');

        // The person emptied the field, then asked for the pre-filled texts.
        $this->signIn($owner)->put(route('versions.einfo.update', [$version, 'demo-1']), [
            'object' => $asset->id, 'accept_prefill' => '1', 'entries' => [$asset->id => [self::CODE => '']],
        ])->assertRedirect()->assertSessionHas('status', trans_choice('einfo.prefill.accepted', 1, ['count' => 1]));

        $entry = $version->einfoEntries()->sole();
        $this->assertSame('Back door key.', $entry->text);
        $this->assertSame(Origin::FromInventory, $this->origin($entry));
    }

    public function test_an_inventory_change_is_flagged_and_accept_refreshes_only_unchanged_texts(): void
    {
        [$owner, $version, $asset] = $this->walkedAsset('Old description.');
        $second = $this->asset($version, 'AS-02', 'Back door key', 'Second description.');
        $this->walk($owner, $version, $second);
        $this->signIn($owner)->put(route('versions.einfo.update', [$version, 'demo-1']), [
            'entries' => [$asset->id => [self::CODE => 'Old description.'], $second->id => [self::CODE => 'Second description.']],
        ])->assertRedirect();
        // The second text is then changed by a person.
        $this->signIn($owner)->put(route('versions.einfo.update', [$version, 'demo-1']), [
            'entries' => [$second->id => [self::CODE => 'Written by hand.']],
        ])->assertRedirect();

        $asset->update(['description' => 'New description.']);
        $second->update(['description' => 'Newer second description.']);

        // Flagged, never replaced silently.
        $this->signIn($owner)->get($this->url($version, $asset))->assertOk()
            ->assertSee(__('einfo.prefill.changed'))
            ->assertSee('New description.')
            ->assertSee(trans_choice('einfo.prefill.outdated', 1, ['count' => 1]));
        $this->assertSame('Old description.', $this->entry($version, $asset)->text);
        $this->signIn($owner)->get($this->url($version, $second))->assertOk()
            ->assertDontSee(__('einfo.prefill.changed'));

        // Accept: the unchanged inventory text takes the new description; a text a person wrote stays.
        $this->signIn($owner)->put(route('versions.einfo.update', [$version, 'demo-1']), [
            'object' => $asset->id, 'accept_prefill' => '1', 'entries' => [$asset->id => [self::CODE => 'Old description.']],
        ])->assertRedirect();
        $this->assertSame('New description.', $this->entry($version, $asset)->text);
        $this->assertSame(Origin::FromInventory, $this->origin($this->entry($version, $asset)));

        $this->signIn($owner)->put(route('versions.einfo.update', [$version, 'demo-1']), [
            'object' => $second->id, 'accept_prefill' => '1', 'entries' => [$second->id => [self::CODE => 'Written by hand.']],
        ])->assertRedirect();
        $this->assertSame('Written by hand.', $this->entry($version, $second)->text);
    }

    public function test_a_reviewer_does_not_see_pre_filled_texts(): void
    {
        [, $version, $asset] = $this->walkedAsset('Front door key.');
        $reviewer = $this->member($version->product, ProductRole::Reviewer);

        $this->signIn($reviewer)->get($this->url($version, $asset))->assertOk()
            ->assertDontSee('Front door key.</textarea>', false)
            ->assertDontSee(__('einfo.prefill.accept'));
    }

    public function test_a_links_rule_lists_the_linked_objects_matching_the_filter(): void
    {
        [, $version, $asset] = $this->walkedAsset('Key.');
        $wifi = $version->inventoryItems()->create(['list' => 'interfaces', 'code' => 'IF-02', 'name' => 'Wi-Fi', 'attributes' => ['kind' => 'network'], 'position' => 2]);
        $ble = $version->inventoryItems()->create(['list' => 'interfaces', 'code' => 'IF-01', 'name' => 'Bluetooth', 'attributes' => ['kind' => 'network'], 'position' => 1]);
        $keypad = $version->inventoryItems()->create(['list' => 'interfaces', 'code' => 'IF-03', 'name' => 'Keypad', 'attributes' => ['kind' => 'user'], 'position' => 3]);
        foreach ([$wifi, $ble, $keypad] as $interface) {
            InventoryLink::create(['product_version_id' => $version->id, 'relation' => 'asset_interface', 'from_item_id' => $asset->id, 'to_item_id' => $interface->id]);
        }
        $prefill = new EinfoPrefill(app(ContentPack::class), new VersionContext($version->fresh()));

        $this->assertSame("IF-01 · Bluetooth\nIF-02 · Wi-Fi", $prefill->render(['from' => 'links', 'list' => 'interfaces', 'where' => ['kind' => 'network']], $asset));
        // Either direction of the relation: the assets behind an interface.
        $this->assertSame('AS-01 · Front door key', $prefill->render(['from' => 'links', 'list' => 'assets'], $keypad));
        $this->assertNull($prefill->render(['from' => 'links', 'list' => 'interfaces', 'where' => ['kind' => 'machine']], $asset));
        // A claim opens the list (translated, :item = the object), so the text states something about the product.
        $claim = ['en' => 'The equipment communicates :item over these interfaces:', 'fr' => "L'équipement communique :item par ces interfaces :"];
        $this->assertSame("The equipment communicates AS-01 · Front door key over these interfaces:\nIF-01 · Bluetooth\nIF-02 · Wi-Fi",
            $prefill->render(['from' => 'links', 'list' => 'interfaces', 'where' => ['kind' => 'network'], 'claim' => $claim], $asset));
        app()->setLocale('fr');
        $this->assertStringStartsWith("L'équipement communique AS-01 · Front door key par ces interfaces :\n", (string) $prefill->render(['from' => 'links', 'list' => 'interfaces', 'claim' => $claim], $asset));
        app()->setLocale('en');
        $this->assertNull($prefill->render(['from' => 'links', 'list' => 'interfaces', 'where' => ['kind' => 'machine'], 'claim' => $claim], $asset));
        $this->assertSame('Key.', $prefill->text(self::CODE, $asset));
        $this->assertNull($prefill->text('E.Info.UPD-1.Product', null));
    }

    /** @return array{0: User, 1: ProductVersion, 2: InventoryItem} */
    private function walkedAsset(string $description): array
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        $version->scopingAnswers()->create(['question' => 'connected', 'value' => true]);
        $asset = $this->asset($version, 'AS-01', 'Front door key', $description);
        $this->walk($owner, $version, $asset);

        return [$owner, $version, $asset];
    }

    private function asset(ProductVersion $version, string $code, string $name, string $description): InventoryItem
    {
        return $version->inventoryItems()->create(['list' => 'assets', 'code' => $code, 'name' => $name, 'description' => $description,
            'attributes' => ['type' => 'security'], 'position' => (int) substr($code, -1)]);
    }

    private function walk(User $owner, ProductVersion $version, InventoryItem $asset): void
    {
        $this->signIn($owner)->put(route('versions.evaluate.update', [$version, 'demo-1', 'ACC-1']), [
            'target' => $asset->id, 'answers' => ['No', 'Yes'], 'justification' => 'Paired app.',
        ])->assertRedirect();
    }

    private function url(ProductVersion $version, InventoryItem $asset): string
    {
        return route('versions.einfo.object', [$version, 'demo-1', $asset->id]);
    }

    private function entry(ProductVersion $version, InventoryItem $asset): EinfoEntry
    {
        return $version->einfoEntries()->where('inventory_item_id', $asset->id)->sole();
    }

    private function origin(EinfoEntry $entry): Origin
    {
        // A fresh Provenance: the scoped instance memoises marks within a request.
        $this->assertNotNull(FieldOrigin::query()->where('target_type', 'einfo_entry')->where('target_key', self::CODE.'|'.$entry->inventory_item_id)->first());

        return (new Provenance)->origin('einfo_entry', $entry->id, 'text', $entry->text);
    }
}
