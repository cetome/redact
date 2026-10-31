<x-layouts.app :title="__('products.title')">
    @php $first = $products->isEmpty() && $canCreate; @endphp
    {{-- Usability by default: with no product yet, the page is the scoping of the first one. --}}
    @if ($first)
        <x-ui.page-header :title="__('scoping.heading')" :subtitle="__('scoping.lead')">
            @if ($demo)
                <x-slot:actions>@include('dashboard._demo')</x-slot:actions>
            @endif
        </x-ui.page-header>
    @else
        <x-ui.page-header :title="__('products.title')" :subtitle="__('products.intro')">
            @if ($canCreate && $atPlanLimit)
                {{-- No product left on the plan: the button becomes the way to add one (App\Billing\Plan). --}}
                <x-slot:actions>
                    @if ($portfolio)
                        <a href="{{ route('organisations.portfolio', $portfolio) }}" class="a-btn a-btn-ghost"><x-lucide-layout-dashboard aria-hidden="true" />{{ __('portfolio.link') }}</a>
                    @endif
                    @if ($demo)
                        @include('dashboard._demo')
                    @endif
                    <div class="a-plan-upgrade">
                        <span class="a-help">{{ $plans->first()->productRefusal() }}</span>
                        <a href="{{ route('products.create') }}" class="a-btn a-btn-outline"><x-lucide-circle-arrow-up aria-hidden="true" />{{ __('plans.upgrade') }}</a>
                    </div>
                </x-slot:actions>
            @elseif ($canCreate || $demo || $portfolio)
                <x-slot:actions>
                    @if ($portfolio)
                        <a href="{{ route('organisations.portfolio', $portfolio) }}" class="a-btn a-btn-ghost"><x-lucide-layout-dashboard aria-hidden="true" />{{ __('portfolio.link') }}</a>
                    @endif
                    @if ($demo)
                        @include('dashboard._demo')
                    @endif
                    @if ($canCreate)
                        <a href="{{ route('products.create') }}" class="a-btn a-btn-primary"><x-lucide-plus aria-hidden="true" />{{ __('products.new') }}</a>
                    @endif
                </x-slot:actions>
            @endif
        </x-ui.page-header>
    @endif

    @if ($invitations->isNotEmpty())
        <div class="a-alert" role="status">
            <x-lucide-mail aria-hidden="true" />
            <div>
                <p class="font-bold">{{ __('products.invitations') }}</p>
                <ul>
                    @foreach ($invitations as $invitation)
                        @if ($invitation->product || $invitation->organisation)
                            <li>{{ __('products.invitation_from', [
                                'product' => $invitation->product->name ?? $invitation->organisation->name,
                                'role' => $invitation->role?->label() ?? __('orgteam.role.'.($invitation->organisation_role === 'admin' ? 'admin' : 'member')),
                            ]) }}</li>
                        @endif
                    @endforeach
                </ul>
                <p class="a-help mt-1">{{ __('team.invite_help') }}</p>
            </div>
        </div>
    @endif

    @if ($products->isEmpty())
        @if ($first)
            @include('products._scope-form', ['organisations' => $organisations])
        @else
            <x-ui.empty icon="package" :title="__('products.title')" :text="__('products.empty')" />
        @endif
    @else
        {{-- One dense row per product: picture, latest version, progress, the next thing to do. --}}
        <div class="a-toolbar" data-filter-scope>
            <x-ui.search target="#product-rows" :placeholder="__('products.search')" />
            <span class="a-toolbar-count" data-filter-count data-template="{{ __('ui.shown') }}"></span>
            <span class="a-toolbar-end a-toolbar-count">{{ trans_choice('products.count', $products->count(), ['count' => $products->count()]) }}</span>
        </div>
        <div class="a-table-wrap">
            <table class="a-table">
                <thead>
                    <tr>
                        <th scope="col">{{ __('products.product') }}</th>
                        <th scope="col">{{ __('versions.version') }}</th>
                        <th scope="col" class="w-[18%] max-md:hidden">{{ __('products.progress') }}</th>
                        <th scope="col" class="w-[16%] max-lg:hidden">{{ __('workflow.steps.evaluate') }}</th>
                        <th scope="col" class="max-sm:hidden">{{ __('products.next') }}</th>
                    </tr>
                </thead>
                <tbody id="product-rows">
                    @foreach ($products as $product)
                        @php
                            $latest = $product->versions->first();
                            $summary = $latest ? \App\Support\WorkflowSummary::for($latest) : null;
                            $trees = $summary ? collect($summary->groups)->firstWhere('key', 'evaluate') : null;
                            $next = $summary?->next();
                        @endphp
                        <tr data-filter-item data-search="{{ $product->name }} {{ $product->reference }} {{ $product->organisation->name }}">
                            <th scope="row">
                                <span class="flex items-center gap-3">
                                    <x-ui.thumb :product="$product" />
                                    <span class="min-w-0">
                                        <a href="{{ $latest ? route('versions.show', $latest) : route('products.show', $product) }}" class="a-row-link" data-row-link>{{ $product->name }}</a>
                                        @if ($product->is_demo)
                                            <span class="a-badge a-badge-neutral ml-1">{{ __('plans.slots.demo') }}</span>
                                        @endif
                                        <span class="a-row-sub">{{ collect([$product->reference, $product->organisation->name, $product->roleOf(auth()->user())?->label() ?? __('roles.internal')])->filter()->implode(' · ') }}</span>
                                    </span>
                                </span>
                            </th>
                            <td class="is-nowrap">
                                @if ($latest)
                                    <a href="{{ route('versions.show', $latest) }}" class="a-row-link font-semibold">{{ $latest->label }}</a>
                                    <x-version-status :status="$latest->status" class="ml-1" />
                                @else
                                    <span class="a-muted">—</span>
                                @endif
                            </td>
                            <td class="max-md:hidden">
                                @if ($summary)
                                    <x-ui.progress :value="$summary->doneSteps()" :max="$summary->totalSteps()" line :label="__('ui.steps_done', ['done' => $summary->doneSteps(), 'total' => $summary->totalSteps()])" />
                                @endif
                            </td>
                            <td class="max-lg:hidden">
                                @if ($trees && $trees['total'])
                                    <x-ui.progress :value="$trees['doneCount']" :max="$trees['total']" line />
                                @endif
                            </td>
                            <td class="max-sm:hidden">
                                @if ($next && $latest->isEditable() && ! $product->is_demo)
                                    <a href="{{ $next['href'] }}" class="a-btn a-btn-outline a-btn-sm max-w-full">
                                        <span class="truncate">{{ $next['label'] }}@if ($next['left']) · {{ __('ui.left', ['count' => $next['left']]) }}@endif</span>
                                        <x-lucide-arrow-right aria-hidden="true" />
                                    </a>
                                @elseif ($latest)
                                    <a href="{{ route('versions.show', $latest) }}" class="a-btn a-btn-ghost a-btn-sm">{{ __('ui.open') }}<x-lucide-arrow-right aria-hidden="true" /></a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="a-help px-4 py-3" data-filter-empty hidden>{{ __('ui.nothing_matches') }}</p>
        </div>
    @endif

    @if ($plans->isNotEmpty())
        <footer class="a-plan-foot a-plan-orgs" aria-label="{{ __('plans.line.label') }}">
            @foreach ($plans as $plan)
                @include('plans._line', ['plan' => $plan, 'showOrganisation' => $plans->count() > 1])
            @endforeach
        </footer>
    @endif
</x-layouts.app>
