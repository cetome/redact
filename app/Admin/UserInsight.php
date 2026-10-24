<?php

namespace App\Admin;

use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Everything the console shows about one person: memberships, sessions,
 * security events and a timeline merging the audit trail and page hits.
 */
final class UserInsight
{
    public const TIMELINE_LIMIT = 150;

    public const SOURCES = ['all', 'activity', 'requests'];

    public function __construct(private readonly User $user) {}

    /**
     * Products the person owns or is a member of, with their role.
     *
     * @return Collection<int, array{product: Product, role: string, external: bool, expires_at: CarbonImmutable|null}>
     */
    public function memberships(): Collection
    {
        $members = DB::table('product_members')->where('user_id', $this->user->id)->get()->keyBy('product_id');

        return Product::query()
            ->with('organisation:id,name')
            ->where(fn ($q) => $q->where('owner_id', $this->user->id)->orWhereIn('id', $members->keys()))
            ->orderBy('name')
            ->get()
            ->map(fn (Product $product) => [
                'product' => $product,
                'role' => $product->owner_id === $this->user->id ? 'owner' : (string) ($members[$product->id]->role ?? ''),
                'external' => (bool) ($members[$product->id]->external ?? false),
                'expires_at' => isset($members[$product->id]->expires_at) ? CarbonImmutable::parse($members[$product->id]->expires_at) : null,
            ]);
    }

    /**
     * Rows with id, ip_address, user_agent and last_activity (CarbonImmutable).
     *
     * @return Collection<int, \stdClass>
     */
    public function sessions(): Collection
    {
        if (config('session.driver') !== 'database') {
            return collect();
        }

        return DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $this->user->id)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(function ($session) {
                $session->last_activity = CarbonImmutable::createFromTimestamp((int) $session->last_activity);

                return $session;
            });
    }

    /** @return Collection<int, Activity> */
    public function securityEvents(int $limit = 50): Collection
    {
        $morph = $this->user->getMorphClass();

        return Activity::query()
            ->where('log_name', 'security')
            ->where(fn ($q) => $q
                ->where(fn ($c) => $c->where('causer_type', $morph)->where('causer_id', $this->user->id))
                ->orWhere(fn ($s) => $s->where('subject_type', $morph)->where('subject_id', $this->user->id)))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Audit trail (activity caused by the person) merged with their page hits, newest first.
     *
     * @param  array{source?: string|null, from?: string|null, to?: string|null}  $filters
     * @return Collection<int, array{at: CarbonImmutable, kind: string, log: ?string, label: string, detail: ?string, product_id: ?string, version_id: ?string, status: ?int, changes: list<array{field: string, old: string, new: string}>}>
     */
    public function timeline(array $filters): Collection
    {
        $source = in_array($filters['source'] ?? 'all', self::SOURCES, true) ? ($filters['source'] ?? 'all') : 'all';
        $from = ! empty($filters['from']) ? CarbonImmutable::createFromFormat('Y-m-d', $filters['from'])->startOfDay() : null;
        $to = ! empty($filters['to']) ? CarbonImmutable::createFromFormat('Y-m-d', $filters['to'])->endOfDay() : null;

        $entries = collect();

        if ($source !== 'requests') {
            $activities = Activity::query()
                ->where('causer_type', $this->user->getMorphClass())
                ->where('causer_id', $this->user->id)
                ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
                ->orderByDesc('id')
                ->limit(self::TIMELINE_LIMIT)
                ->get();

            foreach ($activities as $activity) {
                $entries->push([
                    'at' => CarbonImmutable::parse($activity->created_at),
                    'kind' => 'activity',
                    'log' => $activity->log_name,
                    'label' => $activity->description,
                    'detail' => $activity->subject_type ? class_basename($activity->subject_type).' '.$activity->subject_id : null,
                    'product_id' => null,
                    'version_id' => null,
                    'status' => null,
                    'changes' => ActivityExplorer::changes($activity),
                ]);
            }
        }

        if ($source !== 'activity') {
            $requests = DB::table('request_logs')
                ->where('user_id', $this->user->id)
                ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
                ->orderByDesc('id')
                ->limit(self::TIMELINE_LIMIT)
                ->get();

            foreach ($requests as $request) {
                $entries->push([
                    'at' => CarbonImmutable::parse($request->created_at),
                    'kind' => 'request',
                    'log' => null,
                    'label' => $request->method.' '.($request->route ?? $request->path),
                    'detail' => $request->path,
                    'product_id' => $request->product_id,
                    'version_id' => $request->version_id,
                    'status' => (int) $request->status,
                    'changes' => [],
                ]);
            }
        }

        return $entries->sortByDesc(fn ($entry) => $entry['at']->getTimestamp())->take(self::TIMELINE_LIMIT)->values();
    }
}
