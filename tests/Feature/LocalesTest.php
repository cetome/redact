<?php

namespace Tests\Feature;

use App\Support\Locales;
use Tests\TestCase;

/** Per-language details (i18n): each interface language has them, a new one falls back to English. */
class LocalesTest extends TestCase
{
    public function test_every_interface_language_has_its_details(): void
    {
        foreach (array_keys((array) config('redact.locales')) as $locale) {
            foreach (['ai', 'altcha', 'og', 'stripe', 'colon', 'thousands'] as $key) {
                $this->assertArrayHasKey($key, config('redact.locale_details.'.$locale), "$locale: $key");
            }
        }
        $this->assertSame('French', Locales::detail('ai', 'fr'));
        $this->assertSame('fr-fr', Locales::detail('altcha', 'fr'));
    }

    public function test_a_language_without_details_falls_back_to_english(): void
    {
        $this->assertSame('British English', Locales::detail('ai', 'de'));
        $this->assertSame('en_GB', Locales::detail('og', 'de'));
    }
}
