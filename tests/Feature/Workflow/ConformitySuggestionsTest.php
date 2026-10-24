<?php

namespace Tests\Feature\Workflow;

use App\Assessment\Assessment;
use App\Assessment\Conformity;
use App\Content\ContentPack;
use App\Content\GenericRules;
use App\Content\VersionContext;
use App\Models\InventoryItem;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProducts;
use Tests\Concerns\ComparesConformityOutputs;
use Tests\Concerns\UsesPackCopy;
use Tests\TestCase;

/**
 * The conformity questions (RED restrictions) pre-filled from what the version records
 * (PackRules::restrictionSuggestions): a default of the form, marked, with its reasons; saving
 * confirms it; a saved answer always wins and alone decides the route. The fixture pack derives
 * nothing (GenericRules); a copy of it gets a small demo mapping.
 */
class ConformitySuggestionsTest extends TestCase
{
    use BuildsProducts, ComparesConformityOutputs, RefreshDatabase, UsesPackCopy;

    public function test_the_report_agrees_with_the_page(): void
    {
        $this->useDemoMapping();
        [$owner, $version, $asset] = $this->scopedProduct();

        // EN 18031 not fully implemented: trees still to complete, then a FAIL.
        $this->assertSame(['To answer', 'To answer'], $this->assertReportAgreesWithPage($owner, $version));
        $this->walk($owner, $version, $asset, ['No', 'No']);
        $this->walkProduct($owner, $version, ['Yes']);
        $this->answer($version, 'r_passwords', false);
        $this->assertSame(['Not met', 'Met'], $this->assertReportAgreesWithPage($owner, $version));

        // All trees pass; the restriction unmet, then met.
        $this->walk($owner, $version, $asset, ['No', 'Yes']);
        $this->answer($version, 'r_passwords', true);
        $this->answer($version, 'r_blank_password', true);
        $this->assertSame(['Met', 'Not met'], $this->assertReportAgreesWithPage($owner, $version));
        $this->answer($version, 'r_blank_password', false);
        $this->assertSame(['Met', 'Met'], $this->assertReportAgreesWithPage($owner, $version));

        // Settled by the data (nothing stored): met although its saved answers say otherwise.
        $this->answer($version, 'r_blank_password', true);
        $asset->update(['attributes' => ['type' => 'security', 'stored' => false]]);
        $this->assertSame(['Met', 'Met'], $this->assertReportAgreesWithPage($owner, $version));
    }

    public function test_the_generic_rules_derive_nothing(): void
    {
        [$owner, $version] = $this->scopedProduct();
        $pack = app(ContentPack::class);
        $result = $pack->rules()->scoping($version->scopingAnswers()->pluck('value', 'question')->all(), $pack);

        $this->assertSame([], (new GenericRules)->restrictionSuggestions(new VersionContext($version), $result));
        $this->assertSame([], (new Conformity(Assessment::for($version)))->suggestions());
        $this->signIn($owner)->get(route('versions.conformity', $version))->assertOk()
            ->assertDontSee(__('conformity.derived.label'));
    }

    public function test_unanswered_questions_are_prefilled_with_their_reasons(): void
    {
        $this->useDemoMapping();
        [$owner, $version, $asset] = $this->scopedProduct();

        $page = $this->signIn($owner)->get(route('versions.conformity', $version))->assertOk();
        $page->assertSee('From your scoping and decision trees')
            ->assertSee('AS-01 · Lock PIN: stored on the product')
            ->assertSee(route('versions.inventory.edit', [$version, 'assets', $asset->id]), false)
            ->assertSee(route('versions.evaluate.target', [$version, 'demo-1', 'ACC-1', $asset->id]), false);
        $this->assertMatchesRegularExpression('/name="answers\[r_passwords\]" value="1"\s+checked/', $page->getContent());
        $this->assertMatchesRegularExpression('/name="answers\[r_blank_password\]" value="1"\s+checked/', $page->getContent());
        $this->assertDoesNotMatchRegularExpression('/data-show-if="r_passwords"\s+hidden/', $page->getContent(), 'the follow-up shows under the suggested Yes');

        // Not saved: nothing stored, the outcome and the route ignore the suggestion.
        $this->assertFalse($version->scopingAnswers()->where('question', 'r_passwords')->exists());
        $conformity = new Conformity(Assessment::for($version->fresh()));
        $this->assertTrue($conformity->result()->restrictions['passwords']);
        $this->assertNotFalse($conformity->selfAssessmentPossible());
        $page->assertDontSee(__('conformity.module_a_unavailable'));
    }

