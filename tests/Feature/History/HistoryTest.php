<?php

namespace Tests\Feature\History;

use App\Assessment\VersionFactory;
use App\Enums\ProductRole;
use App\Enums\VersionStatus;
use App\Models\EinfoEntry;
use App\Models\Evaluation;
use App\Models\InventoryItem;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\RiskEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsProducts;
use Tests\Feature\Guest\GuestHelpers;
use Tests\TestCase;

/**
 * Customer histories (App\History, HistoryController): who sees them, what they say, filters,
 * CSV export, cetome support transparency, sandboxes and tenant isolation.
 */
class HistoryTest extends TestCase
{
    use BuildsProducts, GuestHelpers, RefreshDatabase;

    private User $owner;

    private ProductVersion $version;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();

        $this->owner = $this->owner();
        $this->owner->update(['name' => 'Olivia Owner']);
        $this->version = $this->product($this->owner);
        $this->product = $this->version->product;
    }

    private function url(): string
    {
        return route('products.history', $this->product);
    }

    /** The filter form (POST, kept in the session), then the clean page. */
    private function filtered(array $filters, ?Organisation $organisation = null, ?User $as = null): TestResponse
    {
        $page = $organisation ? route('organisations.history', $organisation) : $this->url();
        $this->signIn($as ?? $this->owner)
            ->post($organisation ? route('organisations.history.filter', $organisation) : route('products.history.filter', $this->product), $filters)
            ->assertRedirect($page)->assertSessionHasNoErrors();

        return $this->get($page);
    }

    private function item(string $code = 'AS-01', string $name = 'Door key'): InventoryItem
    {
        return InventoryItem::create(['product_version_id' => $this->version->id, 'list' => 'assets', 'code' => $code, 'name' => $name]);
    }

    private function staff(): User
    {
        $staff = User::factory()->create(['name' => 'Sam Staff']);
        $staff->assignRole(Role::findOrCreate('staff', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $staff;
    }

    // ---- Who sees it -------------------------------------------------------------------------------

    public function test_the_owner_sees_the_product_history_and_its_link(): void
    {
        $this->signIn($this->owner)->get($this->url())
            ->assertOk()
            ->assertSee(__('history.title'))
            ->assertSee('Created the product')
            ->assertSee('Smart lock')
            ->assertSee('Created version')
            ->assertSee('Entries are kept 2 years.');

        $this->signIn($this->owner)->get(route('versions.show', $this->version))->assertOk()->assertSee($this->url(), false);
        $this->signIn($this->owner)->get(route('products.edit', $this->product))->assertOk()->assertSee($this->url(), false);
    }

    public function test_an_organisation_admin_sees_the_product_and_organisation_histories(): void
    {
        $admin = User::factory()->create(['name' => 'Ada Admin']);
        $this->product->organisation->members()->attach($admin->id, ['role' => 'admin']);

        $this->signIn($admin)->get($this->url())->assertOk()->assertSee('Created the product');
        $this->signIn($admin)->get(route('organisations.history', $this->product->organisation))->assertOk()
            ->assertSee(__('history.title'))->assertSee('Created the product');
        // The Team page has the History tab for admins.
        $this->signIn($admin)->get(route('organisations.team', $this->product->organisation))->assertOk()
            ->assertSee(route('organisations.history', $this->product->organisation), false);
    }

    public function test_contributors_read_the_product_history_without_the_export(): void
    {
        $contributor = $this->member($this->product, ProductRole::Contributor, internal: true);
        $known = User::factory()->create(['name' => 'Lena Known', 'email' => 'lena@example.test']);
        $this->signIn($this->owner)->post(route('team.invite', $this->product), ['email' => 'lena@example.test', 'role' => 'reviewer'])->assertRedirect();
        $this->post(route('team.invite', $this->product), ['email' => 'nobody@example.test', 'role' => 'reviewer']);

        // Names only: no e-mail address of the team, of the invited people, of the actors.
        $this->signIn($contributor)->get($this->url())->assertOk()
            ->assertSeeText('Invited Lena Known as Reviewer')
            ->assertSeeText('Invited '.__('history.someone').' as Reviewer')
            ->assertDontSee('lena@example.test')->assertDontSee('nobody@example.test')
            ->assertDontSee($this->owner->email)
            ->assertDontSee(route('products.history.csv', $this->product), false);
        // The owner still reads the addresses.
        $this->signIn($this->owner)->get($this->url())->assertSeeText('Invited lena@example.test as Reviewer');
        $this->signIn($contributor);
        $this->get(route('products.history.csv', $this->product))->assertNotFound();
        $this->get(route('organisations.history', $this->product->organisation))->assertNotFound();
        // The sidebar offers History to contributors too.
        $this->get(route('versions.show', $this->version))->assertOk()->assertSee($this->url(), false);
    }

    public function test_reviewers_members_and_strangers_get_a_404(): void
    {
        $reviewer = $this->member($this->product, ProductRole::Reviewer);
        $internalReviewer = $this->member($this->product, ProductRole::Reviewer, internal: true);
        $member = User::factory()->create();
        $this->product->organisation->members()->attach($member->id, ['role' => 'member']);
        $stranger = User::factory()->create();
        $organisation = $this->product->organisation;

        foreach ([$reviewer, $internalReviewer, $member, $stranger] as $user) {
            $this->signIn($user)->get($this->url())->assertNotFound();
            $this->signIn($user)->get(route('products.history.csv', $this->product))->assertNotFound();
            $this->signIn($user)->get(route('organisations.history', $organisation))->assertNotFound();
            $this->signIn($user)->get(route('organisations.history.csv', $organisation))->assertNotFound();
        }

        // No link for reviewers.
        $this->signIn($reviewer)->get(route('versions.show', $this->version))->assertOk()->assertDontSee($this->url(), false);

        // A reviewer who is also an organisation admin: admin wins.
        $organisation->members()->updateExistingPivot($internalReviewer->id, ['role' => 'admin']);
        $this->signIn($internalReviewer)->get($this->url())->assertOk();
    }

    public function test_a_sandbox_guest_has_no_history(): void
    {
        $this->setUpGuest();
        $guest = $this->startSandbox();
        $version = $this->guestProduct($guest);

        $this->get(route('products.history', $version->product))->assertRedirect(route('guest.claim'));
        $this->get(route('organisations.history', $guest->organisations()->first()))->assertRedirect(route('guest.claim'));
        $this->get(route('versions.show', $version))->assertOk()->assertDontSee(route('products.history', $version->product), false);
    }

    // ---- What it says ------------------------------------------------------------------------------

    public function test_the_main_events_are_described(): void
    {
        Auth::login($this->owner);
        $item = $this->item();
        $evaluation = Evaluation::create(['product_version_id' => $this->version->id, 'standard' => 'demo-1', 'requirement' => 'ACC-1',
            'inventory_item_id' => $item->id, 'verdict' => 'NOT_APPLICABLE', 'justification' => 'Not reachable.']);
        $evaluation->update(['verdict' => 'PASS']);
        EinfoEntry::create(['product_version_id' => $this->version->id, 'einfo' => 'E.Info.ACC-1.Asset', 'inventory_item_id' => $item->id, 'text' => 'Locked.']);
        RiskEntry::create(['product_version_id' => $this->version->id, 'threat' => 'Stolen key', 'position' => 1]);
        activity('report')->causedBy($this->owner)->performedOn($this->version)->withProperties(['kind' => 'technical-file', 'format' => 'zip', 'version' => $this->version->id])->log('exported');
        activity('import')->causedBy($this->owner)->performedOn($this->version)->withProperties(['files' => ['lock.xlsx'], 'counts' => []])->log('imported');
        $this->version->statusReason = 'submitted';
        $this->version->forceFill(['status' => VersionStatus::InReview])->save();
        $this->version->statusReason = 'approved';
        $this->version->forceFill(['status' => VersionStatus::Approved])->save();
        Auth::logout();

        // An invitation through the product Team page.
        $this->signIn($this->owner)->post(route('team.invite', $this->product), ['email' => 'lena@example.test', 'role' => 'reviewer'])->assertRedirect();

        $page = $this->signIn($this->owner)->get($this->url())->assertOk();
        $page->assertSeeText('Changed the verdict of ACC-1 on AS-01 · Door key: NOT APPLICABLE → PASS')
            ->assertSeeText('Evaluated ACC-1 on AS-01 · Door key: NOT APPLICABLE')
            ->assertSeeText('Wrote the E.Info E.Info.ACC-1.Asset for AS-01 · Door key')
            ->assertSeeText('Added the risk “Stolen key”')
            ->assertSeeText('Added AS-01 · Door key to Assets')
            ->assertSeeText('Invited lena@example.test as Reviewer')
            ->assertSeeText('Downloaded the technical file')
            ->assertSeeText('Imported a workbook: lock.xlsx')
            ->assertSeeText('Submitted for review')
            ->assertSeeText('Approved')
            ->assertSeeText('Olivia Owner')
            ->assertSee($this->owner->email);
    }

    public function test_text_changes_show_a_word_diff(): void
    {
        Auth::login($this->owner);
        $item = $this->item();
        $evaluation = Evaluation::create(['product_version_id' => $this->version->id, 'standard' => 'demo-1', 'requirement' => 'ACC-1',
            'inventory_item_id' => $item->id, 'verdict' => 'PASS', 'justification' => 'The key is stored in the secure element.']);
        $evaluation->update(['justification' => 'The key is stored in the encrypted flash.']);

        $this->signIn($this->owner)->get($this->url())->assertOk()
            ->assertSeeText('Edited the evaluation of ACC-1 on AS-01 · Door key')
            ->assertSee('<del class="a-diff-del"><span class="a-sr-only">[Removed] </span>secure element</del>', false)
            ->assertSee('<ins class="a-diff-ins"><span class="a-sr-only">[Added] </span>encrypted flash</ins>', false);
    }

    public function test_entries_keep_their_scope_and_reference_after_the_subject_is_gone(): void
    {
        Auth::login($this->owner);
        $item = $this->item('AS-09', 'Spare key');
        $evaluation = Evaluation::create(['product_version_id' => $this->version->id, 'standard' => 'demo-1', 'requirement' => 'ACC-1',
            'inventory_item_id' => $item->id, 'verdict' => 'FAIL', 'justification' => 'x']);
        $evaluation->delete();

        $entry = Activity::query()->where('log_name', 'evaluation')->where('event', 'deleted')->sole();
        $this->assertSame($this->product->id, $entry->getAttribute('product_id'));
        $this->assertSame($this->version->id, $entry->getAttribute('product_version_id'));
        $this->assertSame($this->product->organisation_id, $entry->getAttribute('organisation_id'));
        $this->assertSame('AS-09 · Spare key', $entry->getProperty('ref.item'));

        $item->delete();
        $this->signIn($this->owner)->get($this->url())->assertSeeText('Removed the evaluation of ACC-1 on AS-09 · Spare key');
    }

    // ---- Filters, pages, export --------------------------------------------------------------------

    public function test_filters_by_area_person_version_date_and_text(): void
    {
        $colleague = User::factory()->create(['name' => 'Carl Colleague']);
        $this->product->organisation->members()->attach($colleague->id, ['role' => 'admin']);
        Auth::login($this->owner);
        RiskEntry::create(['product_version_id' => $this->version->id, 'threat' => 'Stolen key', 'position' => 1]);
        Auth::login($colleague);
        RiskEntry::create(['product_version_id' => $this->version->id, 'threat' => 'Jammed radio', 'position' => 2]);
        $other = app(VersionFactory::class)->create($this->product, '2.0', $this->owner);
        Auth::login($this->owner);
        RiskEntry::create(['product_version_id' => $other->id, 'threat' => 'Cloned badge', 'position' => 1]);
        Auth::logout();

        $this->filtered(['kind' => 'risks'])->assertOk()
            ->assertSeeText('Stolen key')->assertDontSeeText('Created the product');
        // The filters stay on the page (session), with no query string anywhere.
        $kept = $this->get($this->url())->assertOk()->assertSeeText('Stolen key')->assertDontSeeText('Created the product');
        $this->assertDoesNotMatchRegularExpression('/(href|action)="[^"]*\?/', $kept->getContent());
        $this->filtered(['person' => (string) $colleague->id])->assertOk()
            ->assertSeeText('Jammed radio')->assertDontSeeText('Stolen key');
        $this->filtered(['version' => $other->id, 'kind' => 'risks'])->assertOk()
            ->assertSeeText('Cloned badge')->assertDontSeeText('Stolen key');
        $this->filtered(['q' => 'jammed'])->assertOk()
            ->assertSeeText('Jammed radio')->assertDontSeeText('Stolen key');
        $this->filtered(['from' => now()->addDays(2)->format('Y-m-d')])->assertOk()
            ->assertSeeText(__('history.none_filtered'));
        $this->filtered(['q' => 'jammed', 'reset' => '1'])->assertOk()->assertSeeText('Created the product');
        $this->signIn($this->owner)->from($this->url())->post(route('products.history.filter', $this->product), ['kind' => 'nonsense'])
            ->assertRedirect($this->url())->assertSessionHasErrors('kind');
    }

    public function test_bulk_entries_fold_and_pages_follow_a_cursor(): void
    {
        Auth::login($this->owner);
        foreach (range(1, 12) as $i) {
            $this->item(sprintf('AS-%02d', $i + 10), 'Key '.$i);
        }
        Auth::logout();

        $this->signIn($this->owner)->get($this->url())->assertOk()->assertSeeText('+11 more');

        // More lines than a page: an Older link with a cursor.
        Activity::query()->where('log_name', 'inventory')->update(['created_at' => now()->subDay()]);
        foreach (range(1, 60) as $i) {
            activity('report')->causedBy($this->owner)->performedOn($this->version)->withProperties(['kind' => 'technical-file'])->log('exported');
            Activity::query()->latest('id')->first()->forceFill(['created_at' => now()->subMinutes(20 * $i)])->save();
        }
        $page = $this->signIn($this->owner)->get($this->url())->assertOk()->assertSeeText(__('history.older'));
        $older = preg_quote($this->url(), '#').'/before/(\d+)';
        $this->assertMatchesRegularExpression('#'.$older.'#', $page->getContent());
        $this->assertStringNotContainsString('before=', $page->getContent());
        preg_match('#'.$older.'#', $page->getContent(), $m);
        $this->signIn($this->owner)->get(route('products.history.before', [$this->product, $m[1]]))->assertOk()->assertSeeText(__('history.newest'));
    }

    public function test_the_csv_export_is_for_owners_and_admins_and_is_logged(): void
    {
        Auth::login($this->owner);
        RiskEntry::create(['product_version_id' => $this->version->id, 'threat' => '=Stolen key', 'position' => 1]);
        Auth::logout();

        $this->filtered(['kind' => 'risks'])->assertOk()->assertSee('href="'.route('products.history.csv', $this->product).'"', false);
        $response = $this->get(route('products.history.csv', $this->product));
        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Date,Person,E-mail,Version,Area,Event,Details', $csv);
        $this->assertStringContainsString('Added the risk “=Stolen key”', $csv);
        $this->assertStringContainsString($this->owner->email, $csv);

        $log = Activity::query()->where('log_name', 'history')->sole();
        $this->assertSame('exported', $log->description);
        $this->assertSame($this->owner->id, (int) $log->causer_id);
        $this->assertSame($this->product->id, $log->getAttribute('product_id'));
        $this->assertSame(['kind' => 'risks'], $log->getProperty('filters'));

        $this->filtered(['reset' => '1'])->assertSeeText('Downloaded the history (CSV)');

        // Staff on support access read the page but do not export it.
        $this->signIn($this->staff())->get(route('products.history.csv', $this->product))->assertNotFound();
    }

    // ---- cetome support ----------------------------------------------------------------------------

    public function test_support_access_and_staff_plan_changes_are_shown_to_the_customer(): void
    {
        $staff = $this->staff();
        $this->signIn($staff)->get(route('team.index', $this->product))->assertOk();
        $this->signIn($staff)->get($this->url())->assertOk()->assertSee(__('common.support_access'));

        $organisation = $this->product->organisation;
        $this->signIn($staff, 'passkey')->put(route('admin.organisations.plan', $organisation), ['plan' => 'enterprise'])->assertRedirect();

        $page = $this->signIn($this->owner)->get($this->url())->assertOk();
        $page->assertSeeText('cetome support')->assertSeeText('Viewed Smart lock (read-only support access)')->assertDontSeeText('Sam Staff');

        $org = $this->signIn($this->owner)->get(route('organisations.history', $organisation))->assertOk();
        $org->assertSeeText('Viewed Smart lock (read-only support access)')
            ->assertSeeText('Changed the plan: Free plan → Enterprise plan')
            ->assertDontSeeText('Sam Staff');
        // The organisation's own entry of the same change is not shown twice.
        $this->assertSame(1, substr_count($org->getContent(), 'Enterprise plan'));
    }

    // ---- Tenants -----------------------------------------------------------------------------------

    public function test_another_organisations_events_never_leak(): void
    {
        $rival = User::factory()->create(['name' => 'Rita Rival']);
        $rivalOrg = Organisation::create(['name' => 'Rival Ltd', 'created_by' => $rival->id]);
        $rivalOrg->members()->attach($rival->id, ['role' => 'admin']);
        $rivalProduct = Product::create(['organisation_id' => $rivalOrg->id, 'owner_id' => $rival->id, 'name' => 'Rival camera']);
        $rivalVersion = app(VersionFactory::class)->create($rivalProduct, '1.0', $rival);
        Auth::login($rival);
        RiskEntry::create(['product_version_id' => $rivalVersion->id, 'threat' => 'Secret rival threat', 'position' => 1]);
        $rivalOrg->update(['legal_name' => 'Rival Legal SA']);
        Auth::logout();

        $organisation = $this->product->organisation;
        foreach ([
            $this->signIn($this->owner)->get($this->url()),
            $this->filtered(['person' => (string) $rival->id]),
            $this->signIn($this->owner)->get(route('organisations.history', $organisation)),
            $this->filtered(['products' => 'all'], $organisation),
            $this->filtered(['products' => $rivalProduct->id], $organisation),
        ] as $page) {
            $page->assertOk()
                ->assertDontSeeText('Rival camera')->assertDontSeeText('Secret rival threat')
                ->assertDontSeeText('Rival Legal SA')->assertDontSeeText('Rita Rival');
        }
        $this->signIn($this->owner)->get(route('products.history', $rivalProduct))->assertNotFound();
        $this->signIn($this->owner)->get(route('organisations.history', $rivalOrg))->assertNotFound();

        $this->filtered(['products' => 'all'], $organisation)->assertOk();
        $csv = $this->get(route('organisations.history.csv', $organisation))->streamedContent();
        $this->assertStringNotContainsString('Rival', $csv);
    }

    public function test_the_organisation_history_lists_its_own_events_and_all_products_on_request(): void
    {
        $organisation = $this->product->organisation;
        Auth::login($this->owner);
        $organisation->update(['legal_name' => 'ACME Devices SAS']);
        RiskEntry::create(['product_version_id' => $this->version->id, 'threat' => 'Stolen key', 'position' => 1]);
        Auth::logout();

        $this->signIn($this->owner)->get(route('organisations.history', $organisation))->assertOk()
            ->assertSeeText('Edited the legal details')
            ->assertSeeText('Created the product Smart lock')
            ->assertDontSeeText('Stolen key');
        $this->filtered(['products' => 'all'], $organisation)->assertOk()
            ->assertSeeText('Stolen key');
    }

    public function test_team_changes_deletions_and_french_wording(): void
    {
        $contributor = $this->member($this->product, ProductRole::Contributor, internal: true);
        $contributor->update(['name' => 'Lena Martin']);
        $membership = $this->product->members()->where('user_id', $contributor->id)->sole();
        $this->signIn($this->owner)->patch(route('team.update', [$this->product, $membership]), ['role' => 'reviewer'])->assertRedirect();
        $this->signIn($this->owner)->delete(route('team.destroy', [$this->product, $membership]))->assertRedirect();
        $spare = Product::create(['organisation_id' => $this->product->organisation_id, 'owner_id' => $this->owner->id, 'name' => 'Old hub']);
        $this->signIn($this->owner)->delete(route('products.destroy', $spare))->assertRedirect();

        $this->signIn($this->owner)->get($this->url())->assertOk()
            ->assertSeeText('Changed the role of Lena Martin: Contributor → Reviewer')
            ->assertSeeText('Removed Lena Martin from the team');
        $this->signIn($this->owner)->get(route('organisations.history', $this->product->organisation))->assertOk()
            ->assertSeeText('Deleted the product Old hub');

        $this->owner->update(['locale' => 'fr']);
        $this->signIn($this->owner)->get($this->url())->assertOk()
            ->assertSeeText('Historique')
            ->assertSeeText('A retiré Lena Martin de l’équipe')
            ->assertSeeText('Les entrées sont conservées 2 ans.');
    }
}
