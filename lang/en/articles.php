<?php

// "Essential requirements" step (scoping/edit, route key "scoping"): the RED scoping answers set the
// essential requirements (articles 3.3 d/e/f) and the parts of EN 18031 in scope, a person confirms
// (App\Assessment\ArticlesCheck). The risk assessment, the next step, checks its risks against them
// (risks/_coverage).
return [
    'title' => 'Essential requirements',
    'lead' => 'Tick what is true for the product. The answers set the essential requirements of articles 3.3 d, e and f that apply, and so the parts of EN 18031. Confirm them, then assess the product risks.',

    // Risk assessment page: the risks against the essential requirements in scope.
    'check_title' => 'Risks against the essential requirements',
    'check_help' => 'Each essential requirement as the Essential requirements step sets it, next to the risks that concern it, and the EN 18031 requirements the risks name. A mismatch does not block: check it.',
    'col_article' => 'Article',
    'col_scope' => 'Scope',
    'col_risks' => 'Risks',
    'col_check' => 'Check',
    'in_scope' => 'In scope',
    'out_of_scope' => 'Not in scope',
    'not_answered' => 'Not answered',
    'ok_in' => 'In scope and concerned by the risks',
    'ok_out' => 'Not in scope and no risk concerns it',
    'ok_unanswered' => 'Answer the questions of Essential requirements',
    'risks_out_of_scope' => '{1} 1 risk concerns :article but it is not in scope.|[2,*] :count risks concern :article but it is not in scope.',
    'check_cards' => 'Check',
    'no_risk' => ':article is in scope but no risk concerns it.',
    'add_from_library' => 'Add one from the threat library',
    'issues' => '{0} No mismatch|{1} 1 mismatch|[2,*] :count mismatches',
    'risk_link' => 'Risk :n: :threat',
    'go_scoping' => 'Essential requirements',

    'questions_title' => 'What is true for the product',

    'coverage_help' => 'The mechanisms each risk names (a requirement, or a whole family) against the requirements of each part in scope. Informative: the decision trees decide.',
    'covered_count' => ':covered of :total requirements named by a risk',
    'col_requirement' => 'Requirement',
    'col_covered_by' => 'Named by',
    'via_family' => 'through the family :family',
    'uncovered' => 'Not named by any risk',
    'named' => 'Named by the risks',
    'none_covered' => 'No risk names a requirement of this part yet.',
    'outside_scope' => 'Named by the risks, in no part in scope',
    'outside_scope_help' => 'These mechanisms belong to parts of EN 18031 that are not in scope: they cover nothing assessed in this version.',
    'no_part' => 'No part of EN 18031 is in scope.',

    'confirm' => 'Confirm essential requirements',
    'confirm_again' => 'Confirm again',
    'confirm_help' => 'Saves the answers and records that you checked the essential requirements and the parts of EN 18031 in scope.',
    'confirmed_flash' => 'Essential requirements and standards confirmed.',
    'confirmation' => 'Confirmation',
    'state' => [
        'unanswered' => 'Answer the questions, then confirm.',
        'unconfirmed' => 'Not confirmed yet.',
        'stale' => 'To re-confirm: the essential requirements or standards in scope changed since :name confirmed on :date.',
        'confirmed' => 'Confirmed by :name on :date.',
    ],
    'state_short' => [
        'unanswered' => 'Questions to answer',
        'unconfirmed' => 'To confirm',
        'stale' => 'To re-confirm',
        'confirmed' => 'Confirmed',
    ],
    'someone' => 'someone',
];
