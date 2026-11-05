{{--
    Account e-mail, HTML part, as FAST's (app/mail.php mail_html): the blue band with REDact by cetome,
    the subject as title, the button with its address underneath to copy, the code in a box. Inline
    styles are allowed in e-mails only (no CSP in mail clients).
--}}
@php
    $font = "font-family:Inter,-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $subjectLine }}</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;{{ $font }};">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="#f1f5f9">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">
                    <tr>
                        <td bgcolor="#0044aa" style="padding:22px 36px;border-radius:14px 14px 0 0;">
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="{{ $font }};font-size:26px;font-weight:900;letter-spacing:1px;color:#ffffff;"><a href="{{ route('home') }}" style="color:#ffffff;text-decoration:none;">REDact</a></td>
                                    <td style="padding:4px 0 0 14px;{{ $font }};font-size:12px;font-weight:700;letter-spacing:3px;text-transform:uppercase;color:#bfdbfe;">{{ __('common.byline') }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td bgcolor="#ffffff" style="padding:36px;border:1px solid #e2e8f0;border-top:0;border-radius:0 0 14px 14px;">
                            <h1 style="margin:0 0 20px;{{ $font }};font-size:22px;line-height:1.3;font-weight:800;color:#0f172a;">{{ $subjectLine }}</h1>
                            <p style="margin:0 0 16px;{{ $font }};font-size:15px;line-height:1.65;color:#0f172a;">{{ $greeting }}</p>
                            @foreach ($body['intro'] as $paragraph)
                                <p style="margin:0 0 16px;{{ $font }};font-size:15px;line-height:1.65;color:#0f172a;">{{ $paragraph }}</p>
                            @endforeach

                            @isset($body['button'])
                                <table role="presentation" align="center" cellpadding="0" cellspacing="0" style="margin:24px auto 10px;">
                                    <tr>
                                        <td bgcolor="#0044aa" style="padding:15px 32px;border-radius:10px;">
                                            <a href="{{ $body['button']['url'] }}" style="display:inline-block;{{ $font }};color:#ffffff;font-weight:700;font-size:16px;text-decoration:none;">{{ $body['button']['label'] }}</a>
                                        </td>
                                    </tr>
                                </table>
                                {{-- The address itself, to copy when the button does not open (owner 2026-11-04). --}}
                                <p style="margin:0 0 24px;{{ $font }};font-size:11px;line-height:1.5;color:#94a3b8;text-align:center;word-break:break-all;">
                                    {{ __('auth.mail.button_fallback') }}<br>
                                    <a href="{{ $body['button']['url'] }}" style="color:#64748b;">{{ $body['button']['url'] }}</a>
                                </p>
                            @endisset

                            @isset($body['code'])
                                <p style="margin:20px 0 10px;text-align:center;{{ $font }};font-size:14px;line-height:1.5;color:#475569;">{{ $body['code_label'] }}</p>
                                <table role="presentation" align="center" cellpadding="0" cellspacing="0" style="margin:0 auto 24px;">
                                    <tr>
                                        <td bgcolor="#eff6ff" style="padding:16px 32px;border:1px solid #bfdbfe;border-radius:12px;{{ $font }};font-size:34px;font-weight:800;letter-spacing:10px;color:#0044aa;">{{ $body['code'] }}</td>
                                    </tr>
                                </table>
                            @endisset

                            @foreach ($body['outro'] as $paragraph)
                                <p style="margin:0 0 12px;{{ $font }};font-size:14px;line-height:1.6;color:#475569;">{{ $paragraph }}</p>
                            @endforeach

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:28px 0 0;">
                                <tr>
                                    <td style="border-top:1px solid #e2e8f0;padding:16px 0 0;{{ $font }};font-size:13px;color:#64748b;">
                                        <a href="{{ route('home') }}" style="color:#64748b;text-decoration:none;">{{ parse_url(route('home'), PHP_URL_HOST) }}</a> &middot; cetome
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 8px 0;{{ $font }};font-size:12px;line-height:1.6;color:#94a3b8;">{{ __('auth.mail.footer') }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
