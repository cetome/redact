<?php

// Transfer ownership of a product (App\Actions\TransferOwnership, team/index.blade.php).
return [
    'button' => 'Transfer ownership',
    'short' => 'Transfer',
    'title' => 'Transfer :product',
    'new_owner' => 'New owner',
    'previous' => ':name then',
    'previous_as' => [
        'contributor' => 'Stays as Contributor',
        'reviewer' => 'Stays as Reviewer',
        'leave' => 'Leaves the product',
    ],
    'submit' => 'Transfer',
    'confirm' => 'Hand :product over to the new owner?',
    'done' => ':product now belongs to :name.',
    'not_member' => ':name is not a member of :organisation.',
    'no_seat' => 'No seat left for :name on the team. :reason',
    'mail' => [
        'subject' => 'You now own :product in REDact',
        'greeting' => 'New product',
        'intro' => ':by handed :product (:organisation) over to you.',
        'action' => 'Open the product',
    ],
];
