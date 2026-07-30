{{--
    Organizer email verification. Hand-written like emails/ticket.blade.php and
    for the same reason: mail clients strip <style> and know nothing about the
    design tokens, so the violet is inlined literally here.

    It does NOT use Laravel's MailMessage/markdown notification: that template
    pulls its wrapper strings ("Regards", the button subcopy) from the
    framework's own English translations, which this project's i18n cannot
    reach — Spanish is the source language and the generator translates from it.
    Going through MailMessage shipped a Spanish-first product an English email.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Verifica tu email') }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f5; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#18181b;">
    <div style="max-width:560px; margin:0 auto; padding:24px 16px;">
        <div style="background-color:#ffffff; border-radius:12px; padding:32px 24px; border:1px solid #e4e4e7;">

            <h1 style="margin:0 0 16px; font-size:20px; line-height:1.3; font-weight:700; color:#18181b;">
                {{ __('Verifica tu email') }}
            </h1>

            <p style="margin:0 0 24px; font-size:15px; line-height:1.6; color:#3f3f46;">
                {{ __('Confirma tu dirección para poder publicar eventos. Mientras tanto puedes crear y editar borradores.') }}
            </p>

            <div style="text-align:center; margin:0 0 24px;">
                <a href="{{ $url }}"
                   style="display:inline-block; background-color:#7c3aed; color:#ffffff; text-decoration:none; font-size:15px; font-weight:600; padding:12px 24px; border-radius:8px;">
                    {{ __('Verificar mi email') }}
                </a>
            </div>

            <p style="margin:0 0 16px; font-size:13px; line-height:1.6; color:#71717a;">
                {{ __('Si el botón no funciona, copiá y pegá este enlace:') }}<br>
                <a href="{{ $url }}" style="color:#7c3aed; word-break:break-all;">{{ $url }}</a>
            </p>

            <p style="margin:24px 0 0; padding-top:16px; border-top:1px solid #e4e4e7; font-size:12px; line-height:1.6; color:#a1a1aa;">
                {{ __('Si no creaste una cuenta, puedes ignorar este correo.') }}
            </p>
        </div>

        <p style="margin:16px 0 0; text-align:center; font-size:12px; color:#a1a1aa;">
            {{ config('app.name') }}
        </p>
    </div>
</body>
</html>
