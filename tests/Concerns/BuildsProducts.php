<?php

namespace Tests\Concerns;

use App\Assessment\Assessment;
use App\Assessment\AssessmentCases;
use App\Assessment\VersionFactory;
use App\Enums\ProductRole;
use App\Enums\Verdict;
use App\Http\Middleware\EnsureSessionIsValid;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\User;

/**
 * Builds organisations, products, versions and team members for feature tests
 * (on the demo content pack).
 */
trait BuildsProducts
{
    protected function owner(): User
    {
        $user = User::factory()->create();
        $organisation = Organisation::create(['name' => 'ACME', 'created_by' => $user->id]);
        $organisation->members()->attach($user->id, ['role' => 'admin']);

        return $user;
    }

    protected function product(User $owner, string $label = '1.0'): ProductVersion
    {
        $product = Product::create([
            'organisation_id' => $owner->organisations()->first()->id,
            'owner_id' => $owner->id,
            'name' => 'Smart lock',
        ]);

        return app(VersionFactory::class)->create($product, $label, $owner);
    }

    protected function member(Product $product, ProductRole $role, bool $internal = false): User
    {
        $user = User::factory()->create();
        if ($internal) {
            $product->organisation->members()->attach($user->id, ['role' => 'member']);
        }
        $product->members()->create(['user_id' => $user->id, 'role' => $role, 'external' => ! $internal]);

        return $user;
    }

    /** Act as $user with a fresh, valid REDact session. */
    protected function signIn(User $user, string $method = 'email'): static
    {
        return $this->actingAs($user)->withSession([
            EnsureSessionIsValid::SIGNED_IN_AT => time(),
            EnsureSessionIsValid::EPOCH => $user->session_epoch,
            EnsureSessionIsValid::METHOD => $method,
        ]);
    }

    /**
     * Provides the result of every assessment unit still missing (the "Assessment units" step),
     * PASS with its evidence, so that the version can be submitted for review.
     */
    protected function provideUnits(ProductVersion $version, ?User $by = null): void
    {
        foreach (AssessmentCases::for(Assessment::for($version->fresh()))->units() as $unit) {
            if ($unit['result'] === null) {
                $version->caseResults()->create([
                    'standard' => $unit['standard'], 'requirement' => $unit['requirement'], 'case_key' => $unit['case'],
                    'verdict' => Verdict::Pass, 'evidence' => 'Checked on the bench, as documented in the test report.',
                    'recorded_by' => ($by ?? $version->product->owner)->id, 'independent' => false,
                ]);
            }
        }
    }
}
