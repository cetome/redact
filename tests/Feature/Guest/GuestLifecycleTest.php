<?php

namespace Tests\Feature\Guest;

use App\Assessment\VersionFactory;
use App\Auth\SafeMail;
use App\Mail\Auth\VerificationCodeMail;
use App\Models\Document;
use App\Models\LoginToken;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\RequestLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * Sliding expiry, the purge, and the rule that nothing is ever mailed to a guest.
 */
class GuestLifecycleTest extends TestCase
{
    use BuildsProducts, GuestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGuest();
    }

    public function test_expiry_slides_with_activity_at_most_every_ten_minutes(): void
    {
        $guest = $this->startSandbox();

        // Recent enough: no write.
        $recent = now()->addHours(24)->subMinutes(5);
        DB::table('users')->where('id', $guest->id)->update(['guest_expires_at' => $recent]);
        Auth::guard('web')->setUser($guest->fresh());
        $this->get(route('dashboard'))->assertOk();
        $this->assertEqualsWithDelta($recent->timestamp, $guest->fresh()->guest_expires_at->timestamp, 1);

        // Older than ten minutes: pushed to now + 24 h.
        DB::table('users')->where('id', $guest->id)->update(['guest_expires_at' => now()->addHour()]);
        Auth::guard('web')->setUser($guest->fresh());
        $this->get(route('dashboard'))->assertOk();
        $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, $guest->fresh()->guest_expires_at->timestamp, 5);
    }

    public function test_an_expired_sandbox_is_signed_out(): void
    {
        $guest = $this->startSandbox();
        DB::table('users')->where('id', $guest->id)->update(['guest_expires_at' => now()->subMinute()]);
        Auth::guard('web')->setUser($guest->fresh());

        $this->get(route('dashboard'))->assertRedirect(route('guest.start'))->assertSessionHas('status', __('guest.expired'));
        $this->assertGuest();
    }

    public function test_the_purge_deletes_everything_of_expired_guests_and_nothing_else(): void
    {
        Storage::fake('local');

        // An expired guest with a product, a soft-deleted product, a stored file, a token and page views.
        $expired = $this->startSandbox();
        $version = $this->guestProduct($expired);
        $version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-1', 'name' => 'Key']);
        $this->get(route('versions.show', $version))->assertOk();
        app()->terminate();
        $trashed = Product::create(['organisation_id' => $version->product->organisation_id, 'owner_id' => $expired->id, 'name' => 'Old']);
        app(VersionFactory::class)->create($trashed, '0.1', $expired);
        $trashed->delete();
        Storage::disk('local')->put('documents/'.$version->id.'/file.txt', 'x');
        Document::forceCreate([
            'product_version_id' => $version->id, 'uploaded_by' => $expired->id, 'original_name' => 'file.txt',
            'path' => 'documents/'.$version->id.'/file.txt', 'mime' => 'text/plain', 'size' => 1, 'sha256' => str_repeat('0', 64),
        ]);
        $expired->loginTokens()->create([
            'purpose' => 'guest_claim', 'token_hash' => str_repeat('a', 64), 'code_hash' => str_repeat('b', 64),
            'bind_hash' => str_repeat('c', 64), 'expires_at' => now()->addMinutes(5),
        ]);
        $workspace = $expired->organisations()->firstOrFail();
        DB::table('users')->where('id', $expired->id)->update(['guest_expires_at' => now()->subMinute()]);
        Auth::guard('web')->logout();

        // A live guest and a real account with its own product.
        $live = $this->startSandbox();
        $this->guestProduct($live);
        Auth::guard('web')->logout();
        $owner = $this->owner();
        $kept = $this->product($owner);

        $this->artisan('redact:purge-guests')->assertSuccessful();

        $this->assertNull(User::find($expired->id));
        $this->assertSame(0, Product::withTrashed()->where('owner_id', $expired->id)->count());
        $this->assertNull(ProductVersion::find($version->id));
        $this->assertSame(0, Document::count());
        $this->assertFalse(Storage::disk('local')->exists('documents/'.$version->id.'/file.txt'));
        $this->assertNull(Organisation::find($workspace->id));
        $this->assertSame(0, LoginToken::where('user_id', $expired->id)->count());
        $this->assertSame(0, DB::table('inventory_items')->where('product_version_id', $version->id)->count());
        $this->assertSame(0, RequestLog::where('user_id', $expired->id)->count());
        $this->assertGreaterThan(0, RequestLog::whereNull('user_id')->where('guest', true)->count());

        $this->assertNotNull(User::find($live->id));
        $this->assertSame(1, Product::where('owner_id', $live->id)->count());
        $this->assertNotNull(ProductVersion::find($kept->id));
        $this->assertNotNull(User::find($owner->id));

        $entry = Activity::where('log_name', 'guest')->where('description', 'sandbox expired')->sole();
        $this->assertSame(2, $entry->properties['products']);
        $this->assertSame(2, $entry->properties['versions']);
        $this->assertSame(1, $entry->properties['files']);
        $this->assertNull($entry->causer_id);
        $this->assertSame(0, Activity::where('causer_id', $expired->id)->where('causer_type', $expired->getMorphClass())
            ->where('log_name', '!=', 'security')->count());
        // The "started" figure survives, anonymously.
        $this->assertSame(2, Activity::where('log_name', 'guest')->where('description', 'sandbox started')->count());
    }

    public function test_nothing_is_ever_mailed_to_a_reserved_address(): void
    {
        config(['mail.default' => 'array']);
        $guest = $this->startSandbox();
        $transport = fn () => app('mailer')->getSymfonyTransport();

        Mail::to($guest->email)->send(new VerificationCodeMail('Guest', 'en', '123456', 'confirm'));
        Mail::to($guest)->send(new VerificationCodeMail('Guest', 'en', '123456', 'confirm'));
        SafeMail::send($guest->email, new VerificationCodeMail('Guest', 'en', '123456', 'confirm'));
        $guest->notify(new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return ['mail'];
            }

            public function toMail(object $notifiable): MailMessage
            {
                return (new MailMessage)->line('Hello');
            }
        });
        $this->assertCount(0, $transport()->messages());

        // A mixed list keeps only the real recipients.
        Mail::to(['someone@example.com', $guest->email])->send(new VerificationCodeMail('X', 'en', '123456', 'confirm'));
        $this->assertCount(1, $transport()->messages());
        $sent = $transport()->messages()->first()->getOriginalMessage();
        $this->assertSame(['someone@example.com'], array_map(fn ($a) => $a->getAddress(), $sent->getTo()));
    }
}
