<?php

namespace Tests\Feature\Team;

use App\Billing\Plan;
use App\Enums\ProductRole;
use App\Mail\OrganisationInvitation;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\BuildsProducts;
use Tests\Feature\Guest\GuestHelpers;
use Tests\TestCase;

/**
 * The organisation Team page (OrganisationTeamController): admins invite with products, manage
 * organisation roles and products, remove people; members read; outsiders and guests never see it.
 */
class OrganisationTeamTest extends TestCase
{
    use BuildsProducts, GuestHelpers, RefreshDatabase;

    private User $admin;

    private Organisation $organisation;

    private Product $lock;

    private Product $camera;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        config(['redact.plans.free.team_members' => 1]);

        $this->admin = $this->owner();
        $this->admin->update(['name' => 'Alice Admin']);
        $this->organisation = $this->admin->organisations()->first();
        $this->lock = $this->product($this->admin)->product;
        $this->camera = Product::create(['organisation_id' => $this->organisation->id, 'owner_id' => $this->admin->id, 'name' => 'Camera']);
    }

    private function orgMember(string $name, string $role = 'member'): User
    {
        $user = User::factory()->create(['name' => $name]);
        $this->organisation->members()->attach($user->id, ['role' => $role]);

        return $user;
    }

    private function url(): string
    {
        return route('organisations.team', $this->organisation);
    }

    /** Invite through the page and return the e-mailed token. */
    private function inviteAndCatchToken(string $email, array $products = [], string $role = 'member'): string
    {
        $this->signIn($this->admin)->post(route('organisations.team.invite', $this->organisation), [
            'invite_email' => $email,
            'organisation_role' => $role,
            'products' => $products,
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');

        $token = null;
        Mail::assertQueued(OrganisationInvitation::class, function (OrganisationInvitation $mail) use (&$token, $email) {
            $token = $mail->token;

            return $mail->hasTo($email);
        });

        return (string) $token;
    }

    public function test_the_account_menu_leads_to_the_organisation_team(): void
    {
        $this->signIn($this->admin)->get(route('dashboard'))->assertOk()->assertSee(route('organisations.team.mine'), false);
        $this->get(route('organisations.team.mine'))->assertRedirect($this->url());
    }

    public function test_the_page_lists_everyone_with_their_products_and_the_pending_invitations(): void
    {
        $bob = $this->orgMember('Bob Builder');
        $this->lock->members()->create(['user_id' => $bob->id, 'role' => ProductRole::Reviewer]);
        $lab = $this->member($this->camera, ProductRole::Contributor); // outsider on one product
        $this->inviteAndCatchToken('new@example.test', [$this->lock->id => 'contributor']);

        $this->get($this->url())->assertOk()
            ->assertSeeInOrder(['Alice Admin', __('orgteam.role.admin'), 'Camera', __('roles.owner.label'), 'Smart lock', __('roles.owner.label')])
            ->assertSeeInOrder(['Bob Builder', __('orgteam.role.member'), 'Smart lock', __('roles.reviewer.label')])
            ->assertSeeInOrder([$lab->name, __('orgteam.role.external'), 'Camera', __('roles.contributor.label')])
            ->assertSee(route('team.index', $this->lock), false)
            ->assertSee(__('orgteam.pending'))
            ->assertSeeInOrder(['new@example.test', __('orgteam.role.member'), 'Smart lock', __('roles.contributor.label')])
            ->assertSee(__('orgteam.invite'));
    }

    public function test_an_invitation_with_products_is_one_e_mail_and_accepting_joins_the_organisation_and_the_products(): void
    {
        $token = $this->inviteAndCatchToken('new@example.test', [$this->lock->id => 'reviewer', $this->camera->id => 'contributor'], 'admin');
        Mail::assertQueuedCount(1);

        $parent = Invitation::query()->whereNull('product_id')->firstOrFail();
        $this->assertSame($this->organisation->id, $parent->organisation_id);
        $this->assertSame('admin', $parent->organisation_role);
        $this->assertCount(2, $parent->children);
        // The children are pending seats on their products, without a token of their own.
        $this->assertNull($parent->children->first()->token_hash);
        $this->assertSame(1, Plan::teamCount($this->lock));

        $invitee = User::factory()->create(['email' => 'new@example.test']);
        $other = User::factory()->create();
        $this->signIn($other)->post(route('invitations.accept', $token))->assertNotFound();

        $this->signIn($invitee)->get(route('invitations.show', $token))->assertOk()
            ->assertSee(__('orgteam.accept.button', ['organisation' => 'ACME']))->assertSee('Smart lock')->assertSee('Camera');
        $this->post(route('invitations.accept', $token))->assertRedirect($this->url());

        $this->assertTrue($this->organisation->isAdmin($invitee));
        $this->assertSame(ProductRole::Reviewer, $this->lock->roleOf($invitee));
        $this->assertSame(ProductRole::Contributor, $this->camera->roleOf($invitee));
        $this->assertFalse($this->lock->members()->where('user_id', $invitee->id)->first()->external);
        $this->assertTrue(Activity::query()->where('log_name', 'team')->where('event', 'joined')->exists());

        // Used once.
        $this->post(route('invitations.accept', $token))->assertNotFound();
    }

    public function test_inviting_someone_already_in_the_organisation_gives_the_products_at_once(): void
    {
        $bob = $this->orgMember('Bob Builder');
        $this->signIn($this->admin)->post(route('organisations.team.invite', $this->organisation), [
            'invite_email' => strtoupper($bob->email), 'organisation_role' => 'member', 'products' => [$this->camera->id => 'reviewer'],
        ])->assertSessionHas('status', __('orgteam.already_member', ['name' => 'Bob Builder']));

        Mail::assertNothingQueued();
        $this->assertSame(ProductRole::Reviewer, $this->camera->roleOf($bob));
    }

    public function test_an_admin_puts_a_member_on_several_products_at_once_and_changes_or_removes_roles(): void
    {
        $bob = $this->orgMember('Bob Builder');
        $this->signIn($this->admin)->get(route('organisations.team.person', [$this->organisation, $bob->id]))->assertOk()
            ->assertSee('id="person-form"', false)->assertSee(__('orgteam.not_on'));

        $this->patch(route('organisations.team.update', [$this->organisation, $bob]), [
            'organisation_role' => 'member',
            'products' => [$this->lock->id => 'contributor', $this->camera->id => 'reviewer'],
        ])->assertRedirect($this->url())->assertSessionMissing('error');
        $this->assertSame(ProductRole::Contributor, $this->lock->roleOf($bob));
        $this->assertSame(ProductRole::Reviewer, $this->camera->roleOf($bob));
        // ProductMember logs itself in the 'team' log.
        $this->assertSame(2, Activity::query()->where('log_name', 'team')->where('event', 'created')->count());

        $this->patch(route('organisations.team.update', [$this->organisation, $bob]), [
            'products' => [$this->lock->id => 'reviewer', $this->camera->id => ''],
        ]);
        $this->assertSame(ProductRole::Reviewer, $this->lock->roleOf($bob));
        $this->assertNull($this->camera->roleOf($bob));
    }

    public function test_organisation_roles_change_but_never_leave_the_organisation_without_an_admin(): void
    {
        $bob = $this->orgMember('Bob Builder');
        $this->signIn($this->admin);

        // The only admin cannot step down, nor be removed.
        $this->patch(route('organisations.team.update', [$this->organisation, $this->admin]), ['organisation_role' => 'member'])
            ->assertSessionHas('error', __('orgteam.last_admin', ['name' => 'Alice Admin']));
        $this->assertTrue($this->organisation->isAdmin($this->admin));
        $this->delete(route('organisations.team.destroy', [$this->organisation, $this->admin]))->assertSessionHas('error');
        $this->assertTrue($this->organisation->hasMember($this->admin));

        $this->patch(route('organisations.team.update', [$this->organisation, $bob]), ['organisation_role' => 'admin'])->assertSessionMissing('error');
        $this->assertTrue($this->organisation->isAdmin($bob));
        $this->assertTrue(Activity::query()->where('log_name', 'team')->where('event', 'role_changed')->exists());

        // Two admins now: Alice may become a member.
        $this->patch(route('organisations.team.update', [$this->organisation, $this->admin]), ['organisation_role' => 'member'])->assertSessionMissing('error');
        $this->assertFalse($this->organisation->isAdmin($this->admin));
    }

    public function test_removing_someone_from_the_organisation_removes_them_from_every_product(): void
    {
        $bob = $this->orgMember('Bob Builder');
        $this->lock->members()->create(['user_id' => $bob->id, 'role' => ProductRole::Contributor]);
        $this->camera->members()->create(['user_id' => $bob->id, 'role' => ProductRole::Reviewer]);
        $elsewhere = $this->product($this->owner())->product; // another organisation keeps him
        $elsewhere->members()->create(['user_id' => $bob->id, 'role' => ProductRole::Reviewer, 'external' => true]);

        $this->signIn($this->admin)->delete(route('organisations.team.destroy', [$this->organisation, $bob]))
            ->assertRedirect($this->url())->assertSessionHas('status', __('orgteam.removed', ['name' => 'Bob Builder']));

        $this->assertFalse($this->organisation->hasMember($bob));
        $this->assertNull($this->lock->roleOf($bob));
        $this->assertNull($this->camera->roleOf($bob));
        $this->assertSame(ProductRole::Reviewer, $elsewhere->roleOf($bob));
        $this->assertTrue(Activity::query()->where('log_name', 'team')->where('event', 'removed')->exists());
    }

    public function test_the_owner_of_a_product_is_removed_only_once_a_new_owner_is_chosen(): void
    {
        $bob = $this->orgMember('Bob Builder', 'admin');
        $carol = $this->orgMember('Carol');
        $this->camera->update(['owner_id' => $bob->id]);
        $this->camera->members()->create(['user_id' => $carol->id, 'role' => ProductRole::Contributor]);

        $this->signIn($this->admin)->get(route('organisations.team.person', [$this->organisation, $bob->id]))->assertOk()
            ->assertSee(__('orgteam.new_owner', ['product' => 'Camera']));

        $this->delete(route('organisations.team.destroy', [$this->organisation, $bob]))
            ->assertSessionHas('error', trans_choice('orgteam.needs_owner', 1, ['name' => 'Bob Builder', 'products' => 'Camera']));
        $this->assertTrue($this->organisation->hasMember($bob));

        // Someone outside the organisation cannot be made the owner.
        $outsider = User::factory()->create();
        $this->delete(route('organisations.team.destroy', [$this->organisation, $bob]), ['new_owner' => [$this->camera->id => $outsider->id]])
            ->assertSessionHas('error');

        $this->delete(route('organisations.team.destroy', [$this->organisation, $bob]), ['new_owner' => [$this->camera->id => $carol->id]])
            ->assertSessionMissing('error');
        $this->assertFalse($this->organisation->hasMember($bob));
        $this->assertSame($carol->id, $this->camera->fresh()->owner_id);
        $this->assertSame(ProductRole::Owner, $this->camera->fresh()->roleOf($carol));
        $this->assertSame(0, $this->camera->members()->where('user_id', $carol->id)->count());
    }

    public function test_the_free_product_team_limit_applies_on_the_organisation_page(): void
    {
        $this->lock->forceFill(['plan_slot' => Plan::SLOT_FREE])->save();
        $bob = $this->orgMember('Bob Builder');
        $carol = $this->orgMember('Carol');

        $this->signIn($this->admin)->patch(route('organisations.team.update', [$this->organisation, $bob]), ['products' => [$this->lock->id => 'reviewer']])
            ->assertSessionMissing('error');

        // The free product holds its owner and one member: nothing changes, the product is named.
        $refusal = __('orgteam.refused', ['products' => 'Smart lock', 'reason' => trans_choice('plans.refusal.team', 1, ['count' => 1])]);
        $this->patch(route('organisations.team.update', [$this->organisation, $carol]), ['products' => [$this->lock->id => 'contributor', $this->camera->id => 'reviewer']])
            ->assertSessionHas('error', $refusal);
        $this->assertNull($this->lock->roleOf($carol));
        $this->assertNull($this->camera->roleOf($carol));

        $this->post(route('organisations.team.invite', $this->organisation), [
            'invite_email' => 'new@example.test', 'organisation_role' => 'member', 'products' => [$this->lock->id => 'reviewer'],
        ])->assertSessionHas('error', $refusal);
        Mail::assertNothingQueued();
        $this->assertSame(0, Invitation::query()->count());

        // Bob is already counted: changing his role is never refused. The page says the team is full.
        $this->patch(route('organisations.team.update', [$this->organisation, $bob]), ['products' => [$this->lock->id => 'contributor']])->assertSessionMissing('error');
        $this->get(route('organisations.team.invite.show', $this->organisation))->assertOk()->assertSee(__('orgteam.team_full'));
    }

    public function test_members_read_the_page_but_change_nothing(): void
    {
        $bob = $this->orgMember('Bob Builder');
        $this->lock->members()->create(['user_id' => $bob->id, 'role' => ProductRole::Contributor]);
        $carol = $this->orgMember('Carol');

        $this->signIn($bob)->get($this->url())->assertOk()
            ->assertSee('Alice Admin')->assertSee('Carol')
            ->assertDontSee('data-dialog-open="invite"', false)->assertDontSee('data-dialog-fetch="person"', false)->assertDontSee('id="invite-form"', false)
            // Product chips link only where the reader has access.
            ->assertSee(route('team.index', $this->lock), false)->assertDontSee(route('team.index', $this->camera), false);

        $this->post(route('organisations.team.invite', $this->organisation), ['invite_email' => 'x@example.test', 'organisation_role' => 'admin'])->assertForbidden();
        $this->patch(route('organisations.team.update', [$this->organisation, $bob]), ['organisation_role' => 'admin'])->assertForbidden();
        $this->delete(route('organisations.team.destroy', [$this->organisation, $carol]))->assertForbidden();
        $this->assertFalse($this->organisation->isAdmin($bob));
        $this->assertTrue($this->organisation->hasMember($carol));
        Mail::assertNothingQueued();
    }

    public function test_other_organisations_never_see_or_touch_its_people(): void
    {
        $bob = $this->orgMember('Bob Builder');
        $stranger = $this->owner();
        $stranger->update(['name' => 'Stranger']);
        $theirs = $stranger->organisations()->first();

        $this->signIn($stranger)->get($this->url())->assertNotFound();
        $this->post(route('organisations.team.invite', $this->organisation), ['invite_email' => 'x@example.test', 'organisation_role' => 'admin'])->assertNotFound();
        $this->delete(route('organisations.team.destroy', [$this->organisation, $bob]))->assertNotFound();

        // Their own page lists their people only, and they cannot manage ours through it.
        $this->get(route('organisations.team', $theirs))->assertOk()->assertSee('Stranger')->assertDontSee('Bob Builder')->assertDontSee('Alice Admin');
        $this->patch(route('organisations.team.update', [$theirs, $bob]), ['organisation_role' => 'admin'])->assertNotFound();
        $this->delete(route('organisations.team.destroy', [$theirs, $bob]))->assertNotFound();
        $this->assertTrue($this->organisation->hasMember($bob));

        // An invitation of ours cannot be revoked from their page.
        $this->inviteAndCatchToken('new@example.test');
        $invitation = Invitation::query()->firstOrFail();
        $this->signIn($stranger)->delete(route('organisations.team.revoke', [$theirs, $invitation]))->assertNotFound();
        $this->assertNotNull($invitation->fresh());
    }

    public function test_admins_revoke_pending_invitations_with_their_products(): void
    {
        $this->inviteAndCatchToken('new@example.test', [$this->lock->id => 'reviewer']);
        $invitation = Invitation::query()->whereNull('product_id')->firstOrFail();

        $this->delete(route('organisations.team.revoke', [$this->organisation, $invitation]))->assertSessionHas('status', __('team.invitation_revoked'));
        $this->assertSame(0, Invitation::query()->count());
        $this->assertSame(0, Plan::teamCount($this->lock));
        $this->assertTrue(Activity::query()->where('log_name', 'team')->where('event', 'invitation_revoked')->exists());
    }

    public function test_the_product_team_page_leads_admins_to_the_organisation_team(): void
    {
        $label = __('orgteam.manage_all', ['organisation' => 'ACME']);
        $this->signIn($this->admin)->get(route('team.index', $this->lock))->assertOk()->assertSee($label)->assertSee($this->url(), false);

        $bob = $this->orgMember('Bob Builder');
        $this->lock->members()->create(['user_id' => $bob->id, 'role' => ProductRole::Contributor]);
        $this->signIn($bob)->get(route('team.index', $this->lock))->assertOk()->assertDontSee($label);
    }

    public function test_guests_never_reach_the_organisation_team(): void
    {
        $this->setUpGuest();
        $guest = $this->startSandbox();
        $sandbox = $guest->organisations()->firstOrFail();

        $this->get(route('dashboard'))->assertOk()->assertDontSee(route('organisations.team.mine'), false);
        $this->get(route('organisations.team.mine'))->assertRedirect(route('guest.claim'));
        $this->get(route('organisations.team', $sandbox))->assertRedirect(route('guest.claim'));
        $this->post(route('organisations.team.invite', $sandbox), ['invite_email' => 'x@example.test', 'organisation_role' => 'admin'])->assertNotFound();
        Mail::assertNothingQueued();
    }
}
