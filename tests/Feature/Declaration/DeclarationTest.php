<?php

namespace Tests\Feature\Declaration;

use App\Assessment\Assessment;
use App\Declaration\AnnexText;
use App\Declaration\DeclarationData;
use App\Declaration\TechnicalFile;
use App\Enums\ConformityRoute;
use App\Enums\ProductRole;
use App\Enums\Verdict;
use App\Enums\VersionStatus;
use App\Models\Document;
use App\Models\Evaluation;
use App\Models\ProductIdentifier;
use App\Models\ProductVersion;
use App\Models\User;
use App\Support\NotifiedBodies;
use App\Support\WorkflowSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;
use ZipArchive;

/**
 * The last step "Declaration & technical file": NANDO autocomplete and warning on the Conformity
 * step, the certificate recorded only after approval, the EU declaration of conformity following
 * RED Annex VI (Word, DRAFT watermark while anything is open) and the technical file of Annex V
 * (zip with numbered folders, index and README with present / missing).
 *
 * Demo pack: standard demo-1 (article A1), ACC-1 per asset (DN-2 → PASS), UPD-1 product-level.
 */
class DeclarationTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    private User $owner;

    private ProductVersion $version;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        NotifiedBodies::flush();

        $this->owner = $this->owner();
        $this->version = $this->product($this->owner);
        $this->version->product->update(['description' => 'A smart lock and its hub.', 'reference' => 'SL-100']);
        $this->version->identifiers()->create(['kind' => 'trade_name', 'value' => 'Smart Lock Pro', 'position' => 0]);
        $this->version->scopingAnswers()->create(['question' => 'connected', 'value' => true]);
    }

    private function as(User $user): static
    {
        return $this->signIn($user->refresh());
    }

    /** Every walk PASS with a justification, so nothing stays open in the trees. */
    private function passEverything(): void
    {
        $version = $this->version;
        $asset = $version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-01', 'name' => 'Key vault', 'attributes' => ['type' => 'security'], 'position' => 1]);
        $assessment = Assessment::for($version);
        foreach ($assessment->applicableRequirements('demo-1') as $code => $requirement) {
            foreach ($assessment->targets('demo-1', (string) $code) as $item) {
                Evaluation::create([
                    'product_version_id' => $version->id, 'standard' => 'demo-1', 'requirement' => (string) $code,
                    'inventory_item_id' => $item?->id, 'decision_node' => $code === 'ACC-1' ? 'DN-2' : 'DN-1', 'verdict' => Verdict::Pass,
                    'justification' => 'The key vault is reached only through the authenticated keypad; every access is checked against the stored PIN and logged.',
                ]);
            }
        }
        unset($asset);
    }

    private function fillDeclaration(): void
    {
        $this->version->product->organisation->update(['legal_name' => 'ACME Locks SAS', 'address' => "1 rue de la Paix\n75002 Paris", 'country' => 'France']);
        $this->version->forceFill(['declaration' => [
            'number' => 'DoC-001', 'serials' => 'From serial 1000', 'object' => 'Smart lock SL-100, hub included.',
            'legislation' => ['Directive 2011/65/EU (RoHS)'],
            'standards' => [
                ['reference' => 'EN 62368-1:2020+A11:2020', 'article' => '3.1a'],
                ['reference' => 'EN 301 489-17 V3.2.4', 'article' => '3.1b'],
                ['reference' => 'EN 300 328 V2.2.2', 'article' => '3.2'],
            ],
            'accessories' => 'Firmware 2.1.0', 'additional' => '', 'signed_for' => 'ACME Locks SAS',
            'place' => 'Paris', 'date' => '2026-10-20', 'signatory_name' => 'Jane Doe', 'signatory_function' => 'CEO',
            'url' => 'https://acme.example/doc', 'include_image' => true,
        ]])->save();
    }

    private function approve(?ConformityRoute $route = ConformityRoute::ModuleA): void
    {
        $this->version->forceFill(['conformity_route' => $route, 'status' => VersionStatus::Approved, 'approved_at' => now()])->save();
    }

    /** The streamed file, copied before it is deleted. */
    private function saved(TestResponse $response, string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'redact-test-').'.'.$extension;
        $streamed = $response->baseResponse->getFile()->getPathname();
        copy($streamed, $path);
        @unlink($streamed);

        return $path;
    }

    /** @return array{document: string, text: string, headers: string} */
    private function word(string $path): array
    {
        // Opens with PhpWord's own reader.
        $this->assertNotEmpty(IOFactory::load($path, 'Word2007')->getSections());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $document = (string) $zip->getFromName('word/document.xml');
        $headers = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('#^word/header\d*\.xml$#', $name)) {
                $xml = (string) $zip->getFromIndex($i);
                $this->assertNotFalse(@simplexml_load_string($xml), $name.' is well-formed');
                $headers .= $xml;
            }
        }
        $zip->close();
        $this->assertNotFalse(@simplexml_load_string($document));

        return ['document' => $document, 'text' => html_entity_decode(strip_tags(str_replace(['</w:p>', '<w:tab/>'], ["\n", ' '], $document)), ENT_QUOTES | ENT_XML1), 'headers' => $headers];
    }

    // ---- NANDO --------------------------------------------------------------------------------

    public function test_nando_list_feeds_the_conformity_autocomplete(): void
    {
        $this->assertSame('TÜV SÜD Product Service GmbH', NotifiedBodies::find('0123')['name']);
        $this->assertSame('0123 · TÜV SÜD Product Service GmbH (Germany)', NotifiedBodies::label(NotifiedBodies::find('0123')));
        $this->assertGreaterThan(40, count(NotifiedBodies::all()));

        $this->as($this->owner)->get(route('versions.conformity', $this->version))->assertOk()
            ->assertSee('data-nb-picker', false)
            ->assertSee('role="combobox"', false)
            ->assertSee('TÜV SÜD Product Service GmbH', false)
            ->assertSee('href="'.e(NotifiedBodies::url()).'"', false)
            ->assertSee('rel="noopener noreferrer"', false)
            ->assertSee('Check in NANDO')
            ->assertDontSee('name="certificate_reference"', false)
            ->assertSee('The certificate is added once the Notified Body has issued it, after the review.');
    }

    public function test_a_number_missing_from_nando_is_kept_with_a_warning(): void
    {
        $this->version->scopingAnswers()->updateOrCreate(['question' => 'connected'], ['value' => true]);
        $url = route('versions.conformity.update', $this->version);

        $this->as($this->owner)->put($url, ['conformity_route' => 'module_b_c', 'notified_body' => 'Somewhere', 'notified_body_number' => '123'])
            ->assertSessionHasErrors(['notified_body_number' => 'The Notified Body number has four digits, as listed in NANDO.']);

        $this->as($this->owner)->put($url, ['conformity_route' => 'module_b_c', 'notified_body' => 'New body', 'notified_body_number' => '9999'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('warning', 'Not in the NANDO list for RED retrieved on '.NotifiedBodies::retrieved().'. Check the number in NANDO.');
        $this->assertSame('9999', $this->version->fresh()->notified_body_number);
        $this->as($this->owner)->get(route('versions.conformity', $this->version))->assertOk()
            ->assertSee('Not in the NANDO list for RED retrieved on');

        $this->as($this->owner)->put($url, ['conformity_route' => 'module_b_c', 'notified_body' => 'TÜV SÜD Product Service GmbH', 'notified_body_number' => '0123'])
            ->assertSessionHasNoErrors()->assertSessionMissing('warning');
    }

    // ---- Certificate ------------------------------------------------------------------------------

    public function test_the_certificate_is_recorded_only_after_approval_on_the_locked_version(): void
    {
        $version = $this->version;
        $version->forceFill(['conformity_route' => ConformityRoute::ModuleBC, 'notified_body' => 'TÜV SÜD Product Service GmbH', 'notified_body_number' => '0123', 'certificate_reference' => 'OLD-1'])->save();
        $payload = ['certificate_reference' => 'EU-TE-42', 'certificate_issued_on' => '2026-10-01', 'certificate_expires_on' => '2031-10-01'];

        // Draft: no form, the route refuses, the old reference typed on Conformity stays.
        $this->as($this->owner)->get(route('versions.declaration', $version))->assertOk()
            ->assertSee('The certificate is added once the Notified Body has issued it, after the review.')
            ->assertDontSee('name="certificate_issued_on"', false);
        $this->as($this->owner)->put(route('versions.certificate.update', $version), $payload)->assertNotFound();
        $this->as($this->owner)->put(route('versions.conformity.update', $version), ['conformity_route' => 'module_b_c', 'notified_body' => 'TÜV SÜD Product Service GmbH', 'notified_body_number' => '0123', 'certificate_reference' => 'NEW'])->assertRedirect();
        $this->assertSame('OLD-1', $version->fresh()->certificate_reference);

        // Approved: the assessment is locked, the owner records the certificate.
        $version->forceFill(['status' => VersionStatus::Approved, 'approved_at' => now()])->save();
        $this->as($this->owner)->put(route('versions.conformity.update', $version), ['conformity_route' => 'module_a'])->assertNotFound();
        $this->as($this->owner)->get(route('versions.declaration', $version))->assertOk()->assertSee('name="certificate_issued_on"', false);

        $contributor = $this->member($version->product, ProductRole::Contributor, true);
        $reviewer = $this->member($version->product, ProductRole::Reviewer);
        $this->as($contributor)->put(route('versions.certificate.update', $version), $payload)->assertNotFound();
        $this->as($reviewer)->put(route('versions.certificate.update', $version), $payload)->assertNotFound();

        $pdf = UploadedFile::fake()->createWithContent('certificate.pdf', "%PDF-1.4\n%fake\n");
        $this->as($this->owner)->put(route('versions.certificate.update', $version), $payload + ['certificate_file' => $pdf])
            ->assertRedirect()->assertSessionHasNoErrors();

        $version->refresh();
        $this->assertSame('EU-TE-42', $version->certificate_reference);
        $this->assertSame('2026-10-01', $version->certificate_issued_on->toDateString());
        $this->assertSame('TÜV SÜD Product Service GmbH', $version->certificate_body);
        $this->assertSame('0123', $version->certificate_body_number);
        $document = Document::query()->findOrFail($version->certificate_document_id);
        $this->assertSame(TechnicalFile::CERTIFICATE, $document->category);
        $this->assertTrue(Activity::query()->where('description', 'certificate recorded')->where('subject_id', $version->id)->exists());

        // Expiry before issue is refused.
        $this->as($this->owner)->put(route('versions.certificate.update', $version), ['certificate_expires_on' => '2020-01-01'] + $payload)
            ->assertSessionHasErrors('certificate_expires_on');
    }

    public function test_existing_certificate_references_keep_their_issuing_body(): void
    {
        $migration = require database_path('migrations/2026_10_30_300000_add_declaration_and_certificate_to_product_versions.php');
        $this->version->forceFill(['notified_body' => 'Body', 'notified_body_number' => '0123', 'certificate_reference' => 'C-1', 'certificate_body' => null])->save();
        $migration->down();
        $migration->up();
        $this->assertSame('Body', $this->version->fresh()->certificate_body);
        $this->assertSame('0123', $this->version->fresh()->certificate_body_number);
    }

    // ---- Declaration ------------------------------------------------------------------------------

    public function test_the_declaration_follows_annex_vi_point_by_point(): void
    {
        $this->passEverything();
        $this->fillDeclaration();
        $this->approve();
        $data = DeclarationData::for($this->version->fresh());
        $this->assertSame([], $data->openItems());
        $this->assertTrue($data->complete());

        $response = $this->as($this->owner)->get(route('versions.declaration.download', ['version' => $this->version, 'format' => 'docx']))->assertOk();
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('EU declaration of conformity - EN.docx', rawurldecode((string) $response->headers->get('Content-Disposition')));
        ['text' => $text, 'headers' => $headers] = $this->word($this->saved($response, 'docx'));

        $this->assertStringContainsString('EU DECLARATION OF CONFORMITY (No DoC-001)', $text);
        $headings = [
            '1. Radio equipment (product, type, batch or serial number):',
            '2. Name and address of the manufacturer or his authorised representative:',
            '3. This declaration of conformity is issued under the sole responsibility of the manufacturer.',
            '4. Object of the declaration (identification of the radio equipment allowing traceability; it may include a colour image of sufficient clarity where necessary for the identification of the radio equipment):',
            '5. The object of the declaration described above is in conformity with the relevant Union harmonisation legislation:',
            '6. References to the relevant harmonised standards used or references to the other technical specifications in relation to which conformity is declared. References must be listed with their identification number and version and, where applicable, date of issue:',
            '7. Where applicable, the notified body … (name, number) … performed … (description of intervention) … and issued the EU-type examination certificate: …',
            '8. Where applicable, description of accessories and components, including software, which allow the radio equipment to operate as intended and covered by the EU declaration of conformity:',
            '9. Additional information:',
            'Signed for and on behalf of:', '(place and date of issue):', '(name, function) (signature):',
        ];
        $flat = preg_replace('/\s+/u', ' ', $text);
        $at = -1;
        foreach ($headings as $heading) {
            $position = mb_strpos($flat, $heading);
            $this->assertNotFalse($position, 'Missing heading: '.$heading);
            $this->assertGreaterThan($at, $position, 'Out of order: '.$heading);
            $at = $position;
        }
        foreach (['Smart lock', 'Smart Lock Pro', 'From serial 1000', 'ACME Locks SAS', '75002 Paris', 'France', 'Smart lock SL-100, hub included.',
            'Directive 2014/53/EU', 'COMMISSION DELEGATED REGULATION (EU) 2022/30 of 29 October 2021 supplementing Directive 2014/53/EU', 'Directive 2011/65/EU (RoHS)', 'DEMO-1 (sample standard)',
            'EN 62368-1:2020+A11:2020', 'EN 301 489-17 V3.2.4', 'EN 300 328 V2.2.2', 'Article 3(1)(a)', 'Article 3(2)',
            'Not applicable: internal production control (Annex II, Module A).', 'Firmware 2.1.0', 'Paris, 20 October 2026', 'Jane Doe, CEO'] as $expected) {
            $this->assertStringContainsString($expected, $flat);
        }
        $this->assertStringNotContainsString('DRAFT', $text);
        $this->assertStringNotContainsString('textpath', $headers);
        $this->assertStringNotContainsString('REDact', $text);

        $this->assertTrue(Activity::query()->where('log_name', 'report')->where('properties->kind', 'declaration')->exists());

        // French, the official French text of the Official Journal (item 3 ends with a colon there).
        $fr = $this->as($this->owner)->get(route('versions.declaration.download.lang', ['version' => $this->version, 'lang' => 'fr', 'format' => 'docx']))->assertOk();
        $frText = preg_replace('/\s+/u', ' ', $this->word($this->saved($fr, 'docx'))['text']);
        $this->assertStringContainsString('DECLARATION UE DE CONFORMITE (Nº DoC-001)', $frText);
        $this->assertStringContainsString('2. Nom et adresse du fabricant ou de son mandataire:', $frText);
        $this->assertStringContainsString('3. La présente déclaration de conformité est établie sous la seule responsabilité du fabricant:', $frText);
        $this->assertStringContainsString('Produit : Smart lock', $frText);

        // German: the Annex VI text in German, the person's content as entered, no English label.
        $de = $this->as($this->owner)->get(route('versions.declaration.download.lang', ['version' => $this->version, 'lang' => 'de', 'format' => 'docx']))->assertOk();
        $deText = preg_replace('/\s+/u', ' ', $this->word($this->saved($de, 'docx'))['text']);
        $this->assertStringContainsString('EU-KONFORMITÄTSERKLÄRUNG (Nr. DoC-001)', $deText);
        $this->assertStringContainsString('Die alleinige Verantwortung für die Ausstellung dieser Konformitätserklärung trägt der Hersteller.', $deText);
        $this->assertStringContainsString('DELEGIERTE VERORDNUNG (EU) 2022/30', $deText);
        $this->assertStringContainsString('Smart lock SL-100, hub included.', $deText);
        $this->assertStringContainsString('20. Oktober 2026', $deText);

        // The language select posts; the answer is the clean address of that file (no ?lang=).
        $this->as($this->owner)->post(route('versions.declaration.pick', $this->version), ['lang' => 'de', 'format' => 'pdf'])
            ->assertStatus(303)->assertRedirect(url('/versions/'.$this->version->id.'/declaration/download/de.pdf'));
        $this->as($this->owner)->post(route('versions.declaration.pick', $this->version), ['lang' => 'ga', 'format' => 'docx'])->assertSessionHasErrors('lang');
        $this->as($this->owner)->get('/versions/'.$this->version->id.'/declaration/download/ga.docx')->assertNotFound();
        $this->assertStringContainsString('3(1)(a)', $deText);
        $this->assertStringNotContainsString('Product:', $deText);
        $this->assertStringNotContainsString('Not applicable', $deText);
    }

    public function test_the_notified_body_and_certificate_fill_point_7(): void
    {
        $this->passEverything();
        $this->fillDeclaration();
        $this->approve(ConformityRoute::ModuleBC);
        $this->version->forceFill(['notified_body' => 'TÜV SÜD Product Service GmbH', 'notified_body_number' => '0123'])->save();

        $data = DeclarationData::for($this->version->fresh());
        $this->assertSame(['certificate'], array_column($data->openItems(), 'key'));

        $this->version->forceFill(['certificate_reference' => 'EU-TE-42', 'certificate_issued_on' => '2026-10-01'])->save();
        $response = $this->as($this->owner)->get(route('versions.declaration.download', ['version' => $this->version, 'format' => 'docx']))->assertOk();
        $flat = preg_replace('/\s+/u', ' ', $this->word($this->saved($response, 'docx'))['text']);
        $this->assertStringContainsString('The notified body TÜV SÜD Product Service GmbH (0123) performed the EU-type examination (Annex III, Module B) and issued the EU-type examination certificate: EU-TE-42', $flat);
        $this->assertStringContainsString('Issued on 1 October 2026.', $flat);
    }

    public function test_a_declaration_before_approval_or_with_open_items_is_a_draft(): void
    {
        // Draft version, trees not walked, no route: every reason listed, watermark on every page.
        $data = DeclarationData::for($this->version);
        $keys = array_column($data->openItems(), 'key');
        $this->assertContains('not_approved', $keys);
        $this->assertContains('no_route', $keys);
        $this->assertContains('remaining', $keys);

        $response = $this->as($this->owner)->get(route('versions.declaration.download', ['version' => $this->version, 'format' => 'docx']))->assertOk();
        $word = $this->word($this->saved($response, 'docx'));
        $this->assertStringContainsString('string="DRAFT — not valid"', $word['headers']);
        $this->assertStringContainsString('xmlns:v=', $word['headers']);
        $this->assertStringContainsString('DRAFT — not valid', $word['text']);
        $this->assertStringContainsString('No conformity assessment route chosen', $word['text']);
        $this->assertStringContainsString('[to complete]', $word['text']);
        // One list in the side panel (no banner): the state as a badge, each reason once, linking to its fix.
        $page = $this->as($this->owner)->get(route('versions.declaration', $this->version))->assertOk()
            ->assertSee('<span class="a-badge a-badge-danger">DRAFT — not valid</span>', false)
            ->assertSee('href="'.route('versions.conformity', $this->version).'" class="a-link-btn">No conformity assessment route chosen</a>', false)
            ->assertDontSee('a-outcome', false)
            ->assertDontSee('a-decl-open', false);
        $this->assertSame(1, substr_count($page->getContent(), 'No conformity assessment route chosen'));
        $this->assertSame(1, substr_count($page->getContent(), 'The version is draft, not approved in review'));

        // A FAIL, an INCONCLUSIVE and an unjustified walk on an approved version, and a conflicting route.
        $asset = $this->version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-09', 'name' => 'Logs', 'attributes' => ['type' => 'security'], 'position' => 2]);
        Evaluation::create(['product_version_id' => $this->version->id, 'standard' => 'demo-1', 'requirement' => 'ACC-1', 'inventory_item_id' => $asset->id, 'decision_node' => 'DN-2', 'verdict' => Verdict::Fail, 'justification' => 'No PIN on the logs at all, anyone reads them over the network.']);
        Evaluation::create(['product_version_id' => $this->version->id, 'standard' => 'demo-1', 'requirement' => 'UPD-1', 'inventory_item_id' => null, 'verdict' => Verdict::Inconclusive, 'justification' => 'Waiting for the supplier.']);
        $this->approve(ConformityRoute::ModuleA);
        $keys = array_column(DeclarationData::for($this->version->fresh())->openItems(), 'key');
        $this->assertNotContains('not_approved', $keys);
        $this->assertContains('fail', $keys);
        $this->assertContains('inconclusive', $keys);
        $this->assertContains('conflict', $keys);
    }

    public function test_the_declaration_form_saves_and_prefills(): void
    {
        $url = route('versions.declaration-data', $this->version);
        $this->as($this->owner)->get(route('versions.declaration', $this->version))->assertOk()
            ->assertSee("Smart lock 1.0\n\nA smart lock and its hub.", false)   // object proposed from the product, without the identifiers
            ->assertSee('Entered by you, not assessed in REDact')
            ->assertSee('list="standards-31a"', false)
            ->assertSee('EN 300 328', false)
            ->assertSee('Hereby, ACME declares that the radio equipment type SL-100 is in compliance with Directive 2014/53/EU.');

        $this->as($this->owner)->put($url, [
            'object' => 'My object', 'legislation' => ['Directive 2011/65/EU (RoHS)', ''],
            'standards' => [['reference' => 'EN 300 328 V2.2.2', 'article' => '3.2'], ['reference' => '', 'article' => '3.1a'], ['reference' => 'X', 'article' => '9.9']],
            'url' => 'https://acme.example/doc', 'date' => '2026-10-20', 'include_image' => '0',
        ])->assertSessionHasErrors('standards.2.article');

        $this->as($this->owner)->put($url, [
            'object' => 'My object', 'legislation' => ['Directive 2011/65/EU (RoHS)', ''], 'accessories' => '',
            'standards' => [['reference' => 'EN 300 328 V2.2.2', 'article' => '3.2'], ['reference' => '', 'article' => '3.1a']],
            'url' => 'https://acme.example/doc', 'date' => '2026-10-20', 'include_image' => '0',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $data = DeclarationData::for($this->version->fresh());
        $this->assertSame('My object', $data->value('object'));
        $this->assertSame(['Directive 2011/65/EU (RoHS)'], $data->legislation());
        $this->assertSame([['reference' => 'EN 300 328 V2.2.2', 'article' => '3.2']], $data->otherStandards());
        $this->assertSame('', $data->value('accessories'));   // emptied on purpose: never proposed again
        $this->assertFalse($data->includeImage());
        $this->assertStringContainsString('https://acme.example/doc', $data->simplified());
        $this->assertTrue(Activity::query()->where('description', 'declaration saved')->exists());

        // Bad URL, bad date.
        $this->as($this->owner)->put($url, ['url' => 'javascript:alert(1)', 'date' => '20/10/2026'])->assertSessionHasErrors(['url', 'date']);
    }

    public function test_only_the_right_people_edit_the_declaration(): void
    {
        $url = route('versions.declaration-data', $this->version);
        $stranger = User::factory()->create();
        $reviewer = $this->member($this->version->product, ProductRole::Reviewer);
        $contributor = $this->member($this->version->product, ProductRole::Contributor, true);

        $this->as($stranger)->get(route('versions.declaration', $this->version))->assertNotFound();
        $this->as($stranger)->get(route('versions.declaration.download', ['version' => $this->version, 'format' => 'docx']))->assertNotFound();
        $this->as($stranger)->get(route('versions.technical-file.download', $this->version))->assertNotFound();
        $this->as($reviewer)->get(route('versions.declaration', $this->version))->assertOk()->assertDontSee('data-shortcut="mod+s"', false);
        $this->as($reviewer)->put($url, ['object' => 'x'])->assertNotFound();
        $this->as($contributor)->put($url, ['object' => 'draft by contributor'])->assertRedirect();

        // In review: locked for everyone. Approved: owner and administrators only.
        $this->version->forceFill(['status' => VersionStatus::InReview])->save();
        $this->as($this->owner)->put($url, ['object' => 'x'])->assertNotFound();
        $this->approve();
        $this->as($contributor)->put($url, ['object' => 'x'])->assertNotFound();
        $this->as($this->owner)->put($url, ['object' => 'after approval'])->assertRedirect();
        $this->assertSame('after approval', DeclarationData::for($this->version->fresh())->value('object'));

        // The manufacturer: organisation administrators only.
        $organisation = $this->version->product->organisation;
        $this->as($contributor)->put(route('organisations.legal.update', $organisation), ['legal_name' => 'Hacked'])->assertNotFound();
        $this->as($this->owner)->put(route('organisations.legal.update', $organisation), ['legal_name' => 'ACME Locks SAS', 'address' => '1 rue de la Paix', 'country' => 'France'])->assertRedirect();
        $this->assertSame('ACME Locks SAS', $organisation->fresh()->legal_name);
        $this->assertTrue(Activity::query()->where('log_name', 'organisation')->where('subject_id', $organisation->id)->exists());
    }

    // ---- All languages ----------------------------------------------------------------------------

    public function test_each_model_is_listed_once_and_no_english_label_reaches_another_language(): void
    {
        $product = $this->version->product;
        ProductIdentifier::create(['product_id' => $product->id, 'kind' => ProductIdentifier::MODEL, 'value' => 'SL-100', 'notes' => 'EU variant', 'position' => 0]);
        ProductIdentifier::create(['product_id' => $product->id, 'kind' => ProductIdentifier::MODEL, 'value' => 'SL-100X', 'notes' => 'With keypad', 'position' => 1]);
        $this->version->forceFill(['hardware_version' => 'Rev C', 'software_version' => '3.4.1'])->save();

        $documents = [];
        foreach (['bg', 'de', 'en'] as $lang) {
            foreach (['docx', 'pdf'] as $format) {
                $response = $this->as($this->owner)->get(route('versions.declaration.download.lang', ['version' => $this->version, 'lang' => $lang, 'format' => $format]))->assertOk();
                $path = $this->saved($response, $format);
                $documents[$lang.'.'.$format] = $format === 'pdf'
                    ? (new PdfParser)->parseFile($path)->getText()
                    : $this->word($path)['text'];
            }
        }

        foreach ($documents as $name => $text) {
            $flat = (string) preg_replace('/\s+/u', ' ', $text);
            // Point 1 lists each model once with its note; point 4 does not repeat them.
            $this->assertSame(1, preg_match_all('/SL-100(?!X)/u', $flat), $name.': SL-100 once');
            $this->assertSame(1, substr_count($flat, 'SL-100X'), $name.': SL-100X once');
            $this->assertStringContainsString('(EU variant)', $flat, $name);
            $this->assertSame(1, substr_count($flat, 'Rev C'), $name);
            $this->assertSame(1, substr_count($flat, '3.4.1'), $name);
            $this->assertStringContainsString('Smart lock 1.0', $flat, $name);
        }
        foreach (['bg.docx', 'bg.pdf', 'de.docx', 'de.pdf'] as $name) {
            foreach (['Product:', 'Type:', 'Model', 'Hardware version', 'Software version', 'Trade name', 'Not applicable', '[to complete]', 'Batch or serial', 'Signature'] as $english) {
                $this->assertStringNotContainsString($english, $documents[$name], $name.' has "'.$english.'"');
            }
        }
        $this->assertStringContainsString('Model: SL-100 (EU variant)', preg_replace('/\s+/u', ' ', $documents['en.docx']));
    }

    public function test_the_annex_vi_text_exists_in_every_official_language(): void
    {
        $languages = AnnexText::languages();
        $this->assertCount(23, $languages);   // the Directive has no Irish version
        $this->assertArrayNotHasKey('ga', $languages);
        $this->assertSame(['bg', 'es', 'cs', 'da', 'de', 'et', 'el', 'en', 'fr', 'hr', 'it', 'lv', 'lt', 'hu', 'mt', 'nl', 'pl', 'pt', 'ro', 'sk', 'sl', 'fi', 'sv'], array_keys($languages));
        foreach (array_keys($languages) as $lang) {
            foreach (AnnexText::KEYS as $key) {
                $this->assertNotSame('', AnnexText::get($lang, $key), "{$lang}.{$key}");
            }
            $this->assertStringContainsString(':number', AnnexText::get($lang, 'title_numbered'), $lang);
            $this->assertStringContainsString(':manufacturer', AnnexText::get($lang, 'simplified_text'), $lang);
            $this->assertStringContainsString(':type', AnnexText::get($lang, 'simplified_text'), $lang);
            $this->assertStringContainsString('2014/53/', AnnexText::get($lang, 'p5_red'), $lang);
            $this->assertStringContainsString('2022/30', AnnexText::get($lang, 'delegated_regulation'), $lang);
        }
        $this->assertSame('La présente déclaration de conformité est établie sous la seule responsabilité du fabricant:', AnnexText::get('fr', 'p3'));
        $this->assertSame('This declaration of conformity is issued under the sole responsibility of the manufacturer.', AnnexText::get('en', 'p3'));
        $this->assertSame('2026-10-23', (string) AnnexText::source()['retrieved']);
        $this->assertArrayHasKey('ga', (array) AnnexText::source()['missing']);
    }

    public function test_all_languages_come_as_one_zip(): void
    {
        $this->fillDeclaration();
        $response = $this->as($this->owner)->get(route('versions.declaration.all', $this->version))->assertOk();
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->saved($response, 'zip')) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $this->assertCount(46, $names);   // Word and PDF in each of the 23 languages
        $this->assertContains('EU-DoC_Smart-lock_1.0_FR.docx', $names);
        $this->assertContains('EU-DoC_Smart-lock_1.0_FR.pdf', $names);
        $this->assertContains('EU-DoC_Smart-lock_1.0_BG.docx', $names);
        $this->assertContains('EU-DoC_Smart-lock_1.0_BG.pdf', $names);
        $this->assertStringStartsWith('%PDF-', (string) $zip->getFromName('EU-DoC_Smart-lock_1.0_EL.pdf'));
        $path = tempnam(sys_get_temp_dir(), 'doc').'.docx';
        file_put_contents($path, (string) $zip->getFromName('EU-DoC_Smart-lock_1.0_FR.docx'));
        $zip->close();
        $this->assertStringContainsString('La présente déclaration de conformité est établie sous la seule responsabilité du fabricant:', $this->word($path)['text']);
        $this->assertTrue(Activity::query()->where('log_name', 'report')->where('properties->kind', 'declaration')->where('properties->format', 'zip')->exists());

        // The step offers the 23 languages, the person's own selected.
        $this->as($this->owner)->get(route('versions.declaration', $this->version))->assertOk()
            ->assertSee('<option value="en" lang="en" selected>English · EN</option>', false)
            ->assertSee('Deutsch · DE')
            ->assertSee(route('versions.declaration.all', $this->version), false)
            ->assertSee('All 23 languages (zip)');
    }

    /** The text of a PDF (smalot/pdfparser), without any white space, so line breaks do not matter. */
    private function pdfText(string $path): string
    {
        $this->assertStringStartsWith('%PDF-', (string) file_get_contents($path, false, null, 0, 5));
        $text = (new PdfParser)->parseFile($path)->getText();

        return (string) preg_replace('/\s+/u', '', $text);
    }

    public function test_the_pdf_renders_every_script_of_the_directive(): void
    {
        $this->fillDeclaration();
        $this->passEverything();
        $this->approve();

        // Cyrillic, Greek and Latin Extended: item 3 of Annex VI, word for word, read back from the PDF.
        foreach (['bg', 'el', 'mt', 'lt', 'ro', 'pl', 'cs', 'hu'] as $lang) {
            $response = $this->as($this->owner)->get(route('versions.declaration.download.lang', ['version' => $this->version, 'lang' => $lang, 'format' => 'pdf']))->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
            $text = $this->pdfText($this->saved($response, 'pdf'));
            $this->assertStringContainsString((string) preg_replace('/\s+/u', '', AnnexText::get($lang, 'p3')), $text, $lang);
            $this->assertStringContainsString((string) preg_replace('/\s+/u', '', AnnexText::get($lang, 'title_numbered', ['number' => 'DoC-001'])), $text, $lang);
            $this->assertStringContainsString('ACMELocksSAS', $text, $lang);
            $this->assertStringNotContainsString('DRAFT', $text, $lang);
        }
        $this->assertTrue(Activity::query()->where('log_name', 'report')->where('properties->format', 'pdf')->exists());
    }

    public function test_a_draft_pdf_says_so(): void
    {
        $response = $this->as($this->owner)->get(route('versions.declaration.download.lang', ['version' => $this->version, 'lang' => 'fr', 'format' => 'pdf']))->assertOk();
        $text = $this->pdfText($this->saved($response, 'pdf'));
        $this->assertStringContainsString('DRAFT—notvalid', $text);
        $this->assertStringContainsString('Noconformityassessmentroutechosen', $text);
        $this->assertStringContainsString('Laprésentedéclarationdeconformitéestétabliesouslaseuleresponsabilitédufabricant:', $text);
    }

    // ---- Technical file -------------------------------------------------------------------------

    public function test_the_technical_file_follows_annex_v_with_an_index(): void
    {
        $this->passEverything();
        $this->fillDeclaration();
        $this->approve(ConformityRoute::ModuleBC);
        $this->version->forceFill(['notified_body' => 'TÜV SÜD Product Service GmbH', 'notified_body_number' => '0123'])->save();
        $this->as($this->owner)->put(route('versions.certificate.update', $this->version), [
            'certificate_reference' => 'EU-TE-42', 'certificate_issued_on' => '2026-10-01',
            'certificate_file' => UploadedFile::fake()->createWithContent('certificate.pdf', "%PDF-1.4\n%fake\n"),
        ])->assertSessionHasNoErrors();

        // A test report, filed under (h); a manual left unfiled.
        $this->version->forceFill(['status' => VersionStatus::Draft])->save();
        $this->as($this->owner)->post(route('versions.documents.store', $this->version), [
            'document' => UploadedFile::fake()->createWithContent('EMC report.pdf', "%PDF-1.4\n%emc\n"), 'category' => 'test_reports',
        ])->assertSessionHasNoErrors();
        $this->as($this->owner)->post(route('versions.documents.store', $this->version), [
            'document' => UploadedFile::fake()->createWithContent('Manual.pdf', "%PDF-1.4\n%manual\n"),
        ])->assertSessionHasNoErrors();
        $this->approve(ConformityRoute::ModuleBC);
        $manual = Document::query()->where('original_name', 'Manual.pdf')->firstOrFail();
        $this->as($this->owner)->patch(route('versions.technical-file.category', [$this->version, $manual]), ['category' => 'user_information'])->assertRedirect();
        $this->assertSame('user_information', $manual->fresh()->category);
        $this->as($this->owner)->patch(route('versions.technical-file.category', [$this->version, $manual]), ['category' => 'nope'])->assertSessionHasErrors('category');

        // The checklist on the step.
        $this->as($this->owner)->get(route('versions.declaration-file', $this->version))->assertOk()
            ->assertSee('Technical documentation (Annex V)')
            ->assertSee('Design and manufacturing drawings')
            ->assertSee('EMC report.pdf')
            ->assertSee(route('versions.technical-file.category', [$this->version, $manual]), false);

        $response = $this->as($this->owner)->get(route('versions.technical-file.download', $this->version))->assertOk();
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Technical documentation.zip', rawurldecode((string) $response->headers->get('Content-Disposition')));
        $path = $this->saved($response, 'zip');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $root = 'Smart lock 1.0 - Technical documentation/';
        foreach ([
            '01 (a) General description/', '01 (a) General description/(i) Photographs and illustrations/', '01 (a) General description/(iii) User information and installation instructions/',
            '02 (b) Design and manufacturing drawings/', '03 (c) Descriptions and explanations/', '04 (d) Harmonised standards applied/',
            '05 (e) EU declaration of conformity/', '06 (f) EU-type examination certificate/', '07 (g) Design calculations and examinations/',
            '08 (h) Test reports/', '09 (i) Article 10(2) and 10(10)/', '10 Other documents/',
        ] as $folder) {
            $this->assertContains($root.$folder, $names, 'Folder '.$folder);
        }
        foreach ([
            'README.txt', '00 Index of the technical documentation.docx',
            '01 (a) General description/Product description and identification.docx',
            '01 (a) General description/(iii) User information and installation instructions/Manual.pdf',
            '04 (d) Harmonised standards applied/Standards applied.docx',
            '05 (e) EU declaration of conformity/EU declaration of conformity.docx',
            '05 (e) EU declaration of conformity/EU declaration of conformity.pdf',
            '05 (e) EU declaration of conformity/Simplified EU declaration of conformity.txt',
            '06 (f) EU-type examination certificate/certificate.pdf',
            '07 (g) Design calculations and examinations/REDact - Smart lock 1.0 - Assessment.docx',
            '07 (g) Design calculations and examinations/REDact - Smart lock 1.0 - Assessment.xlsx',
            '07 (g) Design calculations and examinations/REDact - Smart lock 1.0 - Decision trees.docx',
            '08 (h) Test reports/REDact - Smart lock 1.0 - Review.docx',
            '08 (h) Test reports/EMC report.pdf',
        ] as $file) {
            $this->assertContains($root.$file, $names, 'File '.$file);
        }

        $readme = (string) $zip->getFromName($root.'README.txt');
        $this->assertStringContainsString('[MISSING] 02 (b) Design and manufacturing drawings', $readme);
        $this->assertStringContainsString('[PRESENT] 06 (f) EU-type examination certificate', $readme);
        $this->assertStringContainsString('[PRESENT] 08 (h) Test reports', $readme);
        $this->assertStringContainsString('- [Present] Notified Body’s certificate', $readme);
        $this->assertStringContainsString('- [Missing] Drawings and schemes of components, sub-assemblies and circuits', $readme);
        $this->assertStringContainsString('not assessed in REDact', $readme);
        $this->assertStringContainsString('Article 10(3)', $readme);

        // The index opens in Word and lists the items with their status.
        $index = tempnam(sys_get_temp_dir(), 'idx').'.docx';
        file_put_contents($index, (string) $zip->getFromName($root.'00 Index of the technical documentation.docx'));
        $zip->close();
        $flat = preg_replace('/\s+/u', ' ', $this->word($index)['text']);
        $this->assertStringContainsString('Technical documentation · index', $flat);
        $this->assertStringContainsString('a general description of the radio equipment including:', $flat);
        $this->assertStringContainsString('Missing', $flat);
        $this->assertStringContainsString('Present', $flat);

        $log = Activity::query()->where('log_name', 'report')->where('properties->kind', 'technical-file')->firstOrFail();
        $this->assertSame('zip', $log->properties['format']);
    }

    public function test_module_a_has_no_certificate_heading_to_fill(): void
    {
        $this->approve(ConformityRoute::ModuleA);
        $row = collect(TechnicalFile::for($this->version->fresh())->checklist())->firstWhere('heading', 'f');
        $this->assertSame('na', $row['status']);
        $this->as($this->owner)->get(route('versions.declaration', $this->version))->assertOk()->assertDontSee('id="certificate"', false);
    }

    // ---- Workflow and guests ----------------------------------------------------------------------

    public function test_the_step_is_last_and_waits_for_the_review(): void
    {
        $this->assertSame('declaration', Assessment::ORDER[count(Assessment::ORDER) - 1]);
        $summary = WorkflowSummary::for($this->version);
        $this->assertSame('declaration', $summary->groups[count($summary->groups) - 1]['key']);
        $this->assertSame('After the review', $summary->groups[count($summary->groups) - 1]['detail']);
        $this->as($this->owner)->get(route('versions.show', $this->version))->assertOk()
            ->assertSee('Declaration &amp; technical file', false)
            ->assertSee('data-shortcut="g l"', false);
    }
}
