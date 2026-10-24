// REDact front end. Alpine.js CSP build: no eval, no inline expressions that
// need it, so the strict Content-Security-Policy holds.
//
// Feature code lives in ./modules/*.js. Each module registers its Alpine
// components on `alpine:init`, so this file never has to change.
import Alpine from '@alpinejs/csp';

window.Alpine = Alpine;

import.meta.glob('./modules/*.js', { eager: true });

Alpine.start();
