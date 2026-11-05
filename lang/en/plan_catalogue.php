<?php

// Console: Plans and prices (App\Http\Controllers\Admin\PlanCatalogueController, App\Billing\Catalogue).
return [
    'nav' => 'Plans and prices',
    'title' => 'Plans and prices',
    'lead' => 'What each plan above Free includes, and its Stripe prices per month and per year. Free is the first product, always.',
    'read_only' => 'Only a super-admin can change plans and prices.',

    'mode' => [
        'test' => 'Prices of the Stripe sandbox. The live prices are created the same way once the live keys are saved.',
        'live' => 'Live prices: customers pay them.',
        'none' => 'No Stripe keys: prices cannot be created yet.',
        'keys' => 'Stripe keys',
    ],

    'badges' => [
        'built_in' => 'Built in',
        'public' => 'Online payment',
        'on_request' => 'On request',
        'off' => 'Off',
        'assigned' => '{1} 1 organisation set by staff|[2,*] :count organisations set by staff',
    ],

    'fields' => [
        'name' => 'Name (:language)',
        'name_help' => 'Empty: the built-in name.',
        'key' => 'Key',
        'key_help' => 'Lower-case letters, digits and _. Cannot be changed.',
        'products' => 'Products included',
        'products_help' => 'The free product counted. Empty: no limit.',
        'team_members' => 'Team members per product',
        'team_help' => 'Besides the owner. Empty: no limit.',
        'ai_units' => 'AI units',
        'ai_period' => 'AI period',
        'extra_ai' => 'AI units per additional product',
        'extra_ai_help' => 'Empty: as many as the plan.',
        'support' => 'Support included',
        'public' => 'Offered on the pricing page with online payment',
        'public_help' => 'Off: “Talk to us”, set by staff on the organisation (a contract or a trial).',
        'active' => 'Plan in use',
    ],

    'items' => [
        'plan' => 'Plan',
        'extra_product' => 'Additional product',
    ],
    'per' => [
        'month' => 'per month',
        'year' => 'per year',
    ],

    'price' => [
        'title' => 'Prices',
        'item' => 'Price',
        'current' => 'Current',
        'subscriptions' => 'Subscriptions',
        'none' => 'None',
        'shown' => 'Shown meanwhile: :label',
        'as_plan' => 'One more unit of the plan',
        'new' => 'New price',
        'amount' => 'Amount in :currency, excluding VAT',
        'create' => 'Create',
        'create_confirm' => 'Create this price in Stripe? New subscriptions use it, existing ones keep their price.',
        'created' => ':name, :item: :price :interval created in Stripe. New subscriptions use it.',
        'help' => 'Excluding VAT, Stripe Tax adds it. A new price is used by new subscriptions, existing ones keep theirs. Without an add-on price, each additional product is one more unit of the plan price.',
        'history' => 'Earlier prices',
        'history_row' => ':item :interval, :amount, :mode, created :date',
        'modes' => ['test' => 'sandbox', 'live' => 'live'],
    ],

    'new' => [
        'title' => 'Add a plan',
        'help' => 'For example Business. Set its prices once it is saved.',
        'save' => 'Add the plan',
    ],

    'save' => 'Save',
    'delete' => 'Delete',
    'delete_confirm' => 'Delete this plan? It is not used by any organisation or subscription.',
    'saved' => ':name saved.',
    'created' => ':name added. Create its prices below.',
    'deleted' => ':name deleted.',

    'errors' => [
        'key' => 'Lower-case letters, digits and _, starting with a letter.',
        'key_taken' => 'A plan already has this key.',
        'built_in' => 'A built-in plan can be switched off, not deleted.',
        'in_use' => 'Organisations or subscriptions use this plan: switch it off instead.',
        'stripe' => 'Stripe refused: :error',
    ],
];
