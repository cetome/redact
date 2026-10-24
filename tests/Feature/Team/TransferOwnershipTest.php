<?php

namespace Tests\Feature\Team;

use App\Billing\Plan;
use App\Enums\ProductRole;
use App\Mail\ProductOwnershipTransferred;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\ProductMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * Transfer ownership (App\Actions\TransferOwnership): product Team page for the owner and the
 * organisation's admins, to another member of the organisation; the previous owner stays or leaves.
 */
class TransferOwnershipTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    private User $admin;

    private Organisation $organisation;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        config(['redact.plans.free.team_members' => 1]);

        $this->admin = $this->owner();
        $this->organisation = $this->admin->organisations()->first();
        $this->product = $this->product($this->admin)->product;
    }

    private function orgMember(string $name, string $role = 'member'): User
    {
        $user = User::factory()->create(['name' => $name]);
        $this->organisation->members()->attach($user->id, ['role' => $role]);

        return $user;
    }

    public function test_the_owner_hands_the_product_over_and_stays_as_contributor(): void
    {
        $bob = $this->orgMember('Bob Builder');
        $this->product->members()->create(['user_id' => $bob->id, 'role' => ProductRole::Reviewer]);
        $slot = $this->product->plan_slot;

        $this->signIn($this->admin)->get(route('team.transfer.show', $this->product))->assertOk()
            ->assertSee(__('transfer.button'))->assertSee('id="transfer-form"', false)->assertSee('Bob Builder');

        $this->post(route('team.transfer', $this->product), ['owner_id' => $bob->id, 'previous' => 'contributor'])
            ->assertRedirect(route('team.index', $this->product))->assertSessionHas('status', __('transfer.done', ['product' => 'Smart lock', 'name' => 'Bob Builder']));

        $product = $this->product->fresh();
        $this->assertSame($bob->id, $product->owner_id);
        $this->assertSame(ProductRole::Owner, $product->roleOf($bob));
        $this->assertSame(ProductRole::Contributor, $product->roleOf($this->admin));
        $this->assertSame(0, $product->members()->where('user_id', $bob->id)->count());
        $this->assertSame($slot, $product->plan_slot);
        Mail::assertQueued(ProductOwnershipTransferred::class, fn ($mail) => $mail->hasTo($bob->email));

        // The history says "Handed Smart lock over to Bob Builder".
        $this->assertTrue(Activity::query()->where('log_name', 'product')->where('attribute_changes', 'like', '%owner_id%')->exists());
        $this->get(route('products.history', $product))->assertOk()->assertSeeText('Handed Smart lock over to Bob Builder');
    }

    public function test_an_organisation_admin_transfers_and_the_previous_owner_may_leave(): void
    {
        $carol = $this->orgMember('Carol');
        $this->product->update(['owner_id' => $carol->id]);
        $bob = $this->orgMember('Bob Builder');

        $this->signIn($this->admin)->post(route('team.transfer', $this->product), ['owner_id' => $bob->id, 'previous' => 'leave'])->assertSessionMissing('error');
        $this->assertSame($bob->id, $this->product->fresh()->owner_id);
        $this->assertNull($this->product->fresh()->roleOf($carol));
    }

    public function test_only_members_of_the_organisation_can_receive_it_and_only_managers_transfer(): void
    {
        $bob = $this->orgMember('Bob Builder');
        $outsider = $this->member($this->product, ProductRole::Reviewer); // external on the team
        $contributor = $this->member($this->product, ProductRole::Contributor, internal: true);

        $this->signIn($this->admin)->post(route('team.transfer', $this->product), ['owner_id' => $outsider->id, 'previous' => 'contributor'])
            ->assertSessionHas('error');
        $this->assertSame($this->admin->id, $this->product->fresh()->owner_id);

        $this->signIn($contributor)->post(route('team.transfer', $this->product), ['owner_id' => $bob->id, 'previous' => 'leave'])->assertNotFound();
        $this->signIn($contributor)->get(route('team.index', $this->product))->assertOk()->assertDontSee('id="transfer-form"', false);
        $this->assertSame($this->admin->id, $this->product->fresh()->owner_id);
        Mail::assertNothingQueued();
    }

    public function test_the_free_product_keeps_its_team_limit(): void
    {
        $this->product->forceFill(['plan_slot' => Plan::SLOT_FREE])->save();
        $bob = $this->orgMember('Bob Builder');
        $carol = $this->orgMember('Carol');
        $this->product->members()->create(['user_id' => $carol->id, 'role' => ProductRole::Reviewer]);

        // Bob is not on the team: the previous owner would take a second seat.
        $this->signIn($this->admin)->post(route('team.transfer', $this->product), ['owner_id' => $bob->id, 'previous' => 'contributor'])
            ->assertSessionHas('error');
        $this->assertSame($this->admin->id, $this->product->fresh()->owner_id);

        // Carol takes it over: her seat becomes the previous owner's.
        $this->post(route('team.transfer', $this->product), ['owner_id' => $carol->id, 'previous' => 'reviewer'])->assertSessionMissing('error');
        $this->assertSame($carol->id, $this->product->fresh()->owner_id);
        $this->assertSame(ProductRole::Reviewer, $this->product->fresh()->roleOf($this->admin));
    }

    public function test_the_organisation_team_dialog_links_owned_products_to_the_transfer(): void
    {
        $this->orgMember('Bob Builder');
        $this->signIn($this->admin)->get(route('organisations.team.person', [$this->organisation, $this->admin->id]))->assertOk()
            ->assertSee(route('team.transfer.show', $this->product), false);
    }

    public function test_the_new_owner_e_mail_waits_for_the_whole_change_to_be_saved(): void
    {
        $bob = $this->orgMember('Bob Builder', 'admin');
        $carol = $this->orgMember('Carol');
        $this->product->update(['owner_id' => $bob->id]);
        $other = Product::create(['organisation_id' => $this->organisation->id, 'owner_id' => $this->admin->id, 'name' => 'Camera']);
        $other->members()->create(['user_id' => $bob->id, 'role' => ProductRole::Reviewer]);

        // The removal fails after the hand-over (removing Bob from Camera breaks): nothing is kept, no e-mail.
        ProductMember::deleting(function (ProductMember $member) use ($bob) {
            if ($member->user_id === $bob->id) {
                throw new \RuntimeException('database gone');
            }
        });
        $this->signIn($this->admin)->delete(route('organisations.team.destroy', [$this->organisation, $bob]), ['new_owner' => [$this->product->id => $carol->id]])
            ->assertServerError();
        $this->assertSame($bob->id, $this->product->fresh()->owner_id);
        $this->assertTrue($this->organisation->hasMember($bob));
        Mail::assertNothingQueued();

        // A removal that succeeds sends it once saved.
        ProductMember::flushEventListeners();
        $this->delete(route('organisations.team.destroy', [$this->organisation, $bob]), ['new_owner' => [$this->product->id => $carol->id]])
            ->assertSessionMissing('error');
        $this->assertSame($carol->id, $this->product->fresh()->owner_id);
        Mail::assertQueued(ProductOwnershipTransferred::class, fn ($mail) => $mail->hasTo($carol->email));
    }
}
