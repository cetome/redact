<?php

namespace Tests\Feature\Sbom;

use App\Ai\Provenance;
use App\Declaration\TechnicalFile;
use App\Enums\Origin;
use App\Enums\ProductRole;
use App\Models\InventoryItem;
use App\Models\InventoryLink;
use App\Models\ProductVersion;
use App\Models\SbomImport;
use App\Models\VexImport;
use App\Models\VexStatement;
use App\Models\VulnerabilityFinding;
use App\Sbom\Identifiers;
use App\Sbom\Vex\VexImporter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/**
 * VEX files (CycloneDX VEX JSON/XML, an SBOM with its VEX, OpenVEX, CSAF VEX): read, matched
 * to the components and Known vulnerabilities, previewed, then the ticked statements recorded.
 */
class VexImportTest extends SbomTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function vex(string $name): UploadedFile
    {
        $copy = sys_get_temp_dir().'/'.uniqid('vex').'-'.$name;
        copy(base_path('tests/Fixtures/vex/'.$name), $copy);

        return new UploadedFile($copy, $name, null, null, true);
    }

    /** @param  array<string, mixed>  $ids */
    private function part(ProductVersion $version, string $code, string $name, string $ver, array $ids = []): InventoryItem
    {
        $item = $version->inventoryItems()->create(['list' => 'software', 'code' => $code, 'name' => $name, 'attributes' => ['type' => 'software', 'version' => $ver]]);
        if ($ids) {
            Identifiers::put($item, $ids);
            $item->save();
        }

        return $item;
    }

    /** @return list<string> every row key of the preview */
    private function allRows(VexImport $import): array
    {
        return array_column(app(VexImporter::class)->plan($import->version, $import->statements), 'key');
    }

    public function test_cyclonedx_vex_matches_by_purl_and_bom_ref_previews_and_records_the_ticked_rows(): void
    {
        [$owner, $version] = $this->ownerWithVersion();
        $lodash = $this->part($version, 'SW-01', 'lodash', '4.17.20', ['purl' => 'pkg:npm/lodash@4.17.20']);
        $openssl = $this->part($version, 'SW-02', 'OpenSSL library', '3.0.7', ['bom_ref' => 'openssl']);

        $response = $this->upload($owner, $version, $this->vex('cyclonedx-vex.json'));
        $import = VexImport::sole();
        $response->assertRedirect(route('versions.vex.show', [$version, $import]));
        $this->assertSame(['cyclonedx-json', 2, 'preview', null], [$import->format, $import->statement_count, $import->status, $import->sbom_import_id]);
        $this->assertSame(0, SbomImport::count(), 'A VEX file is not a bill of materials.');
        Storage::disk('local')->assertExists($import->files[0]['path']);
        $this->assertTrue(Activity::where('log_name', 'sbom')->where('description', 'vex uploaded')->exists());

        $html = (string) $this->signIn($owner)->get(route('versions.vex.show', [$version, $import]))->assertOk()
            ->assertSee('CVE-2020-28500')->assertSee('GHSA-29mw-wpgm-hmr9')
            ->assertSee(__('sbom.vex.match.purl'))->assertSee(__('sbom.vex.match.ref'))
            ->assertSee(__('sbom.vex.status.not_affected'))->assertSee(__('sbom.vex.justification.code_not_reachable'))
            ->assertSee(__('sbom.vex.status.affected'))->assertSee(__('sbom.vex.state.new'))->getContent();
        $this->assertStringNotContainsString('sbom.', $html, 'No untranslated key on the page.');

        // Only the lodash row ticked: the OpenSSL one is left out.
        $rows = app(VexImporter::class)->plan($version, $import->statements);
        $lodashRow = collect($rows)->first(fn ($r) => $r['component']?->id === $lodash->id)['key'];
        $this->signIn($owner)->post(route('versions.vex.apply', [$version, $import]), ['rows' => [$lodashRow]])
            ->assertRedirect(route('versions.inventory', [$version, 'vulnerabilities']));

        $statement = VexStatement::sole();
        $this->assertSame(['CVE-2020-28500', 'not_affected', 'code_not_reachable', $lodash->id, 'cyclonedx-vex.json', $owner->id],
            [$statement->vulnerability_key, $statement->status, $statement->justification, $statement->inventory_item_id, $statement->source_file, $owner->id]);
        $this->assertNotNull($statement->imported_at);
        $item = InventoryItem::where('list', 'vulnerabilities')->sole();
        $this->assertSame('VU-01', $item->code);
        $this->assertSame(['reference' => 'CVE-2020-28500, GHSA-29mw-wpgm-hmr9', 'source' => 'VEX: cyclonedx-vex.json'], $item->getAttribute('attributes'));
        $this->assertSame("Not affected: Code not reachable.\nThe firmware never calls toNumber, trim or trimEnd on untrusted input.", $item->description);
        $this->assertSame(Origin::FromVex, app(Provenance::class)->origin('inventory_item', $item->id, 'description', $item->description));
        $this->assertTrue(InventoryLink::where('from_item_id', $item->id)->where('to_item_id', $lodash->id)->exists());
        $this->assertSame(['recorded' => 1, 'created' => 1, 'linked' => 1, 'skipped' => 1], $import->fresh()->summary);
        $this->assertTrue(Activity::where('log_name', 'sbom')->where('description', 'vex imported')->exists());

        // The Known vulnerabilities list shows the status (justification on hover) and the "From VEX" origin in the form.
        $this->signIn($owner)->get(route('versions.inventory', [$version, 'vulnerabilities']))->assertOk()
            ->assertSee('VEX · '.__('sbom.vex.status.not_affected'))->assertSee(__('sbom.vex.button'))
            ->assertSee('Code not reachable · The firmware never calls')->assertDontSee('line-clamp-1" title="Not affected', false);
        $this->signIn($owner)->get(route('versions.inventory.edit', [$version, 'vulnerabilities', $item->id]))->assertOk()
            ->assertSee(__('ai.origin.from_vex.label'));
        // No look-up found it: the vulnerabilities page lists it under the other VEX statements.
        $this->signIn($owner)->get(route('versions.vulnerabilities', $version))->assertOk()
            ->assertSee(__('sbom.vex.other'))->assertSee('VEX · '.__('sbom.vex.status.not_affected'));

        // The same file again: the recorded statement is "same", the other one still new.
        $this->upload($owner, $version, $this->vex('cyclonedx-vex.json'));
        $again = VexImport::latest('id')->first();
        $states = collect(app(VexImporter::class)->plan($version, $again->statements))->pluck('status', 'component.id');
        $this->assertSame('same', $states[$lodash->id]);
        $this->assertSame('new', $states[$openssl->id]);
    }

    public function test_openvex_changes_a_statement_creates_missing_vulnerabilities_and_never_replaces_a_persons_text(): void
    {
        [$owner, $version] = $this->ownerWithVersion();
        $lodash = $this->part($version, 'SW-01', 'lodash', '4.17.20', ['purl' => 'pkg:npm/lodash@4.17.20']);
        $this->upload($owner, $version, $this->vex('cyclonedx-vex.json'));
        $first = VexImport::sole();
        $this->signIn($owner)->post(route('versions.vex.apply', [$version, $first]), ['rows' => $this->allRows($first)]);
        $this->assertSame(2, VexStatement::count());
        // OpenSSL is not a component: the statement is about the product as a whole, its item stays to do (no description).
        $openssl = InventoryItem::where('list', 'vulnerabilities')->get()->first(fn ($i) => str_starts_with($i->name, 'CVE-2023-0286'));
        $this->assertNull($openssl->description);
        $this->assertNull(VexStatement::where('vulnerability_key', 'CVE-2023-0286')->value('inventory_item_id'));

        $this->upload($owner, $version, $this->vex('openvex.json'));
        $import = VexImport::where('format', 'openvex')->sole();
        $this->signIn($owner)->get(route('versions.vex.show', [$version, $import]))->assertOk()
            ->assertSee(__('sbom.vex.state.changed'))->assertSee(__('sbom.vex.was', ['status' => __('sbom.vex.status.not_affected')]))
            ->assertSee(__('sbom.vex.product'))->assertSee(__('sbom.vex.creates', ['list' => 'Weaknesses']));
        $this->signIn($owner)->post(route('versions.vex.apply', [$version, $import]), ['rows' => $this->allRows($import)])->assertRedirect();

        $lodashStatement = VexStatement::where('inventory_item_id', $lodash->id)->sole();
        $this->assertSame(['fixed', 'Patched in our fork.', $import->id], [$lodashStatement->status, $lodashStatement->action, $lodashStatement->vex_import_id]);
        // A VEX text nobody changed follows the VEX.
        $lodashItem = InventoryItem::find($lodashStatement->vulnerability_item_id);
        $this->assertSame('Fixed: Patched in our fork.', $lodashItem->description);
        $new = InventoryItem::where('list', 'vulnerabilities')->get()->first(fn ($i) => str_starts_with($i->name, 'CVE-2026-0001'));
        $this->assertSame('CVE-2026-0001 · Heap overflow in the update agent', $new->name);
        $this->assertNull($new->description, 'Under investigation stays to do.');
        $this->assertSame('under_investigation', VexStatement::where('vulnerability_item_id', $new->id)->value('status'));

        // A person writes the description: a later VEX never replaces it.
        $lodashItem->forceFill(['description' => 'Our own analysis.'])->save();
        $this->upload($owner, $version, $this->vex('cyclonedx-vex.json'));
        $third = VexImport::latest('id')->first();
        $this->signIn($owner)->post(route('versions.vex.apply', [$version, $third]), ['rows' => $this->allRows($third)]);
        $this->assertSame('not_affected', $lodashStatement->fresh()->status);
        $this->assertSame('Our own analysis.', $lodashItem->fresh()->description);
    }

    public function test_csaf_vex_matches_by_name_and_version_to_the_vulnerability_already_listed(): void
    {
        [$owner, $version] = $this->ownerWithVersion();
        $openssl = $this->part($version, 'SW-01', 'openssl', '3.0.7');
        $listed = $version->inventoryItems()->create(['list' => 'vulnerabilities', 'code' => 'VU-01', 'name' => 'X.400 type confusion', 'attributes' => ['reference' => 'CVE-2023-0286']]);

        $this->upload($owner, $version, $this->vex('csaf-vex.json'))->assertRedirect();
        $import = VexImport::sole();
        $this->assertSame('csaf', $import->format);
        $this->signIn($owner)->get(route('versions.vex.show', [$version, $import]))->assertOk()
            ->assertSee(__('sbom.vex.match.name'))->assertSee(__('sbom.preview.existing', ['code' => 'VU-01']))
            ->assertSee(__('sbom.vex.justification.vulnerable_code_not_in_execute_path'));
        $this->signIn($owner)->post(route('versions.vex.apply', [$version, $import]), ['rows' => $this->allRows($import)])->assertRedirect();

        $this->assertSame(1, InventoryItem::where('list', 'vulnerabilities')->count(), 'No duplicate of the listed vulnerability.');
        $statement = VexStatement::sole();
        $this->assertSame([$listed->id, $openssl->id, 'not_affected', 'CRL checking is disabled in the firmware.'],
            [$statement->vulnerability_item_id, $statement->inventory_item_id, $statement->status, $statement->detail]);
        $this->assertSame("Not affected: Vulnerable code not in the execution path.\nCRL checking is disabled in the firmware.", $listed->fresh()->description);
    }

    public function test_cyclonedx_xml_vex_matches_by_bom_ref_and_a_finding_shows_its_status(): void
    {
        [$owner, $version] = $this->ownerWithVersion();
        $rtos = $this->part($version, 'SW-01', 'Example RTOS', '10.5.1', ['bom_ref' => 'rtos']);
        $finding = VulnerabilityFinding::create(['product_version_id' => $version->id, 'inventory_item_id' => $rtos->id, 'key' => 'CVE-2025-1111',
            'aliases' => ['EUVD-2025-1111'], 'sources' => ['euvd'], 'confidence' => 'exact', 'status' => 'open']);

        $this->upload($owner, $version, $this->vex('cyclonedx-vex.xml'))->assertRedirect();
        $import = VexImport::sole();
        $this->assertSame('cyclonedx-xml', $import->format);
        $this->signIn($owner)->post(route('versions.vex.apply', [$version, $import]), ['rows' => $this->allRows($import)])->assertRedirect();

        $statement = VexStatement::sole();
        $this->assertSame([$rtos->id, 'fixed'], [$statement->inventory_item_id, $statement->status]);
        // The finding goes to the list with it, and shows the status (not repeated below).
        $this->assertSame(['added', $statement->vulnerability_item_id], [$finding->fresh()->status, $finding->fresh()->vulnerability_item_id]);
        $this->signIn($owner)->get(route('versions.vulnerabilities', $version))->assertOk()
            ->assertSee('VEX · '.__('sbom.vex.status.fixed'))->assertDontSee(__('sbom.vex.other'));

        // The CSV of the technical file carries the VEX status.
        $csv = TechnicalFile::for($version->fresh())->vulnerabilitiesCsv();
        $this->assertStringContainsString(__('declaration.file.csv.vex_status'), $csv);
        $this->assertStringContainsString(__('sbom.vex.status.fixed'), $csv);

        // The VEX file itself goes into point (g) of the technical file, as received.
        $vex = collect(TechnicalFile::for($version->fresh())->items())->firstWhere('label', __('declaration.file.items.vex', ['name' => 'cyclonedx-vex.xml']));
        $this->assertNotNull($vex);
        $this->assertSame(['g', 'present'], [$vex['heading'], $vex['status']]);
        $this->assertStringContainsString('/'.__('declaration.file.names.vex').'/', (string) $vex['file']);
        $this->assertStringEndsWith('cyclonedx-vex.xml', (string) $vex['file']);
    }

    public function test_an_sbom_with_vex_imports_its_components_then_reviews_its_statements(): void
    {
        [$owner, $version] = $this->ownerWithVersion();
        $this->upload($owner, $version, $this->vex('sbom-with-vex.json'))->assertRedirect();
        $sbom = SbomImport::sole();
        $vex = VexImport::sole();
        $this->assertSame([$sbom->id, 2], [$vex->sbom_import_id, $vex->statement_count]);
        $this->signIn($owner)->get(route('versions.sbom.show', [$version, $sbom]))->assertOk()
            ->assertSee(__('sbom.vex.embedded', ['count' => 2]));

        $names = array_column($sbom->components, 'name');
        $rows = array_keys(array_filter($names, fn ($n) => $n !== 'Sample lock firmware'));
        $this->signIn($owner)->post(route('versions.sbom.apply', [$version, $sbom]), ['rows' => $rows, 'check' => '0'])
            ->assertRedirect(route('versions.vex.show', [$version, $vex]));

        $plan = app(VexImporter::class)->plan($version, $vex->statements);
        $this->assertSame(['purl', 'name'], array_column($plan, 'match'));
        $this->signIn($owner)->post(route('versions.vex.apply', [$version, $vex]), ['rows' => array_column($plan, 'key')])->assertRedirect();
        $this->assertSame(['not_affected', 'under_investigation'], VexStatement::orderBy('id')->pluck('status')->all());

        // Discarding the SBOM upload discards its VEX too.
        $this->upload($owner, $version, $this->vex('sbom-with-vex.json'));
        $second = SbomImport::where('status', 'preview')->sole();
        $this->signIn($owner)->delete(route('versions.sbom.destroy', [$version, $second]))->assertRedirect();
        $this->assertSame('discarded', VexImport::where('sbom_import_id', $second->id)->value('status'));
    }

    public function test_hostile_malformed_and_unknown_files_are_refused_and_nothing_is_kept(): void
    {
        [$owner, $version] = $this->ownerWithVersion();
        $xxe = '<?xml version="1.0"?><!DOCTYPE bom [<!ENTITY x SYSTEM "file:///etc/passwd">]><bom xmlns="http://cyclonedx.org/schema/bom/1.5"><vulnerabilities><vulnerability><id>&x;</id></vulnerability></vulnerabilities></bom>';
        $this->upload($owner, $version, UploadedFile::fake()->createWithContent('vex.xml', $xxe))->assertSessionHasErrors(['files' => __('sbom.errors.xml_doctype')]);
        $this->upload($owner, $version, UploadedFile::fake()->createWithContent('vex.json', '{"@context": "https://openvex.dev/ns/v0.2.0", "statements": ['))
            ->assertSessionHasErrors(['files' => __('sbom.errors.bad_json')]);
        $this->upload($owner, $version, UploadedFile::fake()->createWithContent('empty.json', '{"@context": "https://openvex.dev/ns/v0.2.0", "@id": "x", "statements": [{"status": "nonsense"}]}'))
            ->assertSessionHasErrors(['files' => __('sbom.errors.vex_empty', ['name' => 'empty.json'])]);
        $this->upload($owner, $version, UploadedFile::fake()->createWithContent('advisory.json', '{"document": {"category": "csaf_security_advisory", "csaf_version": "2.0"}, "vulnerabilities": []}'))
            ->assertSessionHasErrors(['files' => __('sbom.errors.csaf_not_vex', ['name' => 'advisory.json'])]);
        $this->upload($owner, $version, UploadedFile::fake()->createWithContent('other.json', '{"hello": "world"}'))
            ->assertSessionHasErrors(['files' => __('sbom.errors.unknown_format', ['name' => 'other.json'])]);
        $this->upload($owner, $version, UploadedFile::fake()->create('huge.json', 11 * 1024, 'application/json'))->assertSessionHasErrors('files.0');
        config(['sbom.import.max_statements' => 1]);
        $this->upload($owner, $version, $this->vex('cyclonedx-vex.json'))->assertSessionHasErrors(['files' => __('sbom.errors.too_many_statements', ['max' => 1])]);

        $this->assertSame(0, VexImport::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_reviewers_cannot_record_guests_need_an_account_and_a_discarded_upload_goes(): void
    {
        [$owner, $version] = $this->ownerWithVersion();
        $reviewer = $this->member($version->product, ProductRole::Reviewer);
        $this->upload($owner, $version, $this->vex('openvex.json'))->assertRedirect();
        $import = VexImport::sole();

        $this->signIn($reviewer)->get(route('versions.vex.show', [$version, $import]))->assertOk()->assertDontSee(__('sbom.vex.record'));
        $this->signIn($reviewer)->post(route('versions.vex.apply', [$version, $import]), ['rows' => $this->allRows($import)])->assertNotFound();
        $this->signIn($reviewer)->get(route('versions.vex.download', [$version, $import, 0]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->signIn($owner)->post(route('versions.vex.apply', [$version, $import]), ['rows' => []])->assertSessionHasErrors('rows');
        $this->assertSame(0, VexStatement::count());

        $guest = $this->owner();
        $guestVersion = $this->product($guest);
        $guest->forceFill(['is_guest' => true, 'guest_expires_at' => now()->addDay()])->save();
        $this->upload($guest, $guestVersion, $this->vex('openvex.json'))->assertRedirect(route('guest.claim'));
        $this->signIn($guest)->get(route('versions.vex.show', [$guestVersion, $import]))->assertRedirect(route('guest.claim'));
        $this->assertSame(1, VexImport::count());

        $this->signIn($owner)->delete(route('versions.vex.destroy', [$version, $import]))->assertRedirect(route('versions.sbom', $version));
        $this->assertSame('discarded', $import->fresh()->status);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->signIn($owner)->get(route('versions.vex.show', [$version, $import]))->assertNotFound();
    }

    public function test_the_import_page_lists_the_vex_formats_and_uploads(): void
    {
        [$owner, $version] = $this->ownerWithVersion();
        $this->upload($owner, $version, $this->vex('openvex.json'));
        $this->signIn($owner)->get(route('versions.sbom', $version))->assertOk()
            ->assertSee(__('sbom.import.formats.openvex'))->assertSee(__('sbom.import.formats.csaf'))->assertSee(__('sbom.import.formats.vex_cyclonedx'))
            ->assertSee(__('sbom.vex.recent'))->assertSee('openvex.json');
    }
}
