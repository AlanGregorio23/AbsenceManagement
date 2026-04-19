@php
    $requestedLessonsLabel = \App\Models\Leave::formatRequestedLessonsLabel(
        \App\Models\Leave::normalizeRequestedLessonsPayload($leave->requested_lessons ?? null),
        $leave->start_date?->toDateString(),
        $leave->end_date?->toDateString()
    );
@endphp

<x-mail.layout title="Conferma congedo">
    <p style="margin:0 0 8px;">Gentile {{ $guardian->name ?? 'tutore' }},</p>
    <p style="margin:0 0 16px;line-height:1.5;">
        e stata registrata una nuova richiesta di congedo per
        <strong>{{ $studentName !== '' ? $studentName : 'lo studente' }}</strong>.
    </p>

    <div style="margin:0 0 16px;padding:12px;border-radius:8px;background:#f8fafc;border:1px solid #e2e8f0;line-height:1.5;">
        <div><strong>ID congedo:</strong> C-{{ str_pad((string) $leave->id, 4, '0', STR_PAD_LEFT) }}</div>
        <div><strong>Inizio:</strong> {{ $leave->start_date?->format('d/m/Y') }}</div>
        <div><strong>Fine:</strong> {{ $leave->end_date?->format('d/m/Y') }}</div>
        <div><strong>Ore richieste:</strong> {{ (int) $leave->requested_hours }}</div>
        @if($requestedLessonsLabel !== '')
            <div><strong>Periodi scolastici:</strong> {{ $requestedLessonsLabel }}</div>
        @endif
        <div><strong>Destinazione:</strong> {{ $leave->destination ?: '-' }}</div>
        <div><strong>Motivo:</strong> {{ $leave->reason ?? '-' }}</div>
        @if($expiresAt)
            <div><strong>Scadenza link:</strong> {{ $expiresAt->format('d/m/Y H:i') }}</div>
        @endif
    </div>

    <p style="margin:0 0 16px;line-height:1.5;">
        Usa il pulsante qui sotto per accedere alla pagina protetta e firmare elettronicamente.
    </p>

    <p style="margin:0 0 20px;">
        <a href="{{ $signatureUrl }}"
           style="display:inline-block;padding:12px 16px;border-radius:8px;background:#1d4ed8;color:#ffffff;text-decoration:none;font-weight:600;">
            Conferma e firma congedo
        </a>
    </p>

    <p style="margin:0 0 8px;font-size:13px;color:#475569;line-height:1.5;">
        Se il pulsante non funziona, copia e incolla questo link nel browser:
    </p>
    <p style="margin:0;font-size:13px;color:#1e293b;word-break:break-all;">{{ $signatureUrl }}</p>
</x-mail.layout>
