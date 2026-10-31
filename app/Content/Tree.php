<?php

namespace App\Content;

use App\Enums\Verdict;
use InvalidArgumentException;

/**
 * One EN 18031 decision tree (format redact.tree/1, see the content pack's
 * trees/FORMAT.md): decision nodes DN-n with branches, and result nodes.
 */
final class Tree
{
    /** @var array<string, array<string, mixed>> */
    private array $nodes = [];

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(private readonly array $data)
    {
        foreach ($data['nodes'] ?? [] as $node) {
            $this->nodes[$node['id']] = $node;
        }
    }

    /**
     * A branch label as people read it: "Yes" and "No" in the interface language, any other label
     * as the tree prints it (first letter upper case). The stored answer keeps the tree's label.
     */
    public static function answerLabel(string $label): string
    {
        return match (mb_strtolower(trim($label))) {
            'yes' => (string) __('common.yes'),
            'no' => (string) __('common.no'),
            default => ucfirst(mb_strtolower($label)),
        };
    }

    public function standard(): string
    {
        return $this->data['standard'];
    }

    public function requirement(): string
    {
        return $this->data['requirement'];
    }

    public function title(): string
    {
        return Text::of($this->data['title'] ?? '');
    }

    public function clause(): ?string
    {
        return $this->data['clause'] ?? null;
    }

    public function figure(): ?string
    {
        return $this->data['figure'] ?? null;
    }

    /** The "For each ..." box drawn above the tree, when the pack records it. */
    public function scope(): ?string
    {
        return isset($this->data['scope']) ? Text::of($this->data['scope']) : null;
    }

    public function start(): string
    {
        return $this->data['start'];
    }

    /** @return array<string, array<string, mixed>> the nodes, their question and help in the current language */
    public function nodes(): array
    {
        return array_map(self::localised(...), $this->nodes);
    }

    /** @return array<string, mixed> the node, its question and help in the current language */
    public function node(string $id): array
    {
        return self::localised($this->nodes[$id] ?? throw new InvalidArgumentException("Unknown node {$id} in {$this->requirement()}"));
    }

    /**
     * A node's texts ("text", "help") are plain English or {"en": …, "fr": …} (Text): resolved when
     * read, never when loaded, so a long-lived worker serves each language. Branch labels stay as the
     * tree prints them (they are the stored answers; answerLabel() shows them).
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function localised(array $node): array
    {
        foreach (['text', 'help'] as $key) {
            if (isset($node[$key])) {
                $node[$key] = Text::of($node[$key]);
            }
        }

        return $node;
    }

    public function has(string $id): bool
    {
        return isset($this->nodes[$id]);
    }

    /** @return list<string> DN-1, DN-2, ... in numeric order */
    public function decisionNodes(): array
    {
        $ids = array_keys(array_filter($this->nodes, fn (array $n) => $n['kind'] === 'decision'));
        natsort($ids);

        return array_values($ids);
    }

    /**
     * Verdicts a decision node can lead to directly (one branch away).
     * This is what makes a recorded "last node + verdict" pair valid.
     *
     * @return list<Verdict>
     */
    public function verdictsFrom(string $decisionNode): array
    {
        $node = $this->node($decisionNode);
        $verdicts = [];
        foreach ($node['branches'] ?? [] as $branch) {
            $target = $this->nodes[$branch['to']] ?? null;
            if ($target && $target['kind'] === 'result') {
                $verdicts[] = Verdict::fromTreeResult($target['result']);
            }
        }

        return array_values(array_unique($verdicts, SORT_REGULAR));
    }

    /** Can a walk end at $decisionNode with $verdict? */
    public function allows(string $decisionNode, Verdict $verdict): bool
    {
        return $this->has($decisionNode) && in_array($verdict, $this->verdictsFrom($decisionNode), true);
    }

    /**
     * Follow a list of answers from the start node.
     *
     * @param  list<string>  $answers  branch labels, e.g. ["No", "Yes"]
     * @return array{path: list<array{node: string, answer: string}>, last_decision: ?string, verdict: ?Verdict, next: ?string}
     */
    public function walk(array $answers): array
    {
        $current = $this->start();
        $path = [];
        $last = null;

        foreach ($answers as $answer) {
            $node = $this->node($current);
            if ($node['kind'] !== 'decision') {
                break;
            }
            $branch = collect($node['branches'])->first(
                fn (array $b) => mb_strtolower($b['label']) === mb_strtolower($answer)
            );
            if (! $branch) {
                throw new InvalidArgumentException("Answer '{$answer}' is not a branch of {$current}");
            }
            $path[] = ['node' => $current, 'answer' => $branch['label']];
            $last = $current;
            $current = $branch['to'];
        }

        $end = $this->node($current);
        $finished = $end['kind'] === 'result';

        return [
            'path' => $path,
            'last_decision' => $last,
            'verdict' => $finished ? Verdict::fromTreeResult($end['result']) : null,
            'next' => $finished ? null : $current,
        ];
    }

    /** @return array<string, mixed> raw data, for the tree viewer */
    public function toArray(): array
    {
        return $this->data;
    }
}
