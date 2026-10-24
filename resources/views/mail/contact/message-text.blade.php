@if ($greeting)
{!! $greeting !!}

@endif
@foreach ($paragraphs as $paragraph)
{!! $paragraph !!}

@endforeach
@foreach ($fields as [$label, $value, $href])
{!! $label !!}: {!! $value !!}
@endforeach
@if ($body !== null)

{!! $bodyLabel !!}:
{!! $body !!}

@endif
@if ($button)
{!! $button['label'] !!}:
{!! $button['url'] !!}

@endif
--
{!! $footer !!}
