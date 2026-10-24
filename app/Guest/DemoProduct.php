<?php

namespace App\Guest;

use App\Actions\CreateProduct;
use App\Assessment\ArticlesCheck;
use App\Assessment\Assessment;
use App\Assessment\ProductImage;
use App\Assessment\ScopingForm;
use App\Content\ContentPack;
use App\Content\Text;
use App\Declaration\DeclarationData;
use App\Enums\ConformityRoute;
use App\Enums\Verdict;
use App\Models\Evaluation;
use App\Models\InventoryItem;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\User;
use App\Support\ProductModels;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * "See a demo": a complete, lab-grade product assessment loaded into a guest sandbox. The product
 * (description, scoping, risks, inventory, decision-tree walks with their justifications, E.Info,
 * assessment unit results with their evidence, conformity answers, declaration details and picture) is DATA of the content pack
 * (<pack>/demo/<slug>.json, schema redact.demo/1, texts en/fr); this class only writes it through
 * the same models the screens use. Without the file the demo is not offered (available()).
 *
 * Two ways in: a guest sandbox ("See a demo", the product is an ordinary sandbox product and the
 * fictitious manufacturer is set on the sandbox's own workspace, settings.demo_manufacturer, so
 * ClaimSandbox never keeps it as a real legal identity), or an account's dashboard ("Load the demo":
 * products.is_demo, plan slot "demo", read-only, one per organisation; the organisation's legal
 * details are never touched, the declaration reads the fictitious manufacturer from here,
 * manufacturer()). Nothing is logged per row: one "demo loaded" entry in the version's history.
 */
class DemoProduct
{
    public const SLUG = 'smart-bulb';

    public const SCHEMA = 'redact.demo/1';

    /** organisations.settings flag: the legal name and address are the demo's, not the person's. */
    public const MANUFACTURER_FLAG = 'demo_manufacturer';

    /** @var array<string, mixed>|null */
    private ?array $data = null;

    public function __construct(
        private readonly ContentPack $pack,
        private readonly CreateProduct $create,
        private readonly ScopingForm $form,
    ) {}

    /** The demo file of the pack in use, or null (the fixture and open demo packs have none). */
    public function file(): ?string
    {
        $path = rtrim($this->pack->path(), '/\\').DIRECTORY_SEPARATOR.'demo'.DIRECTORY_SEPARATOR.self::SLUG.'.json';

        return is_file($path) && is_readable($path) ? $path : null;
    }

    /** Is there a demo to offer in a sandbox (the sandbox on and the pack ships one)? */
    public function available(): bool
    {
        return Sandbox::enabled() && $this->file() !== null;
    }

    /** Does the pack ship a demo (accounts load it from the dashboard)? */
    public function hasData(): bool
    {
        return $this->file() !== null;
    }

    /** The organisation an account's demo goes to: its first organisation as administrator, else its first one. */
    public static function organisationOf(User $user): ?Organisation
    {
        $ordered = fn () => $user->organisations()->orderBy('organisation_members.created_at')->orderBy('organisations.id');

        return $ordered()->wherePivot('role', 'admin')->first() ?? $ordered()->first();
    }

    /** The demo product of an organisation, if it loaded one. */
    public static function of(Organisation $organisation): ?Product
    {
        return Product::query()->where('organisation_id', $organisation->getKey())->where('is_demo', true)->orderBy('created_at')->first();
    }

    /**
     * The demo's fictitious manufacturer in the current language (the declaration of a demo product),
     * or null when the pack has no demo.
     *
     * @return array{name: string, address: string}|null
     */
    public function manufacturer(): ?array
    {
        if (! $this->hasData()) {
            return null;
        }
        $manufacturer = (array) ($this->data()['manufacturer'] ?? []);
        $t = fn (mixed $value): string => trim(Text::of($value));

        return [
            'name' => $t($manufacturer['legal_name'] ?? ''),
            'address' => trim(implode("\n", array_filter([$t($manufacturer['address'] ?? ''), $t($manufacturer['country'] ?? '')]))),
        ];
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        if ($this->data === null) {
            $file = $this->file() ?? throw new RuntimeException('The content pack has no demo.');
            $data = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($data) || ($data['schema'] ?? null) !== self::SCHEMA) {
                throw new RuntimeException('The demo file is not '.self::SCHEMA.'.');
            }
            $this->data = $data;
        }

