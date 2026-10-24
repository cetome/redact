<?php

namespace App\Actions;

use App\Ai\Features\SuggestScoping;
use App\Assessment\Assessment;
use App\Assessment\ScopingForm;
use App\Assessment\VersionFactory;
use App\Billing\Plan;
use App\Billing\PlanLimitReached;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "New product" = the RED scoping (cetome.com RED scoping): the product, its first version and
 * that version's scoping answers, in one transaction. Used by the New product form
 * (ProductController::store) and by the scoping on the home page (HomeStartController).
 * The caller validates the input and checks the organisation and the sandbox limit. The plan
 * limit is checked here (App\Billing\Plan), whatever page creates the product: when no slot is
 * left, PlanLimitReached is thrown and rendered as New product with the way to add one.
 * Guests keep the sandbox rules (App\Guest\Sandbox).
 */
class CreateProduct
{
    public function __construct(
        private readonly VersionFactory $versions,
        private readonly ScopingForm $form,
        private readonly SuggestScoping $suggest,
    ) {}

    /**
     * "demo" => true: the demo product of an account (App\Guest\DemoProduct), outside the plan
     * (plan_slot "demo", products.is_demo), read-only (App\Support\ProductAccess).
     *
     * @param  array{organisation_id: string, name?: ?string, reference?: ?string, description?: ?string, version_label?: ?string, answers?: ?array<string, mixed>, ai_scoping?: ?string, demo?: bool}  $data
     */
    public function __invoke(User $user, array $data): ProductVersion
    {
        return DB::transaction(function () use ($user, $data) {
            // The plan limit, for every caller: serialise creations in one organisation (two requests
            // cannot both take the last slot), then take the free, a granted or a paid slot.
            $organisation = Organisation::query()->whereKey($data['organisation_id'])->lockForUpdate()->firstOrFail();
            $plan = Plan::fresh($organisation);
            $demo = ! $user->is_guest && ($data['demo'] ?? false) === true;
            $slot = match (true) {
                $demo => Plan::SLOT_DEMO,
                $user->is_guest => Plan::SLOT_FREE,
                default => $plan->nextSlot(),
            };
            if ($slot === null) {
                throw PlanLimitReached::products($plan);
            }

            $product = new Product([
                'organisation_id' => $data['organisation_id'],
                'owner_id' => $user->id,
                'name' => trim((string) ($data['name'] ?? '')) ?: self::defaultName($data['organisation_id']),
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
            ]);
            $product->forceFill(['plan_slot' => $slot, 'is_demo' => $demo])->save();
            $version = $this->versions->create($product, $data['version_label'] ?? '1.0', $user);
            $this->form->save($version, $data['answers'] ?? [], ScopingForm::SCOPING, $user->id);
            $this->suggest->record($version, $data['ai_scoping'] ?? null, $user, $data['organisation_id']);

            return $version;
        });
    }

    /**
     * A product created without a name: "Product #1", "Product #2"… — the first number not taken in
     * the organisation (owner, 2026-10-23). It can be renamed at any time on the Product step.
     */
    public static function defaultName(string $organisationId): string
    {
        $taken = Product::query()->where('organisation_id', $organisationId)->pluck('name')->flip();
        $n = 1;
        while (isset($taken[__('products.default_name', ['n' => $n])])) {
            $n++;
        }

        return __('products.default_name', ['n' => $n]);
    }

    /** The "created" message: which EN 18031 parts the scoping brought in. */
    public function createdMessage(ProductVersion $version): string
    {
        $assessment = Assessment::for($version);
        $standards = $assessment->standards();

        return $standards === []
            ? __('scoping.created_none')
            : __('scoping.created', ['standards' => implode(', ', array_map(fn ($s) => $assessment->pack->standardName($s), $standards))]);
    }
}
