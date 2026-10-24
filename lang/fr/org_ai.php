<?php

// Page Paramètres d’une organisation : la carte Modèle d’IA (App\Http\Controllers\Ai\OrganisationAiController), comme dans CRAted.
return [
    'tab' => 'Paramètres',
    'title' => 'Paramètres',
    'intro' => 'Le fonctionnement de votre organisation dans REDact.',

    'card' => 'Modèle d’IA',
    'lead_saas' => 'L’IA de cetome répond à chaque bouton d’IA, incluse dans votre offre payante. Votre organisation peut utiliser sa propre clé ou son propre modèle à la place : ses appels ne consomment alors pas vos unités d’IA. La clé est enregistrée chiffrée et n’est jamais réaffichée.',
    'lead_self' => 'L’IA par défaut de cette installation répond à chaque bouton d’IA. Votre organisation peut utiliser sa propre clé ou son propre modèle. La clé est enregistrée chiffrée et n’est jamais réaffichée.',
    'read_only' => 'Les administrateurs de votre organisation le choisissent. Voici ce qui vous répond.',
    'same_rules' => 'Quel que soit le modèle, l’IA reçoit les mêmes instructions et les mêmes éléments, et une personne décide.',

    'now' => 'Répond actuellement',
    'now_default_saas' => 'IA par défaut de cetome',
    'now_default_self' => 'L’IA par défaut de cette installation',
    'now_none' => 'Aucune IA : cette installation n’a pas d’IA par défaut, choisissez votre clé ou votre modèle',
    'now_own' => ':choice · :model',

    'choices' => [
        'default_saas' => 'IA par défaut de cetome (incluse)',
        'default_self' => 'L’IA par défaut de cette installation',
        'gemini_own' => 'Google Gemini (votre clé)',
        'claude' => 'Anthropic Claude (votre clé)',
        'openai' => 'OpenAI ChatGPT (votre clé)',
        'mistral' => 'Mistral (votre clé)',
        'openai_compatible' => 'Votre propre point d’accès (compatible OpenAI)',
    ],
    'key_labels' => [
        'gemini_own' => 'Clé d’API Google AI',
        'claude' => 'Clé d’API Anthropic',
        'openai' => 'Clé d’API OpenAI',
        'mistral' => 'Clé d’API Mistral',
        'openai_compatible' => 'Clé d’API (facultative)',
    ],

    'form' => [
        'answered_by' => 'Répond',
        'nothing' => 'Rien à configurer.',
        'endpoint' => 'Point d’accès',
        'endpoint_help_saas' => 'L’adresse de base d’un serveur qui parle le protocole de chat d’OpenAI : REDact appelle /chat/completions dessous. https public uniquement.',
        'endpoint_help_self' => 'L’adresse de base d’un serveur qui parle le protocole de chat d’OpenAI (Ollama, vLLM, LM Studio, Azure OpenAI, une passerelle) : REDact appelle /chat/completions dessous. http est accepté sur cette installation auto-hébergée, par exemple http://localhost:11434/v1.',
        'key' => 'Clé d’API',
        'key_placeholder' => 'VOTRE_CLE_API',
        'key_saved' => 'Clé enregistrée :hint',
        'key_set' => 'Enregistrée le :date. Saisir une nouvelle clé la remplace.',
        'key_help' => 'Enregistrée chiffrée, jamais réaffichée. Une clé ne sert qu’au fournisseur pour lequel elle a été donnée.',
        'model' => 'Modèle',
        'model_empty' => 'Vide : :model.',
        'model_compatible' => 'Le nom sous lequel votre serveur connaît le modèle.',
        'model_placeholder' => 'nom-du-modele',
        'pictures' => 'Ce modèle lit les images',
        'pictures_help' => 'REDact envoie le texte de vos documents. Les images ne vont qu’aux modèles qui les lisent.',
        'save' => 'Enregistrer les réglages d’IA',
        'test' => 'Tester la connexion',
        'test_help' => 'Envoie une courte requête à ce qui répond à votre organisation. Elle ne compte dans aucune allocation.',
    ],

    'answers' => [
        'default' => 'L’IA par défaut répond de nouveau à votre organisation.',
        'gemini_own' => 'Gemini répond à votre organisation avec votre clé.',
        'claude' => 'Claude répond à votre organisation avec votre clé.',
        'openai' => 'OpenAI répond à votre organisation avec votre clé.',
        'mistral' => 'Mistral répond à votre organisation avec votre clé.',
        'openai_compatible' => 'Votre propre modèle répond à votre organisation.',
    ],

    'test' => [
        'ok' => 'Connectée : :model a répondu en :ms ms.',
        'failed' => 'Le test a échoué avec :model.',
        'not_configured' => 'Rien à tester : aucune IA ne répond encore à votre organisation.',
    ],

    'errors' => [
        'model' => 'Lettres, chiffres et . _ : / @ + - uniquement.',
        'model_required' => 'Saisissez le nom sous lequel votre serveur connaît le modèle.',
        'key' => 'La clé ne contient pas d’espace.',
        'key_required' => 'Saisissez la clé d’API de votre organisation pour ce fournisseur.',
        'endpoint_invalid' => 'Saisissez l’adresse du serveur, par exemple https://ai.example.com/v1.',
        'endpoint_https_only' => 'Utilisez une adresse https.',
        'endpoint_unresolved' => 'Cette adresse est introuvable.',
        'endpoint_private' => 'Cette adresse est sur un réseau privé ou local : utilisez une adresse publique.',
    ],
];