    public function test_saving_confirms_the_suggestions_and_then_decides_the_route(): void
    {
        $this->useDemoMapping();
        [$owner, $version, $asset] = $this->scopedProduct();
        $this->walk($owner, $version, $asset, ['No', 'Yes']);
        $this->walkProduct($owner, $version, ['Yes']);

        // The form as shown (suggested Yes / Yes), saved as is.
        $this->signIn($owner)->put(route('versions.conformity.update', $version), [
            'answers' => ['r_passwords' => '1', 'r_blank_password' => '1'],
            'conformity_route' => 'module_a',
        ])->assertSessionHasErrors('conformity_route');

        $answers = $version->scopingAnswers()->pluck('value', 'question')->all();
        $this->assertTrue($answers['r_passwords']);
        $this->assertTrue($answers['r_blank_password']);
        $this->assertFalse((new Conformity(Assessment::for($version->fresh())))->selfAssessmentPossible());

        $this->signIn($owner)->get(route('versions.conformity', $version))->assertOk()
            ->assertDontSee(__('conformity.derived.label'))
            ->assertDontSee('AS-01 · Lock PIN: stored on the product')
            ->assertSee(__('conformity.module_a_unavailable'));
    }

    public function test_a_saved_answer_wins_and_a_different_one_is_flagged(): void
    {
        $this->useDemoMapping();
        [$owner, $version] = $this->scopedProduct();
        $version->scopingAnswers()->create(['question' => 'r_passwords', 'value' => false]);

        $page = $this->signIn($owner)->get(route('versions.conformity', $version))->assertOk();
        $this->assertMatchesRegularExpression('/name="answers\[r_passwords\]" value="1"\s*>/', $page->getContent(), 'the saved No stays');
        $page->assertSee(__('conformity.derived.differs', ['answer' => __('common.yes')]))
            ->assertSee('AS-01 · Lock PIN: stored on the product');
        $this->assertMatchesRegularExpression('/data-show-if="r_passwords"\s+hidden/', $page->getContent(), 'its follow-up stays hidden under the saved No');
    }

    public function test_a_restriction_the_data_settles_is_met_and_not_asked(): void
    {
        $this->useDemoMapping();
        [$owner, $version, $asset] = $this->scopedProduct();
        $asset->update(['attributes' => ['type' => 'security', 'stored' => false]]);
        $this->walk($owner, $version, $asset, ['No', 'Yes']);
        $this->walkProduct($owner, $version, ['Yes']);

        $conformity = new Conformity(Assessment::for($version->fresh()));
        $this->assertSame(['passwords'], $conformity->settledGroups());
        $this->assertSame(['r_passwords', 'r_blank_password'], $conformity->settledQuestions());
        $this->assertTrue($conformity->result()->restrictions['passwords']);

        $page = $this->signIn($owner)->get(route('versions.conformity', $version))->assertOk()
            ->assertSee('Nothing stored, no password: met');
        $this->assertStringNotContainsString('name="answers[r_passwords]"', $page->getContent());

        // A stale post of its questions is not stored; the route follows the trees alone.
        $this->put(route('versions.conformity.update', $version), [
            'answers' => ['r_passwords' => '1', 'r_blank_password' => '1'], 'conformity_route' => 'module_a',
        ])->assertSessionHasNoErrors();
        $this->assertFalse($version->scopingAnswers()->whereIn('question', ['r_passwords', 'r_blank_password'])->exists());
        $this->assertSame('module_a', $version->fresh()->conformity_route?->value);
    }

