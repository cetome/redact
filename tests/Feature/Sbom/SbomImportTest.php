<?php

namespace Tests\Feature\Sbom;

use App\Enums\ProductRole;
use App\Models\InventoryItem;
use App\Models\SbomImport;
use App\Sbom\Component;
use App\Sbom\ComponentImporter;
use App\Sbom\Identifiers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/**
 * Upload → preview → import of the ticked components; who may do it; what is kept.
 */
class SbomImportTest extends SbomTestCase
{
    public function test_an_owner_uploads_previews_and_imports_the_ticked_components(): void
    {
        Queue::fake();
        [$owner, $version] = $this->ownerWithVersion();

        $this->signIn($owner)->get(route('versions.inventory', [$version, 'software']))
            ->assertOk()->assertSee(__('sbom.import_button'))->assertDontSee(__('sbom.vulnerabilities_button'));
        $this->signIn($owner)->get(route('versions.sbom', $version))->assertOk()->assertSee(__('sbom.import.title'));

        $response = $this->upload($owner, $version, $this->fixture('cyclonedx.json', 'firmware.cdx.json'));
        $import = SbomImport::sole();
        $response->assertRedirect(route('versions.sbom.show', [$version, $import]));
        $this->assertSame(['cyclonedx-json', 'mixed', 6, 'preview'], [$import->format, $import->kind, $import->component_count, $import->status]);
        Storage::disk('local')->assertExists($import->files[0]['path']);
        $this->assertSame('firmware.cdx.json', $import->files[0]['name']);
        $this->assertSame(hash_file('sha256', base_path('tests/Fixtures/sbom/cyclonedx.json')), $import->files[0]['sha256']);
        $this->assertTrue(Activity::where('log_name', 'sbom')->where('description', 'sbom uploaded')->exists());

        $this->signIn($owner)->get(route('versions.sbom.show', [$version, $import]))
            ->assertOk()->assertSee('lodash')->assertSee('Example radio SoC')->assertSee(__('sbom.preview.root'))
            ->assertSee(__('sbom.preview.status.new'));

        // Everything but the product itself (index 0) and libcrypto; the SoC stays hardware, the RTOS is corrected.
        $names = array_column($import->components, 'name');
        $rows = array_keys(array_filter($names, fn ($n) => ! in_array($n, ['Sample lock firmware', 'libcrypto'], true)));
        $rtos = array_search('Example RTOS', $names, true);
        $this->signIn($owner)->post(route('versions.sbom.apply', [$version, $import]), [
            'rows' => $rows, 'types' => [$rtos => 'software'], 'check' => '0',
        ])->assertRedirect(route('versions.inventory', [$version, 'software']))->assertSessionHas('inventory.new');

        $items = $version->inventoryItems()->where('list', 'software')->orderBy('code')->get();
        $this->assertSame(['lodash', 'openssl', 'Example RTOS', 'Example radio SoC'], $items->pluck('name')->all());
        // Empty, the list offers the import; with components, the known vulnerabilities too.
        $this->signIn($owner)->get(route('versions.inventory', [$version, 'software']))
            ->assertOk()->assertSee(__('sbom.import_button'))->assertSee(__('sbom.vulnerabilities_button'));
        $this->assertSame(['SW-01', 'SW-02', 'SW-03', 'SW-04'], $items->pluck('code')->all());
        $soc = $items->firstWhere('name', 'Example radio SoC');
        $this->assertSame(['type' => 'hardware', 'version' => 'B2', 'reference' => 'Example Semiconductors'], $soc->getAttribute('attributes'));
        $this->assertSame('2.4 GHz radio system-on-chip', $soc->description);
        $lodash = $items->firstWhere('name', 'lodash');
        $this->assertSame('pkg:npm/lodash@4.17.20', Identifiers::get($lodash, 'purl'));
        $this->assertSame('MIT', Identifiers::get($lodash, 'licence'));
        $this->assertSame('cpe:2.3:a:openssl:openssl:3.0.7:*:*:*:*:*:*:*', Identifiers::get($items->firstWhere('name', 'openssl'), 'cpe'));
        $this->assertSame('imported', $import->fresh()->status);
        $this->assertSame(['created' => 4, 'updated' => 0, 'skipped' => 2], $import->fresh()->summary);
        $this->assertTrue(Activity::where('log_name', 'sbom')->where('description', 'sbom imported')->exists());
        $this->assertTrue(Activity::where('log_name', 'inventory')->where('subject_id', $lodash->id)->exists(), 'Each item is in the audit trail.');
        Queue::assertNothingPushed();

        // The component list shows them; the imported preview is a summary now.
        $this->signIn($owner)->get(route('versions.sbom.show', [$version, $import]))->assertOk()->assertSee(__('sbom.import.state.imported'), false);
    }

