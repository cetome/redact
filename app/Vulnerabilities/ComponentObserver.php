<?php

namespace App\Vulnerabilities;

use App\Jobs\Vulnerabilities\CheckChangedComponent;
use App\Models\InventoryItem;
use App\Sbom\Identifiers;
use App\Sbom\SbomFeature;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Hooks the component list without touching the inventory controller:
 *   saving  takes the optional purl / CPE fields of the component form (identifiers[purl],
 *           identifiers[cpe], sbom/_identifiers-fields) into the identifiers column;
 *   saved   queues a look-up a minute after a component is created or its name, version,
 *           purl or CPE change (signed-in people only: never guests, never in bulk imports,
 *           which check once at the end).
 */
final class ComponentObserver
{
    private static int $muted = 0;

    /**
     * Run without automatic look-ups (imports queue one check at the end).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function muted(Closure $callback): mixed
    {
        self::$muted++;
        try {
            return $callback();
        } finally {
            self::$muted--;
        }
    }

    public function saving(InventoryItem $item): void
    {
        if ($item->list !== SbomFeature::componentsList()) {
            return;
        }
        $request = request();
        if (! $request->routeIs('versions.inventory.store', 'versions.inventory.update') || ! $request->has('identifiers')) {
            return;
        }
        $input = (array) $request->input('identifiers');
        Validator::make(['identifiers' => $input], [
            'identifiers.purl' => ['nullable', 'string', 'max:500', 'regex:'.Identifiers::PURL_PATTERN],
            'identifiers.cpe' => ['nullable', 'string', 'max:300', 'regex:/^cpe:(2\.3:|\/)[aho*-]?/i'],
        ], [
            'identifiers.purl.regex' => __('sbom.identifiers.purl_invalid'),
            'identifiers.cpe.regex' => __('sbom.identifiers.cpe_invalid'),
        ])->validate();

        $cpe = isset($input['cpe']) && is_string($input['cpe']) && trim($input['cpe']) !== '' ? Identifiers::cpe($input['cpe']) : null;
        if (isset($input['cpe']) && is_string($input['cpe']) && trim($input['cpe']) !== '' && $cpe === null) {
            throw ValidationException::withMessages(['identifiers.cpe' => __('sbom.identifiers.cpe_invalid')]);
        }
        Identifiers::put($item, [
            'purl' => Identifiers::purl($input['purl'] ?? null),
            'cpe' => $cpe,
        ]);
    }

    public function saved(InventoryItem $item): void
    {
        if (self::$muted > 0 || $item->list !== SbomFeature::componentsList() || ! config('sbom.checks.auto') || ! config('sbom.checks.enabled')) {
            return;
        }
        $user = auth()->user();
        if (! $user || $user->is_guest || ! SbomFeature::checkable()) {
            return;
        }
        if (! $item->wasRecentlyCreated && ! $item->wasChanged(['name', 'attributes', 'identifiers'])) {
            return;
        }
        if (Checker::matchKind($item) === 'none' || ! VulnerabilityChecks::isStale($item)) {
            return;
        }
        CheckChangedComponent::dispatch($item->id, Checker::fingerprint($item))
            ->delay(now()->addSeconds((int) config('sbom.checks.auto_delay', 60)));
    }
}
