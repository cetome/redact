<?php

namespace App\Assessment;

use App\Content\Text;
use Illuminate\Support\HtmlString;

/**
 * Texts of the standard's assessment criteria (content pack, English, and French where the pack has
 * cetome's translation): picks the locale, says which language it is in (so a French page marks
 * English text with lang="en"), and renders
 * the bullet lines ("- " and "  - ") as nested lists, the other lines as paragraphs. Escaped.
 */
final class CriteriaText
{
    /**
     * @return array{text: string, lang: string|null} lang is null when the text is in the page's language
     */
    public static function of(mixed $value): array
    {
        $locale = app()->getLocale();
        $text = Text::of($value);
        $lang = is_array($value) && ! array_key_exists($locale, $value)
            ? (array_key_exists('en', $value) ? 'en' : (string) array_key_first($value))
            : (is_string($value) ? 'en' : $locale);

        return ['text' => trim($text), 'lang' => $lang === $locale ? null : $lang];
    }

    public static function html(mixed $value, string $class = 'a-criteria-text'): HtmlString
    {
        ['text' => $text, 'lang' => $lang] = self::of($value);
        if ($text === '') {
            return new HtmlString('');
        }

        $html = '';
        $paragraph = [];
        $depth = 0; // open <ul> levels

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (preg_match('/^( *)[-–•]\s+(.*)$/u', $line, $m) === 1) {
                $html .= self::paragraph($paragraph);
                $paragraph = [];
                $level = min(intdiv(strlen($m[1]), 2) + 1, $depth + 1);
                if ($level > $depth) {
                    $html .= '<ul>';
                    $depth = $level;
                } else {
                    $html .= str_repeat('</li></ul>', $depth - $level).'</li>';
                    $depth = $level;
                }
                $html .= '<li>'.e(trim($m[2]));

                continue;
            }
            if (trim($line) === '') {
                $html .= str_repeat('</li></ul>', $depth).self::paragraph($paragraph);
                $depth = 0;
                $paragraph = [];

                continue;
            }
            if ($depth > 0 && str_starts_with($line, '  ')) {
                // A continuation of the bullet above.
                $html .= ' '.e(trim($line));

                continue;
            }
            $html .= str_repeat('</li></ul>', $depth);
            $depth = 0;
            $paragraph[] = trim($line);
        }
        $html .= str_repeat('</li></ul>', $depth).self::paragraph($paragraph);

        return new HtmlString('<div class="'.e($class).'"'.($lang ? ' lang="'.e($lang).'"' : '').'>'.$html.'</div>');
    }

    /** @param  list<string>  $lines */
    private static function paragraph(array $lines): string
    {
        return $lines === [] ? '' : '<p>'.implode('<br>', array_map(fn (string $l) => e($l), $lines)).'</p>';
    }
}
