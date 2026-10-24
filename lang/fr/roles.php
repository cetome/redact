<?php

return [
    'owner' => [
        'label' => 'Propriétaire du produit',
        'help' => 'A créé le produit et dispose de tous les droits.',
    ],
    'contributor' => [
        'label' => 'Contributeur',
        'help' => "Renseigne l'évaluation et utilise l'IA. Ne peut modifier ni les paramètres du produit ni son équipe.",
    ],
    'reviewer' => [
        'label' => 'Relecteur',
        'help' => "Juge l'évaluation de chaque exigence et rend la décision finale. Ne modifie jamais.",
    ],
    'assessor' => [
        'label' => 'Évaluateur',
        'help' => 'Externe : enregistre les unités d’évaluation, rien d’autre.',
    ],
    'internal' => 'Interne',
    'external' => 'Externe',
    'internal_help' => "Membre de l'organisation propriétaire du produit.",
    'external_help' => "Personne extérieure à l'organisation, par exemple un laboratoire ou un consultant.",
];
