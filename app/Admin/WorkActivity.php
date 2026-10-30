<?php

namespace App\Admin;

use App\History\Describer;
use App\History\Entry;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Who does what, over a period of 7, 30 or 90 days, for the console overview: the work of
 * accounts (sandbox guests and the console's own entries left out) from the activity log, by
 * area, by person and by product, and the latest entries in the customers' history sentences
 * (App\History\Describer).
 */
final class WorkActivity
{
    /** Area => the log names it gathers. The order is the order of the chart colours (app-ui.css Z). */
    public const AREAS = [
        'product' => ['product', 'version', 'scoping'],
        'inventory' => ['risk', 'inventory', 'sbom'],
        'trees' => ['evaluation'],
        'einfo' => ['einfo'],
        'review' => ['review', 'assessment_case'],
        'documents' => ['document', 'report', 'import', 'history'],
        'team' => ['team', 'organisation', 'billing'],
        'ai' => ['ai'],
    ];

    public const TOP = 15;

    public const LATEST = 25;

    public function __construct(private readonly int $days) {}

    public static function for(int $days): self
    {
        return new self(in_array($days, UsageAnalytics::PERIODS, true) ? $days : 30);
    }

    public function days(): int
    {
        return $this->days;
    }

    /** @return list<string> */
    public static function logs(): array
    {
        return array_merge(...array_values(self::AREAS));
    }

    public static function areaOf(?string $log): ?string
    {
        foreach (self::AREAS as $area => $logs) {
            if (in_array($log, $logs, true)) {
                return $area;
            }
        }

        return null;
    }

    /** The entries of the period made by an account. */
    private function base(): Builder
    {
        return DB::table('activity_log as a')
            ->join('users as u', 'u.id', '=', 'a.causer_id')
            ->where('a.causer_type', (new User)->getMorphClass())
            ->where('u.is_guest', false)
            ->whereIn('a.log_name', self::logs())
            ->where('a.created_at', '>=', DailySeries::start($this->days));
    }

    /** @return array{actions: int, people: int, products: int, organisations: int} */
    public function totals(): array
    {
        $row = $this->base()
            ->selectRaw('COUNT(*) as actions, COUNT(DISTINCT a.causer_id) as people, '
                .'COUNT(DISTINCT a.product_id) as products, COUNT(DISTINCT a.organisation_id) as organisations')
            ->first();

        return [
            'actions' => (int) ($row->actions ?? 0),
            'people' => (int) ($row->people ?? 0),
            'products' => (int) ($row->products ?? 0),
            'organisations' => (int) ($row->organisations ?? 0),
        ];
    }

    /**
     * Actions per day, one series per area, for the stacked chart.
     *
     * @return array{labels: list<string>, datasets: list<array{key: string, label: string, values: list<int>}>, type: string}
     */
    public function chart(): array
    {
        $byArea = array_fill_keys(array_keys(self::AREAS), []);
        foreach ($this->base()->selectRaw('DATE(a.created_at) as d, a.log_name as l, COUNT(*) as n')
            ->groupByRaw('DATE(a.created_at), a.log_name')->get() as $row) {
            $area = (string) self::areaOf($row->l);
            $byArea[$area][$row->d] = ($byArea[$area][$row->d] ?? 0) + (int) $row->n;
        }

        $labels = array_keys(DailySeries::fill([], $this->days));
        $datasets = [];
        foreach ($byArea as $area => $values) {
            $datasets[] = [
                'key' => $area,
                'label' => (string) __('admin.work.areas.'.$area),
                'values' => array_values(DailySeries::fill($values, $this->days)),
            ];
        }

        return ['labels' => $labels, 'datasets' => $datasets, 'type' => 'stacked'];
    }

    /**
     * The most active people of the period: what they did, where, when.
     *
     * @return list<array{user: User, actions: int, days: int, products: int, last: string, areas: array<string, int>, daily: list<int>, latest: ?Entry}>
     */
    public function people(User $viewer): array
    {
        $rows = $this->base()
            ->selectRaw('a.causer_id as id, COUNT(*) as n, COUNT(DISTINCT DATE(a.created_at)) as days, '
                .'COUNT(DISTINCT a.product_id) as products, MAX(a.created_at) as last, MAX(a.id) as latest')
            ->groupBy('a.causer_id')
            ->orderByDesc('n')
            ->limit(self::TOP)
            ->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $users = User::query()->with('organisations:id,name')->whereIn('id', $ids)->get()->keyBy('id');
        $areas = $this->areas('a.causer_id', $ids);
        $daily = $this->daily('a.causer_id', $ids);
        $latest = $this->describe($viewer, $rows->pluck('latest')->all());

        $people = [];
        foreach ($rows as $row) {
            $user = $users->get((int) $row->id);
            if ($user === null) {
                continue;
            }
            $people[] = [
                'user' => $user,
                'actions' => (int) $row->n,
                'days' => (int) $row->days,
                'products' => (int) $row->products,
                'last' => (string) $row->last,
                'areas' => $areas[(string) $row->id] ?? [],
                'daily' => $daily[(string) $row->id] ?? [],
                'latest' => $latest[(int) $row->latest] ?? null,
            ];
        }

        return $people;
    }

    /**
     * The products worked on most in the period, and by whom.
     *
     * @return list<array{product: Product, actions: int, people: int, last: string, areas: array<string, int>, daily: list<int>, who: list<array{user: User, actions: int}>}>
     */
    public function products(): array
    {
        $rows = $this->base()
            ->whereNotNull('a.product_id')
            ->selectRaw('a.product_id as id, COUNT(*) as n, COUNT(DISTINCT a.causer_id) as people, MAX(a.created_at) as last')
            ->groupBy('a.product_id')
            ->orderByDesc('n')
            ->limit(self::TOP)
            ->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $ids = $rows->pluck('id')->map(fn ($id) => (string) $id)->all();
        $products = Product::withTrashed()->with(['organisation:id,name', 'versions' => fn ($q) => $q->select('id', 'product_id', 'label', 'status', 'created_at')])
            ->whereIn('id', $ids)->get()->keyBy('id');
        $areas = $this->areas('a.product_id', $ids);
        $daily = $this->daily('a.product_id', $ids);

        // The people of each product, most active first.
        $who = [];
        $pairs = $this->base()->whereIn('a.product_id', $ids)
            ->selectRaw('a.product_id as p, a.causer_id as u, COUNT(*) as n')
            ->groupBy('a.product_id', 'a.causer_id')->orderByDesc('n')->get();
        $users = User::query()->whereIn('id', $pairs->pluck('u')->unique()->all())->get(['id', 'name', 'email'])->keyBy('id');
        foreach ($pairs as $pair) {
            if ($user = $users->get((int) $pair->u)) {
                $who[(string) $pair->p][] = ['user' => $user, 'actions' => (int) $pair->n];
            }
        }

        $list = [];
        foreach ($rows as $row) {
            $product = $products->get((string) $row->id);
            if ($product === null) {
                continue;
            }
            $list[] = [
                'product' => $product,
                'actions' => (int) $row->n,
                'people' => (int) $row->people,
                'last' => (string) $row->last,
                'areas' => $areas[(string) $row->id] ?? [],
                'daily' => $daily[(string) $row->id] ?? [],
                'who' => $who[(string) $row->id] ?? [],
            ];
        }

        return $list;
    }

    /**
     * The latest entries of the period, as the customers' history writes them.
     *
     * @return list<array{entry: Entry, user: ?User, organisation: ?string}>
     */
    public function latest(User $viewer): array
    {
        $ids = $this->base()->orderByDesc('a.id')->limit(self::LATEST * 4)->pluck('a.id')->all();
        $entries = $this->describe($viewer, $ids, self::LATEST);
        if ($entries === []) {
            return [];
        }

        $activities = Activity::query()->whereIn('id', array_keys($entries))->get(['id', 'causer_id', 'organisation_id'])->keyBy('id');
        $users = User::query()->whereIn('id', $activities->pluck('causer_id')->unique()->all())->get()->keyBy('id');
        $organisations = DB::table('organisations')->whereIn('id', $activities->pluck('organisation_id')->filter()->unique()->all())->pluck('name', 'id');

        $list = [];
        foreach ($entries as $id => $entry) {
            $activity = $activities->get($id);
            $list[] = [
                'entry' => $entry,
                'user' => $activity ? $users->get((int) $activity->causer_id) : null,
                'organisation' => $activity && $activity->organisation_id ? ($organisations[$activity->organisation_id] ?? null) : null,
            ];
        }

        return $list;
    }

    /**
     * Activity ids => their history line (entries a customer never sees are left out), newest first.
     *
     * @param  list<int|string>  $ids
     * @return array<int, Entry>
     */
    private function describe(User $viewer, array $ids, ?int $limit = null): array
    {
        $activities = Activity::query()->whereIn('id', $ids)->orderByDesc('id')->get();
        if ($activities->isEmpty()) {
            return [];
        }

        $productIds = $activities->pluck('product_id')->filter()->unique()->values()->all();
        $describer = (new Describer($viewer, true))
            ->products(Product::withTrashed()->whereIn('id', $productIds)->pluck('name', 'id')->map(fn ($n) => (string) $n)->all())
            ->versions(Describer::versionLabels($productIds));
        $describer->prime($activities);

        $entries = [];
        foreach ($activities as $activity) {
            if ($entry = $describer->describe($activity)) {
                $entries[(int) $activity->id] = $entry;
                if ($limit !== null && count($entries) >= $limit) {
                    break;
                }
            }
        }

        return $entries;
    }

    /**
     * Actions per area of each person or product, largest first.
     *
     * @param  list<int|string>  $ids
     * @return array<string, array<string, int>>
     */
    private function areas(string $column, array $ids): array
    {
        $areas = [];
        foreach ($this->base()->whereIn($column, $ids)->selectRaw("{$column} as k, a.log_name as l, COUNT(*) as n")
            ->groupBy($column, 'a.log_name')->get() as $row) {
            $area = (string) self::areaOf($row->l);
            $areas[(string) $row->k][$area] = ($areas[(string) $row->k][$area] ?? 0) + (int) $row->n;
        }

        return array_map(function (array $counts) {
            arsort($counts);

            return $counts;
        }, $areas);
    }

    /**
     * Actions per day of each person or product (one value per day of the period).
     *
     * @param  list<int|string>  $ids
     * @return array<string, list<int>>
     */
    private function daily(string $column, array $ids): array
    {
        $days = [];
        foreach ($this->base()->whereIn($column, $ids)->selectRaw("{$column} as k, DATE(a.created_at) as d, COUNT(*) as n")
            ->groupByRaw("{$column}, DATE(a.created_at)")->get() as $row) {
            $days[(string) $row->k][(string) $row->d] = (int) $row->n;
        }

        return array_map(fn (array $values) => array_values(DailySeries::fill($values, $this->days)), $days);
    }
}
