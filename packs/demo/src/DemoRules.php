<?php

namespace RedactDemoPack;

use App\Assessment\ScopingForm;
use App\Content\ContentPack;
use App\Content\GenericRules;
use App\Content\ScopingResult;
use App\Content\VersionContext;
use App\Models\InventoryItem;

/**
 * Rules of the REDact demo pack (invented, for trying the software only).
 *
 * GenericRules does most of the work from the JSON files (articles from the scoping answers,
 * standards from their article, one walk per object of the requirement's list, every E.Info of
 * the list once the walk has a node). This class adds two demo rules:
 *
 *  - restrictions: each group of the "conformity" section of scoping.json is a restriction, met
 *    when every visible question of the group is answered yes. Self-assessment is possible when
 *    every tree is walked with no FAIL and every restriction that applies is met; null while
 *    something is still to answer or to walk;
 *  - DAT-1 is walked only for assets of type "personal".
 */
class DemoRules extends GenericRules
{
    public function scoping(array $answers, ContentPack $pack): ScopingResult
    {
        $base = parent::scoping($answers, $pack);
        $form = app(ScopingForm::class);

        $restrictions = $base->restrictions;
        $complete = $base->selfAssessment !== null;
        foreach ($pack->scoping()['sections'] ?? [] as $section) {
            if (($section['step'] ?? ScopingForm::SCOPING) === ScopingForm::SCOPING) {
                continue;
            }
            foreach ($section['groups'] ?? [] as $group) {
                $visible = array_filter($group['questions'] ?? [], fn (array $q) => $form->isVisible($q, $answers, $base));
                if ($visible === []) {
                    continue; // does not apply to this product
                }
                $met = true;
                foreach ($visible as $question) {
                    $answer = $answers[$question['key']] ?? null;
                    if ($answer === null) {
                        $complete = false;
                    }
                    $met = $met && $answer === true;
                }
                $restrictions[$group['key']] = $met;
            }
        }

        $self = $complete ? ! in_array(false, $restrictions, true) : null;

        return new ScopingResult($base->articles, $base->standards, $restrictions, $self, $base->messages);
    }

    public function evaluationTargets(string $standard, string $requirement, VersionContext $context): array
    {
        $targets = parent::evaluationTargets($standard, $requirement, $context);
        if ($requirement !== 'DAT-1') {
            return $targets;
        }

        return array_values(array_filter($targets, fn (?InventoryItem $item) => $item !== null
            && (($item->getAttribute('attributes') ?? [])['type'] ?? null) === 'personal'));
    }
}
