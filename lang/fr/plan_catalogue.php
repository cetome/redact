<?php

// Console : Offres et prix (App\Http\Controllers\Admin\PlanCatalogueController, App\Billing\Catalogue).
return [
    'nav' => 'Offres et prix',
    'title' => 'Offres et prix',
    'lead' => 'Ce que comprend chaque offre au-delà de l’offre gratuite, et ses prix Stripe au mois et à l’année. L’offre gratuite reste le premier produit, toujours.',
    'read_only' => 'Seul un super-admin peut modifier les offres et les prix.',

    'mode' => [
        'test' => 'Prix de la sandbox Stripe. Les prix live se créent de la même façon une fois les clés live enregistrées.',
        'live' => 'Prix live : les clients les paient.',
        'none' => 'Pas de clés Stripe : les prix ne peuvent pas encore être créés.',
        'keys' => 'Clés Stripe',
    ],

    'badges' => [
        'built_in' => 'Intégrée',
        'public' => 'Paiement en ligne',
        'on_request' => 'Sur demande',
        'off' => 'Désactivée',
        'assigned' => '{1} 1 organisation attribuée par l’équipe|[2,*] :count organisations attribuées par l’équipe',
    ],

    'fields' => [
        'name' => 'Nom (:language)',
        'name_help' => 'Vide : le nom intégré.',
        'key' => 'Clé',
        'key_help' => 'Lettres minuscules, chiffres et _. Ne peut pas être modifiée.',
        'products' => 'Produits inclus',
        'products_help' => 'Produit gratuit compris. Vide : sans limite.',
        'team_members' => 'Membres d’équipe par produit',
        'team_help' => 'En plus du propriétaire. Vide : sans limite.',
        'ai_units' => 'Unités d’IA',
        'ai_period' => 'Période d’IA',
        'extra_ai' => 'Unités d’IA par produit supplémentaire',
        'extra_ai_help' => 'Vide : autant que l’offre.',
        'support' => 'Assistance incluse',
        'public' => 'Proposée sur la page des tarifs avec paiement en ligne',
        'public_help' => 'Désactivé : « Nous contacter », attribuée par l’équipe sur l’organisation (un contrat ou un essai).',
        'active' => 'Offre utilisée',
    ],

    'items' => [
        'plan' => 'Offre',
        'extra_product' => 'Produit supplémentaire',
    ],
    'per' => [
        'month' => 'par mois',
        'year' => 'par an',
    ],

    'price' => [
        'title' => 'Prix',
        'item' => 'Prix',
        'current' => 'Actuel',
        'subscriptions' => 'Abonnements',
        'none' => 'Aucun',
        'shown' => 'Affiché en attendant : :label',
        'as_plan' => 'Une unité de plus de l’offre',
        'new' => 'Nouveau prix',
        'amount' => 'Montant en :currency, hors TVA',
        'create' => 'Créer',
        'create_confirm' => 'Créer ce prix dans Stripe ? Les nouveaux abonnements l’utilisent, les abonnements existants gardent leur prix.',
        'created' => ':name, :item : :price :interval créé dans Stripe. Les nouveaux abonnements l’utilisent.',
        'help' => 'Hors TVA, Stripe Tax l’ajoute. Un nouveau prix sert aux nouveaux abonnements, les abonnements existants gardent le leur. Sans prix de produit supplémentaire, chaque produit supplémentaire est une unité de plus du prix de l’offre.',
        'history' => 'Prix précédents',
        'history_row' => ':item :interval, :amount, :mode, créé le :date',
        'modes' => ['test' => 'sandbox', 'live' => 'live'],
    ],

    'new' => [
        'title' => 'Ajouter une offre',
        'help' => 'Par exemple Business. Créez ses prix une fois l’offre enregistrée.',
        'save' => 'Ajouter l’offre',
    ],

    'save' => 'Enregistrer',
    'delete' => 'Supprimer',
    'delete_confirm' => 'Supprimer cette offre ? Aucune organisation ni aucun abonnement ne l’utilise.',
    'saved' => ':name enregistrée.',
    'created' => ':name ajoutée. Créez ses prix ci-dessous.',
    'deleted' => ':name supprimée.',

    'errors' => [
        'key' => 'Lettres minuscules, chiffres et _, en commençant par une lettre.',
        'key_taken' => 'Une offre a déjà cette clé.',
        'built_in' => 'Une offre intégrée peut être désactivée, pas supprimée.',
        'in_use' => 'Des organisations ou des abonnements utilisent cette offre : désactivez-la plutôt.',
        'stripe' => 'Stripe a refusé : :error',
    ],
];
