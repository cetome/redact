<?php

namespace Tests\Feature\Admin;

use App\Auth\SignIn;
use App\Http\Middleware\EnsureSessionIsValid;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Before;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Shared set-up for the console tests: accounts with platform roles, a
 * signed-in session that EnsureSessionIsValid accepts, and sample data.
 */
trait AdminTestHelpers
{
    #[Before]
    protected function setUpConsole(): void
    {
        $this->afterApplicationCreated(function () {
            // The local .env may have no APP_KEY yet: sessions, CSRF and the IP hash need one.
            if (empty(config('app.key'))) {
                config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
            }
            $this->withoutVite();
        });
    }

    protected function makeUser(array $attributes = [], array $roles = []): User
    {
        $user = User::forceCreate(array_merge([
            'name' => 'Person '.Str::random(4),
            'email' => Str::lower(Str::random(10)).'@example.test',
            'email_verified_at' => now(),
            'active' => true,
            'session_epoch' => 0,
        ], $attributes));

        foreach ($roles as $role) {
            $user->assignRole(Role::findOrCreate($role, 'web'));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    protected function staff(): User
    {
        return $this->makeUser(['name' => 'Staff Member'], ['staff']);
    }

    protected function superAdmin(): User
    {
        return $this->makeUser(['name' => 'Super Admin'], ['super-admin']);
    }

    protected function signIn(User $user): static
    {
        return $this->actingAs($user)->withSession([
            EnsureSessionIsValid::SIGNED_IN_AT => time(),
            EnsureSessionIsValid::EPOCH => (int) $user->session_epoch,
            // The console requires a session signed in with a user-verified passkey.
            EnsureSessionIsValid::METHOD => SignIn::PASSKEY,
        ]);
    }

    /** @return array{customer: User, organisation: Organisation, product: Product, version: ProductVersion} */
    protected function seedSampleData(): array
    {
        $customer = $this->makeUser(['name' => 'Customer One', 'organisation_name' => 'Acme Radio']);
        $organisation = Organisation::create(['name' => 'Acme Radio', 'created_by' => $customer->id]);
        $organisation->members()->attach($customer->id, ['role' => 'admin']);

        $product = Product::create([
            'organisation_id' => $organisation->id,
            'owner_id' => $customer->id,
            'name' => 'Smart Plug',
            'reference' => 'SP-1',
        ]);
        $version = ProductVersion::forceCreate(['product_id' => $product->id, 'label' => '1.0', 'status' => 'draft']);

        DB::table('request_logs')->insert([
            ['user_id' => $customer->id, 'method' => 'GET', 'route' => 'products.show', 'path' => '/products/'.$product->id,
                'status' => 200, 'duration_ms' => 40, 'product_id' => $product->id, 'version_id' => null,
                'ip_hash' => str_repeat('a', 64), 'user_agent' => 'Test', 'locale' => 'en', 'created_at' => now()->subDay()],
            ['user_id' => $customer->id, 'method' => 'GET', 'route' => 'versions.show', 'path' => '/versions/'.$version->id,
                'status' => 500, 'duration_ms' => 900, 'product_id' => null, 'version_id' => $version->id,
                'ip_hash' => str_repeat('a', 64), 'user_agent' => 'Test', 'locale' => 'fr', 'created_at' => now()],
            ['user_id' => null, 'method' => 'GET', 'route' => 'home', 'path' => '/',
                'status' => 200, 'duration_ms' => 12, 'product_id' => null, 'version_id' => null,
                'ip_hash' => str_repeat('b', 64), 'user_agent' => 'Test', 'locale' => 'en', 'created_at' => now()->subDays(3)],
        ]);

        DB::table('ai_usage')->insert([
            ['user_id' => $customer->id, 'organisation_id' => $organisation->id, 'product_version_id' => $version->id,
                'feature' => 'improve_text', 'units' => 3, 'provider' => 'test', 'model' => 'test', 'outcome' => 'ok', 'created_at' => now()],
            ['user_id' => $customer->id, 'organisation_id' => $organisation->id, 'product_version_id' => $version->id,
                'feature' => 'check', 'units' => 1, 'provider' => 'test', 'model' => 'test', 'outcome' => 'error', 'created_at' => now()->subDays(2)],
        ]);

        activity('security')->causedBy($customer)->withProperties(['method' => 'passkey'])->log('sign-in');
        activity('report')->causedBy($customer)->performedOn($version)->log('report exported');

        return compact('customer', 'organisation', 'product', 'version');
    }
}
