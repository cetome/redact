<?php

namespace Tests\Unit;

use App\Support\WordDiff;
use PHPUnit\Framework\TestCase;

class WordDiffTest extends TestCase
{
    public function test_changed_words_are_removed_then_added_between_kept_text(): void
    {
        $this->assertSame([
            ['type' => 'same', 'text' => 'The lock uses '],
            ['type' => 'del', 'text' => 'AES'],
            ['type' => 'same', 'text' => ' '],
            ['type' => 'ins', 'text' => 'unique AES-128'],
            ['type' => 'same', 'text' => ' keys stored in '],
            ['type' => 'del', 'text' => 'flash'],
            ['type' => 'same', 'text' => ' '],
            ['type' => 'ins', 'text' => 'the secure element'],
            ['type' => 'same', 'text' => '.'],
        ], WordDiff::segments('The lock uses AES keys stored in flash.', 'The lock uses unique AES-128 keys stored in the secure element.'));
    }

    public function test_punctuation_is_its_own_token_so_a_word_is_not_marked_for_a_comma(): void
    {
        $this->assertSame([
            ['type' => 'same', 'text' => 'Checked against the '],
            ['type' => 'ins', 'text' => 'updated'],
            ['type' => 'same', 'text' => ' design documentation'],
            ['type' => 'ins', 'text' => ', the access matrix'],
            ['type' => 'same', 'text' => ' and the report '],
            ['type' => 'ins', 'text' => 'of 14 October'],
            ['type' => 'same', 'text' => '.'],
        ], WordDiff::segments('Checked against the design documentation and the report.', 'Checked against the updated design documentation, the access matrix and the report of 14 October.'));
    }

    public function test_a_change_after_a_word_that_lost_its_space_keeps_a_space(): void
    {
        $this->assertSame([
            ['type' => 'same', 'text' => 'Checked against the documentation '],
            ['type' => 'del', 'text' => 'and the report'],
            ['type' => 'same', 'text' => ' '],
            ['type' => 'ins', 'text' => '. The device is closed'],
            ['type' => 'same', 'text' => '.'],
        ], WordDiff::segments('Checked against the documentation and the report.', 'Checked against the documentation. The device is closed.'));
    }

    public function test_empty_sides_and_identical_texts(): void
    {
        $this->assertSame([['type' => 'ins', 'text' => 'New text.']], WordDiff::segments(null, 'New text.'));
        $this->assertSame([['type' => 'del', 'text' => 'Old text.']], WordDiff::segments('Old text.', ''));
        $this->assertSame([['type' => 'same', 'text' => 'Same words.']], WordDiff::segments('Same  words.', "Same words.\n"));
        $this->assertSame([], WordDiff::segments('', null));
    }

    public function test_long_unchanged_runs_keep_only_the_context(): void
    {
        $old = implode(' ', range(1, 60));
        $segments = WordDiff::segments($old, str_replace(' 30 ', ' thirty ', $old), context: 3);

        $this->assertSame([
            ['type' => 'gap', 'text' => '…'],
            ['type' => 'same', 'text' => ' 27 28 29 '],
            ['type' => 'del', 'text' => '30'],
            ['type' => 'same', 'text' => ' '],
            ['type' => 'ins', 'text' => 'thirty'],
            ['type' => 'same', 'text' => ' 31 32 33 '],
            ['type' => 'gap', 'text' => '…'],
        ], $segments);
    }

    public function test_large_rewrites_fall_back_to_one_removal_and_one_addition_and_are_cut(): void
    {
        $old = implode(' ', array_map(fn ($i) => 'a'.$i, range(1, 50)));
        $new = implode(' ', array_map(fn ($i) => 'b'.$i, range(1, 50)));

        $segments = WordDiff::segments($old, $new, maxChars: 20, maxCells: 100);

        $this->assertSame(['del', 'same', 'ins'], array_column($segments, 'type'));
        $this->assertStringEndsWith('…', $segments[0]['text']);
        $this->assertLessThanOrEqual(21, mb_strlen($segments[0]['text']));
    }
}
