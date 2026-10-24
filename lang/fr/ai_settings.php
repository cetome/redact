<?php

// Console : réglages de l’IA (App\Http\Controllers\Admin\AiSettingsController). Votre clé, votre modèle.
return [
    'nav' => 'Réglages IA',
    'title' => 'Réglages de l’IA',
    'lead' => 'Le fournisseur d’IA, le modèle et la clé de cette installation. Les réglages enregistrés ici remplacent ceux du fichier .env.',
    'read_only' => 'Seul un super-administrateur peut modifier ces réglages.',

    'status' => [
        'title' => 'En service',
        'state' => 'État',
        'ready' => 'Prête',
        'not_ready' => 'Non configurée',
        'disabled' => 'Désactivée (REDACT_AI_ENABLED=false)',
        'provider' => 'Fournisseur',
        'model' => 'Modèle',
        'base_url' => 'URL de base de l’API',
        'base_url_default' => 'Celle du fournisseur',
        'key' => 'Clé d’API',
        'key_none' => 'Aucune clé',
        'key_console' => ':hint, enregistrée ici',
        'key_env' => ':hint, depuis :variable',
        'source' => 'Réglages issus de',
        'source_console' => 'Cette page',
        'source_env' => 'Le fichier .env',
        'changed' => 'Dernière modification',
        'changed_by' => ':date par :name',
        'plans' => 'Allocation d’IA',
        'plans_self' => 'Auto-hébergé : pas de limite d’offre (les quotas par personne s’appliquent)',
        'plans_saas' => 'Limitée par les offres des organisations',
        'prompts' => 'Instructions',
        'prompts_pack' => 'Du pack de contenu',
        'prompts_generic' => 'Le texte générique du moteur (le pack de contenu n’en fournit pas)',
    ],

    'providers' => [
        'anthropic' => 'Claude (Anthropic)',
        'openai' => 'ChatGPT (OpenAI)',
        'gemini' => 'Gemini (Google)',
        'mistral' => 'Mistral',
        'openai_compatible' => 'API compatible OpenAI',
    ],

    'form' => [
        'title' => 'Modifier',
        'provider' => 'Fournisseur',
        'model' => 'Identifiant du modèle',
        'model_help' => 'Texte libre, tel que le fournisseur le nomme. Suggestions : :models. Vide : :default.',
        'model_help_compatible' => 'Obligatoire : l’identifiant du modèle tel que l’API le nomme.',
        'base_url' => 'URL de base de l’API',
        'base_url_help' => 'Obligatoire pour une API compatible OpenAI (par exemple https://llm.example.com/v1). Facultative pour les autres : une passerelle devant le fournisseur.',
        'api_key' => 'Clé d’API',
        'api_key_help' => 'Enregistrée chiffrée, jamais réaffichée. Laissez vide pour garder la clé enregistrée.',
        'api_key_saved' => 'Clé enregistrée :hint',
        'clear_key' => 'Supprimer la clé enregistrée (utiliser celle du .env, s’il y en a une)',
        'save' => 'Enregistrer',
        'reset' => 'Revenir aux valeurs du .env',
        'reset_confirm' => 'Supprimer les réglages enregistrés ici et revenir aux valeurs du .env ?',
    ],

    'test' => [
        'title' => 'Tester la connexion',
        'help' => 'Envoie une courte requête au modèle avec les réglages en service. Elle ne compte pas dans les quotas.',
        'button' => 'Tester la connexion',
        'ok' => 'Connectée : :model a répondu en :ms ms.',
        'failed' => 'Le test a échoué avec :model.',
        'not_configured' => 'Rien à tester : choisissez d’abord un fournisseur, un modèle et une clé.',
    ],

    'saved' => 'Réglages de l’IA enregistrés.',
    'reset_done' => 'Réglages de l’IA supprimés : les valeurs du .env s’appliquent.',

    'errors' => [
        'model' => 'Lettres, chiffres et . _ : / @ + - uniquement.',
        'model_required' => 'Une API compatible OpenAI a besoin de l’identifiant du modèle.',
        'key' => 'La clé ne contient pas d’espace.',
    ],
];