    public function test_a_second_bom_shows_present_updated_and_new_and_updates_in_place(): void
    {
        Queue::fake();
        [$owner, $version] = $this->ownerWithVersion();
        $first = $version->inventoryItems()->create(['list' => 'software', 'code' => 'SW-01', 'name' => 'lodash', 'attributes' => ['type' => 'software', 'version' => '4.17.20']]);
        Identifiers::put($first, ['purl' => 'pkg:npm/lodash@4.17.20']);
        $first->save();
        $version->inventoryItems()->create(['list' => 'software', 'code' => 'SW-02', 'name' => 'Example RTOS', 'attributes' => ['type' => 'software', 'version' => '10.5.1', 'reference' => 'Example RTOS Project']]);
        $version->inventoryItems()->create(['list' => 'software', 'code' => 'SW-03', 'name' => 'openssl', 'attributes' => ['type' => 'software', 'version' => '3.0.2']]);

        $this->upload($owner, $version, $this->fixture('cyclonedx.json'))->assertRedirect();
        $import = SbomImport::sole();
        $plan = collect(app(ComponentImporter::class)->plan($version, array_map(fn ($r) => Component::fromArray($r), $import->components)))
            ->mapWithKeys(fn ($row) => [$row['component']->name => $row['status']]);
        $this->assertSame('updated', $plan['lodash'], 'Same name and version, adds the licence and supplier.');
        $this->assertSame('present', $plan['Example RTOS']);
        $this->assertSame('updated', $plan['openssl'], 'Only openssl in the list: a version change.');
        $this->assertSame('new', $plan['libcrypto']);

        $this->signIn($owner)->get(route('versions.sbom.show', [$version, $import]))->assertOk()
            ->assertSee(__('sbom.preview.change.version', ['old' => '3.0.2', 'new' => '3.0.7']));

        $names = array_column($import->components, 'name');
        $this->signIn($owner)->post(route('versions.sbom.apply', [$version, $import]), [
            'rows' => array_keys($names), 'check' => '0',
        ])->assertRedirect();

        $openssl = $version->inventoryItems()->where('name', 'openssl')->sole();
        $this->assertSame('SW-03', $openssl->code, 'Updated in place, decision-tree work stays attached.');
        $this->assertSame('3.0.7', $openssl->attr('version'));
        $this->assertSame('cpe:2.3:a:openssl:openssl:3.0.7:*:*:*:*:*:*:*', Identifiers::get($openssl, 'cpe'));
        $this->assertSame('Example RTOS Project', $version->inventoryItems()->where('name', 'Example RTOS')->sole()->attr('reference'));
        $this->assertSame(1, $version->inventoryItems()->where('name', 'lodash')->count());
        $this->assertSame('OpenJS Foundation', $version->inventoryItems()->where('name', 'lodash')->sole()->attr('reference'));
    }

    public function test_swid_files_together_csv_and_spdx_can_be_uploaded(): void
    {
        Queue::fake();
        [$owner, $version] = $this->ownerWithVersion();

        $this->upload($owner, $version, $this->fixture('swid/busybox.swidtag'), $this->fixture('swid/dropbear.swidtag'))->assertRedirect();
        $this->assertSame(['swid', 2], [SbomImport::latest()->first()->format, SbomImport::latest()->first()->component_count]);

        $this->upload($owner, $version, $this->fixture('components.csv'))->assertRedirect();
        $this->upload($owner, $version, $this->fixture('sample.spdx'))->assertRedirect();
        $this->upload($owner, $version, $this->fixture('spdx3.jsonld'))->assertRedirect();
        $this->assertSame(['swid', 'csv', 'spdx-tag', 'spdx3-jsonld'], SbomImport::orderBy('created_at')->orderBy('id')->pluck('format')->all());
    }

