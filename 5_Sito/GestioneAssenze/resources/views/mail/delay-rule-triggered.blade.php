<x-mail.layout title="Regole ritardi applicate">
    <p style="margin:0 0 12px;line-height:1.5;">
        Lo studente <strong>{{ $studentName }}</strong> ha un conteggio ritardi pari a
        <strong>{{ (int) $delayCount }}</strong>.
    </p>

    <div style="margin:0 0 16px;padding:12px;border-radius:8px;background:#f8fafc;border:1px solid #e2e8f0;line-height:1.5;">
        <div><strong>Riferimento ritardo:</strong> R-{{ str_pad((string) $delayRecord->id, 4, '0', STR_PAD_LEFT) }}</div>
        <div><strong>Data:</strong> {{ $delayRecord->delay_datetime?->format('d/m/Y') }}</div>
        <div><strong>Minuti:</strong> {{ (int) ($delayRecord->minutes ?? 0) }}</div>
    </div>

    <p style="margin:0 0 8px;line-height:1.5;"><strong>Azioni applicate:</strong></p>
    <ul style="margin:0 0 16px;padding-left:18px;line-height:1.5;">
        @foreach ($actionLines as $line)
            <li>{{ $line }}</li>
        @endforeach
    </ul>
</x-mail.layout>
