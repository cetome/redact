<?php

// Étape « Exigences essentielles » (scoping/edit, clé de route « scoping ») : les réponses du
// périmètre RED fixent les exigences essentielles (articles 3.3 d/e/f) et les parties de l’EN 18031
// dans le périmètre, une personne confirme (App\Assessment\ArticlesCheck). L’analyse de risques,
// l’étape suivante, confronte ses risques à elles (risks/_coverage).
return [
    'title' => 'Exigences essentielles',
    'lead' => 'Cochez ce qui est vrai pour le produit. Les réponses fixent les exigences essentielles des articles 3.3 d, e et f qui s’appliquent, et donc les parties de l’EN 18031. Confirmez-les, puis analysez les risques du produit.',

    // Page de l’analyse de risques : les risques face aux exigences essentielles du périmètre.
    'check_title' => 'Risques face aux exigences essentielles',
    'check_help' => 'Chaque exigence essentielle telle que l’étape Exigences essentielles la fixe, à côté des risques qui la concernent, et les exigences de l’EN 18031 que les risques citent. Un écart ne bloque pas : vérifiez-le.',
    'col_article' => 'Article',
    'col_scope' => 'Périmètre',
    'col_risks' => 'Risques',
    'col_check' => 'Vérification',
    'in_scope' => 'Dans le périmètre',
    'out_of_scope' => 'Hors périmètre',
    'not_answered' => 'Sans réponse',
    'ok_in' => 'Dans le périmètre et concerné par les risques',
    'ok_out' => 'Hors périmètre et aucun risque ne le concerne',
    'ok_unanswered' => 'Répondez aux questions des Exigences essentielles',
    'risks_out_of_scope' => '{1} 1 risque concerne :article mais cet article est hors périmètre.|[2,*] :count risques concernent :article mais cet article est hors périmètre.',
    'check_cards' => 'Vérifiez',
    'no_risk' => ':article est dans le périmètre mais aucun risque ne le concerne.',
    'add_from_library' => 'En ajouter un depuis la bibliothèque de menaces',
    'issues' => '{0} Aucun écart|{1} 1 écart|[2,*] :count écarts',
    'risk_link' => 'Risque :n : :threat',
    'go_scoping' => 'Exigences essentielles',

    'questions_title' => 'Ce qui est vrai pour le produit',

    'coverage_help' => 'Les mécanismes que chaque risque cite (une exigence ou toute une famille) face aux exigences de chaque partie dans le périmètre. À titre indicatif : les arbres de décision tranchent.',
    'covered_count' => ':covered exigences sur :total citées par un risque',
    'col_requirement' => 'Exigence',
    'col_covered_by' => 'Citée par',
    'via_family' => 'par la famille :family',
    'uncovered' => 'Citées par aucun risque',
    'named' => 'Citées par les risques',
    'none_covered' => 'Aucun risque ne cite encore d’exigence de cette partie.',
    'outside_scope' => 'Cités par les risques, dans aucune partie du périmètre',
    'outside_scope_help' => 'Ces mécanismes relèvent de parties de l’EN 18031 hors périmètre : ils ne couvrent rien de ce qui est évalué dans cette version.',
    'no_part' => 'Aucune partie de l’EN 18031 n’est dans le périmètre.',

    'confirm' => 'Confirmer les exigences',
    'confirm_again' => 'Confirmer à nouveau',
    'confirm_help' => 'Enregistre les réponses et note que vous avez vérifié les exigences essentielles et les parties de l’EN 18031 dans le périmètre.',
    'confirmed_flash' => 'Exigences essentielles et normes confirmées.',
    'confirmation' => 'Confirmation',
    'state' => [
        'unanswered' => 'Répondez aux questions, puis confirmez.',
        'unconfirmed' => 'Pas encore confirmé.',
        'stale' => 'À reconfirmer : les exigences essentielles ou les normes du périmètre ont changé depuis la confirmation de :name le :date.',
        'confirmed' => 'Confirmé par :name le :date.',
    ],
    'state_short' => [
        'unanswered' => 'Questions sans réponse',
        'unconfirmed' => 'À confirmer',
        'stale' => 'À reconfirmer',
        'confirmed' => 'Confirmé',
    ],
    'someone' => 'quelqu’un',
];
