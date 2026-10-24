<?php

namespace App\Declaration;

use App\Assessment\Assessment;
use App\Assessment\Conformity;
use App\Assessment\ProductImage;
use App\Enums\ConformityRoute;
use App\Enums\VersionStatus;
use App\Guest\DemoProduct;
use App\Models\InventoryItem;
use App\Models\Organisation;
use App\Models\ProductVersion;
use App\Sbom\SbomFeature;
use Illuminate\Support\Carbon;

/**
 * The EU declaration of conformity of one version (Directive 2014/53/EU, Annex VI), and its
 * simplified form (Annex VII): what the app knows (product, identifiers, picture, essential
 * requirements and EN 18031 parts in scope, conformity route, Notified Body, certificate,
 * software components) merged with what the person enters on the Declaration step
 * (product_versions.declaration) and the manufacturer of the organisation.
 *
 * openItems() lists why a declaration printed now is not valid yet: the Word file then carries
 * the "DRAFT — not valid" watermark.
 */
final class DeclarationData
{
    /** Essential requirements REDact does not assess: standards the person enters. */
    public const OTHER_ARTICLES = ['3.1a', '3.1b', '3.2', 'other'];

    /** Suggestions for the standards REDact does not assess (references only, add the version). */
    public const SUGGESTIONS = [
        '3.1a' => ['EN 62368-1', 'EN IEC 62368-1', 'EN 62311', 'EN IEC 62311', 'EN 62479', 'EN 50663', 'EN 50665', 'EN IEC 62209-1528', 'EN 50566'],
        '3.1b' => ['EN 301 489-1', 'EN 301 489-3', 'EN 301 489-17', 'EN 301 489-19', 'EN 301 489-52', 'EN 55032', 'EN 55035', 'EN IEC 61000-3-2', 'EN 61000-3-3'],
        '3.2' => ['EN 300 328', 'EN 301 893', 'EN 300 220-2', 'EN 300 330', 'EN 303 413', 'EN 300 440', 'EN 301 908-1', 'EN 303 446-1', 'EN 302 502'],
    ];

    /** Other Union harmonisation legislation people often add (Annex VI, point 5). */
    public const LEGISLATION_SUGGESTIONS = [
        'Directive 2011/65/EU (RoHS)',
        'Commission Delegated Directive (EU) 2015/863',
        'Directive 2009/125/EC (ecodesign)',
        'Directive 2009/48/EC (safety of toys)',
        'Regulation (EU) 2023/1542 (batteries)',
    ];

    /** Fields of product_versions.declaration, with their maximum length. */
    public const FIELDS = [
        'number' => 60, 'serials' => 300, 'object' => 3000, 'nb_task' => 400, 'accessories' => 3000,
        'additional' => 3000, 'signed_for' => 200, 'place' => 120, 'date' => 10,
        'signatory_name' => 120, 'signatory_function' => 120, 'url' => 300,
    ];

    /** @var array<string, mixed> */
    private array $stored;

    private ?Conformity $conformity = null;

    /** @var list<array{key: string, label: string, href: string|null}>|null */
    private ?array $openItems = null;

    public function __construct(public readonly Assessment $assessment)
    {
        $this->stored = (array) ($assessment->version->declaration ?? []);
    }

    public static function for(ProductVersion $version): self
    {
        $version->loadMissing('product.organisation', 'product.models', 'identifiers');

        return new self(Assessment::for($version));
    }

    public function version(): ProductVersion
    {
        return $this->assessment->version;
    }

    public function organisation(): Organisation
    {
        return $this->version()->product->organisation;
    }

    public function conformity(): Conformity
    {
        return $this->conformity ??= $this->assessment->conformity();
    }

