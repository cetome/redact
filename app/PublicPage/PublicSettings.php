<?php

namespace App\PublicPage;

use App\Models\ProductVersion;
use App\Models\RiskEntry;
use Illuminate\Support\Collection;

/**
 * What the public product page shows of one version, as the manufacturer chose it. Stored in
 * product_versions.public_page as two copies: "draft" (what the settings page saves) and "live"
 * (what visitors see, copied from the draft when the manufacturer publishes from the preview),
 * so no change reaches the public page unseen. The product and the manufacturer are always shown,
 * every other section is a choice. Nothing saved yet: the defaults below, with the security
 * contact of the organisation and the risks of the two highest levels ticked (rank 1 is the highest).
 */
final class PublicSettings
{
    /** The sections a manufacturer switches on or off, in page order, with their defaults. */
    public const SECTIONS = [
        'logo' => false, 'assessment' => true, 'support' => true, 'contact' => true, 'documents' => true,
        'risks' => false, 'vex' => true, 'sbom' => false, 'hbom' => false,
    ];

    /** Risks ticked by default: residual level rank 1 and 2 (the "high" of the risk page). */
    public const DEFAULT_RISK_FROM = 2;

    public const CONTACT_MAX = 300;

    public const RISK_TEXT_MAX = 300;

    /** Search engines may list the page of the latest version (the manufacturer's choice, on by default). */
    public const INDEXED = true;

    /**
     * @param  array<string, bool>  $sections
     * @param  list<string>  $documents  ids of the version's documents offered for download
     * @param  list<string>  $risks  ids of the risks listed one by one
     * @param  array<string, array{title: string, advice: string}>  $riskTexts  public wording per risk id
     */
    public function __construct(
        public readonly array $sections,
        public readonly array $documents,
        public readonly ?string $contact,
        public readonly ?string $supportUntil,
        public readonly array $risks,
        public readonly array $riskTexts,
        public readonly bool $saved,
        public readonly bool $indexed = self::INDEXED,
    ) {}

    /** The settings being edited (the draft), or the defaults when nothing is saved yet. */
    public static function for(ProductVersion $version): self
    {
        return self::from($version, (array) ($version->public_page['draft'] ?? []));
    }

    /** What visitors see, null when the version is not published. */
    public static function live(ProductVersion $version): ?self
    {
        $live = $version->public_page['live'] ?? null;

        return $version->published_at && is_array($live) ? self::from($version, $live) : null;
    }

    /** The published page asks search engines to list it (read without loading the rest, for the sitemap). */
    public static function indexed(ProductVersion $version): bool
    {
        $live = $version->public_page['live'] ?? null;

        return $version->published_at !== null && is_array($live) && (bool) ($live['indexed'] ?? self::INDEXED);
    }

    /** The draft differs from what is published (changes not published yet). */
    public static function hasUnpublishedChanges(ProductVersion $version): bool
    {
        $draft = $version->public_page['draft'] ?? null;

        $live = $version->public_page['live'] ?? null;
        if (is_array($live)) {
            unset($live['at']);
        }

        return $version->published_at !== null && is_array($draft) && $draft != $live;
    }

    /** @param  array<string, mixed>  $stored */
    private static function from(ProductVersion $version, array $stored): self
    {
        $saved = $stored !== [];

        $sections = [];
        foreach (self::SECTIONS as $key => $default) {
            $sections[$key] = (bool) ($stored['sections'][$key] ?? $default);
        }

        $documentIds = $version->documents()->pluck('id')->all();
        $documents = array_values(array_intersect((array) ($stored['documents'] ?? []), $documentIds));

        $riskIds = $version->riskEntries()->pluck('id')->all();
        $risks = $saved
            ? array_values(array_intersect((array) ($stored['risks'] ?? []), $riskIds))
            : self::risksFrom($version->riskEntries()->get(['id', 'residual_rank']), self::DEFAULT_RISK_FROM);

        $texts = [];
        foreach ((array) ($stored['risk_texts'] ?? []) as $id => $text) {
            if (in_array($id, $riskIds, true) && is_array($text)) {
                $texts[$id] = ['title' => trim((string) ($text['title'] ?? '')), 'advice' => trim((string) ($text['advice'] ?? ''))];
            }
        }

        $contact = $saved ? ($stored['contact'] ?? null) : $version->product->organisation->security_contact;

        return new self(
            sections: $sections,
            documents: $documents,
            contact: filled($contact) ? (string) $contact : null,
            supportUntil: self::date($stored['support_until'] ?? null),
            risks: $risks,
            riskTexts: $texts,
            saved: $saved,
            indexed: (bool) ($stored['indexed'] ?? self::INDEXED),
        );
    }

    /**
     * The risks whose residual level is at or above this rank (rank 1 is the highest level).
     *
     * @param  Collection<int, RiskEntry>  $entries
     * @return list<string>
     */
    public static function risksFrom(Collection $entries, int $rank): array
    {
        return $entries->filter(fn (RiskEntry $e) => $e->residual_rank !== null && $e->residual_rank <= $rank)->pluck('id')->values()->all();
    }

    public function shows(string $section): bool
    {
        return $this->sections[$section] ?? false;
    }

    /** The public wording of a risk: what the manufacturer wrote, else the threat's name. */
    public function riskTitle(RiskEntry $entry): string
    {
        $title = $this->riskTexts[$entry->id]['title'] ?? '';

        return $title !== '' ? $title : (string) $entry->threat;
    }

    public function riskAdvice(RiskEntry $entry): string
    {
        return $this->riskTexts[$entry->id]['advice'] ?? '';
    }

    /**
     * What publishing still needs: the switched-on contact and date filled in.
     *
     * @return list<string> section keys
     */
    public function missing(): array
    {
        $missing = [];
        if ($this->shows('contact') && $this->contact === null) {
            $missing[] = 'contact';
        }
        if ($this->shows('support') && $this->supportUntil === null) {
            $missing[] = 'support';
        }

        return $missing;
    }

    /** @return array<string, mixed> one copy (draft or live) of product_versions.public_page */
    public function toArray(): array
    {
        return [
            'sections' => $this->sections,
            'documents' => $this->documents,
            'contact' => $this->contact,
            'support_until' => $this->supportUntil,
            'risks' => $this->risks,
            'risk_texts' => $this->riskTexts,
            'indexed' => $this->indexed,
        ];
    }

    /** A security contact as a link target: mailto: for an e-mail address, the address itself for a web page. */
    public static function contactHref(string $contact): ?string
    {
        if (filter_var($contact, FILTER_VALIDATE_EMAIL)) {
            return 'mailto:'.$contact;
        }

        return preg_match('#^https?://#i', $contact) === 1 && filter_var($contact, FILTER_VALIDATE_URL) ? $contact : null;
    }

    private static function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
