<?php

namespace App\Http\Controllers;

use App\Models\AdminSettings;
use App\Support\AnnualHoursLimitLabels;
use App\Support\SimplePdfBuilder;
use Illuminate\Routing\Controller as BaseController;
use Inertia\Inertia;

class RulesController extends BaseController
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        $sections = $this->buildRuleSections(AdminSettings::forEdit());

        return Inertia::render('Shared/Rules', [
            'sections' => $sections,
            'downloadUrl' => route('rules.download'),
        ]);
    }

    public function downloadPdf(SimplePdfBuilder $pdfBuilder)
    {
        $sections = $this->buildRuleSections(AdminSettings::forEdit());
        $pdfBinary = $pdfBuilder->buildRulesDocument(
            $sections,
            'Regole sistema',
            now()->format('d/m/Y H:i')
        );
        $fileName = 'regole-sistema-'.now()->format('Ymd-His').'.pdf';

        return response($pdfBinary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
        ]);
    }

    /**
     * @param  array<string,mixed>  $settings
     * @return array<int,array{title:string,items:array<int,array{label:string,value:string,details?:array<int,string>}>}>
     */
    private function buildRuleSections(array $settings): array
    {
        $absence = is_array($settings['absence'] ?? null) ? $settings['absence'] : [];
        $reasons = $this->normalizeList($settings['reasons'] ?? []);
        $delay = is_array($settings['delay'] ?? null) ? $settings['delay'] : [];
        $delayRules = $this->normalizeList($settings['delay_rules'] ?? []);

        $reasonItems = [];
        foreach ($reasons as $reason) {
            if (! is_array($reason)) {
                continue;
            }

            $name = trim((string) ($reason['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $primaryValue = ! empty($reason['counts_40_hours'])
                ? AnnualHoursLimitLabels::included()
                : AnnualHoursLimitLabels::excluded();
            $detailParts = [];

            if (! empty($reason['requires_management_consent'])) {
                $detailParts[] = 'Richiede consenso direzione (congedo)';
                if (! empty($reason['requires_document_on_leave_creation'])) {
                    $detailParts[] = 'Documento obbligatorio prima invio';
                }

                $managementConsentNote = trim((string) ($reason['management_consent_note'] ?? ''));
                if ($managementConsentNote !== '') {
                    $detailParts[] = 'Nota: '.$managementConsentNote;
                }
            }

            $reasonEntry = [
                'label' => $name,
                'value' => $primaryValue,
            ];
            if ($detailParts !== []) {
                $reasonEntry['details'] = $detailParts;
            }

            $reasonItems[] = $reasonEntry;
        }

        $delayRuleItems = [];
        foreach ($delayRules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $min = (int) ($rule['min_delays'] ?? 0);
            $max = array_key_exists('max_delays', $rule) && $rule['max_delays'] !== null
                ? (int) $rule['max_delays']
                : null;
            $rangeLabel = $max === null
                ? $min.'+ ritardi'
                : $min.'-'.$max.' ritardi';

            $actions = is_array($rule['actions'] ?? null) ? $rule['actions'] : [];
            $actionLabels = [];
            foreach ($actions as $action) {
                if (! is_array($action)) {
                    continue;
                }

                $type = trim((string) ($action['type'] ?? ''));
                if ($type === '') {
                    continue;
                }

                $typeLabel = $this->translateDelayActionType($type);
                $detail = trim((string) ($action['detail'] ?? ''));
                $actionLabels[] = $detail !== '' ? $typeLabel.' ('.$detail.')' : $typeLabel;
            }

            $infoMessage = trim((string) ($rule['info_message'] ?? ''));
            $primaryRuleValue = $actionLabels !== []
                ? 'Azioni: '.implode(', ', $actionLabels)
                : 'Nessuna azione configurata';
            $detailParts = [];
            if ($infoMessage !== '') {
                $detailParts[] = 'Nota: '.$infoMessage;
            }

            $delayRuleEntry = [
                'label' => $rangeLabel,
                'value' => $primaryRuleValue,
            ];
            if ($detailParts !== []) {
                $delayRuleEntry['details'] = $detailParts;
            }

            $delayRuleItems[] = $delayRuleEntry;
        }

        return [
            [
                'title' => 'Assenze',
                'items' => [
                    [
                        'label' => 'Ore annuali massime',
                        'value' => (string) ($absence['max_annual_hours'] ?? '-'),
                    ],
                    [
                        'label' => 'Soglia avviso ore',
                        'value' => (string) ($absence['warning_threshold_hours'] ?? '-'),
                    ],
                    [
                        'label' => 'Firma tutore obbligatoria',
                        'value' => ! empty($absence['guardian_signature_required']) ? 'Si' : 'No',
                    ],
                    [
                        'label' => 'Giorni minimi certificato medico',
                        'value' => (string) ($absence['medical_certificate_days'] ?? '-'),
                    ],
                    [
                        'label' => 'Giorni massimi certificato medico',
                        'value' => (string) ($absence['medical_certificate_max_days'] ?? '-'),
                    ],
                    [
                        'label' => 'Giorni lavorativi countdown assenza',
                        'value' => (string) ($absence['absence_countdown_days'] ?? '-'),
                    ],
                ],
            ],
            [
                'title' => 'Motivazioni assenze',
                'items' => $reasonItems !== []
                    ? $reasonItems
                    : [[
                        'label' => 'Nessuna motivazione configurata',
                        'value' => '-',
                    ]],
            ],
            [
                'title' => 'Ritardi',
                'items' => [
                    [
                        'label' => 'Soglia minuti ritardo',
                        'value' => (string) ($delay['minutes_threshold'] ?? '-'),
                    ],
                    [
                        'label' => 'Firma tutore obbligatoria',
                        'value' => ! empty($delay['guardian_signature_required']) ? 'Si' : 'No',
                    ],
                    [
                        'label' => 'Giorni lavorativi giustificazione',
                        'value' => (string) ($delay['justification_business_days'] ?? '-'),
                    ],
                    [
                        'label' => 'Preavviso scadenza (giorni lavorativi)',
                        'value' => (string) ($delay['pre_expiry_warning_business_days'] ?? '-'),
                    ],
                ],
            ],
            [
                'title' => 'Regole ritardi',
                'items' => $delayRuleItems !== []
                    ? $delayRuleItems
                    : [[
                        'label' => 'Nessuna regola ritardi configurata',
                        'value' => '-',
                    ]],
            ],
        ];
    }

    /**
     * @return array<int,mixed>
     */
    private function normalizeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if ($value instanceof \Illuminate\Support\Collection) {
            return array_values($value->all());
        }

        if ($value instanceof \Traversable) {
            return array_values(iterator_to_array($value, false));
        }

        return [];
    }

    private function translateDelayActionType(string $type): string
    {
        $normalized = strtolower(trim($type));

        return match ($normalized) {
            'none' => 'Nessuna azione',
            'notify_student' => 'Notifica allievo',
            'notify_guardian' => 'Notifica tutore',
            'notify_teacher' => 'Notifica docente di classe',
            'extra_activity_notice' => 'Possibile attivita extrascolastica (>=60 min)',
            'conduct_penalty' => 'Proposta penalita nota di condotta',
            default => ucfirst(str_replace('_', ' ', $normalized)),
        };
    }
}
