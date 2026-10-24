{{--
    History of a product (every version) or of an organisation (HistoryController, App\History\Feed):
    one dense table, a group row per day, newest first, cursor pages (Older: /history/before/{id},
    Newest). Filters are POSTed and kept in the session (no query string). Each line:
    time, who (initials, name, e-mail for owners and admins; "cetome support" for staff), what
    (area icon + sentence, "+N more" for folded bulks, "N changes" with a word diff for texts) and
    the version (product) or product (organisation, all products). Block Y of app-ui.css.
--}}
@php
    $isProduct = $product !== null;
    $entries = $page['entries'];
    $tz = $feed->describer->timezone();
    $today = now($tz)->toDateString();
    $yesterday = now($tz)->subDay()->toDateString();
    $days = max(1, (int) config('redact.retention.activity_log_days', 730));
    $retention = $days % 365 === 0
        ? trans_choice('history.retention_years', intdiv($days, 365), ['count' => intdiv($days, 365)])
        : trans_choice('history.retention', $days, ['count' => $days]);
    $filtered = collect($filters)->except(['before', 'products'])->filter(fn ($v) => filled($v))->isNotEmpty();
    $showProduct = ! $isProduct && filled($filters['products']);
    $kinds = $isProduct || filled($filters['products']) ? $feed->kinds() : \App\History\Describer::ORGANISATION_KINDS;
    $currentDay = null;
    $columns = $isProduct || $showProduct ? 4 : 3;
