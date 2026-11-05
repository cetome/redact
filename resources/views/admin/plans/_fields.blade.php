{{--
    What a plan includes (console, Plans and prices): the fields of one plan's form, or of a new
    plan ($plan null). Values typed before a validation error come back only in their own form (_plan).
--}}
@php
    $formKey = $plan?->key ?? '_new';
    $mine = old('_plan') === $formKey;
    $value = fn (string $field, $default) => $mine ? old($field, $default) : $default;
    $checked = fn (string $field, bool $default) => $mine ? (bool) old($field) : $default;
    $locales = (array) config('redact.locales', ['en' => 'English']);
@endphp
<input type="hidden" name="_plan" value="{{ $formKey }}">
<div class="grid gap-3 sm:grid-cols-2">
    @foreach ($locales as $locale => $language)
        <div class="a-field">
            <label for="{{ $prefix }}-name-{{ $locale }}" class="a-label">{{ __('plan_catalogue.fields.name', ['language' => $language]) }}</label>
            <input id="{{ $prefix }}-name-{{ $locale }}" name="names[{{ $locale }}]" type="text" class="a-input a-input-sm" maxlength="60"
                   value="{{ $value('names.'.$locale, $plan?->names[$locale] ?? '') }}" placeholder="{{ $plan?->builtIn ? $plan->name($locale) : '' }}"
                   @if ($plan?->builtIn) title="{{ __('plan_catalogue.fields.name_help') }}" @endif>
        </div>
    @endforeach
</div>
<div class="a-plan-form">
    <div class="a-field">
        <label for="{{ $prefix }}-products" class="a-label">{{ __('plan_catalogue.fields.products') }}</label>
        <input id="{{ $prefix }}-products" name="products" type="number" min="1" class="a-input a-input-sm" value="{{ $value('products', $plan?->products ?? ($plan ? '' : 2)) }}"
               title="{{ __('plan_catalogue.fields.products_help') }}" placeholder="∞">
    </div>
    <div class="a-field">
        <label for="{{ $prefix }}-team" class="a-label">{{ __('plan_catalogue.fields.team_members') }}</label>
        <input id="{{ $prefix }}-team" name="team_members" type="number" min="0" class="a-input a-input-sm" value="{{ $value('team_members', $plan?->teamMembers ?? '') }}"
               title="{{ __('plan_catalogue.fields.team_help') }}" placeholder="∞">
    </div>
    <div class="a-field">
        <label for="{{ $prefix }}-ai" class="a-label">{{ __('plan_catalogue.fields.ai_units') }}</label>
        <input id="{{ $prefix }}-ai" name="ai_units" type="number" min="0" required class="a-input a-input-sm" value="{{ $value('ai_units', $plan?->aiUnits ?? 500) }}">
    </div>
    <div class="a-field">
        <label for="{{ $prefix }}-period" class="a-label">{{ __('plan_catalogue.fields.ai_period') }}</label>
        <select id="{{ $prefix }}-period" name="ai_period" class="a-select a-input-sm">
            @foreach (\App\Billing\Plan::PERIODS as $period)
                <option value="{{ $period }}" @selected($value('ai_period', $plan?->aiPeriod ?? 'month') === $period)>{{ __('plans.periods.'.$period) }}</option>
            @endforeach
        </select>
    </div>
    <div class="a-field">
        <label for="{{ $prefix }}-extra-ai" class="a-label">{{ __('plan_catalogue.fields.extra_ai') }}</label>
        <input id="{{ $prefix }}-extra-ai" name="extra_product_ai_units" type="number" min="0" class="a-input a-input-sm"
               value="{{ $value('extra_product_ai_units', $plan?->extraProductAiUnits ?? '') }}" title="{{ __('plan_catalogue.fields.extra_ai_help') }}">
    </div>
</div>
<div class="flex flex-wrap gap-x-5 gap-y-1">
    @foreach (['support' => $plan?->support ?? true, 'public' => $plan?->public ?? true, 'active' => $plan?->active ?? true] as $field => $default)
        <label class="a-check">
            <input type="hidden" name="{{ $field }}" value="0">
            <input type="checkbox" name="{{ $field }}" value="1" @checked($checked($field, $default))>
            <span>{{ __('plan_catalogue.fields.'.$field) }}</span>
        </label>
    @endforeach
</div>
<p class="a-help">{{ __('plan_catalogue.fields.public_help') }}</p>
