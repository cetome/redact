<?php

// « Vos classeurs cetome REDact » sur la page des rapports : les modèles Excel du propriétaire remplis avec la version.
return [
    'title' => 'Vos classeurs cetome REDact',
    'intro' => 'Les modèles Excel cetome REDact, remplis avec cette version : mêmes onglets, formules, tableaux et listes déroulantes, prêts à être repris dans Excel. Ils ne sont pas protégés.',
    'download' => 'Télécharger',
    'download_tooltip' => 'Construit le classeur à partir de cette version maintenant',
    'download_all' => 'Les quatre (zip)',
    'download_all_tooltip' => 'Les quatre classeurs et un fichier de notes dans un seul zip',
    'zip_name' => 'Classeurs cetome REDact',
    'locked' => 'Créez un compte pour télécharger',
    'locked_tooltip' => 'Les classeurs se téléchargent une fois le bac à sable enregistré comme compte',
    'recalculate' => 'Excel recalcule toutes les formules à l’ouverture du classeur. D’autres tableurs peuvent demander un recalcul manuel.',
    'fixes_title' => 'Corrections apportées à la copie livrée',
    'fixes_count' => '{1} :count correction, la voir|[2,*] :count corrections, les voir',
    'fixes_made' => '{1} :count correction dans la copie livrée|[2,*] :count corrections dans la copie livrée',
    'fixes_none' => 'Aucune correction dans ce classeur.',
    'left_title' => 'Laissé comme dans le modèle, à revoir par cetome',
    'overflow_title' => 'Lignes qui ne tiendront pas',
    'none' => 'Aucun modèle de classeur n’est disponible dans le pack de contenu.',
    'workbook' => 'Classeur',

    'notes' => [
        'overflow' => ':sheet a de la place pour :max lignes : :count de plus sont laissées de côté (:names…).',
        'overflow_risks' => 'Le registre des risques a de la place pour :max risques : :count de plus sont laissés de côté.',
        'inconclusive' => ':requirement sur :item est non concluant dans REDact : aucun verdict n’est écrit.',
        'autofill' => ':count textes E.Info appartiennent à des colonnes que le classeur remplit lui-même depuis l’inventaire (marquées Autofill) : ils ne sont pas copiés.',
        'settled' => ':sheet :ref : :reason. Écrit comme respectée à la place de la formule du classeur, qui ne lit que les questions.',
        'matrix_kept' => 'La matrice de risques de la version n’utilise pas cinq niveaux : le classeur garde sa propre matrice.',
        'refused' => 'Des cellules contenant une formule n’ont pas été modifiées : :cells.',
    ],

    'notes_file' => [
        'name' => 'Notes REDact',
        'title' => 'Classeurs cetome REDact de :product, remplis par REDact',
        'applied' => 'Corrigé dans cette copie :',
        'skipped' => 'Non corrigé (le modèle diffère de celui attendu) :',
        'left' => 'Laissé comme dans les modèles, à revoir par cetome :',
        'recalculate' => 'Excel recalcule toutes les formules à l’ouverture d’un classeur. Les classeurs ne sont pas protégés.',
    ],

    'overflow' => ':list : :count lignes pour :max',
];
