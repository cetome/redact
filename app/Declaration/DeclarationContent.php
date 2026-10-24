<?php

namespace App\Declaration;

use App\Enums\ConformityRoute;
use Illuminate\Support\Carbon;

/**
 * What the EU declaration of conformity says, in one official language, as blocks that the
 * Word (DeclarationWord) and PDF (DeclarationPdf) renderers lay out the same way.
 *
 * The model of Annex VI of Directive 2014/53/EU point by point: the fixed text is the Official
 * Journal's, word for word (AnnexText); what the person entered stays as entered (product,
 * models, versions, manufacturer, standards, Notified Body, place, signatory). Only English and
 * French, the app's languages, add short labels ("Product:", "Not applicable."); the other
 * languages show the values alone. A declaration with open items carries the "DRAFT — not
 * valid" notice and watermark, in the person's language.
 *
 * Block shapes:
 *  - ['type' => 'notice', 'title' => string, 'lines' => list<string>]
 *  - ['type' => 'title', 'text' => string]
 *  - ['type' => 'point', 'number' => '1.', 'heading' => string, 'lines' => list<Line>,
 *     'table' => list<array{0: string, 1: string}>|null, 'header' => array{0: string, 1: string}|null, 'image' => string|null]
 *  - ['type' => 'signature', 'rows' => list<array{0: string, 1: string}>, 'caption' => string|null]
 * Line: ['text' => string, 'bold' => bool, 'placeholder' => bool]
 */
final class DeclarationContent
{
    /** Languages with REDact's own labels (lang/<code>/declaration.php). */
    public const LABELLED = ['en', 'fr'];

    public readonly string $lang;

    public function __construct(public readonly DeclarationData $data, ?string $lang = null)
    {
        $this->lang = AnnexText::pick($lang ?? app()->getLocale());
    }

    /** A fixed text of Annex VI in the document's language. */
    public function annex(string $key, array $replace = []): string
    {
        return AnnexText::get($this->lang, $key, $replace);
    }

    /** One of REDact's labels, only in the languages that have them. */
    private function label(string $key, array $replace = []): ?string
    {
        return in_array($this->lang, self::LABELLED, true) ? __('declaration.'.$key, $replace, $this->lang) : null;
    }

    public function day(?\DateTimeInterface $date): string
    {
        // "20 October 2026" in the app's languages (house style), else the language's own long date ("20. Oktober 2026").
        return $date ? Carbon::instance($date)->locale($this->lang)->isoFormat(in_array($this->lang, self::LABELLED, true) ? 'D MMMM YYYY' : 'LL') : '';
    }

    /** "Annex VI title · product" (document properties). */
    public function documentTitle(): string
    {
        return $this->annex('title').' · '.$this->data->version()->product->name;
    }

    /** The running footer, before the page counter. */
    public function footer(): string
    {
        $version = $this->data->version();

        return trim($this->annex('title').' · '.$version->product->name.' · '.$version->label);
    }

    public function creator(): string
    {
        return $this->data->manufacturerName() ?: 'REDact';
    }

    /** The watermark text, or null when the declaration is valid. */
    public function watermark(): ?string
    {
        return $this->data->watermark();
    }

    /** @return array{text: string, bold: bool, placeholder: bool} */
    private static function line(string $text, bool $bold = false, bool $placeholder = false): array
    {
        return ['text' => $text, 'bold' => $bold, 'placeholder' => $placeholder];
    }

    /**
     * A text as lines, or the placeholder when it is empty.
     *
     * @return list<array{text: string, bold: bool, placeholder: bool}>
     */
    private static function lines(string $text, string $empty = '', bool $bold = false): array
    {
        $text = trim(str_replace("\r", '', $text));
        if ($text === '') {
            return $empty === '' ? [] : [self::line($empty, false, true)];
        }

        return array_map(fn (string $l) => self::line($l, $bold), explode("\n", $text));
    }

