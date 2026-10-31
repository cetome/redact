<?php

// Portefeuille d’une organisation (PortfolioController, App\Portfolio\Portfolio).
return [
    'title' => 'Portefeuille',
    'lead' => 'Tous les produits et versions de l’organisation, où en est chacun, et qui fait quoi dans le temps.',
    'period' => 'Période',
    'days' => ':days jours',
    'figures' => 'Chiffres',
    'totals' => [
        'products' => 'Produits',
        'draft' => 'Versions en brouillon',
        'in_review' => 'Versions en revue',
        'approved' => 'Versions approuvées',
        'to_decide' => 'Changements significatifs à décider',
        'rechecks' => 'Éléments à revérifier',
    ],
    'products' => 'Produits et versions',
    'no_products' => 'Aucun produit dans cette organisation pour l’instant.',
    'col' => [
        'product' => 'Produit',
        'version' => 'Version',
        'progress' => 'Avancement',
        'change' => 'Changement significatif',
        'rechecks' => 'À revérifier',
        'last' => 'Dernière activité',
        'person' => 'Personne',
    ],
    'team' => '{0} aucune équipe|{1} 1 membre d’équipe|[2,*] :count membres d’équipe',
    'approved_on' => 'Approuvée le :date',
    'first_version' => 'Première version : rien à comparer',
    'work_title' => 'Qui fait quoi · :days derniers jours',
    'work_lead' => 'Le travail des comptes de l’organisation dans ses produits, d’après l’historique.',
    'work_totals' => [
        'people' => 'Personnes actives',
        'products' => 'Produits travaillés',
    ],
    'people' => 'Personnes les plus actives',
    'no_work' => 'Rien de fait ces :days derniers jours.',
    'all_history' => 'Tout l’historique',
    'paid_title' => 'Le portefeuille est inclus dans les offres payantes',
    'paid_text' => 'Tous les produits et versions de l’organisation au même endroit : où en est chacun, les changements significatifs à décider, ce qui reste à revérifier, et qui fait quoi dans le temps.',
    'link' => 'Portefeuille',
];
