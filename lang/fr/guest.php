<?php

// Bac à sable invité (« Essayer sans compte »).
return [

    'name' => 'Invité',
    'organisation' => 'Espace invité',
    'keep' => 'Garder mon travail',
    'leave' => 'Quitter',
    'leave_confirm' => 'Quitter le bac à sable ? Votre travail reste jusqu’au :date, mais un bac à sable n’a pas de connexion : vous ne pourrez pas y revenir. Pour le garder, créez d’abord un compte.',
    'banner' => 'Bac à sable invité : votre travail sera supprimé le :date si vous ne créez pas de compte.',
    'banner_label' => 'Bac à sable invité',
    'expired' => 'Votre bac à sable a expiré et son contenu a été supprimé. Vous pouvez en ouvrir un nouveau.',
    'product_limit' => 'Un bac à sable contient un seul produit. Créez un compte gratuit pour en évaluer davantage.',
    'documents' => 'Déposer des documents produit nécessite un compte. L’assistance IA est incluse dans l’offre payante.',
    'team' => 'Inviter des collègues et des relecteurs demande un compte gratuit. Votre travail vous suit.',
    'try_link' => 'Essayer sans compte',
    // « Voir une démo » (App\Guest\DemoProduct) : l’évaluation complète d’un produit fictif dans un bac à sable.
    'demo' => [
        'button' => 'Voir une démo',
        'card' => 'Une ampoule connectée, évaluée de bout en bout.',
        'loaded' => 'Démo chargée : une ampoule connectée fictive, prête à explorer.',
        'full' => 'Votre bac à sable contient déjà un produit. Supprimez-le pour charger la démo.',
    ],
    // Premier écran après « Commencer avec ce produit » sur l’accueil (un message flash, une fois).

    'start' => [
        'title' => 'Essayer REDact sans compte',
        'heading' => 'Essayer REDact sans compte',
        'lead' => 'Un bac à sable privé avec tout le parcours. Rien à installer, aucune adresse e-mail demandée.',
        'included' => 'Comment fonctionne le bac à sable',
        'full' => 'Tout le parcours pour un produit : description, périmètre, risques, inventaire, arbres de décision, E.Info et revue.',
        'reports' => 'Les rapports Word et Excel.',
        'no_ai' => 'Pas d’assistance IA. Elle est incluse dans l’offre payante.',
        'files' => 'Compte nécessaire pour télécharger ou importer vos documents (rapports, classeurs, documents produit, SBOM).',
        'kept' => 'Votre travail est conservé :hours heures après votre dernière activité, puis supprimé définitivement.',
        'keep' => 'Créez un compte à tout moment pour le garder : votre travail passe dans le compte.',
        'browser' => 'Le bac à sable vit dans ce navigateur. Si vous le quittez ou restez absent trop longtemps, vous ne pourrez pas y revenir.',
        'submit' => 'Ouvrir mon bac à sable',
        'have_account' => 'Vous avez déjà un compte ?',
        'sign_in' => 'Se connecter',
        'started' => 'Votre bac à sable est prêt. Commencez par décrire un produit.',
        'too_many' => 'Trop de bacs à sable ont été ouverts depuis votre réseau aujourd’hui. Réessayez demain ou créez un compte gratuit.',
        // La vérification anti-spam invisible (sandbox-start.js) : résolue au chargement, puis le formulaire part tout seul.
        'opening' => 'Ouverture…',
        'check' => [
            'verifying' => 'Vérification anti-spam en arrière-plan…',
            'ready' => 'Vérification anti-spam terminée. Prêt à commencer.',
            'preparing' => 'Préparation de votre bac à sable…',
            'failed' => 'La vérification anti-spam n’a pas abouti. Appuyez sur le bouton pour réessayer.',
        ],
    ],

    'claim' => [
        'title' => 'Garder mon travail',
        'heading' => 'Garder mon travail',
        'lead' => 'Créez votre compte gratuit et tout le contenu de ce bac à sable vous suit. Aucun mot de passe.',
        'existing' => 'Vous avez déjà un compte REDact ? Utilisez son adresse et nous y ajoutons ce travail.',
        'submit' => 'M’envoyer un code',
        'sent' => 'Consultez votre boîte de réception pour votre code à 6 chiffres.',
        'code_heading' => 'Saisissez votre code',
        'code_lead' => 'Si :email peut être utilisée, nous lui avons envoyé un code à 6 chiffres. Saisissez-le ici pour garder votre travail.',
        'code_submit' => 'Garder mon travail',
        'other_address' => 'Utiliser une autre adresse',
        'reserved' => 'Veuillez utiliser une vraie adresse e-mail.',
        'converted' => 'Votre compte est prêt et votre travail est en sécurité.',
        'merged' => 'Bon retour. Le travail de votre bac à sable est maintenant dans votre compte.',
    ],

    'mail' => [
        'subject' => 'Votre code pour garder votre travail REDact',
        'intro' => 'Utilisez ce code pour garder le travail de votre bac à sable REDact.',
        'existing' => 'Si cette adresse a déjà un compte REDact, le travail y est ajouté.',
    ],

    'account_required' => 'Créez votre compte gratuit pour télécharger des fichiers ou importer vos documents. Le travail de votre bac à sable vous suit.',
];
