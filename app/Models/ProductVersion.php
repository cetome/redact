<?php

namespace App\Models;

use App\Enums\ConformityRoute;
use App\Enums\VersionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One assessed version of a product. Every piece of assessment data
 * (scoping, risks, inventory, decision trees, E.Info, reviews) hangs off it.
 *
 * @property string $id
 * @property string $product_id
 * @property string $label
 * @property string|null $previous_version_id
 * @property VersionStatus $status
 * @property string|null $content_pack
 * @property string|null $intended_use
 * @property string|null $hardware_version hardware revision of the product being assessed ("Product version")
 * @property string|null $software_version software/firmware version of the product being assessed
 * @property ConformityRoute|null $conformity_route
 * @property bool $units_external the assessment units are performed outside REDact (an independent test laboratory)
 * @property string|null $notified_body
 * @property string|null $notified_body_number
 * @property string|null $certificate_reference the Notified Body's certificate number, recorded after the review (Declaration step)
 * @property Carbon|null $certificate_issued_on
 * @property Carbon|null $certificate_expires_on
 * @property string|null $certificate_body issuing Notified Body (name)
 * @property string|null $certificate_body_number
 * @property string|null $certificate_document_id the uploaded certificate (a document of the version)
 * @property array<string, mixed>|null $declaration EU declaration of conformity data (App\Declaration\DeclarationData)
 * @property string|null $author_name
 * @property Carbon|null $completion_date
 * @property Carbon|null $submitted_at
 * @property Carbon|null $approved_at
 * @property list<string>|null $inventory_none inventory lists marked "This product has none"
 * @property Carbon|null $articles_confirmed_at "Essential requirements" confirmed (see App\Assessment\ArticlesCheck)
 * @property int|null $articles_confirmed_by
 * @property string|null $articles_confirmed_hash fingerprint of the comparison that was confirmed
 * @property Carbon $created_at
 * @property-read Product $product
 */
#[Fillable(['product_id', 'label', 'previous_version_id', 'status', 'content_pack', 'intended_use',
    'hardware_version', 'software_version', 'conformity_route', 'units_external', 'notified_body', 'notified_body_number', 'certificate_reference',
    'author_name', 'completion_date', 'created_by', 'inventory_none', 'declaration'])]
class ProductVersion extends Model
{
    use HasUlids, LogsActivity;

    protected function casts(): array
    {
        return [
            'status' => VersionStatus::class,
            'conformity_route' => ConformityRoute::class,
            'units_external' => 'boolean',
            'completion_date' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'inventory_none' => 'array',
            'articles_confirmed_at' => 'datetime',
            'declaration' => 'array',
            'certificate_issued_on' => 'date',
            'certificate_expires_on' => 'date',
        ];
    }

    /**
     * Why the status changes on the next save (submitted, withdrawn, changes_requested, approved,
     * reopened): written into that save's activity entry, so the history tells which it was.
     */
    public ?string $statusReason = null;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['label', 'status', 'intended_use', 'hardware_version', 'software_version', 'author_name', 'completion_date',
            'conformity_route', 'units_external', 'notified_body', 'notified_body_number'])
            ->logOnlyDirty()->dontLogEmptyChanges()->useLogName('version');
    }

    /** Called by spatie/laravel-activitylog before an entry about this version is saved. */
    public function beforeActivityLogged(Activity $activity, string $event): void
    {
        if ($this->statusReason !== null && $event === 'updated') {
            $activity->properties = collect($activity->properties ?? [])->put('reason', $this->statusReason);
            $this->statusReason = null;
        }
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductVersion, $this> */
    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(ProductVersion::class, 'previous_version_id');
    }

    /** @return HasMany<ProductIdentifier, $this> */
    public function identifiers(): HasMany
    {
        return $this->hasMany(ProductIdentifier::class)->orderBy('position');
    }

    /**
     * Everything that identifies this version, in declaration order: the product's models, the
     * version's own identifiers, then its hardware and software versions (unsaved rows, so the
     * declaration and the technical file list them like any identifier).
     *
     * @return Collection<int, ProductIdentifier>
     */
    public function identification(): Collection
    {
        $rows = $this->product->models->concat($this->identifiers);
        foreach (['hardware_version', 'software_version'] as $kind) {
            if (filled($this->{$kind})) {
                $rows->push(new ProductIdentifier(['kind' => $kind, 'value' => (string) $this->{$kind}]));
            }
        }

        return $rows->values();
    }

    /** @return BelongsTo<User, $this> who confirmed the articles and standards */
    public function articlesConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'articles_confirmed_by');
    }

    /** @return HasMany<ScopingAnswer, $this> */
    public function scopingAnswers(): HasMany
    {
        return $this->hasMany(ScopingAnswer::class);
    }

    /** @return HasMany<RiskLevel, $this> */
    public function riskLevels(): HasMany
    {
        return $this->hasMany(RiskLevel::class)->orderBy('rank');
    }

    /** @return HasMany<RiskMatrixCell, $this> */
    public function riskMatrixCells(): HasMany
    {
        return $this->hasMany(RiskMatrixCell::class);
    }

    /** @return HasMany<RiskEntry, $this> */
    public function riskEntries(): HasMany
    {
        return $this->hasMany(RiskEntry::class)->orderBy('position');
    }

    /** @return HasMany<InventoryItem, $this> */
    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class)->orderBy('position');
    }

    /** @return HasMany<InventoryLink, $this> */
    public function inventoryLinks(): HasMany
    {
        return $this->hasMany(InventoryLink::class);
    }

    /** @return HasMany<Evaluation, $this> */
    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class);
    }

    /** @return HasMany<EinfoEntry, $this> */
    public function einfoEntries(): HasMany
    {
        return $this->hasMany(EinfoEntry::class);
    }

    /** @return HasMany<VersionReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(VersionReview::class);
    }

    /** @return HasMany<AssessmentCaseResult, $this> */
    public function caseResults(): HasMany
    {
        return $this->hasMany(AssessmentCaseResult::class);
    }

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /** @return BelongsTo<Document, $this> the Notified Body's certificate (PDF), once uploaded */
    public function certificateDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'certificate_document_id');
    }

    /** @return HasMany<AiSuggestion, $this> */
    public function aiSuggestions(): HasMany
    {
        return $this->hasMany(AiSuggestion::class);
    }

    /** @return list<string> keys of the inventory lists marked "This product has none" */
    public function noneLists(): array
    {
        return array_values(array_filter((array) $this->inventory_none, 'is_string'));
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /** "Smart lock · 2.1", as CRAted writes it. */
    public function displayName(): string
    {
        return $this->product->name.' · '.$this->label;
    }
}
