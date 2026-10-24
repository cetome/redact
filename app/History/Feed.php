<?php

namespace App\History;

use App\Models\Organisation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Activitylog\Models\Activity;

/**
 * The customer history of one product (every version) or of one organisation: the activity
 * entries stamped with it (App\History\ActivityScope), described by App\History\Describer, filtered,
 * folded ("+12 more" for a bulk of similar entries) and paged newest first with a cursor
 * (`before` = an activity id), so a bulk import never fills pages of identical lines.
 */
final class Feed
{
    public const PER_PAGE = 50;

    /** Entries read at most for one page (search and kind filters run on the described lines). */
    private const SCAN = 5000;

    /** Similar entries this close together fold into one line. */
    private const FOLD_SECONDS = 600;

    /** Log names that can hold each kind (a first, coarse filter in SQL). */
    private const KIND_LOGS = [
        'product' => ['version', 'product'],
        'scoping' => ['scoping', 'version'],
        'risks' => ['risk'],
        'inventory' => ['inventory', 'sbom'],
        'trees' => ['evaluation'],
        'einfo' => ['einfo'],
        'conformity' => ['scoping', 'version'],
        'review' => ['review', 'assessment_case', 'version'],
        'team' => ['team'],
        'documents' => ['document'],
        'exports' => ['report', 'import', 'history', 'version'],
        'ai' => ['ai'],
        'organisation' => ['organisation'],
        'billing' => ['billing', 'organisation'],
        'support' => ['admin'],
    ];

    private function __construct(
        public readonly Describer $describer,
        private readonly ?Product $product,
        private readonly ?Organisation $organisation,
    ) {}

    public static function forProduct(Product $product, User $viewer, bool $showEmails): self
    {
        $describer = (new Describer($viewer, $showEmails))
            ->versions(Describer::versionLabels([$product->id]))
            ->products([$product->id => $product->name]);

        return new self($describer, $product, null);
    }