@endphp
<x-layouts.app :title="__('history.title').' · '.($product?->name ?? $organisation?->name)" :product="$product">
    @if ($access?->isSupportAccess())
        <div class="a-alert a-alert-warning"><x-lucide-eye aria-hidden="true" /><span>{{ __('common.support_access') }}</span></div>
    @endif

    <x-ui.page-header :title="__('history.title')">
        <x-slot:eyebrow>
            @if ($isProduct)<a href="{{ $product->homeUrl() }}">{{ $product->name }}</a>@else{{ $organisation->name }}@endif
        </x-slot:eyebrow>
        @if ($canExport)
            <x-slot:actions>
                <a href="{{ $csvUrl }}" class="a-btn a-btn-outline" title="{{ __('history.export_tip') }}"><x-lucide-download aria-hidden="true" />{{ __('history.export') }}</a>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @unless ($isProduct)
        @include('organisations._tabs', ['organisation' => $organisation, 'active' => 'history'])
    @endunless

    <form method="POST" action="{{ $filterUrl }}" class="a-panel a-hist-filters" role="search" aria-label="{{ __('history.filters') }}">
        @csrf
        @if ($isProduct)
            <div class="a-field">
                <label for="h-version" class="a-label">{{ __('history.version') }}</label>
                <select id="h-version" name="version" class="a-select a-input-sm">
                    <option value="">{{ __('history.all_versions') }}</option>
                    @foreach ($versions as $id => $label)
                        <option value="{{ $id }}" @selected($filters['version'] === $id)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        @else
            <div class="a-field">
                <label for="h-products" class="a-label">{{ __('history.products') }}</label>
                <select id="h-products" name="products" class="a-select a-input-sm">
                    <option value="">{{ __('history.organisation_only') }}</option>
                    <option value="all" @selected($filters['products'] === 'all')>{{ __('history.all_products') }}</option>
                    @foreach ($products as $id => $name)
                        <option value="{{ $id }}" @selected($filters['products'] === $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div class="a-field">
            <label for="h-person" class="a-label">{{ __('history.person') }}</label>
            <select id="h-person" name="person" class="a-select a-input-sm">
                <option value="">{{ __('history.everyone') }}</option>
                @foreach ($people as $value => $name)
                    <option value="{{ $value }}" @selected((string) $filters['person'] === (string) $value)>{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <div class="a-field">
            <label for="h-kind" class="a-label">{{ __('history.kind') }}</label>
            <select id="h-kind" name="kind" class="a-select a-input-sm">
                <option value="">{{ __('history.all_kinds') }}</option>
                @foreach ($kinds as $kind)
                    <option value="{{ $kind }}" @selected($filters['kind'] === $kind)>{{ __('history.kinds.'.$kind) }}</option>
                @endforeach
            </select>
        </div>
        <div class="a-field">
            <label for="h-from" class="a-label">{{ __('history.from') }}</label>
            <input id="h-from" name="from" type="date" class="a-input a-input-sm" value="{{ $filters['from'] }}">
        </div>
        <div class="a-field">
            <label for="h-to" class="a-label">{{ __('history.to') }}</label>
            <input id="h-to" name="to" type="date" class="a-input a-input-sm" value="{{ $filters['to'] }}">
        </div>
        <div class="a-field a-hist-search">
            <label for="h-q" class="a-label">{{ __('history.search') }}</label>
            <input id="h-q" name="q" type="search" class="a-input a-input-sm" value="{{ $filters['q'] }}" maxlength="120">
        </div>
        <div class="a-hist-filter-actions">
            <button type="submit" class="a-btn a-btn-primary a-btn-sm"><x-lucide-filter aria-hidden="true" />{{ __('history.filter') }}</button>
            @if ($filtered || filled($filters['products']))
                <button type="submit" name="reset" value="1" class="a-btn a-btn-ghost a-btn-sm a-btn-icon" title="{{ __('history.reset') }}" aria-label="{{ __('history.reset') }}"><x-lucide-rotate-ccw aria-hidden="true" /></button>
            @endif
        </div>
    </form>

    @if ($entries === [])
        <x-ui.empty icon="history" :title="$filtered ? __('history.none_filtered') : __('history.none')" :text="$retention" />
    @else
        <div class="a-table-wrap">
            <table class="a-table a-table-compact a-hist">
                <caption class="a-sr-only">{{ __('history.title') }}</caption>
                <thead>
                    <tr>
                        <th scope="col" class="is-shrink">{{ __('history.when') }}</th>
                        <th scope="col">{{ __('history.who') }}</th>
                        <th scope="col">{{ __('history.what') }}</th>
                        @if ($isProduct)
                            <th scope="col" class="is-shrink">{{ __('history.where') }}</th>
                        @elseif ($showProduct)
                            <th scope="col">{{ __('history.product') }}</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($entries as $entry)
                        @php
                            $day = $entry->at->toDateString();
                            $newDay = $day !== $currentDay;
                            $currentDay = $day;
                        @endphp
                        @if ($newDay)
                            <tr class="a-table-group a-hist-day">
                                <th scope="colgroup" colspan="{{ $columns }}">
                                    @if ($day === $today){{ __('history.today') }} · @elseif ($day === $yesterday){{ __('history.yesterday') }} · @endif{{ $entry->at->locale(app()->getLocale())->isoFormat('D MMMM YYYY') }}
                                </th>
                            </tr>
                        @endif
                        <tr @class(['a-hist-row', 'is-support' => $entry->actor['kind'] === 'support'])>
                            <td class="a-hist-time">
                                <time datetime="{{ $entry->at->toIso8601String() }}" title="{{ $entry->at->locale(app()->getLocale())->isoFormat('D MMMM YYYY, HH:mm:ss') }}">{{ $entry->at->format('H:i') }}</time>
                            </td>
                            <td>
                                <div class="a-hist-who">
                                    <span @class(['a-avatar a-hist-avatar', 'is-'.$entry->actor['kind']]) aria-hidden="true">
                                        @if ($entry->actor['kind'] === 'support')
                                            <x-lucide-life-buoy />
                                        @elseif ($entry->actor['kind'] === 'system')
                                            <x-lucide-cog />
                                        @else
                                            {{ $entry->actor['initials'] }}
                                        @endif
                                    </span>
                                    {{-- The e-mail (owners and admins only) as the name's tooltip, not repeated on every line. --}}
                                    <span class="a-hist-name" @if ($entry->actor['email']) title="{{ $entry->actor['email'] }}" @endif>{{ $entry->actor['name'] }}@if ($entry->actor['email'])<span class="a-sr-only"> ({{ $entry->actor['email'] }})</span>@endif</span>
                                </div>
                            </td>
                            <td class="a-hist-what">
                                <div class="a-hist-line">
                                    <span class="a-hist-kind" title="{{ __('history.kinds.'.$entry->kind) }}">
                                        <x-dynamic-component :component="'lucide-'.$entry->icon()" aria-hidden="true" />
                                        <span class="a-sr-only">{{ __('history.kinds.'.$entry->kind) }} · </span>
                                    </span>
                                    <span class="a-hist-text">{!! $entry->html !!}</span>
                                </div>
                                @if ($entry->others !== [] || $entry->details !== [])
                                    <div class="a-hist-folds">
                                    @if ($entry->others !== [])
                                        <details class="a-hist-more">
                                            <summary>{{ __('history.more', ['count' => count($entry->others)]) }}</summary>
                                            <ul class="a-hist-others">
                                                @foreach (array_slice($entry->others, 0, 100) as $other)
                                                    <li>{{ $other }}</li>
                                                @endforeach
                                                @if (count($entry->others) > 100)
                                                    <li class="a-muted">{{ __('history.more', ['count' => count($entry->others) - 100]) }}</li>
                                                @endif
                                            </ul>
                                        </details>
                                    @endif
                                    @if ($entry->details !== [])
                                        <details class="a-hist-more">
                                            <summary>{{ trans_choice('history.changes', count($entry->details), ['count' => count($entry->details)]) }}</summary>
                                            <dl class="a-hist-changes">
                                                @foreach ($entry->details as $detail)
                                                    <dt>{{ $detail['field'] }}</dt>
                                                    <dd>
                                                        @if ($detail['diff'] !== null)
                                                            <x-ui.word-diff :segments="$detail['diff']" />
                                                        @elseif ($detail['old'] !== null)
                                                            <del class="a-hist-old">{{ $detail['old'] }}</del>
                                                            <span aria-hidden="true">→</span><span class="a-sr-only">{{ __('history.changed_to') }}</span>
                                                            <ins class="a-hist-new">{{ $detail['new'] }}</ins>
                                                        @else
                                                            <p class="a-hist-written">{{ $detail['new'] }}</p>
                                                        @endif
                                                    </dd>
                                                @endforeach
                                            </dl>
                                        </details>
                                    @endif
                                    </div>
                                @endif
                            </td>
                            @if ($isProduct)
                                <td class="a-hist-where">
                                    @if ($entry->versionLabel)<span class="a-badge a-badge-neutral">{{ $entry->versionLabel }}</span>@endif
                                </td>
                            @elseif ($showProduct)
                                <td class="a-hist-where">{{ $entry->productName }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="a-hist-foot">
        @if ($entries !== [])<p class="a-help">{{ $retention }}</p>@endif
        @if (filled($filters['before']) || $page['next'])
            <nav class="a-hist-pages" aria-label="{{ __('history.pages') }}">
                @if (filled($filters['before']))
                    <a href="{{ $here }}" class="a-btn a-btn-ghost a-btn-sm"><x-lucide-chevrons-left aria-hidden="true" />{{ __('history.newest') }}</a>
                @endif
                @if ($page['next'])
                    <a href="{{ $olderUrl($page['next']) }}" class="a-btn a-btn-outline a-btn-sm" rel="next">{{ __('history.older') }}<x-lucide-chevron-right aria-hidden="true" /></a>
                @endif
            </nav>
        @endif
    </div>
</x-layouts.app>
