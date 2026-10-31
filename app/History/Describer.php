<?php

namespace App\History;

use App\Content\ContentPack;
use App\Content\Text;
use App\Enums\ConformityRoute;
use App\Enums\ProductRole;
use App\Enums\Verdict;
use App\Enums\VersionStatus;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\User;
use App\Support\FileSize;
use App\Support\Locales;
use App\Support\WordDiff;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * Turns activity entries into the sentences of the customer histories (lang/<locale>/history.php):
 * "Changed the verdict of ACM-1 on AS-01 · Door key: NOT APPLICABLE → PASS", with the fields it
 * changed (word diff for texts, App\Support\WordDiff). Entries a customer never sees (the console's
 * own work, duplicates of an explicit entry) give null.
 */
final class Describer
{
    /** Kind => icon. The kinds are the filter of the history pages. */
    public const KINDS = [
        'product' => 'package',
        'scoping' => 'target',
        'risks' => 'shield-alert',
        'inventory' => 'boxes',
        'trees' => 'git-fork',
        'einfo' => 'file-text',
        'conformity' => 'badge-check',
        'review' => 'clipboard-check',
        'team' => 'users',
        'documents' => 'paperclip',
        'exports' => 'download',
        'ai' => 'sparkles',
        'organisation' => 'building-2',
        'billing' => 'credit-card',
        'support' => 'life-buoy',
    ];

    /** Kinds of a product's history, and of an organisation's (products: created, deleted, transferred). */
    public const PRODUCT_KINDS = ['product', 'scoping', 'risks', 'inventory', 'trees', 'einfo', 'conformity', 'review', 'team', 'documents', 'exports', 'ai', 'support'];

    public const ORGANISATION_KINDS = ['team', 'organisation', 'billing', 'product', 'support'];

    /** Logs a customer never sees (sign-ins, account, usage analytics, sandboxes). */
    public const HIDDEN_LOGS = ['security', 'account', 'usage', 'guest'];

    /** The console's entries a customer sees: read-only support access and plan changes. */
    public const SUPPORT_EVENTS = ['support access', 'plan changed'];

    /** Long texts: shown as a word diff. */
    private const TEXTS = ['justification', 'text', 'description', 'risk', 'remediation', 'implementation', 'evidence', 'comment',
        'intended_use', 'notes', 'address', 'authorised_rep_address'];

    /** Fields never listed (ids, positions, bookkeeping, or already in the sentence). */
    private const QUIET = ['id', 'product_version_id', 'product_id', 'organisation_id', 'user_id', 'position', 'updated_by', 'recorded_by',
        'document_id', 'inventory_item_id', 'from_item_id', 'to_item_id', 'evaluation_id', 'created_at', 'updated_at', 'list', 'standard',
        'requirement', 'question', 'einfo', 'feature', 'target_type', 'target_key', 'field', 'relation', 'mime', 'case_key', 'granted_by', 'assessment_case_result_id'];

    /** @var array<int, array{name: string, email: ?string}> */
    private array $users = [];

    /** @var array<string, string> version id => label */
    private array $versionLabels = [];

    /** @var array<string, string> product id => name */
    private array $productNames = [];

    /** @var array<string, array{label: string, step: string}>|null */
    private ?array $questions = null;

    /** @var array<string, string>|null */
    private ?array $lists = null;

    /** @var array<string, array{label: string, options: array<string, string>}>|null */
    private ?array $fields = null;

    private string $timezone;

    public function __construct(private readonly User $viewer, private readonly bool $showEmails)
    {
        $this->timezone = $viewer->timezone ?: (string) config('app.timezone');
    }

    /** @param  array<string, string>  $labels */
    public function versions(array $labels): self
    {
        $this->versionLabels = $labels + $this->versionLabels;

        return $this;
    }

