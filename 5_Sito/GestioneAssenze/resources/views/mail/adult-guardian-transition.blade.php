<x-mail.layout title="Cambio tutore per maggiore eta">
    <p style="margin:0 0 12px;line-height:1.5;">
        Lo studente
        <strong>{{ $studentName !== '' ? $studentName : 'Studente' }}</strong>
        ha compiuto 18 anni in data <strong>{{ $effectiveDate->format('d/m/Y') }}</strong>.
    </p>

    <p style="margin:0 0 16px;line-height:1.5;">
        Da questa data il tutore coincide con lo studente stesso.
    </p>

    <div style="margin:0 0 16px;padding:12px;border-radius:8px;background:#f8fafc;border:1px solid #e2e8f0;line-height:1.5;">
        <div><strong>Nuova email tutore:</strong> {{ strtolower(trim((string) ($student->email ?? '-'))) }}</div>
        <div style="margin-top:8px;"><strong>Email tutore precedenti:</strong></div>
        @if (!empty($previousGuardianEmails))
            <ul style="margin:8px 0 0;padding-left:18px;">
                @foreach ($previousGuardianEmails as $guardianEmail)
                    <li>{{ $guardianEmail }}</li>
                @endforeach
            </ul>
        @else
            <div style="margin-top:4px;">Nessun tutore precedente registrato.</div>
        @endif
    </div>

    <p style="margin:0;line-height:1.5;">
        Messaggio automatico generato dal sistema Gestione Assenze.
    </p>
</x-mail.layout>
