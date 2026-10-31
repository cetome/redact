{{-- Account e-mail, HTML part. Inline styles are allowed in e-mails only (no CSP in mail clients). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $subjectLine }}</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Inter,'Segoe UI',Helvetica,Arial,sans-serif;color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;border:1px solid #e2e8f0;">
                    <tr>
                        <td style="padding:28px 32px 8px 32px;">
                            <span style="display:inline-block;width:12px;height:12px;background:#0044aa;margin-right:8px;vertical-align:middle;"></span>
                            <span style="font-size:13px;font-weight:900;letter-spacing:.3em;text-transform:uppercase;color:#0044aa;vertical-align:middle;">REDact</span>
                            <span style="font-size:13px;color:#64748b;vertical-align:middle;">&nbsp;{{ __('common.byline') }}</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 32px 8px 32px;font-size:16px;line-height:1.6;">
                            <p style="margin:0 0 16px 0;">{{ $greeting }}</p>
                            @foreach ($body['intro'] as $paragraph)
                                <p style="margin:0 0 16px 0;">{{ $paragraph }}</p>
                            @endforeach

                            @isset($body['button'])
                                <p style="margin:24px 0;">
                                    <a href="{{ $body['button']['url'] }}" style="display:inline-block;background:#0044aa;color:#ffffff;text-decoration:none;font-size:13px;font-weight:700;letter-spacing:.15em;text-transform:uppercase;padding:14px 24px;border-radius:8px;">{{ $body['button']['label'] }}</a>
                                </p>
                            @endisset

                            @isset($body['code'])
                                <p style="margin:24px 0 8px 0;font-size:14px;color:#475569;">{{ $body['code_label'] }}</p>
                                <p style="margin:0 0 24px 0;font-size:32px;font-weight:800;letter-spacing:.3em;font-family:'SFMono-Regular',Consolas,monospace;color:#0f172a;">{{ $body['code'] }}</p>
                            @endisset

                            @foreach ($body['outro'] as $paragraph)
                                <p style="margin:0 0 12px 0;font-size:14px;color:#475569;">{{ $paragraph }}</p>
                            @endforeach
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 32px 28px 32px;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b;">
                            {{ __('auth.mail.footer') }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
