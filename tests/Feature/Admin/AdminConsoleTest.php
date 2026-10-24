<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminConsoleTest extends TestCase
{
    use AdminTestHelpers, RefreshDatabase;

    private const PAGES = ['/admin', '/admin/users', '/admin/products', '/admin/activity', '/admin/usage', '/admin/organisations', '/admin/contact'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
    }

    public function test_non_staff_get_404_everywhere(): void
    {
        $data = $this->seedSampleData();
        $customer = $data['customer'];

        foreach ([...self::PAGES, '/admin/users/'.$customer->id, '/admin/users/export', '/admin/activity/export'] as $page) {
            $this->signIn($customer)->get($page)->assertNotFound();
        }

        $this->signIn($customer)->post('/admin/users/'.$customer->id.'/disable')->assertNotFound();
        foreach (['users', 'products', 'organisations', 'activity', 'contact'] as $list) {
            $this->signIn($customer)->post('/admin/'.$list.'/filter', ['q' => 'x'])->assertNotFound();
        }
    }

    public function test_staff_see_every_console_page_uncached(): void
    {
        $data = $this->seedSampleData();
        $staff = $this->staff();

        $this->signIn($staff)->get('/admin')
            ->assertOk()
            ->assertSee(__('admin.dashboard.title'))
            ->assertSee('data-chart', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeaderContains('Cache-Control', 'no-store');

        foreach (self::PAGES as $page) {
            $this->signIn($staff)->get($page)->assertOk()->assertHeaderContains('Cache-Control', 'no-store');
        }

        $this->signIn($staff)->get('/admin/usage/90')->assertOk()->assertSee('versions.show')
            ->assertSee('href="'.route('admin.usage.period', 7).'"', false);
        $this->signIn($staff)->get('/admin/usage/45')->assertNotFound();
        $this->signIn($staff)->get('/admin/users/'.$data['customer']->id)
            ->assertOk()
            ->assertSee('Smart Plug')
            ->assertSee(route('products.show', $data['product']), false)
            ->assertSee('products.show');
        $this->signIn($staff)->get('/admin/products')->assertOk()->assertSee(route('products.show', $data['product']), false);
    }

    public function test_users_list_is_searchable(): void
    {
        $this->seedSampleData();
        $this->makeUser(['name' => 'Zed Other', 'email' => 'zed@elsewhere.test']);

        $staff = $this->staff();
        $this->signIn($staff)->post('/admin/users/filter', ['q' => 'Acme'])->assertRedirect('/admin/users');
        $this->get('/admin/users')
            ->assertOk()
            ->assertSee('Customer One')
            ->assertDontSee('Zed Other');

        // Reset forgets the search.
        $this->post('/admin/users/filter', ['reset' => '1'])->assertRedirect('/admin/users');
        $this->get('/admin/users')->assertOk()->assertSee('Zed Other');

        $this->post('/admin/users/filter', ['status' => 'nonsense'])->assertSessionHasErrors('status');
    }

    public function test_lists_page_by_path_and_no_console_address_has_a_query_string(): void
    {
        $data = $this->seedSampleData();
        foreach (range(1, 30) as $i) {
            $this->makeUser(['name' => sprintf('Paged %02d', $i), 'email' => sprintf('paged%02d@example.test', $i)]);
        }
        $staff = $this->superAdmin();

        $first = $this->signIn($staff)->get('/admin/users')->assertOk()
            ->assertSee('href="'.route('admin.users.page', 2).'"', false);
        $this->signIn($staff)->get('/admin/users/page/2')->assertOk()
            ->assertSee('href="'.route('admin.users.index').'"', false);
        $this->signIn($staff)->get('/admin/users/page/x')->assertNotFound();

        $this->signIn($staff)->post('/admin/activity/filter', ['log' => 'product'])->assertRedirect('/admin/activity');
        $pages = [$first,
            ...array_map(fn (string $url) => $this->signIn($staff)->get($url)->assertOk(), [
                '/admin', '/admin/users/page/2', '/admin/products', '/admin/organisations', '/admin/activity',
                '/admin/usage', '/admin/usage/7', '/admin/contact', '/admin/ai', '/admin/users/'.$data['customer']->id,
                '/admin/organisations/'.$data['organisation']->id,
            ])];
        $own = preg_quote(rtrim(url('/'), '/'), '#');
        foreach ($pages as $page) {
            preg_match_all('#\b(?:href|src|action|formaction|data-url)="([^"]*)"#', $page->getContent(), $m);
            foreach ($m[1] as $url) {
                $url = html_entity_decode($url);
                if (str_starts_with($url, '/') || preg_match('#^'.$own.'(/|$)#', $url)) {
                    $this->assertStringNotContainsString('?', $url, 'Query string in '.$url);
                }
            }
        }
    }

    public function test_the_timeline_filter_is_kept_per_person(): void
    {
        $data = $this->seedSampleData();
        $staff = $this->staff();
        $id = $data['customer']->id;

        $this->signIn($staff)->post('/admin/users/'.$id.'/timeline', ['source' => 'activity', 'from' => '2026-01-01'])
            ->assertRedirect('/admin/users/'.$id);
        $this->get('/admin/users/'.$id)->assertOk()->assertSee('value="2026-01-01"', false);
        $this->post('/admin/users/'.$id.'/timeline', ['reset' => '1'])->assertRedirect('/admin/users/'.$id);
        $this->get('/admin/users/'.$id)->assertOk()->assertDontSee('value="2026-01-01"', false);
        $this->post('/admin/users/'.$id.'/timeline', ['from' => '01/01/2026'])->assertSessionHasErrors('from');
    }

    public function test_activity_explorer_filters_and_shows_changes(): void
    {
        $data = $this->seedSampleData();
        $data['product']->update(['name' => 'Smart Plug Pro']);

        $staff = $this->staff();
        $this->signIn($staff)->post('/admin/activity/filter', ['log' => 'product'])->assertRedirect('/admin/activity');
        $this->get('/admin/activity')
            ->assertOk()
            ->assertSee('Smart Plug Pro')
            ->assertSee('Smart Plug')
            ->assertDontSee('report exported');

        $this->post('/admin/activity/filter', ['q' => 'report'])->assertRedirect('/admin/activity');
        $this->get('/admin/activity')
            ->assertOk()
            ->assertSee('report exported');
    }

    public function test_exports_are_super_admin_only(): void
    {
        $this->seedSampleData();

        $this->signIn($this->staff())->get('/admin/users/export')->assertNotFound();
        $this->signIn($this->staff())->get('/admin/activity/export')->assertNotFound();

        $this->signIn($this->superAdmin())->get('/admin/activity/export')
            ->assertOk()
            ->assertHeaderContains('Content-Type', 'text/csv');

        // The export follows the remembered filters.
        $this->post('/admin/activity/filter', ['q' => 'report'])->assertRedirect('/admin/activity');
        $this->get('/admin/activity')->assertSee('href="'.route('admin.activity.export').'"', false);
        $csv = $this->get('/admin/activity/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('report exported', $csv);
        $this->assertStringNotContainsString('product created', strtolower($csv));
    }
}
