<?php

namespace App\Ai\Features;

use App\Ai\AiClient;
use App\Ai\AiException;
use App\Ai\AiResult;
use App\Ai\Feature;
use App\Ai\Prompts\DraftPrompt;
use App\Ai\Suggestions;
use App\Ai\Target;
use App\Ai\Targets;
use App\Content\ContentPack;
use App\Documents\DocumentText;
use App\Enums\Origin;
use App\Jobs\Ai\DraftFromDocuments as DraftJob;
use App\Models\AiUsage;
use App\Models\EinfoEntry;
use App\Models\ProductVersion;
use App\Models\RiskEntry;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * "Draft from documentation": reads the version's documents and proposes the
 * description, intended use, inventory items, E.Info texts and risks, each as
 * a pending suggestion with its sources. Runs as a job (database queue, or
 * inline with QUEUE_CONNECTION=sync); the units are reserved at dispatch.
 */
class DraftFromDocuments
{
    public function __construct(private readonly AiClient $client) {}

    public static function units(ProductVersion $version): int
    {
        return Feature::draftUnits(DocumentText::totalBytes($version));
    }

    /** @return array{state: string, count?: int, message?: string, at?: string}|null */
    public static function status(ProductVersion $version): ?array
    {
        return Cache::get(self::statusKey($version));
    }

    /** A draft is under way (a job stuck for over 20 minutes no longer blocks a new one). */
    public static function running(ProductVersion $version): bool
    {
        $status = self::status($version);

        return ($status['state'] ?? null) === 'running'
            && isset($status['at']) && now()->subMinutes(20)->lessThan($status['at']);
    }

    public static function statusKey(ProductVersion $version): string
    {
        return 'ai:draft:'.$version->getKey();
    }

    /** Reserve the units and queue the job. */
    public function start(User $user, ProductVersion $version): void
    {
        if (! $version->documents()->whereNotNull('extracted_text')->where('extracted_text', '!=', '')->exists()) {
            throw AiException::invalid(__('ai.draft.no_documents'));
        }
        if (self::running($version)) {
            throw AiException::invalid(__('ai.draft.already_running'));
        }

        $usage = $this->client->reserve($user, $version, Feature::DraftFromDocuments, self::units($version));
        Cache::put(self::statusKey($version), ['state' => 'running', 'at' => now()->toIso8601String()], now()->addHour());

        DraftJob::dispatch($version->getKey(), $user->getKey(), $usage->getKey(), app()->getLocale());
    }

    /** The job's work. Returns the number of suggestions created. */
    public function run(ProductVersion $version, User $user, AiUsage $usage): int
    {
        $result = $this->client->call(DraftPrompt::make($version, DocumentText::forPrompt($version)), $usage);

        Suggestions::supersede($version, Feature::DraftFromDocuments);

        return $this->store($version, $user, $result);
    }

