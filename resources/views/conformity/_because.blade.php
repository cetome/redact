{{-- Why a conformity answer is suggested (Conformity::suggestions): one line per source, linked to it.
     $because: list<array{text: string, href: string|null}>; $open: nothing derived yet (what is missing). --}}
<ul @class(['a-conf-why', 'is-open' => $open ?? false])>
    @foreach ($because as $reason)
        <li>
            @if ($open ?? false)
                <x-lucide-circle-dashed aria-hidden="true" />
            @else
                <x-lucide-corner-down-right aria-hidden="true" />
            @endif
            @if ($reason['href'])
                <a href="{{ $reason['href'] }}">{{ $reason['text'] }}</a>
            @else
                <span>{{ $reason['text'] }}</span>
            @endif
        </li>
    @endforeach
</ul>
