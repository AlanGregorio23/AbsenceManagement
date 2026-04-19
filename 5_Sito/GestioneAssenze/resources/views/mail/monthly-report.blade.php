<x-mail.layout title="Report mensile assenze, ritardi e congedi">
    <p style="margin:0 0 8px;">Buongiorno,</p>
    <p style="margin:0 0 16px;line-height:1.5;">
        in allegato trovi il report mensile di
        <strong>{{ $studentName !== '' ? $studentName : 'studente' }}</strong>
        relativo al periodo
        <strong>{{ $summary['month_label'] ?? '-' }}</strong>.
    </p>

    <div style="margin:0 0 16px;padding:12px;border-radius:8px;background:#f8fafc;border:1px solid #e2e8f0;line-height:1.5;">
        <div><strong>Codice report:</strong> {{ $report->reportCode() }}</div>
        <div><strong>Ore assenza:</strong> {{ (int) ($summary['absence_hours'] ?? 0) }}</div>
        <div><strong>Ritardi:</strong> {{ (int) ($summary['delay_count'] ?? 0) }}</div>
        <div><strong>Congedi:</strong> {{ (int) ($summary['leave_count'] ?? 0) }}</div>
    </div>

    <p style="margin:0;line-height:1.5;">
        Il documento allegato deve essere stampato, firmato e caricato nell area report del portale.
    </p>
</x-mail.layout>