    /**
     * A copy of the fixture pack with a "passwords" conformity question and a demo mapping: the
     * product uses passwords when an asset is stored on it (blank password: Yes, from the ACC-1 tree),
     * and the restriction is unmet when both are saved Yes; with nothing stored, the restriction is
     * settled (met, not asked).
     */
    private function useDemoMapping(): void
    {
        $this->usePackCopy(function (string $path) {
            $this->changeJson($path.'/scoping.json', function (array $data) {
                $data['sections'][] = ['key' => 'restrictions', 'title' => 'Restrictions', 'step' => 'conformity', 'groups' => [[
                    'key' => 'passwords', 'title' => 'Passwords', 'questions' => [
                        ['key' => 'r_passwords', 'type' => 'boolean', 'label' => 'Does the product use passwords?'],
                        ['key' => 'r_blank_password', 'type' => 'boolean', 'label' => 'Can the user set a blank password?',
                            'show_if' => ['question' => 'r_passwords', 'equals' => true]],
                    ],
                ]]];

                return $data;
            });
            file_put_contents($path.'/rules.php', <<<'PHP'
                <?php

                use App\Content\ContentPack;
                use App\Content\GenericRules;
                use App\Content\ScopingResult;
                use App\Content\VersionContext;

                return new class extends GenericRules
                {
                    public function scoping(array $answers, ContentPack $pack): ScopingResult
                    {
                        $result = parent::scoping($answers, $pack);
                        $met = in_array('passwords', (array) ($answers['_settled'] ?? []), true)
                            || ! (($answers['r_passwords'] ?? null) === true && ($answers['r_blank_password'] ?? null) === true);

                        return new ScopingResult($result->articles, $result->standards, $result->restrictions + ['passwords' => $met],
                            $met ? $result->selfAssessment : false);
                    }

                    public function restrictionSuggestions(VersionContext $context, ScopingResult $scoping): array
                    {
                        $stored = $context->items('assets')->filter(fn ($a) => $a->attr('stored') === true)->values();
                        if ($stored->isEmpty()) {
                            return ['passwords' => ['value' => null, 'settled' => true, 'because' => [['text' => 'Nothing stored, no password: met']]]];
                        }

                        return [
                            'r_passwords' => ['value' => true, 'because' => $stored->map(fn ($a) => [
                                'text' => $a->label().': stored on the product',
                                'link' => ['step' => 'inventory', 'list' => 'assets', 'item' => $a->id],
                            ])->all()],
                            'r_blank_password' => ['value' => true, 'because' => [[
                                'text' => 'ACC-1 on '.$stored[0]->code,
                                'link' => ['step' => 'evaluate', 'standard' => 'demo-1', 'requirement' => 'ACC-1', 'item' => $stored[0]->id],
                            ]]],
                        ];
                    }
                };
                PHP);
        });
    }

    private function answer(ProductVersion $version, string $question, bool $value): void
    {
        $version->scopingAnswers()->updateOrCreate(['question' => $question], ['value' => $value]);
    }

    /** @return array{0: User, 1: ProductVersion, 2: InventoryItem} */
    private function scopedProduct(): array
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        $version->scopingAnswers()->create(['question' => 'connected', 'value' => true]);
        $asset = $version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-01', 'name' => 'Lock PIN',
            'attributes' => ['type' => 'security', 'stored' => true]]);

        return [$owner, $version, $asset];
    }

    /** @param list<string> $answers */
    private function walk(User $owner, ProductVersion $version, InventoryItem $asset, array $answers): void
    {
        $this->signIn($owner)->put(route('versions.evaluate.update', [$version, 'demo-1', 'ACC-1']), [
            'target' => $asset->id, 'answers' => $answers, 'justification' => 'Because.',
        ])->assertSessionHasNoErrors();
    }

    /** @param list<string> $answers */
    private function walkProduct(User $owner, ProductVersion $version, array $answers): void
    {
        $this->signIn($owner)->put(route('versions.evaluate.update', [$version, 'demo-1', 'UPD-1']), [
            'target' => 'product', 'answers' => $answers, 'justification' => 'Because.',
        ])->assertSessionHasNoErrors();
    }
}
