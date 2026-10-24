<?php

namespace App\Ai;

use App\Models\AiSuggestion;
use App\Models\Evaluation;
use App\Models\ProductVersion;
use App\Models\User;

/**
 * Creating and finding suggestions (the AI channel).
 */
final class Suggestions
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function create(ProductVersion $version, Feature $feature, string $type, string $key, string $field,
        string $origin, array $payload, AiResult $result, User $by, string $status = 'pending'): AiSuggestion
    {
        return AiSuggestion::create([
            'product_version_id' => $version->getKey(),
            'feature' => $feature->value,
            'target_type' => $type,
            'target_key' => $key,
            'field' => $field,
            'origin' => $origin,
            'payload' => $payload,
            'status' => $status,
            'provider' => mb_substr($result->provider, 0, 32),
            'model' => mb_substr($result->model, 0, 80),
            'requested_by' => $by->getKey(),
        ]);
    }

    /** Earlier pending suggestions of the same kind on the same target are out of date. */
    public static function supersede(ProductVersion $version, Feature $feature, ?string $type = null, ?string $key = null, ?string $field = null): void
    {
        AiSuggestion::query()->where('product_version_id', $version->getKey())
            ->where('feature', $feature->value)->where('status', 'pending')
            ->when($type !== null, fn ($q) => $q->where('target_type', $type))
            ->when($key !== null, fn ($q) => $q->where('target_key', $key))
            ->when($field !== null, fn ($q) => $q->where('field', $field))
            ->update(['status' => 'superseded', 'updated_at' => now()]);
    }

    /** The latest check or path suggestion of an evaluation (pending: not dismissed). */
    public static function latestFor(Evaluation $evaluation, Feature $feature): ?AiSuggestion
    {
        return AiSuggestion::query()
            ->where('product_version_id', $evaluation->product_version_id)
            ->where('feature', $feature->value)
            ->where('target_type', 'evaluation')
            ->where('target_key', $evaluation->getKey())
            ->where('status', 'pending')
            ->latest()->first();
    }

    /**
     * Sources as stored: [{document, location}], only documents of this version.
     *
     * @param  list<string>  $documentNames
     * @return list<array{document: string, location: string}>
     */
    public static function sources(mixed $raw, array $documentNames): array
    {
        $known = [];
        foreach ($documentNames as $name) {
            $known[mb_strtolower($name)] = $name;
        }

        $sources = [];
        foreach (is_array($raw) ? $raw : [] as $source) {
            if (! is_array($source)) {
                continue;
            }
            $document = $known[mb_strtolower(trim((string) ($source['document'] ?? '')))] ?? null;
            if ($document === null) {
                continue;
            }
            $sources[] = ['document' => $document, 'location' => mb_substr(trim((string) ($source['location'] ?? '')), 0, 100)];
            if (count($sources) >= 5) {
                break;
            }
        }

        return $sources;
    }
}