    /** @param  array<string, string>  $names */
    public function products(array $names): self
    {
        $this->productNames = $names + $this->productNames;

        return $this;
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    /**
     * Loads the people a batch of entries names (causers, owners, members), in one query.
     *
     * @param  iterable<Activity>  $activities
     */
    public function prime(iterable $activities): void
    {
        $ids = [];
        foreach ($activities as $activity) {
            if ($activity->causer_id !== null && $activity->causer_type === (new User)->getMorphClass()) {
                $ids[] = (int) $activity->causer_id;
            }
            ['old' => $old, 'new' => $new] = ChangeSet::raw($activity);
            foreach ([$old['owner_id'] ?? null, $new['owner_id'] ?? null, $new['user_id'] ?? null, $old['user_id'] ?? null, $activity->getProperty('user_id')] as $id) {
                if (is_numeric($id)) {
                    $ids[] = (int) $id;
                }
            }
        }
        $ids = array_values(array_diff(array_unique($ids), array_keys($this->users)));
        if ($ids !== []) {
            foreach (DB::table('users')->whereIn('id', $ids)->get(['id', 'name', 'email']) as $row) {
                $this->users[(int) $row->id] = ['name' => (string) $row->name, 'email' => $row->email];
            }
        }
    }

    public function describe(Activity $activity): ?Entry
    {
        if (in_array($activity->log_name, self::HIDDEN_LOGS, true)) {
            return null;
        }
        if ($activity->log_name === 'admin' && ! in_array($activity->description, self::SUPPORT_EVENTS, true)) {
            return null;
        }

        $line = $this->classify($activity);
        if ($line === null) {
            return null;
        }
        [$kind, $key, $params, $details, $groupable] = $line;

        $html = $this->sentence($key, array_map(fn ($v) => '<b class="a-hist-ref">'.e((string) $v).'</b>', $params));
        $text = $this->sentence($key, array_map(fn ($v) => (string) $v, $params));

        $versionId = $activity->getAttribute('product_version_id');
        $productId = $activity->getAttribute('product_id');

        return new Entry(
            id: (int) $activity->id,
            at: CarbonImmutable::parse($activity->created_at)->setTimezone($this->timezone),
            kind: $kind,
            html: $html,
            text: $text,
            actor: $this->actor($activity),
            versionId: $versionId,
            versionLabel: $versionId ? ($this->versionLabels[$versionId] ?? null) : null,
            productId: $productId,
            productName: $productId ? ($this->productNames[$productId] ?? null) : null,
            details: $details,
            groupKey: $groupable ? implode('|', [$activity->causer_id, $activity->log_name, $activity->subject_type, $activity->event, $versionId, $key]) : null,
        );
    }

    /**
     * @return array{kind: 'person'|'support'|'system', name: string, email: ?string, initials: string}
     */
    public function actor(Activity $activity): array
    {
        if ($activity->log_name === 'admin') {
            return ['kind' => 'support', 'name' => __('history.support'), 'email' => null, 'initials' => 'CS'];
        }
        $user = $activity->causer_id !== null ? ($this->users[(int) $activity->causer_id] ?? null) : null;
        if ($user === null) {
            return ['kind' => 'system', 'name' => __('history.system'), 'email' => null, 'initials' => 'R'];
        }

        return [
            'kind' => 'person',
            'name' => $user['name'],
            'email' => $this->showEmails ? $user['email'] : null,
            'initials' => self::initials($user['name']),
        ];
    }

    public static function initials(string $name): string
    {
        return collect(preg_split('/\s+/', trim($name)) ?: [])->filter()->take(2)
            ->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') ?: '?';
    }

    /** @param  array<string, string>  $params */
    private function sentence(string $key, array $params): string
    {
        $line = 'history.events.'.$key;

        return Lang::has($line) ? (string) __($line, $params) : (string) ($params['what'] ?? $key);
    }

    // ---- Classification ---------------------------------------------------------------------------

    /**
     * @return array{0: string, 1: string, 2: array<string, string>, 3: list<array{field: string, old: ?string, new: ?string, diff: ?list<array{type: string, text: string}>}>, 4: bool}|null
     *                                                                                                                                                                                        kind, sentence key, parameters, details, groupable
     */
    private function classify(Activity $a): ?array
    {
        $event = (string) $a->event;
        $description = (string) $a->description;
        $type = class_basename((string) $a->subject_type);
        ['old' => $old, 'new' => $new] = ChangeSet::raw($a);
        $changed = array_keys($event === 'updated' ? $new : []);
        $bulk = in_array($event, ['created', 'deleted'], true);

        return match ($a->log_name) {
            'evaluation' => $this->evaluation($a, $event, $description, $old, $new, $changed),
            'assessment_case' => $this->assessmentCase($a, $event, $old, $new, $changed),
            'einfo' => $description === 'recheck_confirmed'
                ? ['einfo', 'einfo_recheck_confirmed', ['code' => $this->code($this->v($a, 'einfo')), 'item' => $this->item($a)], [], false]
                : $this->model($a, 'einfo', 'einfo', ['code' => $this->code($this->v($a, 'einfo')), 'item' => $this->item($a)], $old, $new),
            'risk' => $this->model($a, 'risks', 'risk', ['name' => (string) $this->v($a, 'threat')], $old, $new, ['threat']),
            'scoping' => $this->scoping($a, $event, $old, $new),
            'inventory' => $this->inventory($a, $event, $description, $type, $old, $new),
            'sbom' => $this->sbom($a, $description),
            'version' => $this->version($a, $event, $description, $type, $old, $new, $changed),
            'product' => $this->product($a, $event, $description, $old, $new, $changed),
            'team' => $this->team($a, $event, $description, $type, $old, $new, $changed),
            'review' => $this->review($a, $event, $type, $old, $new),
            'document' => $this->model($a, 'documents', 'document', ['name' => (string) $this->v($a, 'original_name'), 'size' => self::bytes($this->v($a, 'size'))], $old, $new, ['original_name', 'size']),
            'report' => ['exports', ...$this->export($a)],
            'import' => ['exports', 'workbook_imported', ['files' => $this->names($a->getProperty('files'))], [], false],
            'history' => ['exports', 'history_exported', [], [], false],
            'ai' => $this->ai($a, $event, $description, $changed),
            'admin' => $this->admin($a, $description),
            'organisation' => $this->organisation($a, $event, $old, $new, $changed),
            'billing' => ['billing', 'billing.'.Str::slug($description, '_'), ['what' => Str::ucfirst($description)], [], false],
            default => [$a->getAttribute('product_id') ? 'product' : 'organisation', 'other', ['what' => Str::ucfirst(str_replace('_', ' ', $description))], $this->details($old, $new, $event), $bulk],
        };
    }

    /**
     * A logged model: created / edited / removed, with its changed fields.
     *
     * @param  array<string, string|null>  $params
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  list<string>  $inSentence
     * @return array{0: string, 1: string, 2: array<string, string>, 3: list<array<string, mixed>>, 4: bool}
     */
    private function model(Activity $a, string $kind, string $prefix, array $params, array $old, array $new, array $inSentence = []): array
    {
        $event = in_array($a->event, ['created', 'updated', 'deleted'], true) ? (string) $a->event : 'updated';
        $quiet = $event === 'updated' ? [] : $inSentence;

        return [$kind, $prefix.'_'.$event, $this->clean($params), $this->details($old, $new, $event, $quiet), $event !== 'updated'];
    }

    /** @param  array<string, string|null>  $params @return array<string, string> */
    private function clean(array $params): array
    {
        return array_map(fn ($v) => $v === null || $v === '' ? '—' : (string) $v, $params);
    }

    /** @return array{0: string, 1: string, 2: array<string, string>, 3: list<array<string, mixed>>, 4: bool} */
    private function evaluation(Activity $a, string $event, string $description, array $old, array $new, array $changed): array
    {
        $target = $this->target($a);

        return match (true) {
            $description === 'shared' => ['trees', 'shared', ['target' => $target, 'with' => (string) $a->getProperty('shared_with')], [], false],
            $description === 'untied' => ['trees', 'untied', ['target' => $target], [], false],
            $description === 'recheck_confirmed' => ['trees', 'recheck_confirmed', ['target' => $target], [], false],
            $description === 'tied' => ['trees', 'tied', ['target' => $target, 'with' => (string) $a->getProperty('shared_with')], [], false],
            $event === 'created' => isset($new['verdict'])
                ? ['trees', 'evaluated', ['target' => $target, 'verdict' => $this->verdict($new['verdict'])], $this->details([], $new, 'created', ['verdict']), true]
                : ['trees', 'evaluation_started', ['target' => $target], [], true],
            $event === 'deleted' => ['trees', 'evaluation_deleted', ['target' => $target], [], true],
            in_array('verdict', $changed, true) => ['trees', 'verdict_changed', ['target' => $target, 'old' => $this->verdict($old['verdict'] ?? null), 'new' => $this->verdict($new['verdict'] ?? null)],
                $this->details($old, $new, 'updated', ['verdict']), false],
            $changed === ['decision_node'] => ['trees', 'path_changed', ['target' => $target], $this->details($old, $new, 'updated'), false],
            default => ['trees', 'evaluation_updated', ['target' => $target], $this->details($old, $new, 'updated'), false],
        };
    }

    /** @return array{0: string, 1: string, 2: array<string, string>, 3: list<array<string, mixed>>, 4: bool} */
    private function assessmentCase(Activity $a, string $event, array $old, array $new, array $changed): array
    {
        $case = (string) $this->v($a, 'case_key');
        $params = [
            'case' => Lang::has('assessment.cases.'.$case) ? (string) __('assessment.cases.'.$case) : $case,
            'requirement' => (string) $this->v($a, 'requirement'),
        ];

        return match (true) {
            $event === 'created' => ['review', 'case_created', $params + ['verdict' => $this->verdict($new['verdict'] ?? null)], $this->details([], $new, 'created', ['verdict']), false],
            $event === 'deleted' => ['review', 'case_deleted', $params, [], false],
            in_array('verdict', $changed, true) => ['review', 'case_verdict', $params + ['old' => $this->verdict($old['verdict'] ?? null), 'new' => $this->verdict($new['verdict'] ?? null)],
                $this->details($old, $new, 'updated', ['verdict']), false],
            default => ['review', 'case_updated', $params, $this->details($old, $new, 'updated'), false],
        };
    }

    /** @return array{0: string, 1: string, 2: array<string, string>, 3: list<array<string, mixed>>, 4: bool} */
    private function scoping(Activity $a, string $event, array $old, array $new): array
    {
        $key = (string) $this->v($a, 'question');
        $question = $this->questions()[$key] ?? ['label' => $key, 'step' => 'scoping'];
        $kind = $question['step'] === 'conformity' ? 'conformity' : 'scoping';
        $params = ['question' => Str::limit($question['label'], 120)];

        return match ($event) {
            'created' => [$kind, 'answered', $params + ['value' => $this->value('value', $new['value'] ?? null)], [], true],
            'deleted' => [$kind, 'answer_deleted', $params, [], true],
            default => [$kind, 'answer_changed', $params + ['old' => $this->value('value', $old['value'] ?? null), 'new' => $this->value('value', $new['value'] ?? null)], [], false],
        };
    }

    /** @return array{0: string, 1: string, 2: array<string, string>, 3: list<array<string, mixed>>, 4: bool} */
    private function inventory(Activity $a, string $event, string $description, string $type, array $old, array $new): array
    {
        $list = fn (?string $key) => $key ? ($this->lists()[$key] ?? $key) : '—';

        return match (true) {
            $description === 'marked none' => ['inventory', 'marked_none', ['list' => $list($a->getProperty('list'))], [], false],
            $description === 'none undone' => ['inventory', 'none_undone', ['list' => $list($a->getProperty('list'))], [], false],
            $description === 'none cleared' => ['inventory', 'none_cleared', ['list' => $list($a->getProperty('list'))], [], false],
            $description === 'prefilled' => ['inventory', 'prefilled', ['count' => (string) array_sum(array_map('intval', (array) $a->getProperty('added', [])))], [], false],
            $description === 'grouped' => ['inventory', 'grouped', ['item' => $this->item($a)], [], false],
            $description === 'ungrouped' => ['inventory', 'ungrouped', ['item' => (string) ($a->getProperty('group') ?? $this->item($a))], [], false],
            $type === 'InventoryLink' => [
                'inventory', $event === 'deleted' ? 'link_deleted' : 'link_created',
                ['from' => (string) ($this->ref($a, 'from') ?? ActivityScope::itemLabel($this->v($a, 'from_item_id')) ?? '—'),
                    'to' => (string) ($this->ref($a, 'to') ?? ActivityScope::itemLabel($this->v($a, 'to_item_id')) ?? '—')],
                [], true,
            ],
            $type === 'InventoryItem' => $this->model($a, 'inventory', 'item', ['item' => $this->item($a), 'list' => $list($this->v($a, 'list'))], $old, $new, ['code', 'name']),
            default => ['inventory', 'other', ['what' => Str::ucfirst($description)], [], false],
        };
    }

    /** @return array{0: string, 1: string, 2: array<string, string>, 3: list<array<string, mixed>>, 4: bool} */
    private function sbom(Activity $a, string $description): array
    {
        $findings = implode(', ', array_slice(array_map('strval', (array) $a->getProperty('findings', [])), 0, 8));

        return match ($description) {
            'sbom uploaded' => ['inventory', 'sbom_uploaded', ['files' => $this->names($a->getProperty('files'))], [], false],
            'sbom imported' => ['inventory', 'sbom_imported', [], [], false],
            'vex uploaded' => ['inventory', 'vex_uploaded', ['files' => $this->names($a->getProperty('files'))], [], false],
            'vex imported' => ['inventory', 'vex_imported', ['count' => (string) (int) $a->getProperty('recorded', 0)], [], false],
            'vulnerability check started' => ['inventory', 'vulnerability_check_started', [], [], false],
            'vulnerability check done' => ['inventory', 'vulnerability_check_done', ['count' => (string) (int) $a->getProperty('found', 0)], [], false],
            'vulnerabilities added' => ['inventory', 'vulnerabilities_added', ['list' => $findings], [], false],
            'vulnerabilities dismissed' => ['inventory', 'vulnerabilities_dismissed', ['list' => $findings],
                $this->details([], ['reason' => $a->getProperty('reason')], 'created'), false],
            'vulnerabilities reopened' => ['inventory', 'vulnerabilities_reopened', ['list' => $findings], [], false],
            'component identifiers updated' => ['inventory', 'component_identifiers', ['item' => $this->item($a)], [], false],
            default => ['inventory', 'other', ['what' => Str::ucfirst($description)], [], false],
        };
    }

    /** @return array{0: string, 1: string, 2: array<string, string>, 3: list<array<string, mixed>>, 4: bool} */
    private function version(Activity $a, string $event, string $description, string $type, array $old, array $new, array $changed): array
    {
        if ($type === 'ProductIdentifier') {
            return $this->model($a, 'product', 'identifier', ['value' => (string) $this->v($a, 'value')], $old, $new, ['value', 'kind']);
        }

        $label = (string) ($this->ref($a, 'label') ?? $this->v($a, 'label') ?? '');
        $status = in_array('status', $changed, true);

        return match (true) {
            $description === 'articles_confirmed' => ['scoping', 'articles_confirmed', [], [], false],
            $description === 'change_decided' => ['review', 'change_decided.'.($a->getProperty('decision') === 'significant' ? 'significant' : 'not_significant'),
                ['label' => (string) $a->getProperty('previous')], $this->details([], ['justification' => $a->getProperty('justification')], 'created'), false],
            $description === 'demo loaded' => ['product', 'demo_loaded', [], [], false],
            $description === 'identifiers imported' => ['exports', 'identifiers_imported', [], [], false],
            $description === 'declaration saved' => ['conformity', 'declaration_saved', [], [], false],
            $description === 'certificate recorded' => ['conformity', 'certificate_recorded', [], [], false],
            $event === 'created' => ['product', 'version_created', ['label' => $label], [], false],
            $event === 'deleted' => ['product', 'version_deleted', ['label' => $label], [], false],
            $status => ['review', 'status.'.$this->statusReason($a, $old['status'] ?? null, $new['status'] ?? null),
                ['old' => $this->value('status', $old['status'] ?? null), 'new' => $this->value('status', $new['status'] ?? null)],
                $this->details($old, $new, 'updated', ['status']), false],
            array_intersect($changed, ['conformity_route', 'notified_body', 'notified_body_number']) !== [] && in_array('conformity_route', $changed, true) => ['conformity', 'route_changed', ['new' => $this->value('conformity_route', $new['conformity_route'] ?? null)], $this->details($old, $new, 'updated', ['conformity_route']), false],
            array_intersect($changed, ['notified_body', 'notified_body_number']) !== [] => ['conformity', 'notified_body', [], $this->details($old, $new, 'updated'), false],
            $event === 'updated' => ['product', 'version_updated', ['label' => $label], $this->details($old, $new, 'updated'), false],
            default => ['product', 'other', ['what' => Str::ucfirst(str_replace('_', ' ', $description))], [], false],
        };
    }

    private function statusReason(Activity $a, mixed $from, mixed $to): string
    {
        $reason = $a->getProperty('reason');
        if (is_string($reason) && Lang::has('history.events.status.'.$reason)) {
            return $reason;
        }

        return match (true) {
            $to === 'in_review' => 'submitted',
            $to === 'approved' => 'approved',
            $from === 'approved' && $to === 'draft' => 'reopened',
            $from === 'in_review' && $to === 'draft' => 'returned',
            default => 'changed',
        };
    }

    /** @return array{0: string, 1: string, 2: array<string, string>, 3: list<array<string, mixed>>, 4: bool} */
    private function product(Activity $a, string $event, string $description, array $old, array $new, array $changed): array
    {
        $name = (string) ($this->ref($a, 'name') ?? $this->v($a, 'name') ?? '');

        return match (true) {
            $description === 'picture changed' => ['product', 'picture_changed', [], [], false],
            $description === 'picture removed' => ['product', 'picture_removed', [], [], false],
            $event === 'created' => ['product', 'product_created', ['name' => $name], [], false],
            $event === 'deleted' => ['product', 'product_deleted', ['name' => $name], [], false],
            in_array('owner_id', $changed, true) => ['product', 'product_transferred', ['name' => $name, 'person' => $this->person($new['owner_id'] ?? null)],
                $this->details($old, $new, 'updated', ['owner_id']), false],
            $changed === ['name'] => ['product', 'product_renamed', ['old' => (string) ($old['name'] ?? ''), 'new' => (string) ($new['name'] ?? '')], [], false],
            default => ['product', 'product_updated', ['name' => $name], $this->details($old, $new, 'updated'), false],
        };
    }

    /** @return array{0: string, 1: string, 2: array<string, string>, 3: list<array<string, mixed>>, 4: bool} */
    private function team(Activity $a, string $event, string $description, string $type, array $old, array $new, array $changed): array
    {
        $email = $this->email($a->getProperty('email') ?? $this->ref($a, 'email') ?? $this->invitationEmail($a->getProperty('invitation_id')));

        if ($type === 'ProductMember') {
            $person = (string) ($this->ref($a, 'person') ?? $this->person($new['user_id'] ?? $old['user_id'] ?? null));

            return match (true) {
                $event === 'created' => ['team', 'member_created', ['person' => $person, 'role' => $this->value('role', $new['role'] ?? null)], [], false],
                $event === 'deleted' => ['team', 'member_deleted', ['person' => $person], [], false],
                in_array('role', $changed, true) => ['team', 'member_role', ['person' => $person, 'old' => $this->value('role', $old['role'] ?? null), 'new' => $this->value('role', $new['role'] ?? null)],
                    $this->details($old, $new, 'updated', ['role']), false],
                default => ['team', 'member_updated', ['person' => $person], $this->details($old, $new, 'updated'), false],
            };
        }

        $role = fn (?string $r) => $r ? (Lang::has('orgteam.role.'.$r) ? (string) __('orgteam.role.'.$r) : $r) : '—';

        return match ($description) {
            'invited' => ['team', 'invited', ['email' => $email, 'role' => $this->value('role', $a->getProperty('role'))], [], false],
            'invitation declined' => ['team', 'invitation_declined', [], [], false],
            'invitation revoked' => ['team', $a->subject_type === Product::class ? 'invitation_revoked' : 'org_invitation_revoked', ['email' => $email], [], false],
            'organisation invitation sent' => ['team', 'org_invited', ['email' => $email, 'role' => $role($a->getProperty('organisation_role'))], [], false],
            'organisation joined' => ['team', 'org_joined', ['role' => $role($a->getProperty('organisation_role'))], [], false],
            'organisation role changed' => ['team', 'org_role', ['person' => $this->person($a->getProperty('user_id')), 'old' => $role($a->getProperty('old')), 'new' => $role($a->getProperty('new'))], [], false],
            'removed from the organisation' => ['team', 'org_removed', ['person' => $this->person($a->getProperty('user_id'))], [], false],
            default => ['team', 'other', ['what' => Str::ucfirst($description)], [], false],
        };
    }

    /** @return array{0: string, 1: string, 2: array<string, string>, 3: list<array<string, mixed>>, 4: bool} */
    private function review(Activity $a, string $event, string $type, array $old, array $new): array
    {
        if ($type === 'VersionReview') {
            $verdict = (string) ($new['verdict'] ?? $old['verdict'] ?? '');

            return ['review', $verdict === 'approved' ? 'decision_approved' : 'decision_changes', [], $this->details([], array_intersect_key($new, ['comment' => true]), 'created'), false];
        }

        $verdict = fn ($v) => $v && Lang::has('review.verdicts.'.$v) ? (string) __('review.verdicts.'.$v) : (string) ($v ?? '—');
        // A review of an assessment unit result: "ACM-1 · Conceptual assessment".
        $case = $type === 'AssessmentCaseReview' ? (string) $this->v($a, 'case_key') : '';
        $target = $case !== ''
            ? ((string) ($this->v($a, 'requirement') ?? '—')).' · '.(Lang::has('assessment.cases.'.$case) ? (string) __('assessment.cases.'.$case) : $case)
            : $this->target($a);

        return match ($event) {
            'created' => ['review', 'reviewed', ['target' => $target, 'verdict' => $verdict($new['verdict'] ?? null)], $this->details([], $new, 'created', ['verdict']), false],
            'deleted' => ['review', 'review_deleted', ['target' => $target], [], true],
            default => ['review', 'review_updated', ['target' => $target, 'verdict' => $verdict($new['verdict'] ?? $old['verdict'] ?? null)], $this->details($old, $new, 'updated', ['verdict']), false],
        };
    }

    /** @return array{0: string, 1: array<string, string>, 2: list<array<string, mixed>>, 3: bool} */
    private function export(Activity $a): array
    {
        $kind = (string) $a->getProperty('kind');
        $format = Str::upper((string) $a->getProperty('format'));

        return match (true) {
            in_array($kind, ['assessment', 'trees', 'review'], true) => ['exported_report', ['what' => (string) __('reports.kinds.'.$kind.'.label'), 'format' => $format], [], false],
            $kind === 'declaration' => ['exported_declaration', ['format' => $format], [], false],
            $kind === 'technical-file' => ['exported_technical_file', [], [], false],
            $kind === 'workbooks' => ['exported_workbooks', [], [], false],
            str_starts_with($kind, 'workbook:') => ['exported_workbook', ['what' => Str::headline(Str::after($kind, 'workbook:'))], [], false],
            default => ['exported_other', ['what' => $kind ?: '—', 'format' => $format], [], false],
        };
    }

    /** @return array{0: string, 1: string, 2: array<string, string>, 3: list<array<string, mixed>>, 4: bool}|null */
    private function ai(Activity $a, string $event, string $description, array $changed): ?array
    {
        $feature = (string) ($a->getProperty('feature') ?? $this->v($a, 'feature'));
        $params = ['feature' => Lang::has('ai.features.'.$feature) ? (string) __('ai.features.'.$feature) : Str::headline($feature)];

        return match (true) {
            $description === 'suggestion accepted' => ['ai', 'ai_accepted', $params, [], false],
            $description === 'suggestion rejected' => ['ai', 'ai_rejected', $params, [], false],
            $event === 'created' => ['ai', 'ai_suggested', $params, [], true],
            // Status changes of a suggestion: the explicit accepted / rejected entries tell them.
            default => null,
        };
    }

    /** @return array{0: string, 1: string, 2: array<string, string>, 3: list<array<string, mixed>>, 4: bool} */
    private function admin(Activity $a, string $description): array
    {
        if ($description === 'support access') {
            $name = (string) ($this->ref($a, 'name') ?? ($this->productNames[(string) $a->getAttribute('product_id')] ?? null) ?? '—');

            return ['support', 'support_access', ['name' => $name], [], false];
        }

        $old = (array) $a->getProperty('old', []);
        $new = (array) $a->getProperty('attributes', []);

        $limits = ($old['limits'] ?? null) == ($new['limits'] ?? null) ? [] : $this->details(['limits' => $old['limits'] ?? null], ['limits' => $new['limits'] ?? null], 'updated');

        $key = ($old['plan'] ?? null) === ($new['plan'] ?? null) ? 'support_limits' : 'support_plan';

        return ['support', $key, ['old' => $this->value('plan', $old['plan'] ?? null), 'new' => $this->value('plan', $new['plan'] ?? null)], $limits, false];
    }

    /** @return array{0: string, 1: string, 2: array<string, string>, 3: list<array<string, mixed>>, 4: bool}|null */
    private function organisation(Activity $a, string $event, array $old, array $new, array $changed): ?array
    {
        $name = (string) ($this->ref($a, 'name') ?? $this->v($a, 'name') ?? '');
        $plan = array_intersect($changed, ['plan', 'limits']);

        return match (true) {
            $event === 'created' => ['organisation', 'organisation_created', ['name' => $name], [], false],
            $event === 'deleted' => ['organisation', 'organisation_deleted', ['name' => $name], [], false],
            // A staff plan change has its own console entry ("cetome support changed the plan").
            $plan !== [] && array_diff($changed, ['plan', 'limits']) === [] && $this->causedByStaff($a) => null,
            $plan !== [] && array_diff($changed, ['plan', 'limits']) === [] => ['billing', 'organisation_plan',
                ['old' => $this->value('plan', $old['plan'] ?? null), 'new' => $this->value('plan', $new['plan'] ?? null)], $this->details($old, $new, 'updated', ['plan']), false],
            $changed === ['ai_enabled'] => ['organisation', ($new['ai_enabled'] ?? false) ? 'ai_on' : 'ai_off', [], [], false],
            $changed === ['name'] => ['organisation', 'organisation_renamed', ['old' => (string) ($old['name'] ?? ''), 'new' => (string) ($new['name'] ?? '')], [], false],
            default => ['organisation', 'legal_updated', [], $this->details($old, $new, 'updated'), false],
        };
    }

    private function causedByStaff(Activity $a): bool
    {
        if ($a->causer_id === null) {
            return false;
        }
        $user = $a->causer instanceof User ? $a->causer : User::query()->find($a->causer_id);

        return $user instanceof User && $user->isStaff();
    }

    // ---- Values ------------------------------------------------------------------------------------

    /** A value of the entry: its reference, then the new and old attributes, then the subject as it is now. */
    private function v(Activity $a, string $key): mixed
    {
        $ref = (array) $a->getProperty('ref', []);
        if (array_key_exists($key, $ref)) {
            return $ref[$key];
        }
        ['old' => $old, 'new' => $new] = ChangeSet::raw($a);
        if (array_key_exists($key, $new)) {
            return $new[$key];
        }
        if (array_key_exists($key, $old)) {
            return $old[$key];
        }
        $subject = $a->relationLoaded('subject') ? $a->getRelation('subject') : null;

        return $subject instanceof Model ? $subject->getAttribute($key) : null;
    }

    private function ref(Activity $a, string $key): ?string
    {
        $value = ((array) $a->getProperty('ref', []))[$key] ?? null;

        return is_scalar($value) && $value !== '' ? (string) $value : null;
    }

    private function item(Activity $a): string
    {
        $item = $this->ref($a, 'item');
        if ($item !== null) {
            return $item;
        }
        $code = $this->v($a, 'code');
        if (is_string($code) && $code !== '') {
            $name = $this->v($a, 'name');

            return is_string($name) && $name !== '' ? $code.' · '.$name : $code;
        }
        $id = $this->v($a, 'inventory_item_id');

        return ActivityScope::itemLabel(is_string($id) ? $id : null) ?? '—';
    }

    /** "ACM-1 on AS-01 · Door key", or "ACM-1" for the product itself. */
    private function target(Activity $a): string
    {
        $requirement = (string) ($this->v($a, 'requirement') ?? '—');
        $item = $this->ref($a, 'item') ?? ActivityScope::itemLabel(is_string($id = $this->v($a, 'inventory_item_id')) ? $id : null);

        return $item ? (string) __('history.target', ['requirement' => $requirement, 'item' => $item]) : $requirement;
    }

    private function code(mixed $code): string
    {
        return is_string($code) && $code !== '' ? $code : '—';
    }

    private function verdict(mixed $value): string
    {
        $verdict = $value instanceof Verdict ? $value : (is_string($value) ? Verdict::tryFrom($value) : null);

        return $verdict ? mb_strtoupper($verdict->label()) : (string) __('verdicts.none');
    }

    private function person(mixed $id): string
    {
        if (! is_numeric($id)) {
            return '—';
        }
        $user = $this->users[(int) $id] ?? null;
        if ($user === null) {
            $row = DB::table('users')->where('id', (int) $id)->first(['name', 'email']);
            $user = $row ? ['name' => (string) $row->name, 'email' => $row->email] : null;
            if ($user !== null) {
                $this->users[(int) $id] = $user;
            }
        }

        return $user['name'] ?? '—';
    }

    /**
     * E-mail addresses only for the people allowed to see them (owners, admins); the others
     * (contributors) read the person's name when the address has an account, else "someone".
     */
    private function email(mixed $email): string
    {
        if (! is_string($email) || $email === '') {
            return '—';
        }
        if ($this->showEmails) {
            return $email;
        }
        foreach ($this->users as $user) {
            if (is_string($user['email']) && strcasecmp($user['email'], $email) === 0) {
                return $user['name'];
            }
        }
        $name = DB::table('users')->where('email', mb_strtolower($email))->where('is_guest', false)->value('name');

        return is_string($name) && $name !== '' ? $name : (string) __('history.someone');
    }

    private function invitationEmail(mixed $id): ?string
    {
        return is_string($id) && $id !== '' ? DB::table('invitations')->where('id', $id)->value('email') : null;
    }

    private function names(mixed $files): string
    {
        $names = [];
        foreach ((array) $files as $file) {
            $name = is_array($file) ? ($file['name'] ?? null) : $file;
            if (is_scalar($name) && $name !== '') {
                $names[] = is_array($file) && isset($file['size']) ? $name.' ('.self::bytes($file['size']).')' : (string) $name;
            }
        }

        return $names === [] ? '—' : Str::limit(implode(', ', $names), 160);
    }

    public static function bytes(mixed $size): string
    {
        if (! is_numeric($size)) {
            return '—';
        }
        $size = (int) $size;

        return FileSize::format($size, $size >= 1048576 ? 1 : 0);
    }

    /**
     * Changed fields as rows: texts as a word diff, other values old → new. A created entry lists
     * its texts only (what was written), a removed one nothing.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  list<string>  $skip
     * @return list<array{field: string, old: ?string, new: ?string, diff: ?list<array{type: string, text: string}>}>
     */
    private function details(array $old, array $new, string $event, array $skip = []): array
    {
        if ($event === 'deleted') {
            return [];
        }
        $rows = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $field) {
            $field = (string) $field;
            if (in_array($field, self::QUIET, true) || in_array($field, $skip, true)) {
                continue;
            }
            $isText = in_array($field, self::TEXTS, true) || in_array($field, ['reason'], true);
            if ($event === 'created' && ! $isText) {
                continue;
            }
            $before = $old[$field] ?? null;
            $after = $new[$field] ?? null;
            if ($event === 'created' && ($after === null || $after === '')) {
                continue;
            }
            if ($isText && is_string($after ?? '') && is_string($before ?? '')) {
                $rows[] = [
                    'field' => $this->fieldLabel($field),
                    'old' => null,
                    'new' => $event === 'created' ? Str::limit((string) $after, 800) : null,
                    'diff' => $event === 'created' ? null : WordDiff::segments((string) $before, (string) $after),
                ];

                continue;
            }
            $rows[] = ['field' => $this->fieldLabel($field), 'old' => $this->value($field, $before), 'new' => $this->value($field, $after), 'diff' => null];
        }

        return $rows;
    }

