<?php

namespace App\Ai\Prompts;

use App\Ai\AiRequest;
use App\Ai\Feature;
use App\Content\ContentPack;
use App\Content\Text;
use App\Models\ProductVersion;
use App\Models\RiskEntry;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

/**
 * "Draft from documentation": proposals for the description, the intended use,
 * inventory items of each content-pack list, E.Info texts of existing items and
 * risks, each with its sources. Nothing is written until a person accepts. The task wording
 * comes from the pack (ai/prompts/draft.md) when it has it.
 */
final class DraftPrompt
{
    /** Task wording when the pack has none. */
    public const GENERIC_TASK = <<<'TXT'
    From the product documents, prepare proposals that a person will accept or reject one by one:
    - "description" and "intended_use": leave the text empty when the documents are silent.
    - "items": new objects for the inventory lists given, using only their list keys, their fields and, for choice fields, their allowed values. Skip objects already present.
    - "einfo": texts for the E.Info codes given, for listed objects (item_code) or, for product-level codes, with an empty item_code.
    - "risks": plausible threats with their STRIDE letters and a description, other than the threats already recorded.
    E.Info texts and object descriptions are claims about this product: affirmative, present tense, no hedging or advice ("typically", "usually", "would", "could", "may", "might", "should", "likely", "e.g.", "if applicable", "consider"; in French "généralement", "pourrait", "devrait", "serait", "peut", "p. ex.", "le cas échéant"). Leave out what the documents do not support rather than weaken it.
    Each proposal cites its sources (document name exactly as given, page or section). Propose nothing that no document supports.
    TXT;

    public static function make(ProductVersion $version, string $documents): AiRequest
    {
        $pack = app(ContentPack::class);

        $system = SystemPrompt::for(PackPrompts::text('draft', self::GENERIC_TASK));

        $product = $version->product;
        $parts = [];
        $parts[] = 'Product: '.SystemPrompt::data(trim($product->name.' '.($product->reference ?? '')));
        $parts[] = "Current description:\n".SystemPrompt::data((string) $product->description);
        $parts[] = "Current intended use:\n".SystemPrompt::data((string) $version->intended_use);

        $items = $version->inventoryItems()->get()->groupBy('list');
        $listLines = [];
        foreach ($pack->lists() as $key => $list) {
            $listLines[] = '- list "'.$key.'" ('.Text::of($list['label'] ?? $key, 'en').'): '.Text::of($list['help'] ?? '', 'en');
            foreach ($list['fields'] ?? [] as $field) {
                $type = $field['type'] ?? 'text';
                $allowed = $type === 'select'
                    ? ' allowed values: '.collect($field['options'] ?? [])->map(fn ($o) => $o['value'].' ('.Text::of($o['label'], 'en').')')->implode(', ')
                    : ($type === 'boolean' ? ' allowed values: true, false' : '');
                $listLines[] = '    field "'.$field['key'].'" '.Text::of($field['label'] ?? $field['key'], 'en').' ['.$type.']'.$allowed;
            }
            $existing = ($items[$key] ?? collect())->map(fn ($i) => $i->code.($i->name ? ' '.$i->name : ''))->implode('; ');
            $listLines[] = '    existing: '.($existing !== '' ? $existing : 'none');
        }
        $parts[] = "Inventory lists:\n".SystemPrompt::data(implode("\n", $listLines));

        $einfoLines = [];
        foreach ($pack->einfo() as $code => $definition) {
            $einfoLines[] = '- '.$code.' (for '.($definition['list'] ? 'each object of list "'.$definition['list'].'"' : 'the product').'): '.Text::of($definition['label'] ?? '', 'en');
        }
        $parts[] = "E.Info codes:\n".SystemPrompt::data($einfoLines ? implode("\n", $einfoLines) : 'none');

        $threats = $version->riskEntries()->pluck('threat')->implode('; ');
        $stride = collect($pack->risk()['stride'] ?? [])->map(fn ($label, $letter) => $letter.' = '.Text::of($label, 'en'))->implode(', ');
        $parts[] = "Risks:\nSTRIDE letters: ".$stride."\nExisting threats: ".SystemPrompt::data($threats !== '' ? $threats : 'none');

        $parts[] = "Product documents:\n".($documents !== '' ? $documents : 'none');

        return new AiRequest(Feature::DraftFromDocuments, $system, implode("\n\n", $parts), self::schema($pack), 16384);
    }

    private static function schema(ContentPack $pack): ObjectSchema
    {
        $listKeys = array_keys($pack->lists());
        $einfoCodes = array_keys($pack->einfo());

        $text = fn (string $name, string $description) => new ObjectSchema($name, $description, [
            new StringSchema('text', 'The proposed text, empty when the documents do not say'),
            self::sources(),
        ], ['text', 'sources']);

        return new ObjectSchema('draft', 'Draft proposals from the documents', [
            $text('description', 'Product description'),
            $text('intended_use', 'Intended use and environment'),
            new ArraySchema('items', 'New inventory objects', new ObjectSchema('item', 'One object', [
                $listKeys ? new EnumSchema('list', 'List key', $listKeys) : new StringSchema('list', 'List key'),
                new StringSchema('name', 'Short name'),
                new StringSchema('description', 'What it is in this product, from the documents, stated as a claim'),
                new ArraySchema('attributes', 'Attribute values', new ObjectSchema('attribute', 'One attribute', [
                    new StringSchema('field', 'Field key of the list'),
                    new StringSchema('value', 'Value (an allowed value for choice fields)'),
                ], ['field', 'value'])),
                self::sources(),
            ], ['list', 'name', 'description', 'attributes', 'sources']), maxItems: 60),
            new ArraySchema('einfo', 'E.Info texts', new ObjectSchema('einfo_text', 'One E.Info text', [
                $einfoCodes ? new EnumSchema('einfo', 'E.Info code', $einfoCodes) : new StringSchema('einfo', 'E.Info code'),
                new StringSchema('item_code', 'Code of the existing object, empty for product-level codes'),
                new StringSchema('text', 'The documentation text: an affirmative claim about this product'),
                self::sources(),
            ], ['einfo', 'item_code', 'text', 'sources']), maxItems: 80),
            new ArraySchema('risks', 'New risks', new ObjectSchema('risk', 'One threat', [
                new StringSchema('threat', 'Short name of the threat'),
                new ArraySchema('stride', 'STRIDE letters', new EnumSchema('letter', 'STRIDE letter', RiskEntry::STRIDE)),
                new StringSchema('description', 'How it could happen to this product'),
                self::sources(),
            ], ['threat', 'stride', 'description', 'sources']), maxItems: 30),
        ], ['description', 'intended_use', 'items', 'einfo', 'risks']);
    }

    private static function sources(): Schema
    {
        return new ArraySchema('sources', 'Where it comes from', new ObjectSchema('source', 'One source', [
            new StringSchema('document', 'Document name exactly as given'),
            new StringSchema('location', 'Page or section, e.g. "p. 4"'),
        ], ['document', 'location']), maxItems: 5);
    }
}