    /** @return list<array<string, mixed>> */
    public function blocks(): array
    {
        $data = $this->data;
        $version = $data->version();
        $todo = $this->label('word.to_complete') ?? '…';
        $product = (string) $version->product->name;
        $point = fn (string $number, string $heading, array $lines = [], array $extra = []) => $extra + ['type' => 'point', 'number' => $number, 'heading' => $heading,
            'lines' => $lines, 'table' => null, 'header' => null, 'image' => null];
        $prefix = fn (string $key) => ($label = $this->label($key)) !== null ? $label.' ' : '';
        $blocks = [];

        if ($data->isDraft()) {
            $blocks[] = ['type' => 'notice', 'title' => (string) $data->watermark(), 'lines' => array_map(fn (array $i) => $i['label'], $data->openItems())];
        }
        $number = $data->stored('number');
        $blocks[] = ['type' => 'title', 'text' => $number !== null ? $this->annex('title_numbered', ['number' => $number]) : $this->annex('title')];

        // 1. Radio equipment (product, type, batch or serial number)
        // The product, then each model and identifier once (with its note), then the hardware and
        // software versions; a "Type:" line only when no identifier gives the type (the product reference).
        $lines = [self::line($prefix('word.product').$product, true)];
        $identifiers = $data->identifiers();
        $typed = collect($identifiers)->whereIn('kind', ['model', 'type', 'reference', 'hvin'])->isNotEmpty();
        if (! $typed && $data->typeDesignation() !== $product) {
            $lines[] = self::line($prefix('word.type').$data->typeDesignation());
        }
        foreach ($identifiers as $identifier) {
            $value = $identifier['value'].($identifier['notes'] !== '' ? ' ('.$identifier['notes'].')' : '');
            $kind = $this->label('word.product') !== null ? __('identification.kinds.'.$identifier['kind'], [], $this->lang) : null;
            $lines[] = self::line($kind !== null ? $kind.($this->lang === 'fr' ? ' : ' : ': ').$value : $value);
        }
        $serials = $data->stored('serials');
        if ($serials !== null || $this->label('word.serials') !== null) {
            $lines[] = self::line($prefix('word.serials').($serials ?? (string) $this->label('word.serials_all')));
        }
        $blocks[] = $point('1.', $this->annex('p1'), $lines);

        // 2. Name and address of the manufacturer or his authorised representative
        $lines = [
            ...self::lines($data->hasManufacturer() ? $data->manufacturerName() : '', $todo, true),
            ...self::lines($data->manufacturerAddress(), $data->hasManufacturer() ? '' : $todo),
        ];
        if (($representative = $data->authorisedRepresentative()) !== null) {
            if (($label = $this->label('word.representative')) !== null) {
                $lines[] = self::line($label, true);
            }
            $lines = [...$lines, ...self::lines($representative)];
        }
        $blocks[] = $point('2.', $this->annex('p2'), $lines);

        // 3. Sole responsibility
        $blocks[] = $point('3.', $this->annex('p3'));

        // 4. Object of the declaration
        $blocks[] = $point('4.', $this->annex('p4'), self::lines($data->value('object'), $todo),
            ['image' => $data->includeImage() ? $data->imagePath() : null]);

        // 5. Union harmonisation legislation: the Directive, the Delegated Regulation that applies
        // its article 3(3)(d), (e), (f) (official title), the other acts entered by the person.
        $lines = [self::line($this->annex('p5_red'), true)];
        if ($data->articlesInScope() !== []) {
            $lines[] = self::line($this->annex('delegated_regulation'));
        }
        if ($data->legislation() !== []) {
            $lines[] = self::line($this->annex('p5_other'), true);
            foreach ($data->legislation() as $act) {
                $lines[] = self::line($act);
            }
        }
        $blocks[] = $point('5.', $this->annex('p5'), $lines);

        // 6. Harmonised standards and other technical specifications
        $article = fn (string $code) => $this->label('word.product') !== null
            ? __('declaration.article', ['ref' => DeclarationData::articleCode($code)], $this->lang)
            : DeclarationData::articleCode($code);
        $rows = [];
        foreach ($data->assessedStandards() as $standard) {
            $rows[] = [$standard['reference'], $standard['code'] !== '' ? $article($standard['code']) : $standard['article']];
        }
        foreach ($data->otherStandards() as $standard) {
            $rows[] = [$standard['reference'], $standard['article'] === 'other' ? '' : $article($standard['article'])];
        }
        $reference = $this->label('word.reference');
        $blocks[] = $rows === []
            ? $point('6.', $this->annex('p6'), self::lines('', $todo))
            : $point('6.', $this->annex('p6'), [], ['table' => $rows, 'header' => $reference !== null ? [$reference, (string) $this->label('word.requirement')] : null]);

        // 7. Notified body
        $body = $data->notifiedBody();
        if ($body === null) {
            $lines = [self::line($this->label('word.nb_na') ?? '—')];
        } else {
            $values = [
                'name' => $body['name'] !== '' ? $body['name'] : $todo,
                'number' => $body['number'] !== '' ? $body['number'] : $todo,
                'task' => $body['task'] !== '' ? $body['task'] : $todo,
                'certificate' => $body['certificate'] !== '' ? $body['certificate'] : $todo,
            ];
            // Module H: the body approves the quality system, it issues no EU-type examination certificate.
            $sentence = $this->label($version->conformity_route === ConformityRoute::ModuleH ? 'word.nb_sentence_h' : 'word.nb_sentence', $values);
            $lines = $sentence !== null
                ? [self::line($sentence)]
                : [self::line($values['name'].' ('.$values['number'].')', true), self::line($values['task']), self::line($values['certificate'], true)];
            $issued = $this->day($version->certificate_issued_on);
            $expires = $this->day($version->certificate_expires_on);
            if ($issued !== '' || $expires !== '') {
                $lines[] = self::line($this->label('word.issued_on') !== null
                    ? trim(($issued !== '' ? __('declaration.word.issued_on', ['date' => $issued], $this->lang) : '')
                        .($expires !== '' ? ' '.__('declaration.word.valid_until', ['date' => $expires], $this->lang) : ''))
                    : trim($issued.($expires !== '' ? ' – '.$expires : '')));
            }
        }
        $blocks[] = $point('7.', $this->annex('p7'), $lines);

        // 8. Accessories and components, including software
        $blocks[] = $point('8.', $this->annex('p8'), self::lines($data->value('accessories'), $this->label('word.not_applicable') ?? '—'));

        // 9. Additional information
        $blocks[] = $point('9.', $this->annex('p9'), self::lines($data->value('additional')));

        // Signature, left blank for a handwritten or electronic signature.
        $date = $data->stored('date');
        $issuedOn = $date !== null && strtotime($date) !== false ? $this->day(Carbon::parse($date)) : '';
        $blocks[] = ['type' => 'signature', 'rows' => [
            [$this->annex('signed_for'), $data->value('signed_for') ?: $todo],
            [$this->annex('place_date'), ($data->stored('place') ?? $todo).', '.($issuedOn ?: $todo)],
            [$this->annex('name_function'), ($data->stored('signatory_name') ?? $todo).', '.($data->stored('signatory_function') ?? $todo)],
        ], 'caption' => $this->label('word.signature')];

        return $blocks;
    }

    /** "EU-DoC_Smart-lock_2.0_FR.docx": one file of the all-languages zip. */
    public static function fileName(DeclarationData $data, string $lang, string $extension = 'docx'): string
    {
        $part = fn (string $text) => trim((string) preg_replace('/[^\pL\pN.]+/u', '-', $text), '-') ?: 'x';
        $version = $data->version();

        return 'EU-DoC_'.$part((string) $version->product->name).'_'.$part((string) $version->label).'_'.strtoupper($lang).'.'.$extension;
    }
}
