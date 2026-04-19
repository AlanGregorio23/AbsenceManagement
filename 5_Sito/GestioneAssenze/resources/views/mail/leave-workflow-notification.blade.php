<x-mail.layout :title="$subject">
    <div style="margin:0 0 16px;line-height:1.6;color:#0f172a;">
        {!! nl2br(e((string) $body)) !!}
    </div>

    <p style="margin:0;font-size:13px;color:#64748b;">
        Questa email e stata generata automaticamente da Gestione Assenze.
    </p>
</x-mail.layout>
