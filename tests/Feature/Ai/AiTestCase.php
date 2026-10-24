<?php

namespace Tests\Feature\Ai;

use App\Enums\ProductRole;
use App\Http\Middleware\EnsureSessionIsValid;
use App\Models\Evaluation;
use App\Models\InventoryItem;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\ProductMember;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\PrismFake;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Tests\TestCase;

/**
 * A draft version of a product on the demo pack, owned by $this->owner.
 */
abstract class AiTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Organisation $organisation;

    protected Product $product;

    protected ProductVersion $version;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'redact.ai.enabled' => true,
            'redact.ai.provider' => 'gemini',
            'redact.ai.model' => 'gemini-test-model',
            'redact.ai.fallback_models' => [],
            'redact.ai.quota.per_day' => 150,
            'redact.ai.quota.per_10_minutes' => 40,
            'prism.providers.gemini.api_key' => 'test-key',
            // The plan limits (App\Billing\Plan, tests/Feature/Billing) stay out of the way of the AI tests.
            'redact.plans.free.products' => 10,
            'redact.plans.free.ai_units' => 100000,
        ]);

        $this->owner = User::factory()->create();
        $this->organisation = Organisation::create(['name' => 'Acme', 'created_by' => $this->owner->id, 'ai_enabled' => true]);
        $this->organisation->members()->attach($this->owner->id, ['role' => 'member']);
        $this->product = Product::create([
            'organisation_id' => $this->organisation->id,
            'owner_id' => $this->owner->id,
            'name' => 'Smart lock',
            'description' => 'A door lock with a radio.',
        ]);
        $this->version = ProductVersion::create([
            'product_id' => $this->product->id,
            'label' => '1.0',
            'status' => 'draft',
            'intended_use' => 'Front doors of homes.',
            'created_by' => $this->owner->id,
        ]);
    }

    /** Signed in with a valid session (EnsureSessionIsValid checks its age and epoch). */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        parent::actingAs($user, $guard);

        return $this->withSession([
            EnsureSessionIsValid::SIGNED_IN_AT => time(),
            EnsureSessionIsValid::EPOCH => (int) $user->session_epoch,
        ]);
    }

    protected function member(ProductRole $role): User
    {
        $user = User::factory()->create();
        ProductMember::create(['product_id' => $this->product->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    protected function item(array $attributes = []): InventoryItem
    {
        return InventoryItem::create(array_merge([
            'product_version_id' => $this->version->id,
            'list' => 'assets',
            'code' => 'AS-1',
            'name' => 'Door key',
            'description' => 'The digital key.',
            'attributes' => ['type' => 'security'],
        ], $attributes));
    }

    protected function evaluation(?InventoryItem $item = null): Evaluation
    {
        return Evaluation::create([
            'product_version_id' => $this->version->id,
            'standard' => 'demo-1',
            'requirement' => 'ACC-1',
            'inventory_item_id' => ($item ?? $this->item())->id,
            'decision_node' => 'DN-2',
            'path' => [['node' => 'DN-1', 'answer' => 'No'], ['node' => 'DN-2', 'answer' => 'Yes']],
            'verdict' => 'PASS',
            'justification' => 'Access needs the owner app.',
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $answers  structured answers, in call order
     */
    protected function fakeAi(array $answers): PrismFake
    {
        return Prism::fake(array_map(fn (array $data) => StructuredResponseFake::make()
            ->withText(json_encode($data))
            ->withStructured($data)
            ->withUsage(new Usage(100, 50))
            ->withMeta(new Meta('fake', 'gemini-test-model')), $answers));
    }
}
