<?php

namespace Database\Seeders;

use App\Assessment\ScopingForm;
use App\Billing\Plan;
use App\Enums\VersionStatus;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Support\Facades\App;
use RuntimeException;

/**
 * LOCAL USE ONLY: the invented product behind the home page tour pictures (public/images/tour,
 * see the README there). An account of its own (tour-demo@example.com, a reserved domain) with
 * "Smart door lock (demo)" filled like the DemoSeeder products. No real names anywhere.
 *
 *   php artisan db:seed --class=TourDemoSeeder
 *
 * Re-running replaces the demo product (same content, the random draws are seeded).
 */
class TourDemoSeeder extends DemoSeeder
{
    public const EMAIL = 'tour-demo@example.com';

    public const PRODUCT = 'Smart door lock (demo)';

    public function run(): void
    {
        if (! App::environment('local', 'testing')) {
            throw new RuntimeException('TourDemoSeeder is for local use only.');
        }

        $user = User::query()->where('email', self::EMAIL)->first() ?? User::forceCreate([
            'name' => 'Alex Demo',
            'email' => self::EMAIL,
            'email_verified_at' => now(),
            'locale' => 'en',
            'timezone' => 'Europe/Paris',
        ]);
        $organisation = $user->organisations()->first() ?? tap(Organisation::create(['name' => 'Emotec', 'created_by' => $user->id]),
            fn (Organisation $o) => $o->members()->attach($user->id, ['role' => 'admin']));

        Product::query()->where('owner_id', $user->id)->where('name', self::PRODUCT)->get()
            ->each(fn (Product $p) => $p->versions()->each(fn (ProductVersion $v) => $v->delete()) && $p->forceDelete());

        mt_srand(18031);
        $this->product($user, $organisation, self::PRODUCT, 'DL-100', 'A battery-powered door lock opened with a keypad, a phone app over Bluetooth LE, or remotely through the cloud over Wi-Fi.', [
            ['1.0', VersionStatus::Draft, 0.8],
        ]);

        // The tour shows what a paying customer sees: AI on (an enterprise demo organisation), and the
        // Conformity restrictions pre-filled from the scoping and the decision trees, not answered at random.
        $organisation->forceFill(['plan' => Plan::ENTERPRISE])->save();
        $conformity = array_keys(app(ScopingForm::class)->questions(ScopingForm::CONFORMITY));
        Product::query()->where('owner_id', $user->id)->where('name', self::PRODUCT)->get()
            ->each(fn (Product $p) => $p->versions()->each(fn (ProductVersion $v) => $v->scopingAnswers()->whereIn('question', $conformity)->delete()));
    }
}
