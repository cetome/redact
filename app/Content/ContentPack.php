<?php

namespace App\Content;

use App\Content\Contracts\PackRules;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Loads the content pack (the know-how) from REDACT_CONTENT_PATH.
 *
 * Layout of a pack directory:
 *   pack.json          id, name, version, standards, families
 *   scoping.json       questionnaire (groups, questions, visibility conditions)
 *   risk.json          default risk levels, matrix, STRIDE labels, threat library
 *   lists.json         inventory root lists, their fields, and the relations between them
 *   requirements.json  requirements, the standards they belong to, the list they loop over
 *   einfo.json         E.Info catalogue
 *   trees/<standard>/<REQUIREMENT>.json   decision trees (format redact.tree/1)
 *   assessments/<standard>/<REQUIREMENT>.json   assessment criteria of the standard (optional)
 *   templates/mapping.json + templates/*.xlsx   the workbooks filled and imported (optional)
 *   templates/free/free.json + templates/free/*.xlsx   free workbooks of /templates (optional)
 *   rules.php          returns an App\Content\Contracts\PackRules instance
 *   catalogue.json, einfo-prefill.json, einfo-variants.json   optional, see their methods
 *   ai/prompts/<key>.md   wording of the AI instructions (optional, App\Ai\Prompts\PackPrompts)
 *   Full format: docs/CONTENT-PACK.md.
 *
 * Nothing from the pack is sent to the browser wholesale: controllers expose
 * only what the current page needs.
 */
class ContentPack
{
    /** @var array<string, mixed> */
    private array $data;

    /** @var array<string, Tree> */
    private array $trees = [];

    /** @var array<string, array<string, mixed>|null> "standard/REQ" => assessment criteria */
    private array $assessments = [];

    private ?PackRules $rules = null;

    /** @var array<string, mixed>|null templates/mapping.json, read on demand */
    private ?array $templateMapping = null;

    public function __construct(private readonly string $path)
    {
        if (! is_file($path.'/pack.json')) {
            throw new RuntimeException("No content pack found at {$path} (missing pack.json).");
        }

        $this->data = Cache::rememberForever('content-pack:'.$this->fingerprint(), fn () => $this->load());
    }

    public function path(): string
    {
        return $this->path;
    }

    public function id(): string
    {
        return $this->data['pack']['id'];
    }

    public function version(): string
    {
        return $this->data['pack']['version'];
    }

    /** "cetome-redact@1.0.0", stored on each product version for traceability. */
    public function reference(): string
    {
        return $this->id().'@'.$this->version();
    }

    public function name(): string
    {
        return Text::of($this->data['pack']['name'] ?? $this->id());
    }

    /** @return array<string, array<string, mixed>> standard slug => definition */
    public function standards(): array
    {
        return collect($this->data['pack']['standards'] ?? [])->keyBy('code')->all();
    }

    public function standardName(string $code): string
    {
        return Text::of($this->standards()[$code]['name'] ?? $code);
    }

    /** @return array<string, string> article code => name, e.g. "3.3d" => "Protection of the network" */
    public function articles(): array
    {
        return collect($this->data['pack']['articles'] ?? [])
            ->mapWithKeys(fn (array $a) => [$a['code'] => Text::of($a['name'] ?? $a['code'])])
            ->all();
    }

    /** @return array<string, array<string, mixed>> family code => definition */
    public function families(): array
    {
        return $this->data['pack']['families'] ?? [];
    }

    /** @return array<string, mixed> */
    public function scoping(): array
    {
        return $this->data['scoping'];
    }

    /** @return array<string, mixed> */
    public function risk(): array
    {
        return $this->data['risk'];
    }

    /** @return array<string, array<string, mixed>> list key => definition */
    public function lists(): array
    {
        return collect($this->data['lists']['lists'] ?? [])->keyBy('key')->all();
    }

    /** @return array<string, mixed> */
    public function list(string $key): array
    {
        return $this->lists()[$key] ?? throw new RuntimeException("Unknown list {$key}");
    }

    /** @return array<string, array<string, mixed>> relation key => definition */
    public function relations(): array
    {
        return collect($this->data['lists']['relations'] ?? [])->keyBy('key')->all();
    }

    /** @return array<string, array<string, mixed>> requirement code => definition */
    public function requirements(): array
    {
        return collect($this->data['requirements'])->keyBy('code')->all();
    }

    /** @return array<string, mixed>|null */
    public function requirement(string $code): ?array
    {
        return $this->requirements()[$code] ?? null;
    }

    /**
     * Requirements of one standard, in pack order.
     *
     * @return array<string, array<string, mixed>>
     */
    public function requirementsFor(string $standard): array
    {
        return array_filter($this->requirements(), fn (array $r) => in_array($standard, $r['standards'], true));
    }

