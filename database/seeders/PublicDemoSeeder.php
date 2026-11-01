<?php

namespace Database\Seeders;

use App\Enums\VersionStatus;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\User;
use App\PublicPage\PublicSettings;
use RuntimeException;

/**
 * The demo public page behind the /about picture "A page your buyers can check": the invented
 * "Smart plug (demo)" of Emotec, approved, assessed in full (every decision-tree walk ending PASS or
 * NOT APPLICABLE, low residual risks), its page published at the fixed address /verify/R3D4CT so the
 * QR code in the picture opens it. Its own account (public-demo@example.com, a reserved domain, no
 * sign-in), search engines told not to list it. Production too:
 *
 *   php artisan db:seed --class=PublicDemoSeeder --force
 *
 * Re-running rebuilds the product at the same address (the random draws are seeded).
 */
class PublicDemoSeeder extends DemoSeeder
{
    public const EMAIL = 'public-demo@example.com';

    public const PRODUCT = 'Smart plug (demo)';

    public const CODE = 'R3D4CT';

    /** Walk the trees to PASS or NOT APPLICABLE, not at random. */
    private bool $passing = false;

    public function run(): void
    {
        $user = User::query()->where('email', self::EMAIL)->first() ?? User::forceCreate([
            'name' => 'Emotec demo',
            'email' => self::EMAIL,
            'email_verified_at' => now(),
            'locale' => 'en',
            'timezone' => 'Europe/Paris',
        ]);
        $organisation = $user->organisations()->first() ?? tap(Organisation::create(['name' => 'Emotec', 'created_by' => $user->id]),
            fn (Organisation $o) => $o->members()->attach($user->id, ['role' => 'admin']));

        $taken = Product::withTrashed()->where('public_code', self::CODE)->where('owner_id', '!=', $user->id)->exists();
        if ($taken) {
            throw new RuntimeException('The address /verify/'.self::CODE.' belongs to another product.');
        }
        Product::withTrashed()->where('owner_id', $user->id)->where(fn ($q) => $q->where('name', self::PRODUCT)->orWhere('public_code', self::CODE))->get()
            ->each(fn (Product $p) => $p->versions()->each(fn (ProductVersion $v) => $v->delete()) && $p->forceDelete());

        mt_srand(2026);
        $this->passing = true;
        $this->product($user, $organisation, self::PRODUCT, 'SP-200', 'A Wi-Fi smart plug switched from a phone app and voice assistants.', [
            ['1.0', VersionStatus::Approved, 1.0],
        ]);
        $this->passing = false;

        $version = ProductVersion::query()->whereHas('product', fn ($q) => $q->where('owner_id', $user->id)->where('name', self::PRODUCT))->firstOrFail();
        $version->forceFill(['approved_at' => now()])->save();
        // Mitigated risks: the residual levels of an approved product are low (rank 1 = the highest).
        $ranks = $version->riskLevels()->orderBy('rank')->pluck('rank')->all();
        $low = array_slice($ranks, -3);
        $version->riskEntries()->orderBy('position')->get()
            ->each(fn ($risk, $i) => $risk->forceFill(['residual_rank' => $low[[2, 2, 1, 2, 1, 0][$i % 6]] ?? null])->save());

        $version->product->forceFill(['public_code' => self::CODE])->save();
        $settings = PublicSettings::for($version)->toArray();
        $settings['sections']['risks'] = true;
        $settings = array_merge($settings, [
            'contact' => 'https://emotec.example/security',
            'support_until' => now()->addYears(5)->endOfYear()->toDateString(),
            'indexed' => false,
        ]);
        $version->forceFill([
            'public_page' => ['draft' => $settings, 'live' => $settings + ['at' => now()->toIso8601String()]],
            'published_at' => now(),
            'published_by' => $user->id,
        ])->save();
    }

    /**
     * A path to PASS (else NOT APPLICABLE), the parent's random walk otherwise.
     *
     * @param  array<string, mixed>  $tree
     * @return list<string>
     */
    protected function walk(array $tree): array
    {
        if (! $this->passing) {
            return parent::walk($tree);
        }
        $nodes = collect($tree['nodes'] ?? [])->keyBy('id');
        $search = function (?string $id, array $answers, array $seen, string $wanted) use (&$search, $nodes): ?array {
            $node = $id !== null ? ($nodes[$id] ?? null) : null;
            if (! $node || isset($seen[$id])) {
                return null;
            }
            if ($node['kind'] === 'result') {
                return ($node['result'] ?? null) === $wanted ? $answers : null;
            }
            foreach ($node['branches'] ?? [] as $branch) {
                $found = $search($branch['to'], [...$answers, $branch['label']], $seen + [$id => true], $wanted);
                if ($found !== null) {
                    return $found;
                }
            }

            return null;
        };

        return $search($tree['start'] ?? null, [], [], 'PASS') ?? $search($tree['start'] ?? null, [], [], 'NOT APPLICABLE') ?? parent::walk($tree);
    }
}
