<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class OperationLog extends Model
{
    use HasFactory;

    private const ACTION_LABELS = [
        'absence.request.created' => 'Creazione richiesta di assenza',
        'absence.updated' => 'Aggiornamento assenza',
        'absence.quick.updated' => 'Aggiornamento rapido assenza',
        'absence.approved' => 'Approvazione assenza',
        'absence.approved_without_guardian' => 'Approvazione assenza senza firma tutore',
        'absence.rejected' => 'Rifiuto assenza',
        'absence.deadline.extended' => 'Proroga scadenza certificato',
        'absence.certificate.uploaded' => 'Caricamento certificato medico',
        'absence.certificate.accepted' => 'Accettazione certificato medico',
        'absence.certificate.rejected' => 'Rifiuto certificato medico',
        'absence.certificate.downloaded' => 'Download certificato medico',
        'absence.guardian_signature.viewed' => 'Visualizzazione firma tutore',
        'absence.guardian.signature.confirmed' => 'Firma tutore confermata',
        'absence.guardian_confirmation_email.sent' => 'Invio email firma tutore',
        'absence.guardian_confirmation_email.resent' => 'Reinvio email firma tutore',
        'absence.guardian_confirmation_email.failed' => 'Errore invio email firma tutore',
        'absence.guardian_confirmation_email.missing_guardian' => 'Tutore mancante per invio firma',
        'annual_hours.limit_reached.notified' => 'Notifica limite ore annuali inviata',
        'delay.request.created' => 'Creazione segnalazione ritardo',
        'delay.converted_to_absence' => 'Conversione ritardo in ora di assenza',
        'delay.updated' => 'Modifica ritardo',
        'delay.approved' => 'Validazione ritardo',
        'delay.rejected' => 'Ritardo registrato',
        'delay.guardian.signature.confirmed' => 'Firma tutore ritardo confermata',
        'delay.guardian_signature.viewed' => 'Visualizzazione firma tutore ritardo',
        'delay.guardian_confirmation_email.sent' => 'Invio email firma tutore ritardo',
        'delay.guardian_confirmation_email.resent' => 'Reinvio email firma tutore ritardo',
        'delay.guardian_confirmation_email.failed' => 'Errore invio email firma tutore ritardo',
        'delay.guardian_confirmation_email.missing_guardian' => 'Tutore mancante per firma ritardo',
        'delay.teacher_notification.failed' => 'Errore invio notifica docente ritardo',
        'delay.rule.applied' => 'Applicazione regola ritardi',
        'delay.rule_notification.failed' => 'Errore invio notifica regola ritardi',
        'leave.request.created' => 'Creazione richiesta congedo',
        'leave.pre_approved' => 'Override firma tutore congedo',
        'leave.approved' => 'Approvazione congedo',
        'leave.rejected' => 'Rifiuto congedo',
        'leave.forwarded_to_management' => 'Inoltro congedo in direzione',
        'leave.updated' => 'Modifica congedo',
        'leave.documentation.requested' => 'Richiesta documentazione congedo',
        'leave.documentation.rejected' => 'Rifiuto documentazione congedo',
        'leave.documentation.uploaded' => 'Caricamento documentazione congedo',
        'leave.40_hours.updated' => 'Aggiornamento conteggio limite ore annuale congedo',
        'leave.registered' => 'Registrazione congedo',
        'leave.registered_as_absence' => 'Passaggio congedo ad assenza',
        'leave.pdf.downloaded' => 'Download PDF inoltro direzione',
        'leave.guardian.signature.confirmed' => 'Firma tutore congedo confermata',
        'leave.guardian_confirmation_email.sent' => 'Invio email firma tutore congedo',
        'leave.guardian_confirmation_email.resent' => 'Reinvio email firma tutore congedo',
        'leave.guardian_confirmation_email.failed' => 'Errore invio email firma tutore congedo',
        'leave.guardian_confirmation_email.missing_guardian' => 'Tutore mancante per firma congedo',
        'absence.derived_leave_effective_hours.updated_by_student' => 'Aggiornamento ore effettive da congedo',
        'monthly_report.generated' => 'Generazione report mensile',
        'monthly_report.generation.failed' => 'Errore generazione report mensile',
        'monthly_report.email.sent' => 'Invio email report mensile',
        'monthly_report.email.resent' => 'Reinvio email report mensile',
        'monthly_report.email.failed' => 'Errore invio email report mensile',
        'monthly_report.signed_uploaded' => 'Upload report mensile firmato',
        'monthly_report.approved' => 'Approvazione report mensile firmato',
        'monthly_report.downloaded' => 'Download report mensile originale',
        'monthly_report.signed.downloaded' => 'Download report mensile firmato',
        'admin.user.updated' => 'Aggiornamento utente',
        'admin.user.deleted' => 'Eliminazione utente',
        'admin.user.activated' => 'Attivazione utente',
        'admin.user.deactivated' => 'Disattivazione utente',
        'admin.student.guardian.assigned' => 'Assegnazione tutore allo studente',
        'admin.student.guardian.removed' => 'Rimozione tutore dallo studente',
        'student.guardian.self_assigned' => 'Cambio tutore automatico a maggiore eta',
        'student.guardian.self_assignment_email.sent' => 'Invio email cambio tutore maggiore eta',
        'student.guardian.self_assignment_email.failed' => 'Errore invio email cambio tutore maggiore eta',
        'admin.class.created' => 'Creazione classe',
        'admin.class.updated' => 'Aggiornamento classe',
        'admin.user.created' => 'Creazione utente',
        'admin.users.imported' => 'Importazione utenti da CSV',
        'admin.settings.updated' => 'Aggiornamento impostazioni di sistema',
        'auth.login.succeeded' => 'Login riuscito',
        'auth.login.admin.succeeded' => 'Login admin riuscito',
        'auth.login.failed_invalid_credentials' => 'Login fallito: credenziali non valide',
        'auth.login.failed_inactive_user' => 'Login fallito: account disattivato',
        'auth.login.blocked_rate_limited' => 'Login bloccato da rate limit',
        'auth.login.admin.failed_invalid_credentials' => 'Tentativo login admin fallito: credenziali non valide',
        'auth.login.admin.failed_inactive_user' => 'Tentativo login admin fallito: account disattivato',
        'auth.login.admin.blocked_rate_limited' => 'Tentativo login admin bloccato da rate limit',
        'auth.logout' => 'Logout utente',
    ];

    private const ENTITY_LABELS = [
        'absence' => 'Assenza',
        'delay' => 'Ritardo',
        'leave' => 'Congedo',
        'medical_certificate' => 'Certificato medico',
        'guardian_absence_confirmation' => 'Conferma firma tutore',
        'guardian_delay_confirmation' => 'Conferma firma ritardo',
        'guardian_leave_confirmation' => 'Conferma firma tutore congedo',
        'delay_email_notification' => 'Notifica email ritardo',
        'monthly_report' => 'Report mensile',
        'monthly_report_email_notification' => 'Notifica email report mensile',
        'user' => 'Utente',
        'student' => 'Studente',
        'class' => 'Classe',
        'settings' => 'Impostazioni',
        'auth' => 'Autenticazione',
    ];

    private const LEVEL_LABELS = [
        'INFO' => 'Informazione',
        'WARNING' => 'Avviso',
        'ERROR' => 'Errore',
    ];

    private const PAYLOAD_KEY_LABELS = [
        'before' => 'Valori precedenti',
        'after' => 'Nuovi valori',
        'start_date' => 'Data inizio',
        'end_date' => 'Data fine',
        'assigned_hours' => 'Ore assegnate',
        'reason' => 'Motivazione',
        'destination' => 'Destinazione',
        'medical_certificate_required' => 'Certificato medico richiesto',
        'medical_certificate_deadline' => 'Scadenza certificato medico',
        'delay_datetime' => 'Data/ora ritardo',
        'minutes' => 'Durata ritardo (min)',
        'delay_minutes_in_request' => 'Durata ritardo (min)',
        'justification_deadline' => 'Scadenza giustificazione ritardo',
        'minutes_threshold' => 'Soglia minuti ritardo',
        'delay_count_in_semester' => 'Numero ritardi nel semestre',
        'delay_rule_id' => 'Regola ritardi applicata',
        'delay_rule_actions' => 'Azioni regola ritardi',
        'delay_id' => 'Ritardo',
        'notifications_sent' => 'Notifiche inviate',
        'notifications_failed' => 'Notifiche fallite',
        'converted_to_absence' => 'Conversione in assenza',
        'converted_absence_id' => 'Assenza convertita',
        'recipient_email' => 'Email destinatario',
        'absence_id' => 'Assenza',
        'file_path' => 'Percorso file',
        'source' => 'Origine operazione',
        'with_guardian_signature' => 'Firma tutore presente',
        'comment' => 'Commento',
        'counts_40_hours' => 'Conteggio limite ore annuale',
        'counts_40_hours_comment' => 'Nota conteggio limite ore annuale',
        'certificate_validated' => 'Certificato validato',
        'teacher_comment' => 'Commento docente',
        'status' => 'Stato',
        'requested_hours' => 'Ore richieste',
        'requested_lessons' => 'Periodi scolastici richiesti',
        'count_hours' => 'Conteggio limite ore annuale',
        'count_hours_comment' => 'Commento conteggio limite ore annuale',
        'workflow_comment' => 'Commento workflow',
        'documentation_request_comment' => 'Commento richiesta documentazione',
        'documentation_path' => 'Percorso documentazione',
        'documentation_uploaded_at' => 'Data upload documentazione',
        'registered_absence_id' => 'Assenza collegata',
        'approved_without_guardian' => 'Approvazione senza firma tutore',
        'override_guardian_signature' => 'Override firma tutore',
        'derived_from_leave_id' => 'Congedo collegato',
        'extension_days' => 'Giorni proroga',
        'previous_deadline' => 'Scadenza precedente',
        'new_deadline' => 'Nuova scadenza',
        'guardian_id' => 'Tutore',
        'signature_path' => 'Percorso firma',
        'guardian_email' => 'Email tutore',
        'token_id' => 'Token invio',
        'expires_at' => 'Scadenza link',
        'student_id' => 'Studente',
        'report_month' => 'Mese report',
        'recipients' => 'Destinatari',
        'sent' => 'Email inviate',
        'failed' => 'Email fallite',
        'error' => 'Errore',
        'active' => 'Stato attivo',
        'name' => 'Nome',
        'surname' => 'Cognome',
        'email' => 'Email',
        'target_role' => 'Ruolo target login',
        'role' => 'Ruolo',
        'year' => 'Anno',
        'section' => 'Sezione',
        'teacher_ids' => 'Docenti assegnati',
        'student_ids' => 'Studenti assegnati',
        'relationship' => 'Relazione',
        'is_primary' => 'Tutore principale',
        'deleted_user' => 'Utente eliminato',
        'previous_guardian_emails' => 'Email tutori precedenti',
        'new_guardian_email' => 'Nuova email tutore',
        'effective_date' => 'Data effetto',
        'recipient_roles' => 'Ruoli destinatario',
        'max_attempts' => 'Tentativi massimi login',
        'max_attempts_per_ip' => 'Tentativi massimi login per IP',
        'decay_seconds' => 'Timeout blocco login (sec)',
        'throttle_seconds' => 'Secondi blocco rimanenti',
        'blocked_by' => 'Bloccato da',
        'created_user' => 'Utente creato',
        'created_users' => 'Utenti creati',
        'updated_users' => 'Utenti aggiornati',
        'password_setup_emails_sent' => 'Email impostazione password inviate',
        'password_setup_emails_failed' => 'Email impostazione password fallite',
        'failed_emails' => 'Email con invio fallito',
        'import_file_name' => 'Nome file import',
        'login_security' => 'Sicurezza login',
        'log_retention' => 'Retention log',
    ];

    private const PAYLOAD_VALUE_LABELS = [
        'absence_create' => 'Inserimento richiesta assenza',
        'student_documents' => 'Caricamento documenti studente',
        'reported' => 'Segnalata',
        'justified' => 'Giustificata',
        'arbitrary' => 'Arbitraria',
        'awaiting_guardian_signature' => 'In attesa firma tutore',
        'signed' => 'Firmata',
        'pre_approved' => 'Override firma tutore',
        'approved' => 'Approvata',
        'documentation_requested' => 'Documentazione richiesta',
        'in_review' => 'In valutazione',
        'registered' => 'Registrato',
        'forwarded_to_management' => 'Inoltrata in direzione',
        'rejected' => 'Rifiutata',
        'drawn_canvas' => 'Firma disegnata a mano',
    ];

    protected $fillable = [
        'user_id',
        'level',
        'action',
        'entity',
        'entity_id',
        'payload',
        'ip',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public static function record(
        ?User $user,
        string $action,
        ?string $entity = null,
        ?int $entityId = null,
        array $payload = [],
        string $level = 'INFO',
        ?Request $request = null
    ): void {
        $normalizedAction = trim($action);
        if (str_ends_with($normalizedAction, '.viewed')) {
            return;
        }

        static::query()->create([
            'user_id' => $user?->id,
            'level' => strtoupper(trim($level)),
            'action' => $normalizedAction,
            'entity' => $entity ? trim($entity) : null,
            'entity_id' => $entityId,
            'payload' => $payload,
            'ip' => $request?->ip(),
        ]);
    }

    public static function actionLabel(?string $action): string
    {
        $normalized = strtolower(trim((string) $action));
        if ($normalized === '') {
            return '-';
        }

        return self::ACTION_LABELS[$normalized] ?? self::humanizeStaticToken($normalized);
    }

    public static function entityLabel(?string $entity): string
    {
        $normalized = strtolower(trim((string) $entity));
        if ($normalized === '') {
            return '-';
        }

        return self::ENTITY_LABELS[$normalized] ?? self::humanizeStaticToken($normalized);
    }

    public static function levelLabel(?string $level): string
    {
        $normalized = strtoupper(trim((string) $level));
        if ($normalized === '') {
            return '-';
        }

        return self::LEVEL_LABELS[$normalized] ?? $normalized;
    }

    /**
     * @return array{info_deleted:int,error_deleted:int,interaction_cutoff:string,error_cutoff:string}
     */
    public static function pruneByConfiguredRetention(): array
    {
        $settings = OperationLogSetting::firstOrDefault();
        $interactionDays = OperationLogSetting::sanitizeRetentionDays(
            $settings->interaction_retention_days
        );
        $errorDays = OperationLogSetting::sanitizeRetentionDays(
            $settings->error_retention_days
        );

        $interactionCutoff = now()->subDays($interactionDays);
        $errorCutoff = now()->subDays($errorDays);

        $infoDeleted = (int) static::query()
            ->where('level', 'INFO')
            ->where('created_at', '<', $interactionCutoff)
            ->delete();

        $errorDeleted = (int) static::query()
            ->whereIn('level', ['WARNING', 'ERROR'])
            ->where('created_at', '<', $errorCutoff)
            ->delete();

        return [
            'info_deleted' => $infoDeleted,
            'error_deleted' => $errorDeleted,
            'interaction_cutoff' => $interactionCutoff->toDateTimeString(),
            'error_cutoff' => $errorCutoff->toDateTimeString(),
        ];
    }

    public function getLog(?array $levels = null, ?int $limit = null)
    {
        $query = $this->baseLogQuery($levels);
        if ($limit !== null) {
            $query->limit(max(1, (int) $limit));
        }

        $logs = $query->get()->map(fn (OperationLog $log) => $this->mapLogItem($log));

        return $logs;
    }

    public function getPaginatedLog(
        ?array $levels = null,
        int $perPage = 50,
        ?string $search = null,
        ?string $entity = null,
        ?string $action = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        $safePerPage = max(1, min($perPage, 100));
        $paginator = $this->baseLogQuery(
            $levels,
            $search,
            $entity,
            $action,
            $dateFrom,
            $dateTo
        )
            ->simplePaginate($safePerPage)
            ->withQueryString();

        $data = collect($paginator->items())
            ->map(fn (OperationLog $log) => $this->mapLogItem($log))
            ->values();

        $currentPage = $paginator->currentPage();
        $from = $data->isEmpty() ? null : (($currentPage - 1) * $paginator->perPage()) + 1;
        $to = $data->isEmpty() ? null : $from + $data->count() - 1;

        return [
            'data' => $data,
            'pagination' => [
                'current_page' => $currentPage,
                'per_page' => $paginator->perPage(),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
                'from' => $from,
                'to' => $to,
            ],
        ];
    }

    /**
     * @return array{rows:\Illuminate\Support\LazyCollection<int,array<string,mixed>>,total:int,exported:int,truncated:bool}
     */
    public function getExportRows(
        ?array $levels = null,
        ?string $search = null,
        ?string $entity = null,
        ?string $action = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        int $maxRows = 200000
    ): array {
        $safeLimit = max(1, min($maxRows, 200000));
        $query = $this->baseLogQuery(
            $levels,
            $search,
            $entity,
            $action,
            $dateFrom,
            $dateTo
        );

        $total = (int) (clone $query)->toBase()->getCountForPagination();
        $rows = $query
            ->lazy(1000)
            ->take($safeLimit)
            ->map(fn (OperationLog $log) => $this->mapLogItem($log));
        $exported = min($total, $safeLimit);

        return [
            'rows' => $rows,
            'total' => $total,
            'exported' => $exported,
            'truncated' => $total > $exported,
        ];
    }

    public function getAvailableEntities(?array $levels = null): array
    {
        $query = OperationLog::query()
            ->whereNotNull('entity');

        if ($levels) {
            $normalizedLevels = array_map('strtoupper', $levels);
            $query->whereIn('level', $normalizedLevels);
        }

        return $query
            ->select('entity')
            ->distinct()
            ->orderBy('entity')
            ->pluck('entity')
            ->map(fn (?string $entity) => [
                'code' => $entity,
                'label' => $this->translateEntity($entity),
            ])
            ->values()
            ->all();
    }

    public function getAvailableActions(?array $levels = null, ?string $entity = null): array
    {
        $query = OperationLog::query();

        if ($levels) {
            $normalizedLevels = array_map('strtoupper', $levels);
            $query->whereIn('level', $normalizedLevels);
        }

        $normalizedEntity = strtolower(trim((string) $entity));
        if ($normalizedEntity !== '') {
            $query->whereRaw('LOWER(entity) = ?', [$normalizedEntity]);
        }

        return $query
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->map(fn (?string $action) => [
                'code' => $action,
                'label' => $this->translateAction($action),
            ])
            ->values()
            ->all();
    }

    private function baseLogQuery(
        ?array $levels = null,
        ?string $search = null,
        ?string $entity = null,
        ?string $action = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): Builder {
        $query = OperationLog::query()
            ->with(['user:id,name,surname'])
            ->orderByDesc('created_at');

        if ($levels) {
            $normalizedLevels = array_map('strtoupper', $levels);
            $query->whereIn('level', $normalizedLevels);
        }

        $normalizedEntity = strtolower(trim((string) $entity));
        if ($normalizedEntity !== '') {
            $query->whereRaw('LOWER(entity) = ?', [$normalizedEntity]);
        }

        $normalizedAction = strtolower(trim((string) $action));
        if ($normalizedAction !== '') {
            $query->whereRaw('LOWER(action) = ?', [$normalizedAction]);
        }

        $normalizedDateFrom = $this->normalizeDateInput($dateFrom);
        if ($normalizedDateFrom) {
            $query->whereDate('created_at', '>=', $normalizedDateFrom);
        }

        $normalizedDateTo = $this->normalizeDateInput($dateTo);
        if ($normalizedDateTo) {
            $query->whereDate('created_at', '<=', $normalizedDateTo);
        }

        $normalizedSearch = trim((string) $search);
        if ($normalizedSearch !== '') {
            $searchLike = '%'.strtolower($normalizedSearch).'%';
            $searchTerms = $this->buildSearchTerms($normalizedSearch);
            $matchedActions = $this->matchTranslatedCodes(self::ACTION_LABELS, $normalizedSearch);
            $matchedEntities = $this->matchTranslatedCodes(self::ENTITY_LABELS, $normalizedSearch);

            $query->where(function (Builder $searchQuery) use (
                $searchLike,
                $searchTerms,
                $matchedActions,
                $matchedEntities
            ) {
                $searchQuery
                    ->whereRaw('LOWER(action) LIKE ?', [$searchLike])
                    ->orWhereRaw('LOWER(entity) LIKE ?', [$searchLike])
                    ->orWhereHas('user', function (Builder $userQuery) use ($searchLike, $searchTerms) {
                        $userQuery
                            ->whereRaw('LOWER(name) LIKE ?', [$searchLike])
                            ->orWhereRaw('LOWER(surname) LIKE ?', [$searchLike]);

                        foreach ($searchTerms as $term) {
                            $like = '%'.$term.'%';
                            $userQuery
                                ->orWhereRaw('LOWER(name) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(surname) LIKE ?', [$like]);
                        }
                    });

                if (! empty($matchedActions)) {
                    $searchQuery->orWhereIn('action', $matchedActions);
                }

                if (! empty($matchedEntities)) {
                    $searchQuery->orWhereIn('entity', $matchedEntities);
                }

                if (in_array('sistema', $searchTerms, true)) {
                    $searchQuery->orWhereNull('user_id');
                }
            });
        }

        return $query;
    }

    private function normalizeDateInput(?string $value): ?string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $normalized)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function mapLogItem(OperationLog $log): array
    {
        return [
            'id' => (int) $log->id,
            'livello' => $log->level,
            'livello_label' => $this->translateLevel($log->level),
            'attore' => $this->resolveActorName($log->user),
            'azione' => $this->translateAction($log->action),
            'azione_code' => $log->action,
            'entita' => $this->translateEntity($log->entity),
            'entita_code' => $log->entity,
            'dettagli_json' => $log->payload,
            'ip' => $log->ip,
            'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function resolveActorName(?User $user): string
    {
        if (! $user) {
            return 'Sistema';
        }

        $fullName = trim((string) ($user->name ?? '').' '.(string) ($user->surname ?? ''));
        if ($fullName !== '') {
            return $fullName;
        }

        return trim((string) ($user->name ?? 'Utente'));
    }

    private function translateAction(?string $action): string
    {
        $normalized = strtolower(trim((string) $action));
        if ($normalized === '') {
            return '-';
        }

        if (array_key_exists($normalized, self::ACTION_LABELS)) {
            return self::ACTION_LABELS[$normalized];
        }

        return $this->humanizeToken($normalized);
    }

    private function translateEntity(?string $entity): string
    {
        $normalized = strtolower(trim((string) $entity));
        if ($normalized === '') {
            return '-';
        }

        if (array_key_exists($normalized, self::ENTITY_LABELS)) {
            return self::ENTITY_LABELS[$normalized];
        }

        return $this->humanizeToken($normalized);
    }

    private function translateLevel(?string $level): string
    {
        $normalized = strtoupper(trim((string) $level));
        if ($normalized === '') {
            return '-';
        }

        return self::LEVEL_LABELS[$normalized] ?? $normalized;
    }

    private function translatePayload(mixed $value): mixed
    {
        if (is_null($value)) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'Si' : 'No';
        }

        if (is_string($value)) {
            return $this->translatePayloadValue($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->translatePayload($item), $value);
        }

        $translated = [];
        foreach ($value as $key => $item) {
            $translated[$this->translatePayloadKey((string) $key)] = $this->translatePayload($item);
        }

        return $translated;
    }

    private function translatePayloadKey(string $key): string
    {
        $normalized = strtolower(trim($key));
        if ($normalized === '') {
            return $key;
        }

        return self::PAYLOAD_KEY_LABELS[$normalized] ?? $this->humanizeToken($normalized);
    }

    private function translatePayloadValue(string $value): string
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return $value;
        }

        return self::PAYLOAD_VALUE_LABELS[$normalized] ?? $value;
    }

    private function humanizeToken(string $value): string
    {
        $spaced = str_replace(['.', '_', '-'], ' ', strtolower(trim($value)));
        $spaced = preg_replace('/\s+/', ' ', $spaced) ?: '';
        if ($spaced === '') {
            return '-';
        }

        return ucfirst($spaced);
    }

    private static function humanizeStaticToken(string $value): string
    {
        $spaced = str_replace(['.', '_', '-'], ' ', strtolower(trim($value)));
        $spaced = preg_replace('/\s+/', ' ', $spaced) ?: '';
        if ($spaced === '') {
            return '-';
        }

        return ucfirst($spaced);
    }

    private function matchTranslatedCodes(array $labelMap, string $search): array
    {
        $terms = $this->buildSearchTerms($search);
        if (empty($terms)) {
            return [];
        }

        $matches = [];
        foreach ($labelMap as $code => $label) {
            $normalizedLabel = strtolower((string) $label);
            foreach ($terms as $term) {
                if ($term !== '' && str_contains($normalizedLabel, $term)) {
                    $matches[] = $code;
                    break;
                }
            }
        }

        return array_values(array_unique($matches));
    }

    private function buildSearchTerms(string $search): array
    {
        $normalized = strtolower(trim($search));
        if ($normalized === '') {
            return [];
        }

        $parts = preg_split('/\s+/', $normalized) ?: [];
        $terms = [$normalized];

        foreach ($parts as $part) {
            $word = trim($part);
            if ($word === '') {
                continue;
            }

            $terms[] = $word;
            $terms = array_merge($terms, $this->buildInflectionTerms($word));
        }

        return array_values(array_unique(array_filter($terms, fn ($term) => trim((string) $term) !== '')));
    }

    private function buildInflectionTerms(string $term): array
    {
        $variants = [];
        $length = strlen($term);
        if ($length < 4) {
            return $variants;
        }

        $last = substr($term, -1);
        $base = substr($term, 0, -1);

        if ($last === 'e') {
            $variants[] = $base.'a';
        }

        if ($last === 'a') {
            $variants[] = $base.'e';
        }

        if ($last === 'i') {
            $variants[] = $base.'o';
        }

        if ($last === 'o') {
            $variants[] = $base.'i';
        }

        if ($last === 's') {
            $variants[] = $base;
        }

        return array_values(array_unique($variants));
    }
}