    public static function forOrganisation(Organisation $organisation, User $viewer, bool $showEmails): self
    {
        $names = Describer::productNames($organisation);
        $describer = (new Describer($viewer, $showEmails))
            ->products($names)
            ->versions(Describer::versionLabels(array_keys($names)));

        return new self($describer, null, $organisation);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'version' => ['nullable', 'string', 'size:26'],
            'person' => ['nullable', 'string', 'max:20', 'regex:/^(\d+|support|system)$/'],
            'kind' => ['nullable', Rule::in($this->kinds())],
            'products' => ['nullable', 'string', 'max:26'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:120'],
            'before' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return list<string> */
    public function kinds(): array
    {
        return $this->product
            ? Describer::PRODUCT_KINDS
            : array_values(array_unique([...Describer::ORGANISATION_KINDS, ...Describer::PRODUCT_KINDS]));
    }

    /**
     * Every entry of the product or organisation a customer may see, before the filters.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Activity>
     */
    public function base(array $filters = []): Builder
    {
        $query = Activity::query()
            ->whereNotIn('log_name', Describer::HIDDEN_LOGS)
            ->where(fn (Builder $q) => $q->where('log_name', '!=', 'admin')->orWhereIn('description', Describer::SUPPORT_EVENTS));

        if ($this->product) {
            return $query->where('product_id', $this->product->id);
        }

        $query->where('organisation_id', $this->organisation?->id);
        $scope = (string) ($filters['products'] ?? '');

        return match (true) {
            $scope === 'all' => $query,
            $scope !== '' => $query->where('product_id', $scope),
            // The organisation itself, plus products created, deleted or handed over, and support access.
            default => $query->where(fn (Builder $q) => $q->whereNull('product_id')
                ->orWhere(fn (Builder $p) => $p->where('log_name', 'product')->where(fn (Builder $e) => $e->whereIn('event', ['created', 'deleted'])
                    ->orWhere('attribute_changes', 'like', '%"owner_id"%')))
                ->orWhere(fn (Builder $s) => $s->where('log_name', 'admin')->where('description', 'support access'))),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Activity>
     */
    public function query(array $filters): Builder
    {
        $query = $this->base($filters);

        if (! empty($filters['version'])) {
            $query->where('product_version_id', $filters['version']);
        }
        if (! empty($filters['kind'])) {
            $query->whereIn('log_name', self::KIND_LOGS[$filters['kind']] ?? ['-']);
        }
        $person = (string) ($filters['person'] ?? '');
        if ($person === 'support') {
            $query->where('log_name', 'admin');
        } elseif ($person === 'system') {
            $query->whereNull('causer_id')->where('log_name', '!=', 'admin');
        } elseif ($person !== '') {
            $query->where('causer_type', (new User)->getMorphClass())->where('causer_id', $person)->where('log_name', '!=', 'admin');
        }
        ChangeSet::period($query, $filters['from'] ?? null, $filters['to'] ?? null, $this->describer->timezone());

        return $query;
    }

    /**
     * One page of lines, newest first, and the cursor of the next (older) page.
     *
     * @param  array<string, mixed>  $filters
     * @return array{entries: list<Entry>, next: ?int}
     */
    public function page(array $filters): array
    {
        $query = $this->query($filters)->with('subject');
        $cursor = ! empty($filters['before']) ? (int) $filters['before'] : PHP_INT_MAX;
        $entries = [];
        $scanned = 0;
        $last = null;
        $lastAt = null;

        while (true) {
            $batch = (clone $query)->where('id', '<', $cursor)->orderByDesc('id')->limit(500)->get();
            $this->describer->prime($batch);

            foreach ($batch as $activity) {
                /** @var Activity $activity */
                $entry = $this->matching($activity, $filters);
                $scanned++;
                if ($entry !== null) {
                    if ($last !== null && $entry->groupKey !== null && $entry->groupKey === $last->groupKey
                        && $lastAt !== null && $lastAt->getTimestamp() - $entry->at->getTimestamp() <= self::FOLD_SECONDS) {
                        if (count($last->others) < 200) {
                            $last->others[] = $entry->text;
                        }
                        $lastAt = $entry->at;
                    } elseif (count($entries) === self::PER_PAGE) {
                        return ['entries' => $entries, 'next' => (int) $activity->id + 1];
                    } else {
                        $entries[] = $last = $entry;
                        $lastAt = $entry->at;
                    }
                }
                $cursor = (int) $activity->id;
                if ($scanned >= self::SCAN) {
                    return ['entries' => $entries, 'next' => $cursor];
                }
            }

            if ($batch->count() < 500) {
                return ['entries' => $entries, 'next' => null];
            }
        }
    }

    /** @param  array<string, mixed>  $filters */
    private function matching(Activity $activity, array $filters): ?Entry
    {
        $entry = $this->describer->describe($activity);
        if ($entry === null) {
            return null;
        }
        if (! empty($filters['kind']) && $entry->kind !== $filters['kind']) {
            return null;
        }
        if ($this->organisation && empty($filters['products']) && ! in_array($entry->kind, Describer::ORGANISATION_KINDS, true)) {
            return null;
        }
        if (! empty($filters['q'])) {
            $haystack = Str::lower($entry->text.' '.$entry->actor['name'].' '.($entry->actor['email'] ?? '').' '
                .collect($entry->details)->map(fn ($d) => $d['field'].' '.$d['old'].' '.$d['new'].' '.collect($d['diff'] ?? [])->pluck('text')->implode(' '))->implode(' '));
            if (! Str::contains($haystack, Str::lower((string) $filters['q']))) {
                return null;
            }
        }

        return $entry;
    }

    /** @return list<string> */
    public function csvHeader(bool $withEmails): array
    {
        return array_values(array_filter([
            __('history.csv.date'), __('history.csv.person'), $withEmails ? __('history.csv.email') : null,
            $this->product ? __('history.csv.version') : __('history.csv.product'),
            __('history.csv.kind'), __('history.csv.event'), __('history.csv.details'),
        ], fn ($v) => $v !== null));
    }

    /**
     * Every matching entry, one row each (no folding), for the CSV export.
     *
     * @param  array<string, mixed>  $filters
     * @return \Generator<int, list<mixed>>
     */
    public function csvRows(array $filters, bool $withEmails): \Generator
    {
        foreach ($this->query($filters)->with('subject')->lazyByIdDesc(500)->chunk(500) as $chunk) {
            $this->describer->prime($chunk);
            foreach ($chunk as $activity) {
                /** @var Activity $activity */
                $entry = $this->matching($activity, $filters);
                if ($entry !== null) {
                    yield $this->csvRow($entry, $withEmails);
                }
            }
        }
    }

    /** @return list<mixed> */
    private function csvRow(Entry $entry, bool $withEmails): array
    {
        $row = [$entry->at->format('Y-m-d H:i'), $entry->actor['name']];
        if ($withEmails) {
            $row[] = $entry->actor['email'];
        }
        $row[] = $this->product ? $entry->versionLabel : $entry->productName;
        $row[] = __('history.kinds.'.$entry->kind);
        $row[] = $entry->text.($entry->others !== [] ? ' (+'.count($entry->others).')' : '');
        // Word diffs as [-removed-] {+added+}, other fields old -> new.
        $row[] = collect($entry->details)->map(fn ($d) => $d['field'].': '.($d['diff'] !== null
            ? collect($d['diff'])->map(fn ($s) => match ($s['type']) {
                'del' => '[-'.$s['text'].'-]',
                'ins' => '{+'.$s['text'].'+}',
                default => $s['text'],
            })->implode('')
            : ($d['old'] !== null ? $d['old'].' -> ' : '').$d['new']))->implode(' | ');

        return $row;
    }

    /**
     * The people who appear in the history (filter), cetome support and REDact itself last.
     *
     * @return array<string, string> value => label
     */
    public function people(): array
    {
        $base = $this->base(['products' => 'all']);
        $ids = (clone $base)->where('log_name', '!=', 'admin')->whereNotNull('causer_id')
            ->where('causer_type', (new User)->getMorphClass())->distinct()->pluck('causer_id')->all();
        // cetome staff outside the organisation only act through the console: they are "cetome support".
        $organisation = $this->organisation ?? $this->product?->organisation;
        $people = User::query()->whereIn('id', $ids)->with('roles')->orderBy('name')->get()
            ->reject(fn (User $user) => $user->isStaff() && ! ($organisation && $organisation->hasMember($user)) && $this->product?->owner_id !== $user->id
                && ! ($this->product && $this->product->members()->where('user_id', $user->id)->exists()))
            ->mapWithKeys(fn (User $user) => [(string) $user->id => (string) $user->name])->all();

        if ((clone $base)->where('log_name', 'admin')->exists()) {
            $people['support'] = (string) __('history.support');
        }
        if ((clone $base)->whereNull('causer_id')->where('log_name', '!=', 'admin')->exists()) {
            $people['system'] = (string) __('history.system');
        }

        return $people;
    }
}
