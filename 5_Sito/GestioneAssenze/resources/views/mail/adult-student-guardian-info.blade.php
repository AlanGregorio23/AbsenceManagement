<x-mail.layout :title="$title">
    <p style="margin:0 0 12px;line-height:1.6;">{{ $intro }}</p>

    @if(!empty($details))
        <div style="margin:0 0 16px;padding:12px;border-radius:8px;background:#f8fafc;border:1px solid #e2e8f0;line-height:1.5;">
            @foreach($details as $detail)
                <div>{{ $detail }}</div>
            @endforeach
        </div>
    @endif

    @if(!empty($closing))
        <p style="margin:0 0 12px;line-height:1.6;">{{ $closing }}</p>
    @endif

    <p style="margin:0;font-size:13px;color:#64748b;">
        Email informativa automatica di Gestione Assenze.
    </p>
</x-mail.layout>
