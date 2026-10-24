<?php

namespace App\Ai\Prompts;

use App\Content\ContentPack;
use App\Content\Text;
use App\Content\Tree;
use App\Models\Evaluation;
use App\Models\InventoryItem;
use Throwable;

/**
 * Describes one evaluation for the model: the requirement, the tree's
 * questions, the recorded walk, the verdict and justification, and the
 * object it is about. Built at run time from the content pack; nothing of the
 * pack is stored in this repository.
 */
final class EvaluationContext
{
    public static function tree(Evaluation $evaluation): ?Tree
    {
        return app(ContentPack::class)->tree($evaluation->standard, $evaluation->requirement);
    }

    public static function describe(Evaluation $evaluation, bool $withRecord = true): string
    {
        $pack = app(ContentPack::class);
        $tree = self::tree($evaluation);
        $requirement = $pack->requirement($evaluation->requirement) ?? [];

        $lines = [];
        $lines[] = 'Standard: '.$pack->standardName($evaluation->standard);
        $lines[] = 'Requirement: '.$evaluation->requirement.' '.Text::of($requirement['title'] ?? '');
        if ($tree) {
            $lines[] = 'Decision tree: '.$tree->title().($tree->scope() ? ' ('.$tree->scope().')' : '');
            $lines[] = 'Start node: '.$tree->start();
            $lines[] = 'Questions of the tree:';
            foreach ($tree->decisionNodes() as $id) {
                $node = $tree->node($id);
                $branches = collect($node['branches'] ?? [])->map(function (array $b) use ($tree) {
                    $to = $tree->has($b['to']) ? $tree->node($b['to']) : null;
                    $target = $to && $to['kind'] === 'result' ? 'result '.$to['result'] : $b['to'];

                    return $b['label'].' -> '.$target;
                })->implode(', ');
                $lines[] = '- '.$id.': '.Text::of($node['text'] ?? '').' ['.$branches.']';
                if (! empty($node['help'])) {
                    $lines[] = '  Note: '.Text::of($node['help']);
                }
            }
        }

        $item = $evaluation->item;
        if ($item) {
            $lines[] = '';
            $lines[] = 'Object evaluated:';
            $lines[] = SystemPrompt::data(self::item($item));
        }

        if ($withRecord) {
            $lines[] = '';
            $lines[] = 'Recorded by the person:';
            $path = collect($evaluation->path ?? [])->map(fn ($step) => $step['node'].' = '.$step['answer'])->implode(', ');
            $lines[] = 'Path: '.($path !== '' ? $path : 'none');
            $lines[] = 'Last decision node reached: '.($evaluation->decision_node ?? 'none');
            $lines[] = 'Verdict: '.($evaluation->verdict->value ?? 'none');
            if ($tree && $evaluation->decision_node && $evaluation->verdict) {
                try {
                    $lines[] = 'The tree allows this verdict from this node: '.($tree->allows($evaluation->decision_node, $evaluation->verdict) ? 'yes' : 'no');
                } catch (Throwable) {
                    $lines[] = 'The tree allows this verdict from this node: no (unknown node)';
                }
            }
            $lines[] = 'Justification:';
            $lines[] = SystemPrompt::data((string) ($evaluation->justification ?? ''));
        }

        return implode("\n", $lines);
    }

    public static function item(InventoryItem $item): string
    {
        $pack = app(ContentPack::class);
        $list = $pack->lists()[$item->list] ?? [];
        $lines = [Text::of($list['singular'] ?? $item->list, 'en').' '.$item->code.($item->name ? ': '.$item->name : '')];
        if ($item->description) {
            $lines[] = 'Description: '.$item->description;
        }
        if ($item->isGroup()) {
            $lines[] = 'Group assessed as one object, members: '.implode(', ', $item->memberNames());
        }
        $attributes = $item->getAttribute('attributes') ?? [];
        foreach ($list['fields'] ?? [] as $field) {
            if (! array_key_exists($field['key'], $attributes)) {
                continue;
            }
            $value = $attributes[$field['key']];
            if (($field['type'] ?? null) === 'select') {
                $option = collect($field['options'] ?? [])->firstWhere('value', $value);
                $value = $option ? Text::of($option['label'], 'en') : $value;
            } elseif (is_bool($value)) {
                $value = $value ? 'yes' : 'no';
            }
            $lines[] = Text::of($field['label'] ?? $field['key'], 'en').': '.(is_scalar($value) ? (string) $value : json_encode($value));
        }

        return implode("\n", $lines);
    }
}