    public function test_hostile_files_are_refused_with_a_message_and_nothing_is_kept(): void
    {
        [$owner, $version] = $this->ownerWithVersion();

        $this->upload($owner, $version, $this->fixture('xxe.xml'))->assertSessionHasErrors(['files' => __('sbom.errors.xml_doctype')]);
        $this->upload($owner, $version, $this->fixture('bad.zip'))->assertSessionHasErrors(['files' => __('sbom.errors.bad_zip')]);
        $this->upload($owner, $version, UploadedFile::fake()->create('huge.json', 11 * 1024, 'application/json'))->assertSessionHasErrors('files.0');
        $this->upload($owner, $version, UploadedFile::fake()->create('photo.exe', 1))->assertSessionHasErrors('files.0');

        $this->assertSame(0, SbomImport::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_reviewers_and_strangers_cannot_import_but_reviewers_read_the_original_file(): void
    {
        Queue::fake();
        [$owner, $version] = $this->ownerWithVersion();
        $reviewer = $this->member($version->product, ProductRole::Reviewer);
        $contributor = $this->member($version->product, ProductRole::Contributor);
        $stranger = $this->owner();

        $this->signIn($reviewer)->get(route('versions.sbom', $version))->assertNotFound();
        $this->upload($reviewer, $version, $this->fixture('cyclonedx.json'))->assertNotFound();
        $this->upload($stranger, $version, $this->fixture('cyclonedx.json'))->assertNotFound();

        $this->upload($contributor, $version, $this->fixture('cyclonedx.json'))->assertRedirect();
        $import = SbomImport::sole();
        $this->signIn($reviewer)->post(route('versions.sbom.apply', [$version, $import]), ['rows' => [1]])->assertNotFound();
        $this->signIn($reviewer)->delete(route('versions.sbom.destroy', [$version, $import]))->assertNotFound();

        $download = $this->signIn($reviewer)->get(route('versions.sbom.download', [$version, $import, 0]));
        $download->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
        $this->signIn($stranger)->get(route('versions.sbom.download', [$version, $import, 0]))->assertNotFound();

        // The contributor discards it: the file goes too.
        $this->signIn($contributor)->delete(route('versions.sbom.destroy', [$version, $import]))->assertRedirect(route('versions.sbom', $version));
        $this->assertSame('discarded', $import->fresh()->status);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_nothing_ticked_is_refused_and_the_template_downloads(): void
    {
        [$owner, $version] = $this->ownerWithVersion();
        $this->upload($owner, $version, $this->fixture('components.csv'))->assertRedirect();
        $import = SbomImport::sole();

        $this->signIn($owner)->post(route('versions.sbom.apply', [$version, $import]), ['rows' => []])->assertSessionHasErrors('rows');
        $this->assertSame(0, InventoryItem::count());

        $template = $this->signIn($owner)->get(route('sbom.template'));
        $template->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringStartsWith('PK', (string) $template->getContent());
    }

    public function test_guests_need_an_account_to_import(): void
    {
        Queue::fake();
        [$owner, $version] = $this->ownerWithVersion();
        $owner->forceFill(['is_guest' => true, 'guest_expires_at' => now()->addDay()])->save();

        // Uploads need an account (owner, 2026-10-23): the guest is led to "Keep my work".
        $this->upload($owner, $version, $this->fixture('components.csv'))->assertRedirect(route('guest.claim'));
        $this->assertSame(0, SbomImport::count());
        $this->signIn($owner)->get(route('versions.sbom', $version))->assertRedirect(route('guest.claim'));
        Queue::assertNothingPushed();
    }

    public function test_files_of_deleted_versions_and_abandoned_uploads_are_pruned(): void
    {
        [$owner, $version] = $this->ownerWithVersion();
        $this->upload($owner, $version, $this->fixture('components.csv'))->assertRedirect();
        $import = SbomImport::sole();
        Storage::disk('local')->put('sbom/01gone0000000000000000000/x/0', 'orphan');

        $this->artisan('redact:prune-sbom-files')->assertSuccessful();
        Storage::disk('local')->assertMissing('sbom/01gone0000000000000000000/x/0');
        Storage::disk('local')->assertExists($import->files[0]['path']);

        $this->travel(31)->days();
        $this->artisan('redact:prune-sbom-files')->assertSuccessful();
        Storage::disk('local')->assertMissing($import->files[0]['path']);
        $this->assertSame('discarded', $import->fresh()->status);
    }
}
