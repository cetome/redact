<?php

use RedactDemoPack\DemoRules;

// REDact demo pack: the engine's data-driven GenericRules, extended by src/DemoRules.php to show
// how a pack adds its own method. Invented rules, for trying the software only.

if (! class_exists(DemoRules::class, false)) {
    require __DIR__.'/src/DemoRules.php';
}

return new DemoRules;
