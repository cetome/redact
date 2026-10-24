{{-- Flash messages and validation summary (<x-ui.alert>). warning_link / error_link: ['href' => …, 'label' => …], the action on the right;
     warning_post: a second action that is a POST (['action', 'label', 'fields']).
     :summary="false": the page shows its errors next to the fields (no list on top). --}}
@props(['summary' => true])
@if (session('status'))
    <x-ui.alert tone="success" dismissible flash>{{ session('status') }}</x-ui.alert>
@endif
@if (session('next'))
    {{-- A product just created: what was created and what to do now (CreateProduct::createdMessage). --}}
    <x-ui.alert icon="flag" dismissible flash>{{ session('next') }}</x-ui.alert>
@endif
@if (session('warning'))
    <x-ui.alert tone="warning" icon="circle-help" :action="session('warning_link')" :post="session('warning_post')" flash>{{ session('warning') }}</x-ui.alert>
@endif
@if (session('error'))
    <x-ui.alert tone="danger" :action="session('error_link')" flash>{{ session('error') }}</x-ui.alert>
@endif
@if ($summary && $errors->any())
    <x-ui.alert tone="danger" icon="triangle-alert" :title="__('common.fix_errors')" flash>
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </x-ui.alert>
@endif
