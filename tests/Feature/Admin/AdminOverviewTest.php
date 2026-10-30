<?php

namespace Tests\Feature\Admin;

use App\Admin\WorkActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** Console overview: who does what (App\Admin\WorkActivity). */
class AdminOverviewTest extends TestCase
{
    use AdminTestHelpers, RefreshDatabase;

    /** @return array<string, mixed> */
    private function seedWork(): array
    {
        $data = $this->seedSampleData();
        $colleague = $this->makeUser(['name' => 'Colleague Two']);
        $guest = $this->makeUser(['name' => 'Sandbox Guest', 'is_guest' => true, 'email' => 'guest-9@sandbox.invalid']);

        foreach (range(1, 3) as $i) {
            activity('evaluation')->causedBy($colleague)->performedOn($data['version'])->log('updated');
        }
        activity('review')->causedBy($data['customer'])->performedOn($data['version'])->log('updated');
        activity('evaluation')->causedBy($guest)->performedOn($data['version'])->log('updated');
        // Older than 30 days: only in the 90-day view.
        $old = activity('einfo')->causedBy($colleague)->performedOn($data['version'])->log('updated');
        Activity::query()->whereKey($old->id)->update(['created_at' => now()->subDays(45)]);

        return $data + compact('colleague', 'guest');
    }

    public function test_the_figures_count_accounts_only_by_area_person_and_product(): void
    {
        $data = $this->seedWork();

        $work = WorkActivity::for(30);
        // report exported + 3 evaluations + 1 review; the guest and the 45-day-old entry are left out.
        $this->assertSame(['actions' => 5, 'people' => 2, 'products' => 1, 'organisations' => 1], $work->totals());

        $chart = collect($work->chart()['datasets'])->mapWithKeys(fn ($set) => [$set['key'] => array_sum($set['values'])]);
        $this->assertSame(array_keys(WorkActivity::AREAS), $chart->keys()->all());
        $this->assertSame(3, $chart['trees']);
        $this->assertSame(1, $chart['review']);
        $this->assertSame(1, $chart['documents']);
        $this->assertSame(0, $chart['einfo']);
        $this->assertCount(30, $work->chart()['labels']);

        $people = $work->people($this->staff());
        $this->assertSame(['Colleague Two', 'Customer One'], array_map(fn ($p) => $p['user']->name, $people));
        $this->assertSame(['trees' => 3], $people[0]['areas']);
        $this->assertSame(3, array_sum($people[0]['daily']));

        $products = $work->products();
        $this->assertSame('Smart Plug', $products[0]['product']->name);
        $this->assertSame(2, $products[0]['people']);
        $this->assertSame(['Colleague Two', 'Customer One'], array_map(fn ($w) => $w['user']->name, $products[0]['who']));

        $this->assertSame(6, WorkActivity::for(90)->totals()['actions']);
        $this->assertSame(30, WorkActivity::for(12)->days());
    }

    public function test_the_overview_shows_who_does_what_with_a_period_switch(): void
    {
        $data = $this->seedWork();
        $staff = $this->staff();

        $this->signIn($staff)->get('/admin')->assertOk()
            ->assertSee(__('admin.work.title', ['days' => 30]))
            ->assertSee('Colleague Two')
            ->assertSee('Acme Radio')
            ->assertSee('Smart Plug')
            ->assertSee(__('admin.work.areas.trees'))
            ->assertSee(route('admin.users.show', $data['colleague']), false)
            ->assertSee(route('admin.dashboard.period', 90), false)
            ->assertDontSee('Sandbox Guest')
            ->assertDontSee(__('admin.charts.views'));

        $html = (string) $this->get('/admin/overview/90')->assertOk()->assertSee(__('admin.work.title', ['days' => 90]))->getContent();
        $this->assertMatchesRegularExpression('#/admin/overview/90" class="a-seg-btn"\s+aria-current="page"#', $html);

        $this->get('/admin/overview/12')->assertNotFound();
    }

    public function test_an_empty_console_still_renders(): void
    {
        $this->signIn($this->staff())->get('/admin/overview/7')->assertOk()->assertSee(__('admin.no_data'));
    }
}
