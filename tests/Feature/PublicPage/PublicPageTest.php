<?php

namespace Tests\Feature\PublicPage;

use App\Enums\ProductRole;
use App\Enums\VersionStatus;
use App\Models\Document;
use App\Models\ProductVersion;
use App\Models\User;
use App\PublicPage\PublicCode;
use App\PublicPage\PublicSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * Public product page: choices saved as a draft, published only from the preview of an approved
 * version, at a fixed /verify/{code}; visitors see the published copy and only the files it offers.
 */
class PublicPageTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    private User $owner;

    private ProductVersion $version;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->owner = $this->owner();
        $this->version = $this->product($this->owner);
        $this->version->product->organisation->update(['legal_name' => 'Emotec SAS', 'security_contact' => 'security@emotec.example']);
    }

    private function approve(): void
    {
        $this->version->forceFill(['status' => VersionStatus::Approved, 'approved_at' => now()])->save();
    }

    /** @param  array<string, mixed>  $overrides */
    private function save(array $overrides = []): TestResponse
    {
        return $this->signIn($this->owner)->put(route('versions.public.update', $this->version), $overrides + [
            'sections' => ['assessment' => 1, 'support' => 1, 'contact' => 1, 'documents' => 1, 'risks' => 0, 'vex' => 1, 'sbom' => 0, 'hbom' => 0],
            'contact' => 'https://emotec.example/security',
            'support_until' => '2031-12-31',
            'indexed' => 1,
        ]);
    }

    public function test_the_settings_start_from_the_organisation_contact(): void
    {
        $settings = PublicSettings::for($this->version);

        $this->assertSame('security@emotec.example', $settings->contact);
        $this->assertFalse($settings->shows('risks'));
        $this->signIn($this->owner)->get(route('versions.public', $this->version))->assertOk()->assertSee('security@emotec.example');
    }

    public function test_choices_are_a_draft_until_published_from_the_preview(): void
    {
        $this->approve();
        $this->save()->assertRedirect(route('versions.public', $this->version));
        $this->assertNull($this->version->fresh()->published_at);
        $this->assertNull($this->version->fresh()->product->public_code);

        $this->signIn($this->owner)->get(route('versions.public.preview', $this->version))->assertOk()->assertSee(__('public_page.publish'));
        $this->signIn($this->owner)->post(route('versions.public.publish', $this->version))->assertRedirect();

        $version = $this->version->fresh();
        $code = $version->product->public_code;
        $this->assertNotNull($version->published_at);
        $this->assertMatchesRegularExpression('/^'.PublicCode::PATTERN.'$/', (string) $code);

        $this->get('/verify/'.$code)->assertOk()->assertSee('Emotec SAS')->assertSee('https://emotec.example/security')->assertSee('31 December 2031');

        // A later change stays a draft: visitors keep the published copy until it is published again.
        $this->save(['contact' => 'psirt@emotec.example']);
        $this->assertTrue(PublicSettings::hasUnpublishedChanges($this->version->fresh()));
        $this->get('/verify/'.$code)->assertOk()->assertDontSee('psirt@emotec.example');
        $this->signIn($this->owner)->post(route('versions.public.publish', $this->version));
        $this->get('/verify/'.$code)->assertOk()->assertSee('psirt@emotec.example');
        $this->assertSame($code, $this->version->fresh()->product->public_code, 'the address never changes');
    }

    public function test_a_hidden_section_is_not_on_the_page(): void
    {
        $this->approve();
        $this->save(['sections' => ['contact' => 0, 'support' => 0, 'assessment' => 1]]);
        $this->signIn($this->owner)->post(route('versions.public.publish', $this->version));

        $this->get('/verify/'.$this->version->fresh()->product->public_code)->assertOk()
            ->assertDontSee('https://emotec.example/security')->assertDontSee('31 December 2031')->assertDontSee(__('public_page.page.report'));
    }

    public function test_publishing_needs_an_approved_version_and_the_switched_on_fields(): void
    {
        $this->save();
        $this->signIn($this->owner)->post(route('versions.public.publish', $this->version))->assertNotFound();

        $this->approve();
        $this->save(['contact' => '', 'support_until' => '']);
        $this->signIn($this->owner)->post(route('versions.public.publish', $this->version))->assertSessionHas('error');
        $this->assertNull($this->version->fresh()->published_at);
    }

    public function test_the_contact_is_an_email_or_a_web_address(): void
    {
        $this->save(['contact' => 'call us'])->assertSessionHasErrors('contact');
        $this->save(['contact' => 'javascript:alert(1)'])->assertSessionHasErrors('contact');
        $this->save(['contact' => 'security@emotec.example'])->assertSessionHasNoErrors();
    }

    public function test_only_the_owner_or_an_organisation_admin_chooses_and_publishes(): void
    {
        $this->approve();
        $contributor = $this->member($this->version->product, ProductRole::Contributor);

        $this->signIn($contributor)->get(route('versions.public', $this->version))->assertOk()->assertSee(__('public_page.read_only'));
        $this->signIn($contributor)->put(route('versions.public.update', $this->version), ['contact' => 'x@y.z'])->assertNotFound();
        $this->signIn($contributor)->post(route('versions.public.publish', $this->version))->assertNotFound();

        $stranger = User::factory()->create();
        $this->signIn($stranger)->get(route('versions.public', $this->version))->assertNotFound();
    }

    public function test_unknown_or_unpublished_pages_answer_404(): void
    {
        $this->get('/verify/ZZZZZZ')->assertNotFound();

        $this->approve();
        $this->save();
        $this->signIn($this->owner)->post(route('versions.public.publish', $this->version));
        $code = $this->version->fresh()->product->public_code;
        $this->signIn($this->owner)->delete(route('versions.public.unpublish', $this->version))->assertRedirect();

        auth()->logout();
        $this->get('/verify/'.$code)->assertNotFound();
    }

    public function test_visitors_download_only_the_ticked_documents(): void
    {
        $this->approve();
        $this->signIn($this->owner)->post(route('versions.public.documents', $this->version), [
            'document' => UploadedFile::fake()->createWithContent('manual.pdf', "%PDF-1.4\n%test\n"),
            'category' => 'user_information',
        ])->assertRedirect();
        $ticked = Document::query()->where('product_version_id', $this->version->id)->firstOrFail();
        $other = $this->version->documents()->create([
            'uploaded_by' => $this->owner->id, 'original_name' => 'internal.pdf', 'mime' => 'application/pdf', 'size' => 5, 'sha256' => str_repeat('0', 64), 'path' => 'documents/x/internal', 'category' => 'other',
        ]);
        Storage::disk('local')->put('documents/x/internal', 'secret');
        $this->assertSame([$ticked->id], PublicSettings::for($this->version->fresh())->documents, 'an added document is ticked');

        $this->save(['documents' => [$ticked->id]]);
        $this->signIn($this->owner)->post(route('versions.public.publish', $this->version));
        $code = $this->version->fresh()->product->public_code;
        auth()->logout();

        $this->get(route('verify.document', [$code, $this->version->id, $ticked->id]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(route('verify.document', [$code, $this->version->id, $other->id]))->assertNotFound();
    }

    public function test_risks_are_counted_and_the_ticked_ones_listed_in_public_words(): void
    {
        $this->approve();
        $this->version->riskLevels()->delete();
        $this->version->riskLevels()->createMany([
            ['rank' => 1, 'name' => 'Critical', 'color' => '#dc2626'],
            ['rank' => 2, 'name' => 'Serious', 'color' => '#f59e0b'],
            ['rank' => 3, 'name' => 'Minor', 'color' => '#16a34a'],
        ]);
        $high = $this->version->riskEntries()->create(['position' => 1, 'threat' => 'Internal threat name A', 'residual_rank' => 2]);
        $low = $this->version->riskEntries()->create(['position' => 2, 'threat' => 'Internal threat name B', 'residual_rank' => 3]);

        $this->assertSame([$high->id], PublicSettings::for($this->version->fresh())->risks, 'the two highest levels are ticked by default');

        $this->save([
            'sections' => ['risks' => 1, 'contact' => 0, 'support' => 0],
            'risks' => [$high->id],
            'risk_texts' => [$high->id => ['title' => 'Someone guesses the default code', 'advice' => 'Change it during set-up.']],
        ]);
        $this->signIn($this->owner)->post(route('versions.public.publish', $this->version));

        $this->get('/verify/'.$this->version->fresh()->product->public_code)->assertOk()
            ->assertSee('Someone guesses the default code')->assertSee('Change it during set-up.')
            ->assertDontSee('Internal threat name A')->assertDontSee('Internal threat name B')->assertSee('Minor');
    }

    public function test_the_qr_code_and_the_product_list(): void
    {
        $this->approve();
        $this->save();
        $this->signIn($this->owner)->post(route('versions.public.publish', $this->version));
        $product = $this->version->fresh()->product;

        $svg = $this->signIn($this->owner)->get(route('products.qr', [$product, 'svg']))->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertStringContainsString('<svg', (string) $svg->getContent());
        $this->signIn($this->owner)->get(route('dashboard'))->assertOk()->assertSee($product->public_code)->assertSee(route('verify.show', $product->public_code));
    }

    public function test_search_engines_index_the_latest_version_in_each_language(): void
    {
        $this->assertStringContainsString('Allow: /verify/', (string) $this->get('/robots.txt')->getContent());
        $this->approve();
        $this->save();
        $this->assertStringNotContainsString('/verify/', (string) $this->get('/sitemap.xml')->getContent(), 'nothing before publishing');
        $this->signIn($this->owner)->post(route('versions.public.publish', $this->version));
        $code = $this->version->fresh()->product->public_code;
        auth()->logout();

        $this->get('/verify/'.$code)->assertOk()
            ->assertSee('<meta name="robots" content="index, follow', false)
            ->assertSee('<link rel="canonical" href="'.route('verify.show', $code).'">', false)
            ->assertSee('<link rel="alternate" hreflang="fr" href="'.route('verify.show.lang', [$code, 'fr']).'">', false)
            ->assertSee('"@type":"Product"', false);
        $this->get('/verify/'.$code.'/fr')->assertOk()->assertSee('<link rel="canonical" href="'.route('verify.show.lang', [$code, 'fr']).'">', false);
        $this->assertStringContainsString(route('verify.show.lang', [$code, 'en']), (string) $this->get('/sitemap.xml')->getContent());

        // An earlier version stays out of search results.
        $older = $this->version->replicate(['public_page', 'published_at']);
        $older->forceFill(['label' => '0.9', 'created_at' => now()->subYear(), 'published_at' => now()->subYear(), 'public_page' => $this->version->fresh()->public_page])->save();
        $this->get(route('verify.version', [$code, $older->id]))->assertOk()->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }

    public function test_the_manufacturer_keeps_the_page_out_of_search_engines(): void
    {
        $this->approve();
        $this->save(['indexed' => 0]);
        $this->signIn($this->owner)->get(route('versions.public', $this->version))->assertSee(__('public_page.indexed'));
        $this->signIn($this->owner)->post(route('versions.public.publish', $this->version));
        $code = $this->version->fresh()->product->public_code;
        auth()->logout();

        $this->get('/verify/'.$code)->assertOk()->assertSee('<meta name="robots" content="noindex, nofollow">', false)->assertDontSee('application/ld+json', false);
        $this->assertStringNotContainsString('/verify/', (string) $this->get('/sitemap.xml')->getContent());

        // Switched back on: listed once published again.
        $this->save(['indexed' => 1]);
        $this->get('/verify/'.$code)->assertSee('noindex', false);
        $this->signIn($this->owner)->post(route('versions.public.publish', $this->version));
        auth()->logout();
        $this->get('/verify/'.$code)->assertSee('<meta name="robots" content="index, follow', false);
        $this->assertStringContainsString('/verify/'.$code, (string) $this->get('/sitemap.xml')->getContent());
    }

    public function test_printable_labels_once_published(): void
    {
        $this->approve();
        $this->save();
        $this->signIn($this->owner)->get(route('versions.public.labels', [$this->version, 'packaging', 'en']))->assertNotFound();
        $this->signIn($this->owner)->post(route('versions.public.publish', $this->version));
        $code = $this->version->fresh()->product->public_code;

        $this->signIn($this->owner)->get(route('versions.public', $this->version))->assertSee(route('versions.public.labels', [$this->version, 'packaging', 'en']));
        $this->signIn($this->owner)->get(route('versions.public.labels', [$this->version, 'product', 'en']))->assertOk()
            ->assertSee('verify/'.$code)->assertSee(__('public_page.labels.per_sheet', ['count' => 80]));
        $this->signIn($this->owner)->get(route('versions.public.labels', [$this->version, 'manual', 'fr']))->assertOk()
            ->assertSee('Informations de sécurité du produit')
            ->assertSee('Évaluation, mises à jour de sécurité, signalement des vulnérabilités, documents et vulnérabilités connues.');
        $this->signIn(User::factory()->create())->get(route('versions.public.labels', [$this->version, 'product', 'en']))->assertNotFound();
    }

    public function test_the_about_page_presents_the_public_page(): void
    {
        $this->get(route('about'))->assertOk()->assertSee(__('public.about.verify_title'));
    }

    public function test_the_page_follows_the_language_picked_on_it(): void
    {
        $this->approve();
        $this->save();
        $this->signIn($this->owner)->post(route('versions.public.publish', $this->version));
        $code = $this->version->fresh()->product->public_code;

        $this->get('/verify/'.$code.'/fr')->assertOk()->assertSee('Contact sécurité');
        $this->get('/verify/'.$code.'/en')->assertOk()->assertSee('Security contact');
    }
}
