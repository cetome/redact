<?php

/*
 * Formulaire de contact (/contact, App\Http\Controllers\ContactController), ses e-mails et la liste de la console.
 * Sujets : App\Models\ContactMessage::TOPICS.
 */
return [
    'meta' => [
        'title' => 'Contacter l’équipe REDact ◼ cetome',
        'description' => 'Une question sur les tarifs de REDact, l’auto-hébergement, l’assistance ou un partenariat : écrivez à l’équipe cetome.',
    ],
    'title' => 'Parlons-en',
    'lead' => 'Tarifs, auto-hébergement, assistance ou partenariat : dites-nous ce dont vous avez besoin.',

    'fields' => [
        'name' => 'Nom',
        'email' => 'E-mail',
        'company' => 'Société',
        'topic' => 'Sujet',
        'message' => 'Message',
    ],
    'optional' => 'facultatif',
    'placeholders' => [
        'name' => 'Votre nom',
        'email' => 'vous@societe.com',
        'company' => 'Votre société',
        'message' => 'Vos produits, votre question…',
    ],
    'choose' => 'Choisissez un sujet',
    'topics' => [
        'pricing' => 'Tarifs / version en ligne',
        'self-hosted' => 'Auto-hébergé',
        'expert' => 'Accompagnement par un expert',
        'support' => 'Assistance',
        'partnership' => 'Partenariat',
        'privacy' => 'Confidentialité / demande sur vos données',
        'other' => 'Autre',
    ],
    'privacy' => 'Vos coordonnées servent uniquement à vous répondre et sont conservées :months mois.',
    'privacy_link' => 'Politique de confidentialité',
    'submit' => 'Envoyer le message',
    'sending' => 'Envoi…',

    'next_title' => 'Et ensuite',
    'next' => 'Nous répondons par e-mail, en français ou en anglais.',
    'security' => 'Une faille de sécurité dans REDact ? Écrivez à :email.',

    'sent_title' => 'Message envoyé',
    'sent_text' => 'Merci. Nous vous répondrons par e-mail.',
    'another' => 'Envoyer un autre message',

    'errors' => [
        'throttled' => 'Trop de messages depuis cette connexion. Réessayez plus tard.',
        'altcha_missing' => 'La vérification de sécurité ne s’est pas lancée. Une fois la case ci-dessous cochée, envoyez à nouveau.',
        'altcha_failed' => 'La vérification de sécurité a échoué ou expiré. Une fois la case ci-dessous cochée, envoyez à nouveau.',
        'name_link' => 'Indiquez votre nom, sans adresse web.',
        'topic' => 'Choisissez un sujet.',
    ],

    'mail' => [
        // À cetome (l’e-mail part en anglais ; ces textes servent de traduction).
        'staff_subject' => 'Contact : :topic, :name',
        'language' => 'Langue',
        'account' => 'Compte',
        'no_account' => 'Non connecté',
        'organisation' => 'Organisation',
        'open_console' => 'Ouvrir dans la console',
        'staff_footer' => 'Envoyé depuis le formulaire de contact de REDact. Répondez à cet e-mail pour répondre à l’expéditeur.',
    ],

    'admin' => [
        'nav' => 'Contact',
        'title' => 'Messages de contact',
        'lead' => 'Envoyés avec le formulaire de contact, supprimés après :days jours.',
        'count' => '{0} Aucun message|{1} :count message|[2,*] :count messages',
        'search_placeholder' => 'Nom, e-mail ou société',
        'all_topics' => 'Tous les sujets',
        'account' => 'Compte',
        'status' => 'État',
        'received' => 'Reçu',
        'sent' => 'Envoyé',
        'failed' => 'Échec',
        'queued' => 'En attente',
        'none' => 'Aucun message.',
        'back' => 'Messages de contact',
        'reply' => 'Répondre',
        'details' => 'Détails',
        'browser' => 'Navigateur',
    ],
];
