<?php

namespace Tests\Feature\Guest;

use App\Ai\Availability;
use App\Auth\SignIn;
use App\Guest\Sandbox;
use App\Mail\ProductInvitation;
use App\Models\Evaluation;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Prism\Prism\Facades\Prism;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A guest gets the full workflow and the reports, and nothing else: no AI,
 * no documents, no team, one product, never staff.
 */
class GuestRestrictionsTest extends TestCase
{
    use GuestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGuest();
        Mail::fake();
        config([
            'redact.ai.enabled' => true,
            'redact.ai.provider' => 'gemini',
            'redact.ai.model' => 'gemini-test-model',
            'prism.providers.gemini.api_key' => 'test-key',
        ]);
    }

    public function test_a_guest_walks_the_full_workflow_but_needs_an_account_to_download(): void
    {
        $guest = $this->startSandbox();
        $version = $this->guestProduct($guest);

        $this->put(route('versions.scoping.update', $version), ['answers' => ['connected' => '1']])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('versions.risks.store', $version), [
            'threat' => 'Fake firmware', 'stride' => ['T'], 'impact' => 5, 'likelihood' => 3, 'requirements' => 'UPD-1', 'residual_rank' => 4,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('versions.inventory.store', [$version, 'assets']), [
            'code' => 'AS-01', 'name' => 'Door key', 'attributes' => ['type' => 'security'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $asset = $version->inventoryItems()->where('code', 'AS-01')->firstOrFail();

        $this->put(route('versions.evaluate.update', [$version, 'demo-1', 'ACC-1']), [
            'target' => $asset->id, 'answers' => ['No', 'Yes'], 'justification' => 'Paired app.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('DN-2', Evaluation::firstOrFail()->decision_node);

        $this->put(route('versions.einfo.update', [$version, 'demo-1']), [
            'entries' => [$asset->id => ['E.Info.ACC-1.Asset' => 'Paired app only.']],
        ])->assertRedirect();

        foreach ([
            route('dashboard'), route('team.index', $version->product),
            route('versions.show', $version), route('versions.scoping', $version), route('versions.risks', $version),
            route('versions.evaluate.requirement', [$version, 'demo-1', 'ACC-1']), route('versions.einfo', [$version, 'demo-1']),
            route('versions.review', $version), route('reports.index', $version), route('versions.documents', $version),
        ] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get(route('products.show', $version->product))->assertRedirect(route('versions.show', $version));

        // Downloads need an account (owner, 2026-10-23): the guest is led to "Keep my work".
        foreach (['docx', 'xlsx'] as $format) {
            $this->get(route('reports.download', ['version' => $version, 'kind' => 'assessment', 'format' => $format]))
                ->assertRedirect(route('guest.claim'))->assertSessionHas('status', __('guest.account_required'));
        }
        $this->assertDatabaseMissing('activity_log', ['log_name' => 'report', 'causer_id' => $guest->id]);

        // Without a reviewer the guest reviews it (self-review); here an evaluation is not justified yet.
        $this->post(route('versions.submit', $version))->assertSessionHas('error', trans_choice('assessment.submit_refused', 1, ['count' => 1]));
        Mail::assertNothingOutgoing();
    }

    public function test_a_sandbox_holds_exactly_one_product(): void
    {
        $guest = $this->startSandbox();
        $this->get(route('products.create'))->assertOk();
        $this->guestProduct($guest);

        $this->get(route('products.create'))->assertRedirect(route('dashboard'));
        $this->post(route('products.store'), [
            'organisation_id' => $guest->organisations()->first()->id, 'name' => 'Second', 'version_label' => '1.0',
        ])->assertRedirect(route('dashboard'))->assertSessionHas('error', __('guest.product_limit'));
        $this->assertSame(1, Product::withTrashed()->where('owner_id', $guest->id)->count());
        $this->get(route('dashboard'))->assertOk()->assertDontSee(route('products.create'), false);

        // Deleting it frees the place (a sandbox product is deleted for good).
        $product = Product::where('owner_id', $guest->id)->firstOrFail();
        $this->delete(route('products.destroy', $product))->assertRedirect();
        $this->assertSame(0, Product::withTrashed()->where('owner_id', $guest->id)->count());
        $this->get(route('products.create'))->assertOk();
    }

    public function test_the_ai_is_refused_and_explained(): void
    {
        Prism::fake([]);
        $guest = $this->startSandbox();
        $version = $this->guestProduct($guest);

        $this->postJson(route('ai.improve', $version), ['target' => 'version.intended_use', 'text' => 'A lock.'])->assertNotFound();
        $this->post(route('ai.draft', $version))->assertNotFound();
        $this->assertFalse(Availability::allows($guest, $version));
        $this->assertSame(__('ai.unavailable.guest'), Availability::reason($guest, $version));
        $this->get(route('versions.ai', $version))->assertOk()->assertSee(__('ai.unavailable.guest'));
        $this->assertDatabaseCount('ai_usage', 0);
    }

    public function test_documents_cannot_be_uploaded(): void
    {
        Storage::fake('local');
        $guest = $this->startSandbox();
        $version = $this->guestProduct($guest);

        $this->post(route('versions.documents.store', $version), [
            'document' => UploadedFile::fake()->createWithContent('notes.txt', 'Secure boot.'),
        ])->assertRedirect(route('guest.claim'));
        $this->assertSame(0, $version->documents()->count());

        // Bills of materials too: the import page itself leads to "Keep my work".
        $this->get(route('versions.sbom', $version))->assertRedirect(route('guest.claim'));
        $this->post(route('versions.sbom.store', $version), [])->assertRedirect(route('guest.claim'));
        $this->get(route('sbom.template'))->assertRedirect(route('guest.claim'));
        $this->getJson(route('reports.download', ['version' => $version, 'kind' => 'assessment', 'format' => 'xlsx']))->assertForbidden();

        $this->get(route('versions.documents', $version))->assertOk()
            ->assertSee(__('guest.documents'))
            ->assertDontSee(route('versions.documents.store', $version), false)
            ->assertDontSee(route('versions.import.store', $version), false);
    }

    public function test_the_team_cannot_be_invited(): void
    {
        $guest = $this->startSandbox();
        $product = $this->guestProduct($guest)->product;

        $this->post(route('team.invite', $product), ['email' => 'lab@example.com', 'role' => 'reviewer'])->assertNotFound();
        $this->assertSame(0, $product->invitations()->count());
        Mail::assertNotQueued(ProductInvitation::class);

        $this->get(route('team.index', $product))->assertOk()
            ->assertSee(__('guest.team'))
            ->assertDontSee(route('team.invite', $product), false)
            ->assertDontSee($guest->email);
    }

    public function test_account_and_passkey_pages_lead_to_keep_my_work(): void
    {
        $this->startSandbox();

        $this->get(route('account.show'))->assertRedirect(route('guest.claim'));
        $this->get(route('auth.confirm'))->assertRedirect(route('guest.claim'));
        $this->get(route('auth.passkey.suggest'))->assertRedirect(route('guest.claim'));
        $this->put(route('account.update'), ['name' => 'X', 'locale' => 'en', 'timezone' => 'UTC'])->assertNotFound();
        $this->getJson(route('passkey.registration-options'))->assertNotFound();
        $this->delete(route('account.destroy'), ['confirm' => '1'])->assertNotFound();
    }

    public function test_a_guest_is_never_staff(): void
    {
        $guest = $this->startSandbox();
        Role::findOrCreate('staff', 'web');

        try {
            $guest->assignRole('staff');
            $this->fail('A guest was given a role.');
        } catch (LogicException) {
        }
        $this->assertFalse($guest->fresh()->isStaff());
        $this->get('/admin')->assertNotFound();

        $this->assertSame(1, Artisan::call('redact:make-staff', ['email' => $guest->email]));
        $this->assertSame(0, $guest->fresh()->roles()->count());
    }

    public function test_the_banner_and_header_are_for_guests_only(): void
    {
        $guest = $this->startSandbox();
        $date = Sandbox::expiryLabel($guest->fresh());

        $page = $this->get(route('dashboard'))->assertOk()
            ->assertSee(__('guest.banner', ['date' => $date]))
            ->assertSee('data-guest-banner', false)
            ->assertSee(route('guest.claim'), false)
            ->assertSee(__('guest.leave'))
            ->assertDontSee(__('nav.sign_out'))
            ->assertDontSee(route('account.show'), false);
        // "Keep my work" once, in the banner with the deadline (not again in the header).
        $this->assertSame(1, substr_count($page->getContent(), '>'.__('guest.keep').'</a>'));

        $user = $this->makeUser();
        $this->signedIn($user, SignIn::EMAIL_LINK)->get(route('dashboard'))->assertOk()
            ->assertDontSee('data-guest-banner', false)
            ->assertDontSee(__('guest.keep'))
            ->assertSee(__('nav.sign_out'));
        $this->get(route('guest.claim'))->assertRedirect(route('dashboard'));
        $this->post(route('guest.claim.send'), [])->assertNotFound();
    }

    public function test_a_guest_cannot_reach_another_product(): void
    {
        $other = $this->makeUser();
        $this->startSandbox();
        $product = Product::create([
            'organisation_id' => Organisation::create(['name' => 'Other', 'created_by' => $other->id])->id,
            'owner_id' => $other->id,
            'name' => 'Not yours',
        ]);

        $this->get(route('products.show', $product))->assertNotFound();
        $this->assertInstanceOf(User::class, auth()->user());
    }
}
