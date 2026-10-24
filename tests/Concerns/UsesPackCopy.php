<?php

namespace Tests\Concerns;

use App\Content\ContentPack;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Runs a test on a throwaway copy of a content pack (the fixture pack by default), changed by
 * the test, then deleted. Each copy has its own path, so the pack cache never mixes them.
 */
trait UsesPackCopy
{
    /** @var list<string> */
    private array $packCopies = [];

    /**
     * @param  callable(string): void  $change  receives the copy's path
     */
    protected function usePackCopy(callable $change, string $from = 'tests/Fixtures/content-pack'): string
    {
        $path = storage_path('framework/testing/pack-'.Str::lower((string) Str::ulid()));
        File::copyDirectory(base_path($from), $path);
        $this->packCopies[] = $path;
        $change($path);

        config(['redact.content_path' => $path]);
        $this->app->forgetInstance(ContentPack::class);

        return $path;
    }

    /** @param  callable(array<string, mixed>): array<string, mixed>  $change */
    protected function changeJson(string $file, callable $change): void
    {
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : [];
        file_put_contents($file, json_encode($change($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    protected function tearDown(): void
    {
        foreach ($this->packCopies as $path) {
            File::deleteDirectory($path);
        }
        $this->packCopies = [];

        parent::tearDown();
    }
}