    private function fieldLabel(string $field): string
    {
        return Lang::has('history.fields.'.$field) ? (string) __('history.fields.'.$field) : Str::ucfirst(str_replace('_', ' ', $field));
    }

    /** A stored value in words: verdicts, statuses, roles, routes, plans, dates, yes / no, lists. */
    private function value(string $field, mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '—';
        }

        try {
            return match ($field) {
                'verdict' => $this->verdict($value),
                'status' => VersionStatus::tryFrom((string) $value)?->label() ?? (string) $value,
                'role' => ProductRole::tryFrom((string) $value)?->label() ?? (string) $value,
                'conformity_route' => ConformityRoute::tryFrom((string) $value)?->label() ?? (string) $value,
                'plan' => Lang::has('plans.name.'.$value) ? (string) __('plans.name.'.$value) : (string) $value,
                'category' => Lang::has('declaration.technical.categories.'.$value) ? (string) __('declaration.technical.categories.'.$value) : (string) $value,
                'owner_id', 'user_id' => $this->person($value),
                'size' => self::bytes($value),
                'limits' => $this->limits($value),
                'attributes' => $this->attributes($value),
                'expires_at', 'completion_date', 'ends_at' => CarbonImmutable::parse((string) $value)->setTimezone($this->timezone)->isoFormat('D MMMM YYYY'),
                default => $this->plain($value),
            };
        } catch (Throwable) {
            return $this->plain($value);
        }
    }

    private function plain(mixed $value): string
    {
        if (is_bool($value)) {
            return (string) __($value ? 'common.yes' : 'common.no');
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $key => $item) {
                if ($item === null || $item === '' || $item === []) {
                    continue;
                }
                $text = is_array($item) ? implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : (string) json_encode($v), $item)) : $this->plain($item);
                $parts[] = is_int($key) ? $text : $this->fieldLabel((string) $key).Locales::detail('colon').$text;
            }

            return $parts === [] ? '—' : Str::limit(implode(is_int(array_key_first($value)) ? ', ' : ' · ', $parts), 300);
        }

        return Str::limit((string) $value, 300);
    }

    /** An organisation's plan overrides with the console's labels ("Extra products granted: 2 · AI period: per month"). */
    private function limits(mixed $value): string
    {
        if (! is_array($value)) {
            return $this->plain($value);
        }
        $parts = [];
        foreach ($value as $key => $item) {
            if ($item === null || $item === '') {
                continue;
            }
            $label = Lang::has('plans.admin.fields.'.$key) ? (string) __('plans.admin.fields.'.$key) : $this->fieldLabel((string) $key);
            $text = $key === 'ai_period' && Lang::has('plans.periods.'.$item) ? (string) __('plans.periods.'.$item) : $this->plain($item);
            $parts[] = $label.Locales::detail('colon').$text;
        }

        return $parts === [] ? '—' : implode(' · ', $parts);
    }

    /** An item's attributes with the content pack's field and option labels ("Type: Data · Stored on the product: Yes"). */
    private function attributes(mixed $value): string
    {
        if (! is_array($value)) {
            return $this->plain($value);
        }
        $fields = $this->fields();
        $parts = [];
        foreach ($value as $key => $item) {
            if ($item === null || $item === '' || $item === []) {
                continue;
            }
            $field = $fields[(string) $key] ?? null;
            if ($field === null) {
                $parts[] = (is_int($key) ? '' : $this->fieldLabel((string) $key).Locales::detail('colon')).$this->plain($item);

                continue;
            }
            $options = $field['options'];
            $text = is_array($item)
                ? implode(', ', array_map(fn ($v) => is_scalar($v) ? ($options[(string) $v] ?? (string) $v) : (string) json_encode($v), $item))
                : (is_scalar($item) && isset($options[(string) $item]) ? $options[(string) $item] : $this->plain($item));
            $parts[] = $field['label'].Locales::detail('colon').$text;
        }

        return $parts === [] ? '—' : Str::limit(implode(' · ', $parts), 300);
    }

    // ---- Content pack (labels only) --------------------------------------------------------------

    /** @return array<string, array{label: string, step: string}> */
    private function questions(): array
    {
        if ($this->questions === null) {
            $this->questions = [];
            try {
                foreach (app(ContentPack::class)->scoping()['sections'] ?? [] as $section) {
                    foreach ($section['groups'] ?? [] as $group) {
                        foreach ($group['questions'] ?? [] as $question) {
                            $this->questions[(string) $question['key']] = ['label' => Text::of($question['label'] ?? $question['key']), 'step' => (string) ($section['step'] ?? 'scoping')];
                        }
                    }
                }
            } catch (Throwable) {
                // No pack: the keys are shown.
            }
        }

        return $this->questions;
    }

    /** @return array<string, string> list key => label */
    private function lists(): array
    {
        if ($this->lists === null) {
            $this->lists = [];
            try {
                foreach (app(ContentPack::class)->lists() as $key => $list) {
                    $this->lists[(string) $key] = Text::of($list['label'] ?? $key);
                }
            } catch (Throwable) {
                // No pack: the keys are shown.
            }
        }

        return $this->lists;
    }

    /** @return array<string, array{label: string, options: array<string, string>}> field key => labels (first list wins) */
    private function fields(): array
    {
        if ($this->fields === null) {
            $this->fields = [];
            try {
                foreach (app(ContentPack::class)->lists() as $list) {
                    foreach ($list['fields'] ?? [] as $field) {
                        $key = (string) ($field['key'] ?? '');
                        if ($key === '' || isset($this->fields[$key])) {
                            continue;
                        }
                        $options = [];
                        foreach ($field['options'] ?? [] as $option) {
                            $options[(string) ($option['value'] ?? '')] = Text::of($option['label'] ?? ($option['value'] ?? ''));
                        }
                        $this->fields[$key] = ['label' => Text::of($field['label'] ?? $key), 'options' => $options];
                    }
                }
            } catch (Throwable) {
                // No pack: the keys are shown.
            }
        }

        return $this->fields;
    }

    /** The labels of the versions of products (current ones, then removed ones from their entries). */
    public static function versionLabels(array $productIds): array
    {
        $labels = [];
        foreach (DB::table('activity_log')->whereIn('product_id', $productIds)->where('subject_type', ProductVersion::class)
            ->whereIn('event', ['created', 'deleted'])->get(['subject_id', 'attribute_changes']) as $row) {
            $changes = json_decode((string) $row->attribute_changes, true) ?: [];
            $label = $changes['attributes']['label'] ?? $changes['old']['label'] ?? null;
            if (is_string($label)) {
                $labels[(string) $row->subject_id] = $label;
            }
        }
        foreach (ProductVersion::query()->whereIn('product_id', $productIds)->pluck('label', 'id') as $id => $label) {
            $labels[(string) $id] = (string) $label;
        }

        return $labels;
    }

    /** @return array<string, string> product id => name (deleted products too) */
    public static function productNames(Organisation $organisation): array
    {
        return Product::withTrashed()->where('organisation_id', $organisation->id)->pluck('name', 'id')
            ->map(fn ($name) => (string) $name)->all();
    }
}
