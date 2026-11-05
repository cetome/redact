<?php

namespace Tests\Feature\Team;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * The organisation card of Settings (logo, name, manufacturer) and a person's picture (Account),
 * App\Http\Controllers\PictureController and OrganisationLegalController.
 */
class PicturesTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    private User $admin;

    private Organisation $organisation;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = $this->owner();
        $this->organisation = $this->admin->organisations()->first();
    }

    public function test_an_admin_sets_the_logo_and_the_name_members_see_the_logo(): void
    {
        $this->signIn($this->admin)->get(route('organisations.settings', $this->organisation))->assertOk()
            ->assertSee(__('organisation.card'))->assertSee(__('organisation.add_logo'))->assertSee('name="legal_name"', false);

        $this->post(route('organisations.logo.store', $this->organisation), ['logo' => UploadedFile::fake()->image('logo.jpg', 1200, 600)])
            ->assertSessionHas('status', __('organisation.logo_saved'));
        $path = $this->organisation->refresh()->logo_path;
        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);
        [$w, $h] = getimagesizefromstring(Storage::disk('local')->get($path));
        $this->assertSame([800, 400], [$w, $h], 'Resized to 800 pixels at most, re-encoded as PNG.');

        $this->put(route('organisations.legal.update', $this->organisation), ['name' => 'Emotec', 'legal_name' => 'Emotec SAS', 'country' => 'France'])
            ->assertRedirect(route('organisations.settings', $this->organisation))->assertSessionHas('status', __('organisation.saved'));
        $this->assertSame(['Emotec', 'Emotec SAS'], [$this->organisation->refresh()->name, $this->organisation->legal_name]);
        $this->put(route('organisations.legal.update', $this->organisation), ['name' => ''])->assertSessionHasErrors('name');

        // A member sees the logo, cannot change it; someone else gets nothing.
        $member = User::factory()->create();
        $this->organisation->members()->attach($member->id, ['role' => 'member']);
        $this->signIn($member)->get(route('organisations.logo', $this->organisation))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->post(route('organisations.logo.store', $this->organisation), ['logo' => UploadedFile::fake()->image('x.png')])->assertNotFound();
        $this->get(route('organisations.settings', $this->organisation))->assertRedirect(route('organisations.team', $this->organisation));
        $this->signIn(User::factory()->create())->get(route('organisations.logo', $this->organisation))->assertNotFound();

        $this->signIn($this->admin)->delete(route('organisations.logo.destroy', $this->organisation))->assertSessionHas('status', __('organisation.logo_removed'));
        $this->assertNull($this->organisation->refresh()->logo_path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_an_svg_logo_is_cleaned_and_kept_as_svg(): void
    {
        $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><script>alert(1)</script>'
            .'<rect width="10" height="10" fill="#0044aa" onclick="alert(2)"/><image href="https://evil.example/x.png"/></svg>';
        $this->signIn($this->admin)->post(route('organisations.logo.store', $this->organisation), ['logo' => UploadedFile::fake()->createWithContent('logo.svg', $svg)])
            ->assertSessionHas('status', __('organisation.logo_saved'));
        $path = (string) $this->organisation->refresh()->logo_path;
        $this->assertStringEndsWith('.svg', $path);
        $stored = Storage::disk('local')->get($path);
        $this->assertStringContainsString('<rect', $stored);
        foreach (['<script', 'onclick', 'evil.example'] as $gone) {
            $this->assertStringNotContainsString($gone, $stored);
        }
        $this->get(route('organisations.logo', $this->organisation))->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml')->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");

        $this->post(route('organisations.logo.store', $this->organisation), ['logo' => UploadedFile::fake()->createWithContent('logo.svg', '<html><body>not an svg</body></html>')])
            ->assertSessionHasErrors('logo');
    }

    public function test_a_file_that_is_not_a_picture_is_refused(): void
    {
        $this->signIn($this->admin)->post(route('organisations.logo.store', $this->organisation), ['logo' => UploadedFile::fake()->create('logo.png', 10, 'image/png')])
            ->assertSessionHasErrors('logo');
        $this->assertNull($this->organisation->refresh()->logo_path);
    }

    public function test_a_person_sets_their_picture_seen_by_the_people_they_work_with(): void
    {
        $this->signIn($this->admin)->get(route('account.show'))->assertOk()->assertSee(__('auth.account.add_picture'))
            ->assertSee('id="picture-picture"', false);
        $this->post(route('account.picture.store'), ['picture' => UploadedFile::fake()->image('me.png', 900, 900)])
            ->assertSessionHas('status', __('auth.account.picture_saved'));
        $this->assertNotNull($this->admin->refresh()->picture_path);
        $this->get(route('dashboard'))->assertOk()->assertSee(route('people.picture', $this->admin), false);

        $colleague = User::factory()->create();
        $this->organisation->members()->attach($colleague->id, ['role' => 'member']);
        $this->signIn($colleague)->get(route('people.picture', $this->admin))->assertOk();
        $this->signIn(User::factory()->create())->get(route('people.picture', $this->admin))->assertNotFound();

        $this->signIn($this->admin)->delete(route('account.picture.destroy'))->assertSessionHas('status', __('auth.account.picture_removed'));
        $this->assertNull($this->admin->refresh()->picture_path);
    }
}
