{{--
    AI tools under one evaluation (included by the evaluation page):
    @includeIf('ai._evaluation-tools', ['version' => $version, 'evaluation' => $evaluation])
    "Check this evaluation" and "Suggest a path", both advisory: nothing is changed, a person decides.
--}}
@can('edit', $version)
<div class="mt-3 grid gap-3 border-t border-line pt-3 md:grid-cols-2">
    <x-ai.check :version="$version" :evaluation="$evaluation" />
    <x-ai.path :version="$version" :evaluation="$evaluation" />
</div>
@endcan
