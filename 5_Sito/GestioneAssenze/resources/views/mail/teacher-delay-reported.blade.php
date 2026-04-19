<x-mail.layout title="Nuovi ritardi segnalati">
    <p style="margin:0 0 16px;line-height:1.5;">
        Lo studente <strong>{{ $studentName }}</strong> ha inserito
        <strong>{{ (int) $reportedMinutes }}</strong> minuti di ritardo.
    </p>

    <div style="margin:0 0 16px;padding:12px;border-radius:8px;background:#f8fafc;border:1px solid #e2e8f0;line-height:1.5;">
        <div><strong>Riferimento:</strong> R-{{ str_pad((string) $delayRecord->id, 4, '0', STR_PAD_LEFT) }}</div>
        <div><strong>Data:</strong> {{ $delayRecord->delay_datetime?->format('d/m/Y') }}</div>
        <div><strong>Stato iniziale:</strong> Segnalato (in attesa validazione docente)</div>
        <div><strong>Motivo:</strong> {{ $delayRecord->notes ?? '-' }}</div>
    </div>

    <p style="margin:0;line-height:1.5;">
        Apri la sezione ritardi del portale per la validazione.
    </p>
</x-mail.layout>
