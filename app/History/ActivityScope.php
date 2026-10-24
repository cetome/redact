<?php

namespace App\History;

use App\Models\AssessmentCaseReview;
use App\Models\Evaluation;
use App\Models\EvaluationReview;
use App\Models\InventoryItem;
use App\Models\InventoryLink;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\ProductMember;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Stamps every activity entry with the organisation, product and version it belongs to
 * (activity_log.organisation_id / product_id / product_version_id), and keeps a small
 * reference of its subject in properties.ref (requirement and item, risk title, person …), so the
 * customer histories (App\History\Feed) stay readable and scoped after the subject is gone.
 *
 * Registered once in AppServiceProvider (an Eloquent `creating` listener on the activity model).
 * Never stores secrets or file contents: only codes, names and labels already shown in the app.
 */
final class ActivityScope
{
    /** @var array<string, array{0: ?string, 1: ?string}> version id => [product id, organisation id] */
    private static array $versions = [];

    /** @var array<string, string> product id => organisation id */
    private static array $products = [];

    /** @var array<string, string> inventory item id => "AS-01 · Door key" */
    private static array $items = [];

    public static function register(): void
    {
        Activity::creating(function (Activity $activity) {
            self::stamp($activity);
        });
    }

    public static function stamp(Activity $activity): void
    {
        $subject = $activity->relationLoaded('subject') ? $activity->getRelation('subject') : null;
        $properties = collect($activity->properties ?? []);

        [$org, $product, $version] = $subject instanceof Model
            ? self::scopeOf($subject)
            : [null, null, null];

        // An entry without a subject may name its version (reports carry `version`).
        if ($version === null && $product === null && is_string($properties->get('version'))) {
            [$org, $product, $version] = self::fromVersion($properties->get('version'));
        }

        $activity->organisation_id ??= $org;
        $activity->product_id ??= $product;
        $activity->product_version_id ??= $version;

        if ($subject instanceof Model && ! $properties->has('ref')) {
            $ref = self::reference($subject);
            if ($ref !== []) {
                $activity->properties = $properties->put('ref', $ref);
            }
        }
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string} organisation, product, version
     */
    public static function scopeOf(Model $subject): array
    {
        return match (true) {
            $subject instanceof Organisation => [(string) $subject->getKey(), null, null],
            $subject instanceof Product => [$subject->organisation_id, (string) $subject->getKey(), null],
            $subject instanceof ProductVersion => self::fromVersion((string) $subject->getKey(), $subject->product_id),
            $subject instanceof User => [null, null, null],
            filled($subject->getAttribute('product_version_id')) => self::fromVersion((string) $subject->getAttribute('product_version_id')),
            $subject instanceof EvaluationReview => self::fromEvaluation((string) $subject->evaluation_id),
            $subject instanceof AssessmentCaseReview => self::fromCaseResult((string) $subject->assessment_case_result_id),
            filled($subject->getAttribute('product_id')) => self::fromProduct((string) $subject->getAttribute('product_id')),
            filled($subject->getAttribute('organisation_id')) => [(string) $subject->getAttribute('organisation_id'), null, null],
            default => [null, null, null],
        };
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} */
    private static function fromVersion(string $versionId, ?string $productId = null): array
    {
        if (! array_key_exists($versionId, self::$versions)) {
            $productId ??= DB::table('product_versions')->where('id', $versionId)->value('product_id');
            if ($productId === null) {
                return [null, null, null];
            }
            self::$versions[$versionId] = [$productId, self::orgOf($productId)];
        }
        [$product, $org] = self::$versions[$versionId];

        return [$org, $product, $product ? $versionId : null];
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} */
    private static function fromProduct(string $productId): array
    {
        return [self::orgOf($productId), $productId, null];
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} */
    private static function fromEvaluation(string $evaluationId): array
    {
        $versionId = DB::table('evaluations')->where('id', $evaluationId)->value('product_version_id');

        return $versionId ? self::fromVersion((string) $versionId) : [null, null, null];
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} */
    private static function fromCaseResult(string $resultId): array
    {
        $versionId = DB::table('assessment_case_results')->where('id', $resultId)->value('product_version_id');

        return $versionId ? self::fromVersion((string) $versionId) : [null, null, null];
    }

    private static function orgOf(string $productId): ?string
    {
        if (! isset(self::$products[$productId])) {
            $org = DB::table('products')->where('id', $productId)->value('organisation_id');
            if ($org === null) {
                return null;
            }
            self::$products[$productId] = $org;
        }

        return self::$products[$productId];
    }

