<?php

namespace App\Declaration;

/**
 * The fixed text of the EU declaration of conformity in every official language of the Directive:
 * Annex VI and VII of Directive 2014/53/EU and the title of Delegated Regulation (EU) 2022/30,
 * word for word from the Official Journal (resources/data/red-doc-annex-vi.json, its source and
 * retrieval date inside). Irish is missing: the Directive has no Irish version.
 *
 * Keys of a language: name, annex, title, title_numbered (:number), p1 … p9, p5_red, p5_other,
 * signed_for, place_date, name_function, footnote, simplified_title, simplified_lead,
 * simplified_text (:manufacturer, :type), simplified_url, delegated_regulation.
 */
final class AnnexText
{
    public const KEYS = ['name', 'annex', 'title', 'title_numbered', 'p1', 'p2', 'p3', 'p4', 'p5', 'p5_red', 'p5_other', 'p6', 'p7', 'p8', 'p9',
        'signed_for', 'place_date', 'name_function', 'footnote', 'simplified_title', 'simplified_lead', 'simplified_text', 'simplified_url',
        'delegated_regulation'];

    /** @var array{source: array<string, mixed>, languages: array<string, array<string, string>>}|null */
    private static ?array $data = null;

    public static function path(): string
    {
        return resource_path('data/red-doc-annex-vi.json');
    }

    /** @return array{source: array<string, mixed>, languages: array<string, array<string, string>>} */
    private static function data(): array
    {
        if (self::$data === null) {
            $raw = json_decode((string) file_get_contents(self::path()), true);
            self::$data = [
                'source' => (array) ($raw['source'] ?? []),
                'languages' => array_filter((array) ($raw['languages'] ?? []), 'is_array'),
            ];
        }

        return self::$data;
    }

    /** @return array<string, string> ISO 639-1 code => the language's own name, in the Official Journal's order */
    public static function languages(): array
    {
        return array_map(fn (array $text) => (string) $text['name'], self::data()['languages']);
    }

    public static function has(string $lang): bool
    {
        return isset(self::data()['languages'][$lang]);
    }

    /** One fixed text, with its placeholders replaced. */
    public static function get(string $lang, string $key, array $replace = []): string
    {
        $text = (string) (self::data()['languages'][$lang][$key] ?? self::data()['languages']['en'][$key] ?? '');
        foreach ($replace as $name => $value) {
            $text = str_replace(':'.$name, (string) $value, $text);
        }

        return $text;
    }

    /** The language of a request or a person, else English. */
    public static function pick(?string $lang): string
    {
        $lang = strtolower((string) $lang);

        return self::has($lang) ? $lang : 'en';
    }

    /** @return array<string, mixed> where the texts come from */
    public static function source(): array
    {
        return self::data()['source'];
    }
}
