<?php

namespace App\Providers;

use App\Models\InventoryItem;
use App\Vulnerabilities\ComponentObserver;
use Illuminate\Support\ServiceProvider;

/** Bills of materials and the known-vulnerability check (App\Sbom, App\Vulnerabilities). */
class SbomServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        InventoryItem::observe(ComponentObserver::class);
    }
}