    /** A stored text field, or null. */
    public function stored(string $key): ?string
    {
        $value = $this->stored[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** Was the field ever saved (an empty saved value is a choice, never refilled)? */
    public function saved(string $key): bool
    {
        return array_key_exists($key, $this->stored);
    }

    /** A field as the form shows it: the saved value, else the app's proposal. */
    public function value(string $key): string
    {
        if ($this->saved($key)) {
            return (string) ($this->stored($key) ?? '');
        }

        return match ($key) {
            'object' => $this->proposedObject(),
            'accessories' => $this->proposedAccessories(),
            'signed_for' => $this->manufacturerName(),
            'nb_task' => $this->proposedTask(),
            default => '',
        };
    }

    public function includeImage(): bool
    {
        return (bool) ($this->stored['include_image'] ?? true);
    }

    // ---- Point 1: radio equipment ---------------------------------------------------------------

    /** @return list<array{kind: string, label: string, value: string, notes: string}> */
    public function identifiers(): array
    {
        // Models (product), identifiers (version), then hardware and software versions (ProductVersion::identification()).
        return $this->version()->identification()->map(fn ($i) => [
            'kind' => (string) $i->kind, 'label' => $i->kindLabel(), 'value' => (string) $i->value, 'notes' => (string) $i->notes,
        ])->values()->all();
    }

    /** The type designation (model, type or reference identifier), else the product name. */
    public function typeDesignation(): string
    {
        foreach (['model', 'type', 'reference', 'hvin'] as $kind) {
            foreach ($this->identifiers() as $identifier) {
                if ($identifier['kind'] === $kind) {
                    return $identifier['value'];
                }
            }
        }

        return trim((string) $this->version()->product->reference) ?: (string) $this->version()->product->name;
    }

    public function imagePath(): ?string
    {
        return app(ProductImage::class)->path($this->version()->product);
    }

    // ---- Point 2: manufacturer --------------------------------------------------------------------

    /**
     * The demo product's fictitious manufacturer (App\Guest\DemoProduct), never the organisation's
     * legal details; null for any other product.
     *
     * @return array{name: string, address: string}|null
     */
    public function demoManufacturer(): ?array
    {
        return $this->version()->product->is_demo ? app(DemoProduct::class)->manufacturer() : null;
    }

    public function isDemo(): bool
    {
        return (bool) $this->version()->product->is_demo;
    }

    public function manufacturerName(): string
    {
        if ($demo = $this->demoManufacturer()) {
            return $demo['name'];
        }
        $organisation = $this->organisation();

        return trim((string) ($organisation->legal_name ?: $organisation->name));
    }

    public function manufacturerAddress(): string
    {
        if ($demo = $this->demoManufacturer()) {
            return $demo['address'];
        }
        $organisation = $this->organisation();

        return trim(implode("\n", array_filter([trim((string) $organisation->address), trim((string) $organisation->country)])));
    }

    public function hasManufacturer(): bool
    {
        if ($demo = $this->demoManufacturer()) {
            return $demo['name'] !== '' && $demo['address'] !== '';
        }

        return filled($this->organisation()->legal_name) && filled($this->organisation()->address);
    }

    public function authorisedRepresentative(): ?string
    {
        if ($this->isDemo()) {
            return null;
        }
        $organisation = $this->organisation();
        if (blank($organisation->authorised_rep_name)) {
            return null;
        }

        return trim($organisation->authorised_rep_name."\n".trim((string) $organisation->authorised_rep_address));
    }

    // ---- Point 4: object of the declaration -------------------------------------------------------

    public function proposedObject(): string
    {
        // The product and its version, then its description: the identifiers are point 1's, never
        // repeated here, and no word of the app, so the proposal reads in every language.
        $version = $this->version();
        $lines = [trim($version->product->name.' '.$version->label)];
        $description = trim((string) $version->product->description);
        if ($description !== '') {
            $lines[] = '';
            $lines[] = mb_strlen($description) > 600 ? rtrim(mb_substr($description, 0, 600)).'…' : $description;
        }

        return implode("\n", $lines);
    }

    // ---- Point 5: legislation ---------------------------------------------------------------------

    /**
     * The essential requirements of article 3(3) in scope, as "Article 3(3)(d)" when the pack's
     * code reads 3.3d, else the pack's own label.
     *
     * @return list<array{code: string, label: string}>
     */
    public function articlesInScope(): array
    {
        $names = $this->assessment->pack->articles();
        $rows = [];
        foreach ($this->assessment->scoping()->articles as $code => $inScope) {
            if ($inScope) {
                $rows[] = ['code' => (string) $code, 'label' => self::articleLabel((string) $code, $names[$code] ?? (string) $code)];
            }
        }

        return $rows;
    }

    /** "3.3d" → "Article 3(3)(d)", "3.1a" → "Article 3(1)(a)", "3.2" → "Article 3(2)". */
    public static function articleLabel(string $code, ?string $fallback = null): string
    {
        $ref = self::articleCode($code);

        return $ref !== $code ? __('declaration.article', ['ref' => $ref]) : ($fallback ?? $code);
    }

    /** "3.3d" → "3(3)(d)", the notation of the Official Journal in every language; other codes unchanged. */
    public static function articleCode(string $code): string
    {
        return preg_match('/^(\d+)\.(\d+)([a-z])?$/', $code, $m) === 1
            ? $m[1].'('.$m[2].')'.(isset($m[3]) ? '('.$m[3].')' : '')
            : $code;
    }

    /** @return list<string> other Union harmonisation legislation entered by the person */
    public function legislation(): array
    {
        return array_values(array_filter(array_map(fn ($v) => trim((string) $v), (array) ($this->stored['legislation'] ?? [])), fn ($v) => $v !== ''));
    }

    // ---- Point 6: standards -----------------------------------------------------------------------

    /**
     * The parts of EN 18031 in scope, from the assessment.
     *
     * @return list<array{reference: string, article: string, standard: string, code: string}>
     */
    public function assessedStandards(): array
    {
        $pack = $this->assessment->pack;
        $rows = [];
        foreach ($this->assessment->standards() as $standard) {
            $article = (string) ($pack->standards()[$standard]['article'] ?? '');
            $rows[] = [
                'reference' => $pack->standardName($standard),
                'article' => $article !== '' ? self::articleLabel($article, $pack->articles()[$article] ?? $article) : '',
                'standard' => $standard,
                // The article as 3(3)(d) when the pack's code reads 3.3d, else empty (its label above).
                'code' => self::articleCode($article) !== $article ? $article : '',
            ];
        }

        return $rows;
    }

    /**
     * Standards entered by the person for what REDact does not assess (3.1(a), 3.1(b), 3.2, other).
     *
     * @return list<array{reference: string, article: string}>
     */
    public function otherStandards(): array
    {
        $rows = [];
        foreach ((array) ($this->stored['standards'] ?? []) as $row) {
            $reference = trim((string) ($row['reference'] ?? ''));
            $article = (string) ($row['article'] ?? 'other');
            if ($reference !== '' && in_array($article, self::OTHER_ARTICLES, true)) {
                $rows[] = ['reference' => $reference, 'article' => $article];
            }
        }

        return $rows;
    }

    /** @return list<array{reference: string, article: string}> the entered standards of one essential requirement */
    public function otherStandardsFor(string $article): array
    {
        return array_values(array_filter($this->otherStandards(), fn (array $row) => $row['article'] === $article));
    }

    /** Self-assessment with harmonised standards cited with restrictions in the OJEU. */
    public function citedWithRestrictions(): bool
    {
        return $this->version()->conformity_route === ConformityRoute::ModuleA && $this->assessedStandards() !== [];
    }

    // ---- Point 7: notified body -------------------------------------------------------------------

    public function needsNotifiedBody(): bool
    {
        return (bool) $this->version()->conformity_route?->needsNotifiedBody();
    }

    public function proposedTask(): string
    {
        return match ($this->version()->conformity_route) {
            ConformityRoute::ModuleBC => __('declaration.nb.task_b'),
            ConformityRoute::ModuleH => __('declaration.nb.task_h'),
            default => '',
        };
    }

    /** @return array{name: string, number: string, task: string, certificate: string, issued: string, expires: string}|null */
    public function notifiedBody(): ?array
    {
        if (! $this->needsNotifiedBody()) {
            return null;
        }
        $version = $this->version();

        return [
            'name' => (string) ($version->certificate_body ?: $version->notified_body),
            'number' => (string) ($version->certificate_body_number ?: $version->notified_body_number),
            'task' => $this->value('nb_task'),
            'certificate' => (string) $version->certificate_reference,
            'issued' => self::day($version->certificate_issued_on),
            'expires' => self::day($version->certificate_expires_on),
        ];
    }

    public function hasCertificate(): bool
    {
        return filled($this->version()->certificate_reference);
    }

    // ---- Point 8: accessories, components, software -----------------------------------------------

    /**
     * Firmware and software components of the inventory, with their versions.
     *
     * @return list<array{name: string, version: string, type: string}>
     */
    public function softwareComponents(): array
    {
        if (! SbomFeature::importable($this->assessment->pack)) {
            return [];
        }

        return $this->version()->inventoryItems()->where('list', SbomFeature::componentsList())->get()
            ->map(fn (InventoryItem $item) => [
                'name' => $item->label(),
                'version' => is_scalar($item->attr('version')) ? (string) $item->attr('version') : '',
                'type' => is_scalar($item->attr('type')) ? (string) $item->attr('type') : '',
            ])->values()->all();
    }

    public function proposedAccessories(): string
    {
        return implode("\n", array_map(
            fn (array $c) => trim($c['name'].($c['version'] !== '' ? ' '.__('declaration.version_short', ['label' => ltrim($c['version'], 'vV')]) : '')),
            $this->softwareComponents(),
        ));
    }

    // ---- Signature --------------------------------------------------------------------------------

    public function issueDate(): string
    {
        $date = $this->stored('date');
        if ($date === null) {
            return '';
        }
        try {
            return Carbon::parse($date)->locale(app()->getLocale())->isoFormat('D MMMM YYYY');
        } catch (\Throwable) {
            return '';
        }
    }

    // ---- Validity ---------------------------------------------------------------------------------

    /**
     * Why a declaration generated now is a draft (the Word file then says "DRAFT — not valid"):
     * the version is not approved, the route is missing or conflicts, decision trees FAIL, are
     * INCONCLUSIVE, unjustified or still to walk, the Notified Body's certificate is missing.
     *
     * @return list<array{key: string, label: string, href: string|null}>
     */
    public function openItems(): array
    {
        if ($this->openItems !== null) {
            return $this->openItems;
        }
        $version = $this->version();
        $assessment = $this->assessment;
        $conformity = $this->conformity();
        $items = [];
        $add = function (string $key, string $label, ?string $href) use (&$items) {
            $items[] = ['key' => $key, 'label' => $label, 'href' => $href];
        };

        // The demo product: a fictitious product, never a valid declaration (DEMO marking).
        if ($this->isDemo()) {
            $add('demo', __('declaration.demo.open'), null);
        }
        if ($version->status !== VersionStatus::Approved) {
            $add('not_approved', __('declaration.open.not_approved', ['status' => mb_strtolower($version->status->label())]), $assessment->stepUrl('review'));
        }
        if ($version->conformity_route === null) {
            $add('no_route', __('declaration.open.no_route'), $assessment->stepUrl('conformity'));
        } elseif ($conformity->conflict()) {
            $add('conflict', __('declaration.open.conflict'), $assessment->stepUrl('conformity'));
        }
        if (($fail = $conformity->failCount()) > 0) {
            $add('fail', trans_choice('declaration.open.fail', $fail, ['count' => $fail]), $assessment->stepUrl('evaluate'));
        }
        if (($inconclusive = count($assessment->inconclusive())) > 0) {
            $add('inconclusive', trans_choice('declaration.open.inconclusive', $inconclusive, ['count' => $inconclusive]), $assessment->stepUrl('evaluate'));
        }
        if (($unjustified = count($assessment->unjustified())) > 0) {
            $add('unjustified', trans_choice('declaration.open.unjustified', $unjustified, ['count' => $unjustified]), $assessment->stepUrl('evaluate'));
        }
        $remaining = 0;
        foreach ($conformity->progress() as $p) {
            $remaining += max(0, $p['missing'] + $p['incomplete'] + $p['invalid']) + $p['unlinked'];
        }
        if ($remaining > 0) {
            $add('remaining', trans_choice('declaration.open.remaining', $remaining, ['count' => $remaining]), $assessment->stepUrl('evaluate'));
        }
        if ($this->needsNotifiedBody() && ! $this->hasCertificate()) {
            $add('certificate', __('declaration.open.certificate'), route('versions.declaration', $version).'#certificate');
        }

        return $this->openItems = $items;
    }

    public function isDraft(): bool
    {
        return $this->openItems() !== [];
    }

    /** The watermark of a declaration printed now: "DEMO — fictitious product", "DRAFT — not valid", or null when valid. */
    public function watermark(): ?string
    {
        return match (true) {
            $this->isDemo() => __('declaration.demo.watermark'),
            $this->isDraft() => __('declaration.draft.watermark'),
            default => null,
        };
    }

    /**
     * Fields the declaration still needs (shown as "[to complete]" in the Word file).
     *
     * @return list<string> manufacturer, signatory, place, date
     */
    public function missing(): array
    {
        return array_keys(array_filter([
            'manufacturer' => ! $this->hasManufacturer(),
            'signatory' => $this->stored('signatory_name') === null || $this->stored('signatory_function') === null,
            'place' => $this->stored('place') === null,
            'date' => $this->stored('date') === null,
        ]));
    }

    /** Ready to sign: approved, nothing open, every field filled. */
    public function complete(): bool
    {
        // The status first: the sidebar asks on every page, the open items cost more.
        return $this->version()->status === VersionStatus::Approved && $this->missing() === [] && ! $this->isDraft();
    }

    // ---- Annex VII --------------------------------------------------------------------------------

    /** The simplified EU declaration of conformity, ready to paste into the user manual. */
    public function simplified(): string
    {
        $url = $this->stored('url') ?? __('declaration.simplified.url_placeholder');

        return __('declaration.simplified.text', ['manufacturer' => $this->manufacturerName(), 'type' => $this->typeDesignation()])
            ."\n".__('declaration.simplified.url_line').' '.$url;
    }

    public static function day(?\DateTimeInterface $date): string
    {
        return $date ? Carbon::instance($date)->locale(app()->getLocale())->isoFormat('D MMMM YYYY') : '';
    }
}
