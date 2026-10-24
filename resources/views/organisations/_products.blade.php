{{--
    The organisation's products with a role select each (Not on it, Contributor, Reviewer).
    $person: the person being managed (their current roles, Owner fixed), null when inviting.
    A product whose free team is full (App\Billing\Plan) cannot take someone new.
--}}
@php
    $currentRoles = $person
        ? $person['products']->reject(fn ($i) => $i['expired'])->mapWithKeys(fn ($i) => [$i['product']->id => $i['role']])
        : collect();
@endphp
<fieldset class="a-fieldset">
    <legend class="a-label">{{ $legend }}</legend>
    <div class="a-table-wrap">
        <table class="a-table a-table-compact a-orgteam-products">
            <thead>
                <tr>
                    <th scope="col">{{ __('orgteam.product') }}</th>
                    <th scope="col" class="is-shrink">{{ __('orgteam.product_role') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($products as $product)
                    @php
                        $role = $currentRoles[$product->id] ?? null;
                        $full = $role === null && ($person ? isset($refusedFor[$product->id]) : ($seats[$product->id]['full'] ?? false));
                        $chosen = $current[$product->id] ?? $role?->value ?? '';
                    @endphp
                    <tr @class(['is-muted' => $full])>
                        <th scope="row">
                            <label for="{{ $person ? 'p' : 'i' }}-{{ $product->id }}">{{ $product->name }}</label>
                            @if ($full)<span class="a-badge a-badge-neutral ml-1">{{ __('orgteam.team_full') }}</span>@endif
                        </th>
                        <td>
                            @if ($role === \App\Enums\ProductRole::Owner)
                                <span class="inline-flex items-center gap-2">
                                    <span class="a-badge" title="{{ $role->help() }}">{{ $role->label() }}</span>
                                    {{-- Hand-over happens on the product's Team page (App\Actions\TransferOwnership). --}}
                                    <a href="{{ route('team.transfer.show', $product) }}" class="a-btn a-btn-ghost a-btn-sm">{{ __('transfer.short') }}</a>
                                </span>
                            @else
                                <select id="{{ $person ? 'p' : 'i' }}-{{ $product->id }}" name="products[{{ $product->id }}]" class="a-select a-input-sm" @disabled($full)>
                                    <option value="">{{ __('orgteam.not_on') }}</option>
                                    @foreach ($roles as $option)
                                        <option value="{{ $option->value }}" @selected($chosen === $option->value)>{{ $option->label() }}</option>
                                    @endforeach
                                </select>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</fieldset>
