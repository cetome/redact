<?php

namespace App\PublicPage;

use App\Models\ProductVersion;

/**
 * Printable QR labels of a published version, in millimetres: one on the product, a packaging
 * sticker, a block for the user manual. An A4 sheet holds as many copies as fit inside its margin.
 */
final class Labels
{
    /** @var array<string, array{w: int, h: int, gap: int}> */
    public const KINDS = [
        'product' => ['w' => 20, 'h' => 24, 'gap' => 2],
        'packaging' => ['w' => 50, 'h' => 25, 'gap' => 3],
        'manual' => ['w' => 105, 'h' => 42, 'gap' => 4],
    ];

    /** A4 and its margin, as printers leave a few millimetres blank. */
    public const SHEET = ['w' => 210, 'h' => 297, 'margin' => 8];

    /** @return array{cols: int, rows: int, count: int} */
    public static function grid(string $kind): array
    {
        $size = self::KINDS[$kind];
        $fit = fn (int $room, int $cell) => max(1, intdiv($room - 2 * self::SHEET['margin'] + $size['gap'], $cell + $size['gap']));
        $cols = $fit(self::SHEET['w'], $size['w']);
        $rows = $fit(self::SHEET['h'], $size['h']);

        return ['cols' => $cols, 'rows' => $rows, 'count' => $cols * $rows];
    }

    /**
     * What the published page shows, in a few words for the manual label ("Assessment, security
     * updates and documents."), in the label's language; null when it shows none of them. Also the
     * end of the page's search description (public-product/show).
     */
    public static function contents(ProductVersion $version, string $lang): ?string
    {
        $live = PublicSettings::live($version);
        if ($live === null) {
            return null;
        }
        $items = collect(['assessment', 'support', 'contact', 'documents', 'vex', 'risks'])->filter(fn ($s) => $live->shows($s))
            ->when($live->shows('sbom') || $live->shows('hbom'), fn ($c) => $c->push('bom'))
            ->map(fn ($s) => __('public_page.labels.contents.'.$s, [], $lang))->values();
        if ($items->isEmpty()) {
            return null;
        }
        $text = $items->count() === 1 ? $items[0] : $items->slice(0, -1)->implode(', ').' '.__('public_page.labels.and', [], $lang).' '.$items->last();

        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1).'.';
    }
}
