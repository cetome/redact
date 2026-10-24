{{-- Stats row of the "Assessment units" step (counts from App\Assessment\AssessmentCases::overview()). --}}
<div class="a-stats">
    <x-ui.stat :label="__('assessment.stat_recorded')" :value="$counts['recorded']" :total="$counts['total']" bar />
    <x-ui.stat :label="__('assessment.stat_fail')" :value="$counts['fail']" :tone="$counts['fail'] ? 'bad' : null" />
    <x-ui.stat :label="__('verdicts.INCONCLUSIVE')" :value="$counts['inconclusive']" :tone="$counts['inconclusive'] ? 'bad' : null" />
    <x-ui.stat :label="__('assessment.stat_stale')" :value="$counts['stale']" :tone="$counts['stale'] ? 'bad' : null" />
</div>
