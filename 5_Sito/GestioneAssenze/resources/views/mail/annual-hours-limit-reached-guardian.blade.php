<x-mail.layout title="Limite ore annuali raggiunto">
    <p style="margin:0 0 8px;">Ciao {{ $guardianName !== '' ? $guardianName : 'Tutore' }},</p>

    <p style="margin:0 0 12px;line-height:1.5;">
        ti informiamo che lo studente <strong>{{ $studentName }}</strong>
        ha raggiunto il limite di ore annuali.
    </p>

    <div style="margin:0 0 16px;padding:12px;border-radius:8px;background:#f8fafc;border:1px solid #e2e8f0;line-height:1.5;">
        <div><strong>Totale ore:</strong> {{ $totalHours }}</div>
        <div><strong>Limite impostato:</strong> {{ $maxHours }}</div>
        <div><strong>Anno scolastico:</strong> {{ $schoolYear }}</div>
    </div>

    <p style="margin:0 0 12px;line-height:1.5;">
        Contatta il docente di classe per eventuali chiarimenti.
    </p>

    <p style="margin:0;font-size:13px;color:#64748b;">
        Gestione Assenze
    </p>
</x-mail.layout>
