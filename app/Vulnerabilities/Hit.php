<?php

namespace App\Vulnerabilities;

/**
 * One vulnerability one source reports for one component. Hits about the same vulnerability
 * (same CVE, or sharing an alias) from several sources are merged into one finding.
 */
final class Hit
{
    /**
     * @param  list<string>  $aliases
     * @param  list<string>  $fixed
     * @param  list<string>  $sources
     * @param  array<string, string>  $urls
     * @param  list<string>  $exploitedBy
     */
    public function __construct(
        public string $id,
        public array $aliases = [],
        public ?string $title = null,
        public ?string $description = null,
        public ?float $cvss = null,
        public ?string $severity = null,
        public array $fixed = [],
        public array $sources = [],
        public array $urls = [],
        public string $confidence = 'exact',
        public ?string $matchedOn = null,
        public ?string $published = null,
        public bool $exploited = false,
        public array $exploitedBy = [],
    ) {}

    /** The id findings are stored under: the CVE when there is one. */
    public function key(): string
    {
        foreach (array_merge([$this->id], $this->aliases) as $id) {
            if (preg_match('/^CVE-\d{4}-\d{4,}$/', $id)) {
                return $id;
            }
        }

        return $this->id;
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_values(array_unique(array_filter(array_merge([$this->id], $this->aliases))));
    }

    public function cve(): ?string
    {
        $key = $this->key();

        return str_starts_with($key, 'CVE-') ? $key : null;
    }

    public function merge(self $other): void
    {
        $ids = array_values(array_unique(array_merge($this->ids(), $other->ids())));
        $this->aliases = $ids;
        $key = $this->key();
        $this->id = $key;
        $this->aliases = array_values(array_diff($ids, [$key]));
        $this->title ??= $other->title;
        // NVD's English description is the reference text; otherwise the longest one.
        if ($this->description === null || (in_array('nvd', $other->sources, true) && $other->description !== null)) {
            $this->description = $other->description ?? $this->description;
        }
        if ($other->cvss !== null && ($this->cvss === null || in_array('nvd', $other->sources, true))) {
            $this->cvss = $other->cvss;
            $this->severity = $other->severity ?? Cvss::severity($other->cvss);
        }
        $this->severity ??= $other->severity;
        $this->fixed = array_values(array_unique(array_merge($this->fixed, $other->fixed)));
        $this->sources = array_values(array_unique(array_merge($this->sources, $other->sources)));
        $this->urls = $this->urls + $other->urls;
        // Exact beats possible: a purl or CPE match confirms a name-and-version one.
        if ($other->confidence === 'exact') {
            $this->confidence = 'exact';
            $this->matchedOn = $other->matchedOn ?? $this->matchedOn;
        }
        $this->matchedOn ??= $other->matchedOn;
        $this->published ??= $other->published;
        $this->exploited = $this->exploited || $other->exploited;
        $this->exploitedBy = array_values(array_unique(array_merge($this->exploitedBy, $other->exploitedBy)));
    }

    public static function urlFor(string $source, string $id): string
    {
        return match ($source) {
            'euvd' => 'https://euvd.enisa.europa.eu/vulnerability/'.rawurlencode($id),
            'nvd' => 'https://nvd.nist.gov/vuln/detail/'.rawurlencode($id),
            'osv' => 'https://osv.dev/vulnerability/'.rawurlencode($id),
            'kev' => 'https://www.cisa.gov/known-exploited-vulnerabilities-catalog?search_api_fulltext='.rawurlencode($id),
            default => '',
        };
    }
}