        return $this->data;
    }

    /**
     * Creates the demo product for $user, in the current language, and returns its version: a guest
     * gets an ordinary product in its sandbox workspace, an account the read-only demo product of
     * $organisation (default organisationOf()). The caller checks who may load it (sandbox limits,
     * one demo per organisation).
     */
    public function load(User $user, ?Organisation $organisation = null): ProductVersion
    {
        $data = $this->data();
        $locale = app()->getLocale();
        $t = fn (mixed $value): string => trim(Text::of($value, $locale));
        $account = ! $user->is_guest;
        $organisation ??= $account ? self::organisationOf($user) : $user->organisations()->orderBy('organisation_members.created_at')->first();
        if ($organisation === null) {
            throw new RuntimeException('No organisation to load the demo into.');
        }

        $version = activity()->withoutLogging(fn () => DB::transaction(function () use ($data, $t, $user, $organisation, $account) {
            if (! $account) {
                $this->sandboxManufacturer($organisation, (array) ($data['manufacturer'] ?? []), $t);
            }

            $product = (array) $data['product'];
            $versionData = (array) ($data['version'] ?? []);
            $version = ($this->create)($user, [
                'organisation_id' => (string) $organisation->getKey(),
                'name' => $t($product['name']),
                'reference' => $t($product['reference'] ?? '') ?: null,
                'description' => $t($product['description'] ?? '') ?: null,
                'version_label' => $t($versionData['label'] ?? '1.0') ?: '1.0',
                'answers' => array_map(fn ($v) => $v ? '1' : '0', (array) ($data['scoping'] ?? [])),
                'demo' => $account,
            ]);

            ProductModels::sync($version->product, array_map(
                fn (array $m) => ['value' => $t($m['value'] ?? ''), 'notes' => $t($m['notes'] ?? '')],
                array_values((array) ($product['models'] ?? [])),
            ));
            foreach (array_values((array) ($versionData['identifiers'] ?? [])) as $i => $identifier) {
                $version->identifiers()->create(['kind' => (string) $identifier['kind'], 'value' => $t($identifier['value']), 'position' => $i]);
            }
            $version->forceFill([
                'intended_use' => $t($versionData['intended_use'] ?? '') ?: null,
                'hardware_version' => $t($versionData['hardware_version'] ?? '') ?: null,
                'software_version' => $t($versionData['software_version'] ?? '') ?: null,
                'author_name' => $t($versionData['author_name'] ?? '') ?: $version->author_name,
                'completion_date' => now()->toDateString(),
            ])->save();

            ArticlesCheck::for($version->refresh())->confirm($user);

            $this->risks($version, (array) ($data['risks'] ?? []), $t, $user);
            $items = $this->inventory($version, $data, $t, $user);
            $this->evaluations($version, (array) ($data['evaluations'] ?? []), $items, $t, $user);
            foreach ((array) ($data['einfo'] ?? []) as $entry) {
                $version->einfoEntries()->create([
                    'einfo' => (string) $entry['code'],
                    'inventory_item_id' => isset($entry['item']) ? $this->itemId($items, (string) $entry['item']) : null,
                    'text' => $t($entry['text']),
                    'updated_by' => $user->id,
                ]);
            }

            // The result of every assessment unit of the standard, with its evidence (the "Assessment
            // units" step): provided by the manufacturer, so recorded by the person loading the demo.
            foreach ((array) ($data['units'] ?? []) as $unit) {
                $version->caseResults()->create([
                    'standard' => (string) $unit['standard'],
                    'requirement' => (string) $unit['requirement'],
                    'case_key' => (string) $unit['case'],
                    'verdict' => Verdict::from((string) $unit['verdict']),
                    'evidence' => $t($unit['evidence']),
                    'recorded_by' => $user->id,
                    'independent' => false,
                ]);
            }

            // The restriction answers (Conformity step), then the route they allow.
            $version->refresh();
            $settled = Assessment::for($version)->conformity()->settledQuestions();
            $this->form->save($version, array_map(fn ($v) => $v ? '1' : '0', (array) ($data['conformity'] ?? [])), ScopingForm::CONFORMITY, $user->id, $settled);
            $route = ConformityRoute::from((string) ($data['route'] ?? ConformityRoute::ModuleA->value));
            $version->forceFill([
                'conformity_route' => $route,
                'declaration' => $this->declaration((array) ($data['declaration'] ?? []), $t),
            ])->save();

            return $version;
        }));

        activity()->withoutLogging(fn () => $this->picture($version, (string) ($data['picture'] ?? '')));

        activity('version')->performedOn($version)->causedBy($user)
            ->withProperties(['demo' => self::SLUG])->log('demo loaded');

        return $version->refresh();
    }

    /** @param array<string, mixed> $manufacturer */
    private function sandboxManufacturer(Organisation $organisation, array $manufacturer, callable $t): void
    {
        if ($manufacturer === []) {
            return;
        }
        $organisation->forceFill([
            'legal_name' => $t($manufacturer['legal_name'] ?? ''),
            'address' => $t($manufacturer['address'] ?? ''),
            'country' => $t($manufacturer['country'] ?? ''),
            'settings' => [...(array) $organisation->settings, self::MANUFACTURER_FLAG => true],
        ])->save();
    }

    /** @param array<array-key, array<string, mixed>> $risks */
    private function risks(ProductVersion $version, array $risks, callable $t, User $user): void
    {
        foreach (array_values($risks) as $i => $risk) {
            $version->riskEntries()->create([
                'position' => $i + 1,
                'threat' => $t($risk['threat']),
                'description' => $t($risk['description'] ?? '') ?: null,
                'stride' => array_values((array) ($risk['stride'] ?? [])),
                'risk' => $t($risk['risk'] ?? '') ?: null,
                'impact' => (int) $risk['impact'],
                'likelihood' => (int) $risk['likelihood'],
                'articles' => array_values((array) $risk['articles']),
                'requirements' => array_values((array) ($risk['requirements'] ?? [])),
                'remediation' => $t($risk['remediation'] ?? '') ?: null,
                'implementation' => $t($risk['implementation'] ?? '') ?: null,
                'residual_rank' => (int) $risk['residual_rank'],
                'updated_by' => $user->id,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, InventoryItem> code => item
     */
    private function inventory(ProductVersion $version, array $data, callable $t, User $user): array
    {
        $lists = $this->pack->lists();
        $items = [];
        foreach ((array) ($data['inventory'] ?? []) as $list => $rows) {
            if (! isset($lists[$list])) {
                throw new RuntimeException("The demo uses an unknown list: {$list}.");
            }
            foreach (array_values((array) $rows) as $i => $row) {
                $items[(string) $row['code']] = $version->inventoryItems()->create([
                    'list' => $list,
                    'code' => (string) $row['code'],
                    'name' => $t($row['name']),
                    'description' => $t($row['description'] ?? '') ?: null,
                    'attributes' => (array) ($row['attributes'] ?? []),
                    'position' => $i + 1,
                    'updated_by' => $user->id,
                ]);
            }
        }
        foreach ((array) ($data['links'] ?? []) as $link) {
            $version->inventoryLinks()->create([
                'relation' => (string) $link['relation'],
                'from_item_id' => $this->itemId($items, (string) $link['from']),
                'to_item_id' => $this->itemId($items, (string) $link['to']),
            ]);
        }
        $none = array_values(array_filter((array) ($data['none'] ?? []), fn ($list) => is_string($list) && isset($lists[$list]) && ! isset($data['inventory'][$list])));
        $version->forceFill(['inventory_none' => $none ?: null])->save();

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $evaluations
     * @param  array<string, InventoryItem>  $items
     */
    private function evaluations(ProductVersion $version, array $evaluations, array $items, callable $t, User $user): void
    {
        foreach ($evaluations as $row) {
            $tree = $this->pack->tree((string) $row['standard'], (string) $row['requirement'])
                ?? throw new RuntimeException("No tree for {$row['standard']} {$row['requirement']}.");
            $walk = $tree->walk(array_map('strval', (array) $row['answers']));
            if ($walk['verdict'] === null) {
                throw new RuntimeException("The demo walk of {$row['requirement']} on {$row['item']} does not reach a result.");
            }
            Evaluation::create([
                'product_version_id' => $version->id,
                'standard' => (string) $row['standard'],
                'requirement' => (string) $row['requirement'],
                'inventory_item_id' => isset($row['item']) ? $this->itemId($items, (string) $row['item']) : null,
                'decision_node' => $walk['last_decision'],
                'path' => $walk['path'],
                'verdict' => $walk['verdict'],
                'justification' => $t($row['justification']),
                'updated_by' => $user->id,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $declaration
     * @return array<string, mixed>
     */
    private function declaration(array $declaration, callable $t): array
    {
        $out = [];
        foreach (array_keys(DeclarationData::FIELDS) as $field) {
            if (array_key_exists($field, $declaration)) {
                $out[$field] = $t($declaration[$field]);
            }
        }
        // "today": the declaration is dated the day the demo is loaded.
        if (($out['date'] ?? null) === 'today') {
            $out['date'] = now()->toDateString();
        }
        $out['standards'] = array_map(fn (array $s) => ['reference' => $t($s['reference']), 'article' => (string) $s['article']], array_values((array) ($declaration['standards'] ?? [])));
        $out['legislation'] = array_map(fn ($l) => $t($l), array_values((array) ($declaration['legislation'] ?? [])));

        return $out;
    }

    /** The product picture, through ProductImage like an upload (re-encoded, private disk). */
    private function picture(ProductVersion $version, string $name): void
    {
        $file = $name !== '' ? dirname((string) $this->file()).DIRECTORY_SEPARATOR.basename($name) : null;
        if ($file === null || ! is_file($file)) {
            return;
        }
        app(ProductImage::class)->store($version->product, new UploadedFile($file, basename($file), null, null, true));
    }

    /** @param array<string, InventoryItem> $items */
    private function itemId(array $items, string $code): string
    {
        return (string) (($items[$code] ?? throw new RuntimeException("The demo refers to an unknown item: {$code}."))->getKey());
    }
}
