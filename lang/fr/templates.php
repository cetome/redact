<?php

// Modèles gratuits (/templates) : les classeurs cetome REDact d’origine, avec le texte du propriétaire
// de redact.cetome.com (raccourci seulement où il se répétait). Peu de mots, « la RED ».

return [
    'nav' => 'Modèles',
    'meta' => [
        'title' => 'Modèles gratuits pour la RED et l’EN 18031 (Excel) ◼ REDact',
        'description' => 'Les classeurs cetome REDact d’origine, gratuits et sans compte : questionnaire de périmètre, analyse de risques, arbres de décision (E.DT, E.Just), E.Info et REDux.',
    ],
    'kicker' => 'cetome REDact',
    'title' => 'Modèles gratuits',
    'lead' => 'Les fabricants IoT peuvent utiliser cette boîte à outils gratuite pour réaliser l’analyse de risques, les arbres de décision EN 18031 et les E.Info.',

    // Sections de l’ancienne page : leurs classeurs, dans cet ordre (les autres suivent dans « more »).
    'groups' => [
        'pre' => ['kicker' => '01 — Pré-conformité', 'title' => 'Évaluez le périmètre de votre produit pour la RED.'],
        'en18031' => ['kicker' => '02 — Modèles EN 18031', 'title' => 'Accélérez la conformité à la RED.'],
        'redux' => ['kicker' => '03 — REDux', 'title' => 'L’expérience unifiée de REDact.'],
        'more' => ['kicker' => 'Autres', 'title' => 'Autres modèles.'],
    ],

    'files' => [
        'scoping' => [
            'name' => 'Questionnaire de périmètre',
            'text' => 'Déterminez le périmètre de votre produit et les articles 3.3 d / e / f qui s’appliquent.',
            'alt' => 'Questionnaire de périmètre : capacités de l’équipement radio cochées, articles 3.3 d et e concernés.',
        ],
        'risk' => [
            'name' => 'Analyse de risques',
            'text' => 'Identifiez menaces et risques et reliez-les aux mécanismes de sécurité de l’EN 18031.',
            'alt' => 'Feuille d’analyse de risques : une menace avec ses catégories STRIDE, son niveau de risque, les articles et la remédiation EN 18031.',
        ],
        'edt' => [
            'name' => 'Arbres de décision',
            'codes' => ['E.DT', 'E.Just'],
            'text' => 'Complétez et vérifiez les arbres de décision et la justification des preuves.',
            'alt' => 'Feuille d’arbres de décision : nœuds de décision par exigence avec les colonnes PASS, FAIL et N/A.',
        ],
        'einfo' => [
            'name' => 'Informations de preuve',
            'codes' => ['E.Info'],
            'text' => 'L’automatisation accélère la saisie des E.Info.',
            'alt' => 'Feuille E.Info : actifs avec leur type, leur nœud de décision et les champs E.Info remplis automatiquement.',
        ],
        'redux' => [
            'name' => 'Modèle unifié pour les produits complexes',
            'codes' => ['E.DT', 'E.Just', 'E.Info'],
            'text' => 'E.DT, E.Just et E.Info dans un seul fichier. Saisissez les informations du produit une fois, réutilisez-les dans vos E.Info.',
            'alt' => 'Synthèse REDux : auto-évaluation RED possible, avec les vérifications EN 18031, mots de passe et protection des enfants.',
        ],
    ],
    'version' => 'v:version',
    'size' => ':size Ko',
    'xlsx' => 'Excel',
    'zip' => 'Zip',
    'download' => 'Télécharger',
    'download_label' => 'Télécharger :name',
    'all' => 'Tout télécharger',
    'all_label' => 'Télécharger tous les classeurs dans un zip',
    'app' => 'Vous préférez l’application ? Commencez ici',
    'none' => 'Aucun modèle n’est proposé sur cette installation.',

    // Console, page Utilisation.
    'admin' => [
        'title' => 'Téléchargements de modèles',
        'help' => 'Classeurs gratuits téléchargés depuis /templates',
        'file' => 'Modèle',
        'downloads' => 'Téléchargements',
        'all' => 'Tout (zip)',
    ],
];
