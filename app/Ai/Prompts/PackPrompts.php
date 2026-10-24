<?php

namespace App\Ai\Prompts;

use App\Content\ContentPack;
use Throwable;

/**
 * The wording of the AI instructions, from the content pack when it has it.
 *
 * A pack may ship ai/prompts/<key>.md (plain text, UTF-8): "system" is the opening paragraph of
 * every call (who the model assists, in which domain), the other keys are the task instructions
 * of each AI feature. A missing or empty file falls back to the engine's neutral wording, passed
 * by the caller. The engine's own guardrails (no verdict, untrusted data, JSON only, language)
 * are never read from the pack: SystemPrompt always adds them.
 */
final class PackPrompts
{
    /** Keys a pack may provide, with the feature they word. */
    public const KEYS = ['system', 'improve_text', 'suggest_scoping', 'check_evaluation', 'suggest_path', 'draft'];

    /** Longest text read from a pack file, in characters. */
    public const MAX_LENGTH = 20000;

    /** @var array<string, string|null> "path|mtime|size" => text */
    private static array $cache = [];

    /** The pack's text for $key, or $fallback when the pack has none. */
    public static function text(string $key, string $fallback): string
    {
        return self::fromPack($key) ?? $fallback;
    }

    /** The pack's text for $key, null when the pack has no (non-empty) file for it. */
    public static function fromPack(string $key): ?string
    {
        if (! in_array($key, self::KEYS, true)) {
            return null;
        }
        try {
            $file = app(ContentPack::class)->path().'/ai/prompts/'.$key.'.md';
            if (! is_file($file)) {
                return null;
            }
            $cacheKey = $file.'|'.filemtime($file).'|'.filesize($file);
            if (! array_key_exists($cacheKey, self::$cache)) {
                $text = (string) preg_replace('/^\xEF\xBB\xBF/', '', (string) file_get_contents($file));
                $text = str_replace(["\r\n", "\r"], "\n", $text);
                $text = rtrim(mb_substr($text, 0, self::MAX_LENGTH));
                self::$cache[$cacheKey] = $text === '' ? null : $text;
            }

            return self::$cache[$cacheKey];
        } catch (Throwable) {
            return null;
        }
    }
}