    private function store(ProductVersion $version, User $user, AiResult $result): int
    {
        $pack = app(ContentPack::class);
        $data = $result->data;
        $names = DocumentText::names($version);
        $count = 0;
        $suggest = function (string $type, string $key, string $field, array $payload) use ($version, $user, $result, &$count) {
            Suggestions::create($version, Feature::DraftFromDocuments, $type, $key, $field, Origin::AiGenerated->value, $payload, $result, $user);
            $count++;
        };

        // Description and intended use.
        foreach (['description' => $version->product->description, 'intended_use' => $version->intended_use] as $field => $current) {
            $text = trim((string) ($data[$field]['text'] ?? ''));
            if ($text !== '' && mb_strlen($text) <= 20000 && $text !== trim((string) $current)) {
                $suggest('product_version', $version->getKey(), $field, ['value' => $text, 'sources' => Suggestions::sources($data[$field]['sources'] ?? [], $names)]);
            }
        }

        // New inventory items, checked against each list's field schema.
        $items = $version->inventoryItems()->get();
        $seen = $items->map(fn ($i) => $i->list.'|'.mb_strtolower(trim((string) $i->name)))->flip()->all();
        foreach (is_array($data['items'] ?? null) ? $data['items'] : [] as $raw) {
            $list = $pack->lists()[(string) ($raw['list'] ?? '')] ?? null;
            $name = mb_substr(trim((string) ($raw['name'] ?? '')), 0, 200);
            if (! $list || $name === '' || isset($seen[$list['key'].'|'.mb_strtolower($name)])) {
                continue;
            }
            $seen[$list['key'].'|'.mb_strtolower($name)] = true;

            $attributes = [];
            foreach (is_array($raw['attributes'] ?? null) ? $raw['attributes'] : [] as $attribute) {
                $definition = collect($list['fields'] ?? [])->firstWhere('key', (string) ($attribute['field'] ?? ''));
                if (! $definition) {
                    continue;
                }
                $value = (new Target($version, 'inventory_item', 'new', 'attributes.'.$definition['key'], null, $definition))->accepts($attribute['value'] ?? null);
                if ($value !== null) {
                    $attributes[$definition['key']] = $value;
                }
            }

            $suggest('inventory_item', 'new:'.Str::lower((string) Str::ulid()), Targets::NEW_FIELD, [
                'list' => $list['key'],
                'name' => $name,
                'description' => mb_substr(trim((string) ($raw['description'] ?? '')), 0, 20000),
                'attributes' => $attributes,
                'sources' => Suggestions::sources($raw['sources'] ?? [], $names),
            ]);
        }

        // E.Info texts, only for codes of the pack and objects that exist.
        $einfo = $pack->einfo();
        $done = [];
        foreach (is_array($data['einfo'] ?? null) ? $data['einfo'] : [] as $raw) {
            $code = (string) ($raw['einfo'] ?? '');
            $definition = $einfo[$code] ?? null;
            $text = trim((string) ($raw['text'] ?? ''));
            if (! $definition || $text === '' || mb_strlen($text) > 20000) {
                continue;
            }
            $itemId = null;
            if (($definition['list'] ?? null) !== null) {
                $item = $items->first(fn ($i) => $i->list === $definition['list'] && strcasecmp($i->code, trim((string) ($raw['item_code'] ?? ''))) === 0);
                if (! $item) {
                    continue;
                }
                $itemId = $item->id;
            }
            $key = Targets::einfoKey($code, $itemId);
            $current = EinfoEntry::query()->where('product_version_id', $version->getKey())->where('einfo', $code)
                ->where('inventory_item_id', $itemId)->value('text');
            if (isset($done[$key]) || trim((string) $current) === $text) {
                continue;
            }
            $done[$key] = true;
            $suggest('einfo_entry', $key, 'text', ['value' => $text, 'sources' => Suggestions::sources($raw['sources'] ?? [], $names)]);
        }

        // New risks.
        $threats = $version->riskEntries()->pluck('threat')->map(fn ($t) => mb_strtolower(trim($t)))->flip()->all();
        foreach (is_array($data['risks'] ?? null) ? $data['risks'] : [] as $raw) {
            $threat = mb_substr(trim((string) ($raw['threat'] ?? '')), 0, 200);
            if ($threat === '' || isset($threats[mb_strtolower($threat)])) {
                continue;
            }
            $threats[mb_strtolower($threat)] = true;
            $stride = array_values(array_intersect(RiskEntry::STRIDE, array_map('strval', is_array($raw['stride'] ?? null) ? $raw['stride'] : [])));
            $suggest('risk_entry', 'new:'.Str::lower((string) Str::ulid()), Targets::NEW_FIELD, [
                'threat' => $threat,
                'stride' => $stride,
                'description' => mb_substr(trim((string) ($raw['description'] ?? '')), 0, 20000),
                'sources' => Suggestions::sources($raw['sources'] ?? [], $names),
            ]);
        }

        return $count;
    }
}
