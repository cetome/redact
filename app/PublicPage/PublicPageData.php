<?php

namespace App\PublicPage;

use App\Assessment\Assessment;
use App\Declaration\DeclarationData;
use App\Models\Document;
use App\Models\ProductVersion;
use App\Models\RiskEntry;
use App\Models\RiskLevel;
use App\Models\SbomImport;
use App\Models\VexImport;
use App\Models\VexStatement;
use App\Models\VulnerabilityScan;
use App\Sbom\Vex\VexStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Everything the public product page shows of one version, and nothing more: the sections the
 * manufacturer switched off are never read. The same object renders the page, its preview in
 * the app and the files it offers (each download checks the file is one of these).
 */
final class PublicPageData
{
    /** Bills of materials kinds per section ("mixed" files hold both). */
    public const BOM_KINDS = ['sbom' => ['sbom', 'mixed'], 'hbom' => ['hbom', 'mixed']];

    private ?array $standards = null;

    public function __construct(public readonly ProductVersion $version, public readonly PublicSettings $settings) {}

    public static function for(ProductVersion $version, ?PublicSettings $settings = null): self
    {
        $version->loadMissing('product.organisation', 'product.models');

        return new self($version, $settings ?? PublicSettings::for($version));
    }

    public function shows(string $section): bool
    {
        return $this->settings->shows($section);
    }

    /** @return list<string> the product's models (Product identification) */
    public function models(): array
    {
        return $this->version->product->models->pluck('value')->filter()->values()->all();
    }

    /** @return array{name: string, address: ?string, country: ?string, rep_name: ?string, rep_address: ?string} */
    public function manufacturer(): array
    {
        $org = $this->version->product->organisation;

        return [
            'name' => (string) ($org->legal_name ?: $org->name),
            'address' => $org->address,
            'country' => $org->country,
            'rep_name' => $org->authorised_rep_name,
            'rep_address' => $org->authorised_rep_address,
        ];
    }

    public function assessedOn(): ?Carbon
    {
        return $this->version->approved_at ?? $this->version->completion_date;
    }

    /**
     * One row per part of EN 18031 in the pack: assessed (applicable requirements, how many not
     * met) or not applicable to this product.
     *
     * @return list<array{reference: string, article: string, applicable: bool, requirements: int, failed: int}>
     */
    public function standards(): array
    {
        if ($this->standards !== null) {
            return $this->standards;
        }
        $assessment = Assessment::for($this->version);
        $pack = $assessment->pack;
        $rows = [];
        foreach ($pack->standards() as $code => $standard) {
            $article = (string) ($standard['article'] ?? '');
            $applicable = $assessment->hasStandard((string) $code);
            $progress = $applicable ? $assessment->evaluationProgress((string) $code) : null;
            $rows[] = [
                'reference' => $pack->standardName((string) $code),
                'article' => $article !== '' ? DeclarationData::articleLabel($article, $pack->articles()[$article] ?? $article) : '',
                'applicable' => $applicable,
                'requirements' => $applicable ? count($assessment->applicableRequirements((string) $code)) : 0,
                'failed' => (int) ($progress['fail'] ?? 0),
            ];
        }

        return $this->standards = $rows;
    }

    /** Every assessed part met: no requirement failed. Null when no part was assessed. */
    public function allMet(): ?bool
    {
        $assessed = array_filter($this->standards(), fn ($row) => $row['applicable']);

        return $assessed === [] ? null : array_sum(array_column($assessed, 'failed')) === 0;
    }

    /** @return Collection<int, Document> */
    public function documents(): Collection
    {
        if (! $this->shows('documents') || $this->settings->documents === []) {
            return collect();
        }
        $order = array_flip($this->settings->documents);

        return $this->version->documents()->whereIn('id', $this->settings->documents)
            ->get(['id', 'product_version_id', 'original_name', 'mime', 'size', 'path', 'category'])
            ->sortBy(fn (Document $d) => $order[$d->id] ?? PHP_INT_MAX)->values();
    }

    /** @return Collection<int, RiskLevel> keyed by rank, 1 = highest */
    public function riskLevels(): Collection
    {
        return $this->version->riskLevels()->orderBy('rank')->get()->keyBy('rank');
    }

    /** @return array<int, int> residual rank => number of risks (rated ones only) */
    public function riskCounts(): array
    {
        $counts = [];
        foreach ($this->version->riskEntries()->whereNotNull('residual_rank')->get(['residual_rank']) as $entry) {
            $counts[(int) $entry->residual_rank] = ($counts[(int) $entry->residual_rank] ?? 0) + 1;
        }

        return $counts;
    }

    /** @return Collection<int, RiskEntry> the risks listed one by one, highest level first */
    public function listedRisks(): Collection
    {
        if ($this->settings->risks === []) {
            return collect();
        }

        return $this->version->riskEntries()->whereIn('id', $this->settings->risks)
            ->orderByRaw('residual_rank is null')->orderBy('residual_rank')->orderBy('position')
            ->get(['id', 'threat', 'residual_rank', 'position']);
    }

    /** @return Collection<int, VexStatement> */
    public function vexStatements(): Collection
    {
        return VexStatus::of($this->version->id);
    }

    /** @return array<string, int> VEX status => number of statements */
    public function vexCounts(): array
    {
        return $this->vexStatements()->countBy('status')->all();
    }

    public function lastChecked(): ?Carbon
    {
        $at = VulnerabilityScan::query()->where('product_version_id', $this->version->id)->max('checked_at');

        return $at ? Carbon::parse($at) : null;
    }

    /** @return Collection<int, VexImport> */
    public function vexImports(): Collection
    {
        return VexImport::query()->where('product_version_id', $this->version->id)
            ->whereNotNull('imported_at')->where('status', '!=', 'discarded')->orderBy('created_at')->get(['id', 'product_version_id', 'files', 'format', 'imported_at']);
    }

    /** @return Collection<int, SbomImport> the applied bills of materials of one section (sbom, hbom) */
    public function boms(string $section): Collection
    {
        return SbomImport::query()->where('product_version_id', $this->version->id)
            ->whereIn('kind', self::BOM_KINDS[$section] ?? [])->whereNotNull('imported_at')->where('status', '!=', 'discarded')
            ->orderBy('created_at')->get(['id', 'product_version_id', 'kind', 'format', 'files', 'component_count', 'imported_at']);
    }

    /** May a visitor download this document of the page? */
    public function offersDocument(Document $document): bool
    {
        return $this->shows('documents') && $document->product_version_id === $this->version->id
            && in_array($document->id, $this->settings->documents, true);
    }

    public function offersBom(SbomImport $import): bool
    {
        foreach (array_keys(self::BOM_KINDS) as $section) {
            if ($this->shows($section) && $this->boms($section)->contains('id', $import->id)) {
                return true;
            }
        }

        return false;
    }

    public function offersVex(VexImport $import): bool
    {
        return $this->shows('vex') && $this->vexImports()->contains('id', $import->id);
    }
}
