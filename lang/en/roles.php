<?php

// Role help texts follow CRAted's wording.
return [
    'owner' => [
        'label' => 'Product owner',
        'help' => 'Created the product and has every right on it.',
    ],
    'contributor' => [
        'label' => 'Contributor',
        'help' => 'Fills in the assessment and runs the AI. Cannot change the product settings or its team.',
    ],
    'reviewer' => [
        'label' => 'Reviewer',
        'help' => 'Judges the assessment of each requirement and gives the final decision. Never edits.',
    ],
    'assessor' => [
        'label' => 'Assessor',
        'help' => 'External: records the assessment units, nothing else.',
    ],
    'internal' => 'Internal',
    'external' => 'External',
    'internal_help' => 'A member of the organisation that owns the product.',
    'external_help' => 'Someone outside the organisation, for example a test lab or a consultant.',
];
