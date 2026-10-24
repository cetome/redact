{{--
    Overview, under the work: the product card (picture, description, models, product version,
    documents of this version), the versions of the product (this one marked, "New version" in a
    modal) and the team (link-free: the Team step is in the sidebar).
    @include('versions._product', ['version' => $version, 'access' => $access])
--}}
@php
    $product = $version->product;
    $newVersionOpen = $errors->has('label') || request()->routeIs('versions.show.new-version');
@endphp
<div class="a-stack a-overview-product">
    <x-product.card :product="$product" :version="$version" :heading="false" />

    <div class="a-grid-main">
        <section class="a-panel self-start" aria-labelledby="versions-title">
            <div class="a-panel-head">
                <h2 class="a-h3" id="versions-title">{{ __('products.versions') }} <span class="a-toolbar-count">{{ $product->versions->count() }}</span></h2>
                @if ($access->canManage())
                    <a href="{{ route('versions.show.new-version', $version) }}" class="a-btn a-btn-outline a-btn-sm" data-dialog-open="new-version"><x-lucide-plus aria-hidden="true" />{{ __('versions.new') }}</a>
                @endif
            </div>
            <table class="a-table a-table-compact">
                <thead>
                    <tr>
                        <th scope="col">{{ __('versions.version') }}</th>
                        <th scope="col">{{ __('workflow.status') }}</th>
                        <th scope="col" class="w-[28%] max-sm:hidden">{{ __('products.progress') }}</th>
                        <th scope="col" class="max-md:hidden">{{ __('versions.previous') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($product->versions as $other)
                        @php $otherSummary = $loop->index < 5 ? \App\Support\WorkflowSummary::for($other) : null; @endphp
                        <tr @class(['is-current' => $other->is($version)])>
                            <th scope="row">
                                <a href="{{ route('versions.show', $other) }}" class="a-row-link" data-row-link @if ($other->is($version)) aria-current="page" @endif>{{ $other->label }}</a>
                                @if ($other->is($version))
                                    <span class="a-badge a-badge-outline ml-1">{{ __('products.this_version') }}</span>
                                @endif
                                <span class="a-row-sub">{{ __('versions.created_on', ['date' => $other->created_at->isoFormat('D MMMM YYYY')]) }}</span>
                            </th>
                            <td><x-version-status :status="$other->status" /></td>
                            <td class="max-sm:hidden">@if ($otherSummary)<x-ui.progress :value="$otherSummary->doneSteps()" :max="$otherSummary->totalSteps()" line />@endif</td>
                            <td class="a-muted max-md:hidden">{{ $other->previousVersion?->label ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <section class="a-panel self-start" aria-labelledby="team-title">
            <div class="a-panel-head"><h2 class="a-h3" id="team-title">{{ __('products.team') }} <span class="a-toolbar-count">{{ $product->members->count() + 1 }}</span></h2></div>
            <ul class="a-pcard-team">
                <li><x-lucide-user aria-hidden="true" /><span class="min-w-0 flex-1 truncate">{{ $product->owner->name }}</span><span class="a-badge">{{ __('roles.owner.label') }}</span></li>
                @foreach ($product->members as $member)
                    <li><x-lucide-user aria-hidden="true" /><span class="min-w-0 flex-1 truncate">{{ $member->user->name }}</span><span class="a-badge a-badge-neutral">{{ $member->role->label() }}@if ($member->external) · {{ __('roles.external') }}@endif</span></li>
                @endforeach
            </ul>
        </section>
    </div>
</div>

@if ($access->canManage())
    <x-ui.drawer id="new-version" :modal="true" :open="$newVersionOpen" :close-url="route('versions.show', $version)" :title="__('workflow.new_version_title')">
        @include('versions._new-version', ['product' => $product, 'formId' => 'new-version-form'])
        <x-slot:footer>
            <button type="button" class="a-btn a-btn-ghost" data-dialog-close>{{ __('common.cancel') }}</button>
            <span class="a-spacer"></span>
            <button class="a-btn a-btn-primary" type="submit" form="new-version-form"><x-lucide-plus aria-hidden="true" />{{ __('versions.new') }}</button>
        </x-slot:footer>
    </x-ui.drawer>
@endif
