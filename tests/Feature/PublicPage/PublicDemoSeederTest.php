<?php

namespace Tests\Feature\PublicPage;

use App\Models\Product;
use Database\Seeders\PublicDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The demo public page of /about (PublicDemoSeeder): published at its fixed address, all
 * requirements met, kept out of search engines, the same address when seeded again.
 */
class PublicDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_page_is_published_at_its_fixed_address(): void
    {
        $this->seed(PublicDemoSeeder::class);
        $this->seed(PublicDemoSeeder::class);

        $this->assertSame(1, Product::where('public_code', PublicDemoSeeder::CODE)->count());
        $this->get('/verify/'.PublicDemoSeeder::CODE)->assertOk()
            ->assertSee(PublicDemoSeeder::PRODUCT)
            ->assertSee(__('public_page.page.all_met'))
            ->assertSee('noindex', false);
        $this->assertStringNotContainsString('/verify/'.PublicDemoSeeder::CODE, (string) $this->get('/sitemap.xml')->getContent());
    }
}