    /**
     * What the history needs to name the subject once it is gone (codes and names only).
     *
     * @return array<string, mixed>
     */
    public static function reference(Model $subject): array
    {
        $ref = match (true) {
            $subject instanceof Evaluation => [
                'standard' => $subject->standard,
                'requirement' => $subject->requirement,
                'item' => self::itemLabel($subject->inventory_item_id),
            ],
            $subject instanceof EvaluationReview => self::evaluationRef((string) $subject->evaluation_id),
            $subject instanceof AssessmentCaseReview => self::caseResultRef((string) $subject->assessment_case_result_id),
            $subject instanceof InventoryItem => ['list' => $subject->list, 'item' => $subject->label()],
            $subject instanceof InventoryLink => [
                'relation' => $subject->relation,
                'from' => self::itemLabel($subject->from_item_id),
                'to' => self::itemLabel($subject->to_item_id),
            ],
            $subject instanceof ProductMember => self::personRef($subject->user_id),
            $subject instanceof ProductVersion => ['label' => $subject->label],
            $subject instanceof Product => ['name' => $subject->name],
            $subject instanceof Organisation => ['name' => $subject->name],
            default => self::genericRef($subject),
        };

        return array_filter($ref, fn ($value) => $value !== null && $value !== '');
    }

    /** @return array<string, mixed> */
    private static function genericRef(Model $subject): array
    {
        $ref = [];
        foreach (['requirement', 'standard', 'case_key', 'einfo', 'question', 'threat', 'kind', 'value', 'original_name', 'feature', 'field', 'target_type'] as $key) {
            $value = $subject->getAttribute($key);
            if (is_scalar($value) && $value !== '') {
                $ref[$key] = mb_strimwidth((string) $value, 0, 160, '…');
            }
        }
        if (filled($subject->getAttribute('inventory_item_id'))) {
            $ref['item'] = self::itemLabel((string) $subject->getAttribute('inventory_item_id'));
        }

        return $ref;
    }

    /** @return array<string, mixed> */
    private static function evaluationRef(string $evaluationId): array
    {
        $row = DB::table('evaluations')->where('id', $evaluationId)->first(['standard', 'requirement', 'inventory_item_id']);

        return $row ? ['standard' => $row->standard, 'requirement' => $row->requirement, 'item' => self::itemLabel($row->inventory_item_id)] : [];
    }

    /** @return array<string, mixed> */
    private static function caseResultRef(string $resultId): array
    {
        $row = DB::table('assessment_case_results')->where('id', $resultId)->first(['standard', 'requirement', 'case_key']);

        return $row ? ['standard' => $row->standard, 'requirement' => $row->requirement, 'case_key' => $row->case_key] : [];
    }

    /** @return array<string, mixed> */
    private static function personRef(?int $userId): array
    {
        $user = $userId ? DB::table('users')->where('id', $userId)->first(['name', 'email']) : null;

        return $user ? ['person' => $user->name, 'email' => $user->email] : [];
    }

    public static function itemLabel(?string $itemId): ?string
    {
        if ($itemId === null || $itemId === '') {
            return null;
        }
        if (! array_key_exists($itemId, self::$items)) {
            $row = DB::table('inventory_items')->where('id', $itemId)->first(['code', 'name']);
            if ($row === null) {
                return null;
            }
            self::$items[$itemId] = $row->name ? $row->code.' · '.$row->name : (string) $row->code;
            if (count(self::$items) > 5000) {
                self::$items = array_slice(self::$items, -1000, null, true);
            }
        }

        return self::$items[$itemId];
    }

    /**
     * Fills the scope of entries written before the columns existed, from their subjects (or the
     * version / product their attributes name). Entries whose subject is gone stay unscoped.
     */
    public static function backfill(): int
    {
        $count = 0;
        Activity::query()->whereNull('organisation_id')->whereNotNull('subject_type')
            ->whereNotIn('log_name', ['security', 'account', 'usage', 'guest'])
            ->chunkById(500, function ($activities) use (&$count) {
                foreach ($activities as $activity) {
                    /** @var Activity $activity */
                    $scope = self::scopeFromStored($activity);
                    if ($scope[0] === null) {
                        continue;
                    }
                    DB::table('activity_log')->where('id', $activity->id)->update([
                        'organisation_id' => $scope[0],
                        'product_id' => $scope[1],
                        'product_version_id' => $scope[2],
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} */
    private static function scopeFromStored(Activity $activity): array
    {
        $type = (string) $activity->subject_type;
        $id = (string) $activity->subject_id;
        $changes = $activity->attribute_changes?->toArray() ?? [];
        $attributes = array_merge((array) ($changes['old'] ?? []), (array) ($changes['attributes'] ?? []));

        $model = class_exists($type) ? $type::query()->find($id) : null;
        if ($model instanceof Model) {
            return self::scopeOf($model);
        }

        return match (true) {
            $type === Product::class => self::fromProduct($id),
            $type === ProductVersion::class => self::fromVersion($id),
            $type === Organisation::class => [$id, null, null],
            filled($attributes['product_version_id'] ?? null) => self::fromVersion((string) $attributes['product_version_id']),
            filled($attributes['product_id'] ?? null) => self::fromProduct((string) $attributes['product_id']),
            filled($attributes['evaluation_id'] ?? null) => self::fromEvaluation((string) $attributes['evaluation_id']),
            default => [null, null, null],
        };
    }
}
