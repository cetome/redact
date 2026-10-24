<?php

// Transfert de propriété d'un produit (App\Actions\TransferOwnership, team/index.blade.php).
return [
    'button' => 'Transférer la propriété',
    'short' => 'Transférer',
    'title' => 'Transférer :product',
    'new_owner' => 'Nouveau propriétaire',
    'previous' => 'Ensuite, :name',
    'previous_as' => [
        'contributor' => 'Reste contributeur',
        'reviewer' => 'Reste relecteur',
        'leave' => 'Quitte le produit',
    ],
    'submit' => 'Transférer',
    'confirm' => 'Confier :product au nouveau propriétaire ?',
    'done' => ':product appartient maintenant à :name.',
    'not_member' => ':name n’est pas membre de :organisation.',
    'no_seat' => 'Plus de place dans l’équipe pour :name. :reason',
    'mail' => [
        'subject' => 'Vous êtes maintenant propriétaire de :product dans REDact',
        'greeting' => 'Nouveau produit',
        'intro' => ':by vous a confié :product (:organisation).',
        'action' => 'Ouvrir le produit',
    ],
];