    /**
     * Typical objects to pick from when filling a list (catalogue.json, optional):
     * each with a name, attribute values from the list's schema and its source.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogue(string $list): array
    {
        return array_values($this->data['catalogue'][$list] ?? []);
    }

    /**
     * Named groupings proposed for one list (catalogue.json "groups", optional): each with a key,
     * a name and "match", the attribute values an item must carry to belong to it. The first
     * matching definition names a group of items (EN 18031 lets assets with the same use case and
     * protection level be handled as one).
     *
     * @return list<array{key: string, name: mixed, match: array<string, mixed>}>
     */
    public function catalogueGroups(string $list): array
    {
        return array_values($this->data['catalogue_groups'][$list] ?? []);
    }

    /**
     * How the pack orders the catalogue picker (catalogue.json "ranking", optional):
     * {"order": [...keys App\Assessment\CatalogueSuggestions compares in turn], "presence": {value: rank}}.
     * Empty when the pack leaves the engine's default order.
     *
     * @return array<string, mixed>
     */
    public function catalogueRanking(): array
    {
        $ranking = $this->data['catalogue_ranking'] ?? [];

        return is_array($ranking) ? $ranking : [];
    }

    /**
     * Interchangeable words in E.Info identifiers (einfo-variants.json, optional): each
     * {"pattern": <PCRE with a named group "v">, "values": [...]}. An id printed for one kind of
     * object (e.g. "...KindA...") is also looked up as the same id for the other kinds of the
     * same set. Empty when the pack has none. App\Assessment\Justification::variants applies it.
     *
     * @return list<array{pattern: string, values: list<string>}>
     */
    public function einfoVariants(): array
    {
        $variants = [];
        foreach ($this->data['einfo_variants'] ?? [] as $variant) {
            if (is_array($variant) && is_string($variant['pattern'] ?? null) && is_array($variant['values'] ?? null)) {
                $variants[] = ['pattern' => $variant['pattern'], 'values' => array_values(array_map('strval', $variant['values']))];
            }
        }

        return $variants;
    }

    /** @return array<string, array<string, mixed>> E.Info code => definition */
    public function einfo(): array
    {
        return collect($this->data['einfo'])->keyBy('code')->all();
    }

    /**
     * Texts the pack pre-fills from the inventory (einfo-prefill.json, optional): E.Info code => rule
     * {"from": "description"} or {"from": "links", "list": <list>, "where": {<field>: <value>}}, with an
     * optional "claim" (translated lead sentence, ":item" = the object). The first rule naming a code wins. App\Assessment\EinfoPrefill computes the text.
     *
     * @return array<string, array<string, mixed>>
     */
    public function einfoPrefill(): array
    {
        $rules = [];
        foreach ($this->data['einfo_prefill'] ?? [] as $rule) {
            foreach ((array) ($rule['ids'] ?? []) as $code) {
                $rules[$code] ??= $rule;
            }
        }

        return $rules;
    }

    public function tree(string $standard, string $requirement): ?Tree
    {
        $key = $standard.'/'.$requirement;
        if (! array_key_exists($key, $this->trees)) {
            $raw = $this->data['trees'][$standard][$requirement] ?? null;
            $this->trees[$key] = $raw ? new Tree($raw) : null;
        }

        return $this->trees[$key];
    }

    /**
     * The standard's "Assessment criteria" clause of one requirement (assessments/<standard>/<REQ>.json,
     * optional): objective, implementation categories, required information, the justification
     * rules per decision node and the assessment cases (conceptual, functional completeness,
     * functional sufficiency). Null when the pack has no criteria for it.
     *
     * Read on demand (one file per requirement) and cached per file, so the pack's main cache
     * stays small: most pages never need these texts.
     *
     * @return array<string, mixed>|null
     */
    public function assessment(string $standard, string $requirement): ?array
    {
        $key = $standard.'/'.$requirement;
        if (! array_key_exists($key, $this->assessments)) {
            $this->assessments[$key] = null;
            if (preg_match('/^[A-Za-z0-9._-]+$/', $standard) === 1 && preg_match('/^[A-Za-z0-9._-]+$/', $requirement) === 1) {
                $file = $this->path.'/assessments/'.$key.'.json';
                if (is_file($file)) {
                    $this->assessments[$key] = Cache::rememberForever(
                        'content-pack:assessment:'.hash('sha256', $file.':'.filemtime($file).':'.filesize($file)),
                        fn () => json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR),
                    );
                }
            }
        }

