<?php

namespace Tests\Feature\Guest;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A sandbox guest sees the Declaration & technical file step and fills it in, but the Word
 * declaration, the technical file zip and the certificate need an account ("Keep my work").
 */
class GuestDeclarationTest extends TestCase
{
    use GuestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGuest();
    }

    public function test_guests_see_the_step_but_cannot_download(): void
    {
        $guest = $this->startSandbox();
        $version = $this->guestProduct($guest);

        $this->get(route('versions.declaration', $version))->assertOk()
            ->assertSee('Downloads need an account.')
            ->assertSee(route('guest.claim'), false);
        $this->put(route('versions.declaration-data', $version), ['object' => 'Sandbox lock'])->assertRedirect();

        foreach ([
            route('versions.declaration.download', ['version' => $version, 'format' => 'docx']),
            route('versions.declaration.download.lang', ['version' => $version, 'lang' => 'de', 'format' => 'docx']),
            route('versions.declaration.all', $version),
            route('versions.declaration.download', ['version' => $version, 'format' => 'pdf']),
            route('versions.technical-file.download', $version),
        ] as $url) {
            $this->get($url)->assertRedirect(route('guest.claim'))->assertSessionHas('status', __('guest.account_required'));
        }
        $this->put(route('versions.certificate.update', $version), ['certificate_reference' => 'X'])->assertRedirect(route('guest.claim'));
        $this->getJson(route('versions.technical-file.download', $version))->assertForbidden();
        $this->assertDatabaseMissing('activity_log', ['log_name' => 'report', 'causer_id' => $guest->id]);
    }
}
