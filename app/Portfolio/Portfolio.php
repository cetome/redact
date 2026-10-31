<?php

namespace App\Portfolio;

use App\Billing\Plan;
use App\Enums\VersionStatus;
use App\Models\EinfoEntry;
use App\Models\Evaluation;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\User;
use App\Support\WorkflowSummary;
use Illuminate\Support\Facades\DB;

/**
 * An organisation's portfolio (paid plans, its admins): every product and every version, where
 * each stands (status, progress of the latest version, significant change, what is left to
 * re-check) and who last worked on it. Who does what over time comes from App\Admin\WorkActivity,
 * limited to the organisation.
 */
final class Portfolio
{
    /** @var list<array<string, mixed>>|null */
    private ?array $products = null;

    public function __construct(public readonly Organisation $organisation) {}

    /** Paid or granted products, Enterprise, or a self-hosted install (no SaaS plan there). */
    public static function available(Organisation $organisation): bool
    {
        return Plan::selfHosted() || Plan::for($organisation)->hasExtras();
    }

    /**
     * One row per product, most recently changed first, with its versions newest first. Only the
     * latest version carries the workflow progress (as on the products dashboard).
     *
     * @return list<array{product: Product, versions: list<array<string, mixed>>}>
     */
    public function products(): array
    {
        if ($this->products !== null) {
            return $this->products;
        }

        $products = Product::query()->where('organisation_id', $this->organisation->id)
            ->with(['owner', 'versions' => fn ($q) => $q->orderByDesc('id')])
            ->withCount('members')
            ->orderByDesc('updated_at')->get();
        $versionIds = $products->flatMap(fn (Product $p) => $p->versions->pluck('id'))->all();
        $last = $this->lastActivity($versionIds);
        $rechecks = $this->rechecks($versionIds);

        $rows = [];
        foreach ($products as $product) {
            $versions = [];
            foreach ($product->versions as $i => $version) {
                $row = [
                    'version' => $version,
                    'latest' => $i === 0,
                    'last' => $last[$version->id] ?? null,
                    'rechecks' => $rechecks[$version->id] ?? 0,
                    'change' => null,
                    'summary' => null,
                ];
                if ($i === 0) {
                    $summary = WorkflowSummary::for($version);
                    $changes = $summary->assessment->changes();
                    $row['summary'] = $summary;
                    $row['change'] = $changes ? ['state' => $changes->state(), 'previous' => $changes->previous->label, 'decision' => $version->change_decision] : null;
                } elseif ($version->change_decision !== null) {
                    $row['change'] = ['state' => 'decided', 'previous' => null, 'decision' => $version->change_decision];
                }
                $versions[] = $row;
            }
            $rows[] = ['product' => $product, 'versions' => $versions];
        }

        return $this->products = $rows;
    }

    /**
     * Figures for the tiles: products, versions by status, items to re-check, significant
     * changes still to decide (latest versions).
     *
     * @return array{products: int, draft: int, in_review: int, approved: int, rechecks: int, to_decide: int}
     */
    public function totals(): array
    {
        $versions = collect($this->products())->flatMap(fn (array $p) => $p['versions']);
        $status = fn (VersionStatus $s) => $versions->filter(fn (array $v) => $v['version']->status === $s)->count();

        return [
            'products' => count($this->products()),
            'draft' => $status(VersionStatus::Draft),
            'in_review' => $status(VersionStatus::InReview),
            'approved' => $status(VersionStatus::Approved),
            'rechecks' => (int) $versions->sum('rechecks'),
            'to_decide' => $versions->filter(fn (array $v) => in_array($v['change']['state'] ?? null, ['to_decide', 'outdated'], true))->count(),
        ];
    }

    /**
     * version id => [at, user] of the newest entry of an account in its history.
     *
     * @param  list<string>  $versionIds
     * @return array<string, array{at: string, user: User|null}>
     */
    private function lastActivity(array $versionIds): array
    {
        if ($versionIds === []) {
            return [];
        }
        $latest = DB::table('activity_log')
            ->whereIn('product_version_id', $versionIds)
            ->where('causer_type', (new User)->getMorphClass())
            ->selectRaw('product_version_id as v, MAX(id) as id')
            ->groupBy('product_version_id')->pluck('id', 'v');
        $entries = DB::table('activity_log')->whereIn('id', $latest->values())->get(['id', 'product_version_id', 'causer_id', 'created_at']);
        $users = User::query()->whereIn('id', $entries->pluck('causer_id')->unique())->get(['id', 'name', 'email', 'is_guest'])->keyBy('id');

        return $entries->mapWithKeys(fn ($e) => [(string) $e->product_version_id => [
            'at' => (string) $e->created_at,
            'user' => $users->get((int) $e->causer_id),
        ]])->all();
    }

    /**
     * version id => evaluations and E.Info entries still to re-check (SignificantChange).
     *
     * @param  list<string>  $versionIds
     * @return array<string, int>
     */
    private function rechecks(array $versionIds): array
    {
        $counts = [];
        foreach ([Evaluation::class, EinfoEntry::class] as $model) {
            foreach ($model::query()->whereIn('product_version_id', $versionIds)->where('recheck', true)
                ->selectRaw('product_version_id as v, COUNT(*) as n')->groupBy('product_version_id')->pluck('n', 'v') as $id => $n) {
                $counts[(string) $id] = ($counts[(string) $id] ?? 0) + (int) $n;
            }
        }

        return $counts;
    }
}