        return $this->assessments[$key];
    }

    /**
     * The workbook mapping (templates/mapping.json, optional): which cell of each cetome workbook
     * holds which piece of a version, read both ways (App\Workbooks). Empty when the pack has none.
     *
     * @return array<string, mixed>
     */
    public function templateMapping(): array
    {
        if ($this->templateMapping === null) {
            $file = $this->path.'/templates/mapping.json';
            $this->templateMapping = is_file($file)
                ? Cache::rememberForever(
                    'content-pack:templates:'.hash('sha256', $file.':'.filemtime($file).':'.filesize($file)),
                    fn () => json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR),
                )
                : [];
        }

        return $this->templateMapping;
    }

    /**
     * Path of the template file of one workbook of the mapping (templates/<file>), or null when
     * the pack has no such workbook or the file is missing. Only plain file names are accepted.
     */
    public function template(string $key): ?string
    {
        foreach ($this->templateMapping()['workbooks'] ?? [] as $workbook) {
            if (($workbook['key'] ?? null) !== $key) {
                continue;
            }
            $name = (string) ($workbook['file'] ?? '');
            if ($name === '' || $name !== basename($name) || ! str_ends_with(strtolower($name), '.xlsx')) {
                return null;
            }
            $file = $this->path.'/templates/'.$name;

            return is_file($file) ? $file : null;
        }

        return null;
    }

    /**
     * The free workbooks offered without an account on /templates (templates/free/free.json and
     * the files next to it, optional): the name of the "Download all" archive and key => absolute
     * path, in manifest order, and key => path of its picture (images/<name>.webp, optional).
     * Only plain .xlsx file names and images/*.webp pictures that exist are kept; empty when the
     * pack has none (the engine then offers no template).
     *
     * @return array{zip: string, files: array<string, string>, images: array<string, string>}
     */
    public function freeTemplates(): array
    {
        $dir = $this->path.'/templates/free';
        $file = $dir.'/free.json';
        $manifest = is_file($file) ? (array) json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : [];

        $files = [];
        $images = [];
        foreach ((array) ($manifest['files'] ?? []) as $entry) {
            $key = (string) ($entry['key'] ?? '');
            $name = (string) ($entry['file'] ?? '');
            if (preg_match('/^[a-z0-9-]{1,40}$/', $key) !== 1 || $key === 'all' || isset($files[$key])
                || $name === '' || $name !== basename($name) || ! str_ends_with(strtolower($name), '.xlsx')) {
                continue;
            }
            if (! is_file($dir.'/'.$name)) {
                continue;
            }
            $files[$key] = $dir.'/'.$name;
            $image = (string) ($entry['image'] ?? '');
            if (preg_match('#^images/[A-Za-z0-9._-]+\.webp$#', $image) === 1 && is_file($dir.'/'.$image)) {
                $images[$key] = $dir.'/'.$image;
            }
        }

        $zip = basename((string) ($manifest['zip'] ?? ''));

        return ['zip' => str_ends_with(strtolower($zip), '.zip') ? $zip : 'templates.zip', 'files' => $files, 'images' => $images];
    }

    /** @return array<string, Tree> requirement code => tree */
    public function treesFor(string $standard): array
    {
        $trees = [];
        foreach (array_keys($this->data['trees'][$standard] ?? []) as $requirement) {
            $trees[$requirement] = $this->tree($standard, $requirement);
        }

        return $trees;
    }

    public function rules(): PackRules
    {
        if ($this->rules === null) {
            $rules = require $this->path.'/rules.php';
            if (! $rules instanceof PackRules) {
                throw new RuntimeException('rules.php of the content pack must return a PackRules instance.');
            }
            $this->rules = $rules;
        }

        return $this->rules;
    }

    /** Changes whenever a pack file changes, so the cache follows deployments. */
    private function fingerprint(): string
    {
        $parts = [];
        foreach ($this->files() as $file) {
            $parts[] = $file.':'.filemtime($file).':'.filesize($file);
        }

        return hash('sha256', implode('|', $parts));
    }

    /** @return list<string> */
    private function files(): array
    {
        $files = glob($this->path.'/*.json') ?: [];
        foreach (glob($this->path.'/trees/*/*.json') ?: [] as $tree) {
            $files[] = $tree;
        }
        sort($files);

        return $files;
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        $read = function (string $file, bool $required = true): array {
            $full = $this->path.'/'.$file;
            if (! is_file($full)) {
                if ($required) {
                    throw new RuntimeException("Content pack file missing: {$file}");
                }

                return [];
            }

            return json_decode((string) file_get_contents($full), true, 512, JSON_THROW_ON_ERROR);
        };

        $trees = [];
        foreach (glob($this->path.'/trees/*/*.json') ?: [] as $file) {
            $standard = basename(dirname($file));
            $trees[$standard][basename($file, '.json')] = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        }

        return [
            'pack' => $read('pack.json'),
            'scoping' => $read('scoping.json'),
            'risk' => $read('risk.json'),
            'lists' => $read('lists.json'),
            'requirements' => $read('requirements.json'),
            'einfo' => $read('einfo.json', false),
            'einfo_prefill' => $read('einfo-prefill.json', false)['rules'] ?? [],
            'catalogue' => $read('catalogue.json', false)['lists'] ?? [],
            'catalogue_groups' => $read('catalogue.json', false)['groups'] ?? [],
            'catalogue_ranking' => $read('catalogue.json', false)['ranking'] ?? [],
            'einfo_variants' => $read('einfo-variants.json', false)['variants'] ?? [],
            'trees' => $trees,
        ];
    }
}
