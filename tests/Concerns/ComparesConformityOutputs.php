<?php

namespace Tests\Concerns;

use App\Assessment\Assessment;
use App\Models\ProductVersion;
use App\Models\User;
use App\Reports\ReportData;

/**
 * The Conformity page and the reports (ReportData, printed by the Word and Excel reports) read the
 * same Conformity result: the restriction states, card by card, and the self-assessment outcome.
 */
trait ComparesConformityOutputs
{
    /**
     * Asserts the page and the report agree, and returns the states (Met, Not met, To answer, Not applicable).
     *
     * @return list<string>
     */
    protected function assertReportAgreesWithPage(User $user, ProductVersion $version): array
    {
        $html = (string) $this->signIn($user)->get(route('versions.conformity', $version))->assertOk()->getContent();
        preg_match_all('/<h3 class="a-check-title">.*?<\/h3>\s*<span class="a-badge[^"]*">(.*?)<\/span>/s', $html, $m);
        $page = array_map(fn (string $badge) => trim(html_entity_decode(strip_tags($badge))), $m[1]);

        $report = ReportData::for($version->fresh(), $user)->scoping;
        $printed = array_map(fn (array $r) => __('reports.restriction_'.$r['state']), $report['restrictions']);
        $this->assertSame($page, $printed, 'the report prints the states the page shows, in the same order');

        $possible = Assessment::for($version->fresh())->conformity()->selfAssessmentPossible();
        $this->assertSame(match ($possible) {
            true => __('reports.self_assessment.yes'),
            false => __('reports.self_assessment.no'),
            null => __('reports.self_assessment.unknown'),
        }, $report['self_assessment']);
        $this->assertStringContainsString(e(match ($possible) {
            true => __('conformity.self_possible'),
            false => __('conformity.nb_mandatory'),
            null => __('conformity.pending'),
        }), $html);

        return $page;
    }
}
