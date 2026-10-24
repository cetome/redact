<?php

namespace Tests\Feature\Guest;

use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\User;
use Tests\Feature\Auth\AuthHelpers;

/**
 * Starts guest sandboxes through the real /try flow (ALTCHA faked: "ok").
 */
trait GuestHelpers
{
    use AuthHelpers;

    protected function setUpGuest(): void
    {
        $this->setUpAuth();
        $this->fakeAltcha();
        // The limit is pinned: a developer's .env may raise REDACT_GUEST_PER_IP_PER_DAY for local runs.
        config(['redact.guest.enabled' => true, 'redact.guest.per_ip_per_day' => 5]);
    }

    /** POST /try as a visitor; the test client is signed in as the new guest afterwards. */
    protected function startSandbox(): User
    {
        $this->post(route('guest.create'), ['altcha' => 'ok'])->assertRedirect(route('dashboard'));

        return User::query()->where('is_guest', true)->latest('id')->firstOrFail();
    }

    protected function guestProduct(User $guest): ProductVersion
    {
        $this->post(route('products.store'), [
            'organisation_id' => $guest->organisations()->firstOrFail()->id,
            'name' => 'Sandbox lock',
            'version_label' => '1.0',
        ])->assertRedirect();

        return Product::query()->where('owner_id', $guest->id)->firstOrFail()->versions()->firstOrFail();
    }
}
