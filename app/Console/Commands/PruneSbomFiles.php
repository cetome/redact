<?php

namespace App\Console\Commands;

use App\Http\Controllers\SbomController;
use App\Models\ProductVersion;
use App\Models\SbomImport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps the private disk tidy: original bill-of-materials files of versions that no longer
 * exist (deleted products, expired guest sandboxes: the rows cascade, the files do not), and
 * uploads nobody imported within 30 days.
 */
#[Signature('redact:prune-sbom-files')]
#[Description('Delete bill-of-materials files of deleted versions and abandoned uploads')]
class PruneSbomFiles extends Command
{
    public function handle(): int
    {
        $disk = Storage::disk(SbomController::DISK);
        $orphans = 0;
        foreach ($disk->directories('sbom') as $directory) {
            if (! ProductVersion::whereKey(basename($directory))->exists()) {
                $disk->deleteDirectory($directory);
                $orphans++;
            }
        }

        $abandoned = 0;
        SbomImport::query()->where('status', 'preview')->where('created_at', '<', now()->subDays(30))
            ->each(function (SbomImport $import) use ($disk, &$abandoned) {
                $directory = 'sbom/'.$import->product_version_id.'/'.$import->id;
                if ($disk->directoryExists($directory)) {
                    $disk->deleteDirectory($directory);
                }
                $import->forceFill(['status' => 'discarded', 'components' => [], 'files' => []])->save();
                $abandoned++;
            });

        $this->info("Removed files of {$orphans} deleted versions and {$abandoned} abandoned uploads.");

        return self::SUCCESS;
    }
}
