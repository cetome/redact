<?php

return [
    'origin' => [
        'ai_generated' => [
            'label' => 'Généré par IA',
            'tooltip' => "Écrit par l'IA et non modifié par une personne depuis.",
        ],
        'ai_enhanced' => [
            'label' => 'Amélioré par IA',
            'tooltip' => "Amélioré par l'IA à la demande d'une personne.",
        ],
        'human_edited' => [
            'label' => 'Modifié par un humain',
            'tooltip' => "Écrit par l'IA, puis modifié par une personne.",
        ],
        'human' => [
            'label' => 'Humain',
            'tooltip' => 'Écrit par une personne.',
        ],
        'from_inventory' => [
            'label' => 'Depuis l’inventaire',
            'tooltip' => 'Rempli depuis votre inventaire et enregistré par une personne sans modification.',
            'tooltip_unsaved' => 'Rempli depuis votre inventaire. Pas encore enregistré : vérifiez-le, puis enregistrez.',
            'tooltip_edited' => 'Rempli depuis votre inventaire, puis modifié par une personne.',
        ],
        'imported' => [
            'label' => 'Importé de votre classeur',
            'tooltip' => 'Lu dans votre propre classeur cetome REDact, sans IA, et non modifié depuis.',
            'tooltip_edited' => 'Importé de votre classeur, puis modifié par une personne.',
        ],
        // Pas une marque IA : repris de la déclaration VEX du fabricant (un fichier VEX importé).
        'from_vex' => [
            'label' => 'Depuis le VEX',
            'tooltip' => 'Repris de la déclaration VEX du fabricant, sans modification depuis.',
            'tooltip_unsaved' => 'Repris de la déclaration VEX. Pas encore enregistré : vérifiez, puis enregistrez.',
            'tooltip_edited' => 'Repris de la déclaration VEX, puis modifié par une personne.',
        ],
    ],
    'provider_label' => 'cetome par défaut',

    'units' => [
        'uses' => '{1} Utilise :count unité IA.|[2,*] Utilise :count unités IA.',
    ],

    'features' => [
        'improve_text' => 'Améliorer le texte avec l’IA',
        'check' => 'Vérification IA',
        'suggest_path' => 'Chemin suggéré',
        'draft_from_documents' => 'Brouillon depuis la documentation',
        'suggest_scope' => 'Suggestion de périmètre',
    ],

    'sources' => 'Sources',

    'improve' => [
        'button' => 'Améliorer le texte avec l’IA',
        'working' => 'Amélioration…',
        'tooltip' => 'Réécrit ce texte en phrases plus claires avec les mêmes faits. Vous voyez chaque changement avant de le garder.',
        'empty' => 'Écrivez d’abord le texte, l’IA pourra ensuite l’améliorer.',
        'dialog_title' => 'Formulation améliorée',
        'dialog_intro' => 'Les mêmes faits, des phrases plus claires. Vérifiez que rien n’a été ajouté ni perdu, puis gardez-la ou gardez vos mots.',
        'proposed' => 'Texte proposé',
        'what_changed' => 'Ce qui a changé',
        'nothing' => 'L’IA n’a rien trouvé à améliorer : votre texte se lit déjà bien.',
        'keep' => 'Garder mes mots',
        'accept' => 'Accepter',
    ],

    'check' => [
        'button' => 'Vérifier cette évaluation',
        'again' => 'Vérifier à nouveau',
        'tooltip' => 'Lit les réponses enregistrées, le nœud de décision, le verdict et la justification, et signale ce qui semble incohérent. Ne change rien.',
        'title' => 'Vérification IA',
        'done' => 'L’IA a vérifié l’évaluation. Ses remarques figurent sous l’évaluation.',
        'no_findings' => 'L’IA n’a rien trouvé d’incohérent.',
        'stale' => 'L’évaluation a changé depuis cette vérification.',
        'human_decides' => 'L’IA ne décide jamais : vous pesez ces remarques et gardez ou modifiez vous-même l’évaluation.',
        'dismiss' => 'Masquer',
        'dismiss_tooltip' => 'Masquer ce résultat de l’IA. Rien ne change dans l’évaluation.',
        'severity' => [
            'problem' => 'Problème',
            'warning' => 'Attention',
            'info' => 'Remarque',
        ],
    ],

    'path' => [
        'button' => 'Suggérer un chemin',
        'again' => 'Suggérer à nouveau',
        'tooltip' => 'Propose une réponse à chaque question de l’arbre, avec sa raison et sa source, à partir des données de l’élément et des documents. Vous choisissez vous-même chaque réponse.',
        'title' => 'Chemin suggéré',
        'done' => 'L’IA a suggéré des réponses. Elles figurent sous l’évaluation, à vérifier.',
        'human_decides' => 'Simples suggestions : choisissez vous-même chaque réponse dans l’arbre. L’IA ne décide jamais du chemin ni du verdict.',
        'none' => 'Les éléments disponibles ne permettent pas à l’IA de suggérer de réponse.',
        'suggested' => 'L’IA suggère : :answer',
        'yours' => 'Votre réponse : :answer',
        'source' => 'Source : :source',
    ],

    'draft' => [
        'title' => 'Brouillon depuis la documentation',
        'button' => 'Brouillon depuis la documentation',
        'help' => 'L’IA lit les documents du produit et propose la description, l’usage prévu, des éléments d’inventaire, des textes E.Info et des risques, chacun avec ses sources. Rien n’est écrit tant que vous ne l’acceptez pas.',
        'tooltip' => 'Lit tous les documents de cette version et propose des textes et des éléments à accepter un par un.',
        'where' => 'Les propositions apparaissent sur la page Assistance IA.',
        'started' => 'L’IA lit les documents. Ses propositions apparaîtront sur cette page.',
        'running' => 'L’IA lit les documents. Cette page se met à jour quand les propositions sont prêtes.',
        'done' => '{0} L’IA a lu les documents et n’a rien trouvé de nouveau à proposer.|{1} L’IA a fait :count proposition. Acceptez-la ou rejetez-la ci-dessous.|[2,*] L’IA a fait :count propositions. Acceptez-les ou rejetez-les ci-dessous.',
        'failed' => 'Le brouillon n’a pas pu être terminé. Réessayez dans un instant.',
        'no_documents' => 'Ajoutez d’abord au moins un document dont le texte est lisible.',
        'already_running' => 'Un brouillon est déjà en cours pour cette version.',
    ],

    'review' => [
        'group_count' => '{0} Rien à revoir|{1} 1 groupe|[2,*] :count groupes',
        'title' => 'Assistance IA',
        'intro' => 'Ce que l’IA a proposé pour cette version. Rien n’entre dans l’évaluation tant qu’une personne ne l’accepte pas, et un texte accepté garde sa marque IA jusqu’à ce qu’une personne le modifie.',
        'open' => 'Ouvrir la page Assistance IA',
        'pending' => 'Propositions à trancher',
        'nothing' => 'Aucune proposition en attente.',
        'nothing_help' => 'Utilisez « Brouillon depuis la documentation » ou « Améliorer le texte avec l’IA » à côté d’un texte.',
        'current' => 'Texte actuel',
        'accept' => 'Accepter',
        'accept_tooltip' => 'Utiliser ce texte dans l’évaluation.',
        'accept_replace_tooltip' => 'Remplacer le texte actuel par celui-ci.',
        'accept_all' => 'Tout accepter',
        'accept_all_tooltip' => 'Utiliser toutes les propositions de ce groupe dans l’évaluation.',
        'reject' => 'Rejeter',
        'reject_tooltip' => 'Écarter cette proposition. Rien ne change dans l’évaluation.',
        'accepted' => 'Acceptée. Le texte porte désormais sa marque IA.',
        'rejected' => 'Rejetée. Rien n’a changé.',
        'dismissed' => 'Masqué. Rien n’a changé.',
        'accepted_many' => '{0} Rien n’a été accepté.|{1} :count proposition acceptée.|[2,*] :count propositions acceptées.',
        'failed_many' => '{1} :count n’a pas pu être acceptée.|[2,*] :count n’ont pas pu être acceptées.',
        'decided' => 'Dernières décisions',
        'decided_by' => 'par :name le :date',
        'status' => [
            'accepted' => 'Acceptée',
            'rejected' => 'Rejetée',
        ],
        'rules' => 'L’IA ne donne jamais de verdict et ne décide jamais d’un chemin dans un arbre de décision. Une personne prend chaque décision.',
    ],

    'allowance' => [
        'title' => 'Quota IA',
        'day' => ':used unités sur :limit utilisées ces dernières 24 heures.',
        'burst' => ':used unités sur :limit utilisées ces 10 dernières minutes.',
        'model' => 'Modèle IA : :model.',
    ],

    'quota' => [
        'too_big' => 'Cette action demande :units unités IA et votre quota est de :cap à la fois. Les actions plus petites restent disponibles.',
        'day' => 'Vous avez utilisé votre quota IA du jour (:limit unités par 24 heures). Il se reconstitue au fil de la journée.',
        'burst' => 'Trop de demandes IA en peu de temps (:limit unités par 10 minutes). Attendez quelques minutes et réessayez.',
        'unchecked' => 'Votre quota IA n’a pas pu être vérifié pour le moment. Réessayez dans un instant.',
    ],

    'unavailable' => [
        'short' => 'IA indisponible',
        'disabled' => 'L’assistance IA est désactivée sur ce service.',
        'organisation' => 'Votre organisation a désactivé l’assistance IA. Un administrateur de l’organisation peut la réactiver.',
        'role' => 'Seuls le propriétaire du produit, les contributeurs et les administrateurs de l’organisation peuvent utiliser l’assistance IA.',
        'not_draft' => 'L’assistance IA est disponible tant que la version est un brouillon.',
        'not_configured' => 'L’assistance IA n’est pas disponible pour le moment.',
        'guest' => 'L’assistance IA est incluse dans l’offre payante.',
    ],

    'errors' => [
        'unavailable' => 'L’IA n’a pas pu répondre pour le moment. Réessayez dans un instant.',
        'target_not_allowed' => 'L’IA ne peut pas proposer de valeur pour ce champ.',
        'target_gone' => 'Ce champ n’existe plus.',
        'already_decided' => 'Cette proposition a déjà été tranchée.',
        'not_acceptable' => 'Ce résultat de l’IA est un simple conseil : il n’y a rien à accepter.',
        'value_invalid' => 'Cette proposition ne peut pas être utilisée telle quelle. Rejetez-la ou redemandez.',
        'no_tree' => 'Il n’y a pas d’arbre de décision pour cette exigence.',
    ],

    'groups' => [
        'product' => 'Produit : :name',
        'new_item' => 'Nouvel élément (:list) : :name',
        'new_risk' => 'Nouveau risque : :threat',
        'risk' => 'Risque : :threat',
        'gone' => 'Un élément qui n’existe plus',
    ],

    'targets' => [
        'product_description' => 'Description du produit',
        'intended_use' => 'Usage prévu',
        'justification' => 'Justification de :requirement',
        'risk' => 'Risque :threat · :field',
        'einfo' => 'E.Info :code',
    ],

    'fields' => [
        'name' => 'Nom',
        'description' => 'Description',
        'intended_use' => 'Usage prévu',
        'justification' => 'Justification',
        'text' => 'Texte',
        'threat' => 'Menace',
        'risk' => 'Risque',
        'remediation' => 'Remédiation',
        'implementation' => 'Mise en œuvre',
    ],

    'documents' => [
        'title' => 'Documents du produit',
        'intro' => 'Fiches techniques, manuels et documents d’architecture de cette version. Ils restent privés et ne sont lus que par « Brouillon depuis la documentation » et « Suggérer un chemin ».',
        'none' => 'Aucun document pour l’instant.',
        'count' => '{0} Aucun document (jusqu’à :max).|{1} :count document (jusqu’à :max).|[2,*] :count documents (jusqu’à :max).',
        'manage' => 'Gérer les documents',
        'add' => 'Ajouter un document',
        'upload' => 'Envoyer',
        'limits' => 'PDF, Word (.docx), Excel (.xlsx), CSV, texte ou Markdown, jusqu’à :mb Mo chacun et :max par version.',
        'download_tooltip' => 'Télécharger le fichier tel qu’il a été envoyé.',
        'delete_tooltip' => 'Supprimer définitivement ce document.',
        'confirm_delete' => 'Supprimer définitivement :name ?',
        'uploaded' => ':name ajouté.',
        'uploaded_no_text' => ':name ajouté, mais aucun texte n’a pu en être lu (un PDF scanné ?). L’IA ne pourra pas l’utiliser.',
        'deleted' => ':name supprimé.',
        'too_many' => 'Une version peut contenir jusqu’à :max documents. Supprimez-en un d’abord.',
        'wrong_type' => 'Ce fichier n’est pas un document PDF, Word (.docx), Excel (.xlsx), CSV, texte ou Markdown.',
        'not_saved' => 'Le document n’a pas pu être enregistré. Réessayez.',
        'locked' => 'Créez un compte pour déposer',
        'locked_tooltip' => 'Les documents se déposent une fois le bac à sable enregistré comme compte',
    ],
];
