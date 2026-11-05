<?php

// Console : Stripe (App\Http\Controllers\Admin\StripeSettingsController). Clés et webhook, sandbox puis live.
return [
    'nav' => 'Stripe',
    'title' => 'Stripe',
    'lead' => 'Le compte Stripe utilisé pour la facturation. Configurez-le d’abord dans une sandbox, puis refaites la même chose avec les clés live. Les réglages enregistrés ici remplacent les valeurs du .env.',
    'read_only' => 'Seul un super-admin peut modifier ces réglages.',

    'status' => [
        'title' => 'En service',
        'state' => 'Facturation',
        'ready' => 'Active',
        'not_ready' => 'Inactive',
        'not_ready_help' => 'Clés et prix nécessaires',
        'mode' => 'Mode',
        'mode_test' => 'Sandbox (test)',
        'mode_live' => 'Live',
        'mode_none' => 'Aucune clé',
        'key' => 'Clé publiable',
        'secret' => 'Clé secrète',
        'webhook_secret' => 'Secret du webhook',
        'hint_console' => ':hint, enregistrée ici',
        'hint_env' => ':hint, depuis :variable',
        'none' => 'Aucune',
        'prices' => 'Prix',
        'prices_count' => '{0} Aucun dans ce mode pour l’instant|{1} 1 prix dans ce mode|[2,*] :count prix dans ce mode',
        'customers' => 'Clients Stripe',
        'plans' => 'Offres',
        'plans_self' => 'Auto-hébergé, sans limite',
        'plans_saas' => 'Gratuit, puis produits payants',
        'changed' => 'Dernière modification',
        'changed_by' => ':date par :name',
    ],

    'keys' => [
        'title' => '1. Clés',
        'help' => 'Dans Stripe, Développeurs, Clés API. Les clés de sandbox commencent par pk_test_ et sk_test_, les clés live par pk_live_ et sk_live_.',
        'key' => 'Clé publiable',
        'secret' => 'Clé secrète',
        'secret_help' => 'Une clé secrète, ou une clé restreinte (rk_) avec l’écriture sur Customers, Checkout Sessions, Subscriptions, Billing Portal, Products, Prices et Webhook Endpoints. Stockée chiffrée, jamais réaffichée. Laissez vide pour garder la clé enregistrée.',
        'saved' => 'Clé enregistrée :hint',
        'webhook_secret' => 'Secret de signature du webhook (facultatif)',
        'webhook_secret_help' => 'Seulement pour un endpoint créé dans le tableau de bord Stripe (whsec_…). L’étape 3 le renseigne pour vous.',
        'forget' => '{1} Je passe de la sandbox au live, ou l’inverse : oublier le client et l’abonnement Stripe de l’organisation qui en a un|[2,*] Je passe de la sandbox au live, ou l’inverse : oublier les clients et abonnements Stripe des :count organisations qui en ont un',
        'forget_help' => 'Seulement quand vous passez des clés sandbox aux clés live, ou l’inverse. Un client de la sandbox n’existe pas en live (et inversement), REDact l’oublie donc. Les organisations, les personnes et les produits restent. Leurs produits payants passent en lecture seule jusqu’à un nouvel abonnement. Inutile pour enregistrer de nouvelles clés du même mode.',
        'save' => 'Enregistrer',
        'reset' => 'Revenir aux valeurs du .env',
        'reset_confirm' => 'Supprimer les réglages Stripe enregistrés ici et reprendre les valeurs du .env ?',
    ],

    'plans' => [
        'title' => '3. Offres et prix',
        'help' => 'Les prix de chaque formule, au mois et à l’année, et de chaque produit supplémentaire : créés dans Stripe depuis la page Offres et prix, dans le mode de ces clés.',
        'open' => 'Offres et prix',
    ],

    'webhook' => [
        'title' => '2. Webhook',
        'help' => 'Stripe informe REDact des paiements et des abonnements à cette adresse. Elle est la même en sandbox et en live. Chaque mode a son propre endpoint et son secret de signature.',
        'url' => 'Endpoint',
        'events' => 'Événements',
        'create' => 'Créer l’endpoint dans Stripe',
        'recreate' => 'Recréer l’endpoint',
        'create_confirm' => 'Créer l’endpoint dans Stripe ? Un endpoint existant à cette adresse est remplacé.',
        'created' => 'Endpoint créé dans Stripe, son secret de signature est enregistré.',
        'needs_keys' => 'Enregistrez d’abord la clé secrète.',
        'public' => 'Stripe n’accepte qu’une adresse https publique.',
    ],

    'saved' => 'Réglages Stripe enregistrés.',
    'saved_unlinked' => '{1} Réglages Stripe enregistrés. 1 organisation a été détachée de son client Stripe.|[2,*] Réglages Stripe enregistrés. :count organisations ont été détachées de leur client Stripe.',
    'reset_done' => 'Réglages Stripe supprimés : les valeurs du .env s’appliquent.',

    'errors' => [
        'key' => 'Une clé publiable commence par pk_test_ ou pk_live_.',
        'secret' => 'Une clé secrète commence par sk_test_, sk_live_, rk_test_ ou rk_live_.',
        'webhook_secret' => 'Un secret de signature commence par whsec_.',
        'modes' => 'Les deux clés doivent venir du même endroit : la sandbox, ou le live.',
        'forget' => '{1} Ces clés sont de l’autre mode (sandbox ou live) : cochez la case pour oublier le client Stripe de l’organisation qui en a un.|[2,*] Ces clés sont de l’autre mode (sandbox ou live) : cochez la case pour oublier les clients Stripe des :count organisations qui en ont un.',
        'stripe' => 'Stripe a refusé : :error',
    ],
];
