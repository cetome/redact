<?php

/*
 * Politique de confidentialité (/privacy). Pas privacy.php : un __('Privacy') le chargerait sous Windows.
 * Variables remplies par la vue depuis la configuration : :notice et :contact (liens, le formulaire de
 * contact sur le sujet « Confidentialité / demande sur vos données »),
 * :hours (bac à sable), :unverified_days, :requests_months, :activity_months, :security_months,
 * :contact_months (config redact.retention), :ai_provider et :ai_training (le fournisseur d'IA utilisé,
 * voir 'providers' et 'training').
 */
return [
    'version' => 'Version 2026-10-24',

    'links' => [
        'notice' => 'mentions légales',
        'contact' => 'formulaire de contact',
    ],

    // Fournisseurs d'App\Ai\AiSettings, tels que nommés dans la liste des sous-traitants.
    'providers' => [
        'gemini' => 'Google LLC / Google Ireland (API Gemini, offre payante)',
        'anthropic' => 'Anthropic (API Claude)',
        'openai' => 'OpenAI (API)',
        'mistral' => 'Mistral AI (API)',
        'openai_compatible' => 'un fournisseur d\'IA compatible OpenAI choisi par cetome',
    ],

    // Ce que disent les conditions du fournisseur sur l'entraînement, quand cetome les a vérifiées (vide sinon).
    'training' => [
        'gemini' => 'Avec cette offre payante, Google n\'utilise pas les requêtes et les réponses pour entraîner ses modèles (conditions de l\'API Gemini pour les services payants).',
    ],

    'sections' => [
        'controller' => ['Responsable du traitement', [
            'cetome (voir les :notice). Contact : le :contact ou l\'adresse postale des mentions légales.',
        ]],
        'data' => ['Ce que nous traitons et pourquoi', [], [
            'account' => ['Compte', 'Nom, adresse e-mail, organisation, langue, votre choix concernant les nouveautés produit, la date et la version des conditions acceptées, vos sessions et connexions. Vous vous connectez avec une clé d\'accès (nous ne gardons que sa clé publique, jamais vos données biométriques) ou avec un lien ou un code envoyé par e-mail : il n\'y a pas de mot de passe. Finalité : fournir et sécuriser le service. Base : contrat. Conservé tant que le compte existe. Une adresse jamais confirmée est supprimée après :unverified_days jours.'],
            'content' => ['Produits et documents', 'Ce que votre équipe saisit (descriptions de produits, inventaire, évaluations, composants) et importe (documents, images, nomenclatures). Finalité : fournir le service. Base : contrat. Nous agissons comme sous-traitant pour les données personnelles qu\'ils contiennent. Conservés jusqu\'à ce que vous les supprimiez.'],
            'history' => ['Historique d\'activité', 'Qui a modifié quoi et quand dans vos produits et votre organisation, visible par votre équipe. Finalité : traçabilité et sécurité. Base : contrat et intérêt légitime. Conservé :activity_months mois, les événements de sécurité (connexions, clés d\'accès) :security_months mois.'],
            'logs' => ['Journaux de requêtes', 'Pages consultées, statut et durée de réponse, langue, type de navigateur et une empreinte à clé de l\'adresse IP (jamais l\'adresse elle-même). Finalité : sécurité, support et statistiques d\'usage agrégées. Base : intérêt légitime. Conservés :requests_months mois.'],
            'sandbox' => ['Bac à sable', 'Le travail fait sans compte est supprimé :hours heures après la dernière activité, sauf si vous créez un compte.'],
            'payment' => ['Paiement', 'Pour les offres payantes, Stripe recueille le nom et l\'adresse de facturation, le numéro de TVA et le moyen de paiement sur ses propres pages : les numéros de carte n\'arrivent jamais à REDact. Nous gardons la référence client Stripe, le type de carte et ses 4 derniers chiffres, et l\'abonnement. Les factures sont affichées depuis Stripe. Avant chaque paiement, nous enregistrons l\'accord pour un démarrage immédiat du service (date, personne, version des conditions, empreinte à clé de l\'adresse IP), conservé comme preuve tant que l\'organisation existe. Base : contrat et obligations comptables.'],
            'ai' => ['Traitement par l\'IA', 'Uniquement quand une personne de votre équipe utilise une fonction d\'IA, les données utiles du produit et des extraits de documents sont envoyés au fournisseur d\'IA du service, :ai_provider, qui les traite pour notre compte afin de produire la proposition. :ai_training Les invités n\'utilisent jamais l\'IA et votre organisation peut la désactiver. Les organisations payantes et Entreprise peuvent utiliser leur propre modèle ou leur propre clé : leur contenu va alors au fournisseur qu\'elles ont choisi, selon leur propre contrat avec lui.'],
            'vulnerabilities' => ['Recherche de vulnérabilités connues', 'Seuls les identifiants des composants (nom, version, fournisseur, package URL, CPE, identifiants CVE) sont envoyés à l\'EUVD de l\'ENISA, à la NVD du NIST et à OSV.dev. Le catalogue de la CISA des vulnérabilités exploitées est téléchargé en entier. Jamais le nom d\'un produit, d\'une organisation ou d\'une personne.'],
            'emails' => ['E-mails', 'Liens et codes de connexion, invitations, notifications de relecture, et nouveautés produit seulement si vous l\'avez choisi. Envoyés par IONOS.'],
            'contact' => ['Formulaire de contact', 'Votre nom, adresse e-mail, société, sujet et message, avec une empreinte à clé de l\'adresse IP et le type de navigateur. Finalité : vous répondre. Base : intérêt légitime (démarches précontractuelles quand vous demandez une offre). Conservés :contact_months mois.'],
        ]],
        'sharing' => ['Qui peut voir vos données', [
            'Les personnes des équipes de vos produits, selon leurs rôles, et les administrateurs de votre organisation. Le personnel de cetome peut ouvrir un produit en lecture seule pour fournir du support ou examiner un abus ou un incident de sécurité : chaque accès est enregistré et visible dans l\'historique du produit et de l\'organisation. Nous ne vendons aucune donnée et n\'utilisons ni publicité ni traceur tiers.',
        ]],
        'processors' => ['Sous-traitants', [], [
            'ionos' => ['IONOS SE, Allemagne', 'Hébergement et envoi des e-mails, dans l\'Union européenne'],
            'stripe' => ['Stripe', 'Paiements et factures (offres payantes)'],
            'ai' => [':ai_provider', 'Propositions de l\'IA, seulement quand une personne utilise une fonction d\'IA'],
        ]],
        'where' => ['Où', [
            'Hébergement dans l\'Union européenne. Stripe et le fournisseur d\'IA peuvent traiter des données hors de l\'UE selon les clauses contractuelles types de la Commission européenne.',
        ]],
        'cookies' => ['Cookies', [
            'Uniquement des cookies strictement nécessaires : le cookie de session, un cookie de sécurité contre la falsification de requêtes intersites, pendant une heure, un cookie qui lie un code de connexion à votre navigateur et, seulement si vous cochez « Rester connecté », un cookie qui vous garde connecté pendant :remember_days jours. Le thème de couleur est enregistré dans votre navigateur. Aucun bandeau de consentement n\'est nécessaire.',
        ]],
        'rights' => ['Vos droits', [
            'Accès, rectification, effacement, limitation, opposition et portabilité. Vous pouvez exporter vos données et supprimer votre compte depuis votre page de compte, ou nous écrire par le :contact ou à l\'adresse des mentions légales. Vous pouvez saisir la CNIL.',
        ]],
    ],
];
