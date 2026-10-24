<?php

namespace Tests\Feature\Auth;

use App\Actions\CreateAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sign-up does not ask for the organisation (owner, 2026-10-23): it is named after the person.
 */
class SignUpWithoutOrganisationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_form_has_no_organisation_field(): void
    {
        $this->get(route('register'))->assertOk()->assertDontSee('name="organisation"', false);
    }

    public function test_the_organisation_is_named_after_the_person_unless_given(): void
    {
        $create = app(CreateAccount::class);

        $ada = $create(['name' => 'Ada Lovelace', 'email' => 'ada@example.test']);
        $this->assertSame('Ada Lovelace', $ada->organisations()->sole()->name);
        $this->assertNull($ada->organisation_name);

        $grace = $create(['name' => 'Grace Hopper', 'email' => 'grace@example.test', 'organisation' => 'Navy Labs']);
        $this->assertSame('Navy Labs', $grace->organisations()->sole()->name);
    }
}
