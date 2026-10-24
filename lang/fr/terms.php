<?php

/*
 * Conditions d'utilisation et mentions légales (/terms). Éditeur et hébergeur tels que cetome.com/legal/ les indique (cetome, France).
 * Variables remplies par la vue depuis la configuration : :hours = durée du bac à sable, :email = contact sécurité
 * (lien mailto), :price = prix d'un produit payant, :ai_units = unités d'IA par mois et par produit payant,
 * :free_team = l'équipe du produit gratuit (« Propriétaire + 1 membre »), :talk = lien « contactez-nous »
 * (formulaire de contact, sujet auto-hébergé, comme la page des tarifs).
 */
return [
    'version' => 'Version 2026-10-23',

    'links' => [
        'pgp' => 'clé PGP',
        'talk' => 'contactez-nous',
    ],

    'notice_title' => 'Mentions légales',
    'notice' => [
        'publisher' => ['Éditeur', 'cetome, immatriculée sous le nom Cédric LÉVY-BENCHETON EI'],
        'siret' => ['SIRET', '849 836 101 00014'],
        'vat' => ['TVA', 'FR85849836101'],
        'office' => ['Siège', '37 Rue Antoine Charial, 69003 Lyon, France'],
        'director' => ['Directeur de la publication', 'Cédric Lévy-Bencheton'],
        'contact' => ['Contact', 'Formulaire de contact'],
        'host' => ['Hébergeur', 'IONOS SE, Elgendorfer Str. 57, 56410 Montabaur, Allemagne (Union européenne)'],
    ],

    'terms_title' => 'Conditions d\'utilisation',
    'terms' => [
        'scope' => ['Champ', [
            'Ces conditions s\'appliquent à toute utilisation de REDact, avec ou sans compte. Elles forment le contrat entre vous et cetome, l\'éditeur indiqué ci-dessus.',
        ]],
        'service' => ['Ce qu\'est REDact', [
            'REDact vous aide à documenter l\'évaluation d\'équipements radioélectriques au regard de la directive européenne sur les équipements radioélectriques (RED) pour les exigences essentielles de cybersécurité (articles 3.3 d), e) et f)), avec les normes harmonisées EN 18031. Ce n\'est pas un conseil juridique. Le fabricant reste seul responsable de la conformité de ses produits, de sa documentation technique et de sa déclaration de conformité.',
        ]],
        'account' => ['Votre compte', [
            'Votre compte est personnel : protégez vos clés d\'accès et votre messagerie. Vous êtes responsable de son utilisation. Nous pouvons suspendre un compte qui enfreint ces conditions ou met le service en danger.',
        ]],
        'sandbox' => ['Essai sans compte', [
            'Un bac à sable conserve votre travail :hours heures après votre dernière activité, puis le supprime, sauf si vous créez un compte. Il n\'inclut ni IA, ni documents, ni équipe. Les téléchargements et les imports nécessitent un compte.',
        ]],
        'plans' => ['Offres', [
            'Gratuite : le premier produit de votre organisation, gratuit pour toujours (:free_team), avec les règles cetome, les téléchargements et les imports. Sans IA.',
            'Payante : :price par mois hors TVA pour 2 produits (le produit gratuit et un produit payant), puis :price par mois pour chaque produit supplémentaire. Les produits payants incluent une équipe illimitée, le support et l\'IA (:ai_units unités d\'IA par mois et par produit payant, partagées par l\'organisation).',
            'Entreprise (auto-hébergée ou dédiée, sur demande) : par contrat séparé, :talk.',
        ]],
        'billing' => ['Paiement et factures', [
            'Les offres payantes sont facturées chaque mois, d\'avance, par Stripe, notre prestataire de paiement. Un produit ajouté en cours de période est facturé au prorata sur la facture suivante. Stripe applique la TVA due : les entreprises de l\'UE ayant un numéro de TVA valide sont facturées en autoliquidation. Les factures sont sur votre page de facturation et dans le portail client Stripe.',
        ]],
        'cancellation' => ['Résiliation et impayés', [
            'Un administrateur de l\'organisation peut résilier à tout moment depuis la page de facturation. La résiliation prend effet à la fin de la période payée. La période en cours n\'est pas remboursée, sauf si la loi l\'impose.',
            'Quand un abonnement prend fin, les produits payants passent en lecture seule : rien n\'est supprimé, tous les téléchargements fonctionnent et le produit gratuit reste modifiable. Une réactivation les rend de nouveau modifiables. Si un paiement échoue, Stripe le relance et vos produits restent modifiables entre-temps. S\'il échoue encore, l\'abonnement prend fin.',
        ]],
        'prices' => ['Évolution des prix', [
            'Nous annonçons tout changement de prix par e-mail au moins 30 jours avant son application. Vous pouvez résilier d\'ici là.',
        ]],
        'consumers' => ['Consommateurs', [
            'REDact s\'adresse aux entreprises. Si vous vous abonnez en tant que consommateur dans l\'Union européenne, vous pouvez vous rétracter dans les 14 jours suivant l\'abonnement, sans motif, par le formulaire de contact. Avant de payer, vous demandez expressément que le service commence immédiatement et reconnaissez perdre ce droit une fois le service pleinement exécuté : si vous vous rétractez dans les 14 jours, vous payez la période utilisée.',
        ]],
        'ai' => ['Assistance IA', [
            'L\'IA est facultative. Ses résultats sont toujours signalés comme IA et ne sont que des propositions : rien n\'entre dans votre évaluation sans l\'accord d\'une personne. L\'IA ne rend jamais de verdict et le fabricant reste responsable de ce qu\'il accepte. Votre organisation peut désactiver l\'IA. Les organisations payantes et Entreprise peuvent utiliser leur propre modèle ou leur propre clé : leur contenu va alors au fournisseur qu\'elles ont choisi, selon leur propre contrat avec lui.',
        ]],
        'use' => ['Usage acceptable', [
            'N\'extrayez pas, ne copiez pas en masse, n\'aspirez pas et n\'accédez pas de façon automatisée au contenu de REDact (arbres de décision, critères d\'évaluation, textes de pré-remplissage, bibliothèque de menaces, règles), et ne faites pas d\'ingénierie inverse du pack de contenu cetome. Ne contournez pas les limites de débit et d\'offre, et n\'utilisez pas REDact pour fournir un service à des tiers ou créer un produit concurrent, sans l\'accord écrit de cetome. Nous appliquons des limites de débit et pouvons suspendre un accès qui enfreint cette section.',
        ]],
        'licence' => ['Open source et propriété intellectuelle', [
            'Le moteur REDact est un logiciel libre sous licence GNU Affero General Public License v3.0 (AGPL-3.0), qui seule régit son code source. Le pack de contenu cetome (règles, transcriptions des arbres de décision, critères d\'évaluation, bibliothèque de menaces, textes de pré-remplissage, modèles de rapports, instructions de l\'IA) et le service hébergé appartiennent à cetome et ne relèvent pas de cette licence. Vous pouvez les utiliser, ainsi que les documents que vous produisez avec eux, pour le travail de conformité de votre organisation.',
        ]],
        'data' => ['Vos données', [
            'Ce que vous saisissez et importez reste à vous. cetome l\'héberge et le traite uniquement pour fournir le service, comme le décrit la politique de confidentialité. Vous pouvez l\'exporter ou le supprimer.',
        ]],
        'security' => ['Sécurité', [
            'Signalez les vulnérabilités à :email. Nous répondons sous 2 jours ouvrés et n\'engageons aucune poursuite contre une recherche de bonne foi qui respecte la vie privée des utilisateurs et la disponibilité du service.',
            'Chiffrez les détails sensibles avec notre :pgp (empreinte :fingerprint).',
        ]],
        'liability' => ['Responsabilité', [
            'Le service est fourni en l\'état. REDact ne garantit ni la conformité de vos produits, ni la décision d\'un organisme notifié, d\'un laboratoire ou d\'une autorité de surveillance du marché. Dans les limites permises par la loi, cetome n\'est pas responsable des dommages indirects, pertes d\'activité ou sanctions réglementaires, et sa responsabilité totale est limitée aux sommes que vous avez payées pour REDact dans les 12 mois précédant la réclamation.',
        ]],
        'law' => ['Modifications, droit applicable et tribunaux', [
            'Nous vous informerons à l\'avance de toute modification importante de ces conditions. Le droit français s\'applique. Tout litige relève des tribunaux de Lyon (France), sauf si la loi donne à un consommateur un autre tribunal.',
        ]],
    ],
];
