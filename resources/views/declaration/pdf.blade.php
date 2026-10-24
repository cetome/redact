{{--
    The EU declaration of conformity for dompdf (App\Declaration\DeclarationPdf): never served to a
    browser, so the strict CSP of the app does not apply; styles are inline in <style>, as dompdf
    needs them. DejaVu Sans covers every language of the Directive.
--}}
<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 20mm 20mm 22mm 20mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; line-height: 1.4; color: #0f172a; }
        .wm { position: fixed; top: 105mm; left: -10mm; width: 190mm; text-align: center; font-size: 46pt; font-weight: bold;
              color: #c8ced6; opacity: 0.45; transform: rotate(-45deg); z-index: -1; }
        .notice { border: 1.5pt solid #b3261e; background: #fde7e5; color: #b3261e; padding: 6pt 9pt; margin-bottom: 12pt; }
        .notice b { font-size: 10.5pt; }
        .notice ul { margin: 3pt 0 0 0; padding-left: 12pt; font-size: 8pt; }
        h1 { font-size: 15pt; color: {{ $brand }}; margin: 0 0 12pt 0; }
        .point { margin: 0 0 8pt 0; page-break-inside: avoid; }
        .head { font-weight: bold; margin: 0 0 3pt 0; padding-left: 18pt; text-indent: -18pt; }
        .no { color: {{ $brand }}; padding-right: 7pt; }
        .line { margin: 0 0 1.5pt 18pt; }
        .bold { font-weight: bold; }
        .placeholder { color: #94a3b8; font-style: italic; }
        img.object { margin: 4pt 0 4pt 18pt; max-width: 60mm; max-height: 60mm; }
        table { margin: 2pt 0 0 18pt; width: 152mm; border-collapse: collapse; }
        th { background: {{ $brand }}; color: #fff; font-size: 8pt; text-align: left; padding: 3pt 5pt; }
        td { border: 0.5pt solid #e2e8f0; padding: 3pt 5pt; vertical-align: top; }
        .signature { margin-top: 14pt; page-break-inside: avoid; }
        .signature p { margin: 0 0 4pt 0; }
        .sign { margin: 40pt 0 0 55%; border-top: 0.75pt solid #475569; padding-top: 2pt; font-size: 7pt; color: #64748b; height: 10pt; }
    </style>
</head>
<body>
    @if ($watermark)
        <div class="wm">{{ $watermark }}</div>
    @endif
    @foreach ($blocks as $block)
        @if ($block['type'] === 'notice')
            <div class="notice">
                <b>{{ $block['title'] }}</b>
                <ul>
                    @foreach ($block['lines'] as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            </div>
        @elseif ($block['type'] === 'title')
            <h1>{{ $block['text'] }}</h1>
        @elseif ($block['type'] === 'point')
            <div class="point">
                <p class="head"><span class="no">{{ $block['number'] }}</span>{{ $block['heading'] }}</p>
                @foreach ($block['lines'] as $line)
                    <p @class(['line', 'bold' => $line['bold'], 'placeholder' => $line['placeholder']])>{{ $line['text'] }}</p>
                @endforeach
                @if ($block['image'])
                    <img class="object" src="{{ $image($block['image']) }}" alt="">
                @endif
                @if ($block['table'])
                    <table>
                        @if ($block['header'])
                            <tr><th style="width: 65%">{{ $block['header'][0] }}</th><th>{{ $block['header'][1] }}</th></tr>
                        @endif
                        @foreach ($block['table'] as $row)
                            <tr><td style="width: 65%">{{ $row[0] }}</td><td>{{ $row[1] }}</td></tr>
                        @endforeach
                    </table>
                @endif
            </div>
        @elseif ($block['type'] === 'signature')
            <div class="signature">
                @foreach ($block['rows'] as $row)
                    <p><span class="bold">{{ $row[0] }}</span> {{ $row[1] }}</p>
                @endforeach
                <div class="sign">{{ $block['caption'] ?? '' }}</div>
            </div>
        @endif
    @endforeach
</body>
</html>
