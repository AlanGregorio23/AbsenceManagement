<x-mail.layout title="Reimpostazione password">
    <p style="margin:0 0 14px;font-size:15px;line-height:1.6;">
        Ciao,<br>
        abbiamo ricevuto una richiesta di reimpostazione della password per l account <strong>{{ $email }}</strong>.
    </p>
    <p style="margin:0 0 20px;font-size:15px;line-height:1.6;">
        Per scegliere una nuova password, premi il pulsante qui sotto.
    </p>

    <p style="margin:0 0 20px;">
        <a href="{{ $resetUrl }}"
           style="display:inline-block;padding:12px 18px;border-radius:8px;background:#1d4ed8;color:#ffffff;text-decoration:none;font-size:15px;font-weight:700;">
            Reimposta password
        </a>
    </p>

    <div style="margin:0 0 20px;padding:12px 14px;border-radius:8px;background-color:#f8fafc;border:1px solid #dbeafe;font-size:13px;line-height:1.55;">
        @if($expiryMinutes !== null)
        Il link e valido per <strong>{{ $expiryMinutes }} minuti</strong>.
        @else
        Il link resta valido <strong>finche non imposti la password</strong>.
        @endif
        Se non hai richiesto tu questa operazione, ignora questa email.
    </div>

    <p style="margin:0 0 8px;font-size:13px;color:#4b5563;line-height:1.5;">
        Se il pulsante non funziona, copia e incolla questo link nel browser:
    </p>
    <p style="margin:0 0 14px;word-break:break-all;font-size:12px;line-height:1.5;color:#1d4ed8;">
        <a href="{{ $resetUrl }}" style="color:#1d4ed8;text-decoration:underline;">{{ $resetUrl }}</a>
    </p>

    <p style="margin:0;font-size:12px;line-height:1.5;color:#6b7280;">
        Messaggio automatico inviato da {{ $appName }}.
    </p>
</x-mail.layout>
