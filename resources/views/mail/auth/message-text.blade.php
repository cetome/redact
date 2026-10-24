{!! $greeting !!}

@foreach ($body['intro'] as $paragraph)
{!! $paragraph !!}

@endforeach
@isset($body['button'])
{!! $body['button']['label'] !!}:
{!! $body['button']['url'] !!}

@endisset
@isset($body['code'])
{!! $body['code_label'] !!} {!! $body['code'] !!}

@endisset
@foreach ($body['outro'] as $paragraph)
{!! $paragraph !!}

@endforeach
--
{!! __('auth.mail.footer') !!}
