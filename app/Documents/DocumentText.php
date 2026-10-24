<?php

namespace App\Documents;

use App\Ai\Prompts\SystemPrompt;
use App\Models\ProductVersion;

/**
 * The version's documents as untrusted <document> blocks for a prompt, within
 * a character budget shared between the documents.
 */
final class DocumentText
{
    public static function forPrompt(ProductVersion $version, int $budget = 300_000): string
    {
        $documents = $version->documents()->orderBy('created_at')
            ->get(['id', 'original_name', 'extracted_text', 'created_at'])
            ->filter(fn ($d) => trim((string) $d->extracted_text) !== '')
            ->values();
        if ($documents->isEmpty()) {
            return '';
        }

        $share = intdiv($budget, $documents->count());
        $blocks = [];
        foreach ($documents as $document) {
            $text = (string) $document->extracted_text;
            if (mb_strlen($text) > $share) {
                $text = mb_substr($text, 0, $share)."\n[... the rest of this document was cut ...]";
            }
            $blocks[] = SystemPrompt::data($text, 'document', ['name' => $document->original_name]);
        }

        return implode("\n\n", $blocks);
    }

    /** Total size of the version's documents in bytes (for the draft's unit price). */
    public static function totalBytes(ProductVersion $version): int
    {
        return (int) $version->documents()->sum('size');
    }

    /** @return list<string> */
    public static function names(ProductVersion $version): array
    {
        return $version->documents()->pluck('original_name')->all();
    }
}
