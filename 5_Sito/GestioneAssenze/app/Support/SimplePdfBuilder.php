<?php

namespace App\Support;

class SimplePdfBuilder
{
    /**
     * @param  array<int,string>  $lines
     */
    public function build(array $lines, string $title): string
    {
        $pages = $this->buildPages($title, $lines);

        return $this->renderDocument($pages, [
            'F1' => 'Helvetica',
        ]);
    }

    /**
     * @param  array<int,array{title:string,items:array<int,array{label:string,value:string}>}>  $sections
     */
    public function buildRulesDocument(
        array $sections,
        string $title = 'Regole sistema',
        ?string $generatedAt = null
    ): string {
        $pageWidth = 595.0;
        $pageHeight = 842.0;
        $margin = 32.0;
        $contentWidth = $pageWidth - ($margin * 2.0);
        $headerHeight = 82.0;
        $footerReserve = 28.0;
        $sectionGap = 12.0;

        $colors = [
            'stroke_primary' => [0.18, 0.30, 0.48],
            'stroke_secondary' => [0.82, 0.86, 0.91],
            'fill_page' => [0.96, 0.97, 0.99],
            'fill_section' => [1.0, 1.0, 1.0],
            'fill_header' => [0.93, 0.96, 0.99],
            'fill_alt_row' => [0.965, 0.975, 0.988],
            'fill_title_band' => [0.18, 0.30, 0.48],
            'text' => [0.12, 0.16, 0.22],
            'text_muted' => [0.36, 0.41, 0.47],
            'white' => [1.0, 1.0, 1.0],
        ];
        $strokes = [
            'outer' => 0.34,
            'inner' => 0.18,
        ];
        $fontSizes = [
            'title' => 15.5,
            'subtitle' => 9.5,
            'section' => 9.8,
            'label' => 8.3,
            'body' => 9.3,
            'table' => 8.4,
            'footer' => 7.6,
        ];

        $safeTitle = trim($title) !== '' ? trim($title) : 'Regole sistema';
        $safeGeneratedAt = trim((string) $generatedAt);
        if ($safeGeneratedAt === '') {
            $safeGeneratedAt = date('d/m/Y H:i');
        }
        $rulesLogo = $this->loadJpegImageAsset(public_path('logo.jpg'));
        $rulesLogoResource = $rulesLogo !== null ? 'RLOGO' : null;
        $rulesImageAssets = $rulesLogo !== null ? ['RLOGO' => $rulesLogo] : [];

        $normalizedSections = [];
        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $sectionTitle = trim((string) ($section['title'] ?? 'Sezione'));
            if ($sectionTitle === '') {
                $sectionTitle = 'Sezione';
            }

            $items = [];
            $sourceItems = is_array($section['items'] ?? null) ? $section['items'] : [];
            foreach ($sourceItems as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $label = trim((string) ($item['label'] ?? ''));
                $value = trim((string) ($item['value'] ?? ''));
                if ($label === '' && $value === '') {
                    continue;
                }

                $items[] = [
                    'label' => $label !== '' ? $label : '-',
                    'value' => $value !== '' ? $value : '-',
                ];
            }

            if ($items === []) {
                $items[] = [
                    'label' => 'Nessun dato',
                    'value' => '-',
                ];
            }

            $normalizedSections[] = [
                'title' => $sectionTitle,
                'items' => $items,
            ];
        }

        if ($normalizedSections === []) {
            $normalizedSections[] = [
                'title' => 'Regole',
                'items' => [[
                    'label' => 'Nessuna regola configurata',
                    'value' => '-',
                ]],
            ];
        }

        $pages = [];
        $pageNumber = 1;
        $commands = [];
        $currentTop = $this->drawRulesPageHeader(
            $commands,
            $safeTitle,
            $safeGeneratedAt,
            $pageNumber,
            $pageWidth,
            $pageHeight,
            $margin,
            $headerHeight,
            $colors,
            $strokes,
            $fontSizes,
            $rulesLogoResource,
            $rulesLogo
        );

        foreach ($normalizedSections as $section) {
            $items = $section['items'];
            $sectionHeight = max(
                126.0,
                $this->estimateKeyValueTableHeight(
                    $contentWidth,
                    is_array($items) ? $items : [],
                    $fontSizes,
                    16.0
                ) + 12.0
            );
            $minimumBottomY = $margin + $footerReserve;

            if (($currentTop - $sectionHeight) < $minimumBottomY) {
                $this->drawRulesPageFooter(
                    $commands,
                    $pageNumber,
                    $margin,
                    $contentWidth,
                    $colors,
                    $fontSizes
                );
                $pages[] = implode("\n", $commands);

                $pageNumber++;
                $commands = [];
                $currentTop = $this->drawRulesPageHeader(
                    $commands,
                    $safeTitle,
                    $safeGeneratedAt,
                    $pageNumber,
                    $pageWidth,
                    $pageHeight,
                    $margin,
                    $headerHeight,
                    $colors,
                    $strokes,
                    $fontSizes,
                    $rulesLogoResource,
                    $rulesLogo
                );
            }

            $sectionBottom = $currentTop - $sectionHeight;
            $this->setFillColor($commands, $colors['fill_section']);
            $this->drawRectFill($commands, $margin, $sectionBottom, $contentWidth, $sectionHeight);
            $this->drawKeyValueTable(
                $commands,
                $margin,
                $sectionBottom,
                $contentWidth,
                $sectionHeight,
                trim((string) $section['title']),
                is_array($items) ? $items : [],
                $colors,
                $strokes,
                $fontSizes
            );

            $currentTop = $sectionBottom - $sectionGap;
        }

        $this->drawRulesPageFooter(
            $commands,
            $pageNumber,
            $margin,
            $contentWidth,
            $colors,
            $fontSizes
        );
        $pages[] = implode("\n", $commands);

        return $this->renderDocument(
            $pages,
            [
                'F1' => 'Helvetica',
                'F2' => 'Helvetica-Bold',
                'F3' => 'Helvetica-Oblique',
            ],
            $rulesImageAssets
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function buildSchoolMonthlyReport(array $payload): string
    {
        $layout = MonthlyReportPdfLayout::spec();
        $sections = $layout['sections'];
        $colors = $layout['colors'];
        $strokes = $layout['strokes'];
        $fontSizes = $layout['fonts']['sizes'];

        $schoolName = trim((string) ($payload['school_name'] ?? 'Istituto scolastico'));
        if ($schoolName === '') {
            $schoolName = 'Istituto scolastico';
        }

        $reportCode = trim((string) ($payload['report_code'] ?? '-')) ?: '-';
        $generatedAt = trim((string) ($payload['generated_at'] ?? '-')) ?: '-';
        $studentName = trim((string) ($payload['student_name'] ?? '-')) ?: '-';
        $classLabel = trim((string) ($payload['class_label'] ?? '-')) ?: '-';
        $monthLabel = trim((string) ($payload['month_label'] ?? '-')) ?: '-';
        $schoolYear = trim((string) ($payload['school_year'] ?? '-')) ?: '-';
        $summaryRows = is_array($payload['summary_rows'] ?? null) ? $payload['summary_rows'] : [];
        $hoursRows = is_array($payload['hours_rows'] ?? null) ? $payload['hours_rows'] : [];
        $medicalRows = is_array($payload['medical_rows'] ?? null) ? $payload['medical_rows'] : [];
        $notes = is_array($payload['notes'] ?? null) ? $payload['notes'] : [];
        $hoursSectionTitle = trim((string) ($payload['hours_section_title'] ?? ''));
        if ($hoursSectionTitle === '') {
            $hoursSectionTitle = 'Situazione limite annuale';
        }
        $monthlyLogo = $this->loadJpegImageAsset(public_path('logo.jpg'));
        $monthlyLogoResource = $monthlyLogo !== null ? 'MLOGO' : null;
        $monthlyImageAssets = $monthlyLogo !== null ? ['MLOGO' => $monthlyLogo] : [];

        $commands = [];
        $this->setFillColor($commands, $colors['fill_page']);
        $this->drawRectFill(
            $commands,
            0.0,
            0.0,
            (float) $layout['page']['width'],
            (float) $layout['page']['height']
        );

        foreach (['header', 'student_info', 'summary', 'hours_40', 'medical', 'discipline_notes', 'guardian_signature'] as $sectionKey) {
            $section = $sections[$sectionKey];
            $sectionFill = $sectionKey === 'header'
                ? $colors['white']
                : $colors['fill_section'];
            $this->setFillColor($commands, $sectionFill);
            $this->drawRectFill(
                $commands,
                (float) $section['x'],
                (float) $section['y'],
                (float) $section['w'],
                (float) $section['h']
            );
        }

        $this->setStrokeColor($commands, $colors['stroke_secondary']);
        $this->setLineWidth($commands, (float) $strokes['inner']);

        // 1) Intestazione con banda titolo
        $header = $sections['header'];
        $logo = $header['logo'];
        $bandHeight = (float) ($header['title_band_h'] ?? 24.0);
        $bandY = (float) $header['y'] + (float) $header['h'] - $bandHeight;

        $this->setFillColor($commands, $colors['fill_band']);
        $this->drawRectFill(
            $commands,
            (float) $header['x'],
            $bandY,
            (float) $header['w'],
            $bandHeight
        );

        $this->setStrokeColor($commands, $colors['stroke_secondary']);
        $this->setLineWidth($commands, (float) $strokes['inner']);
        $this->drawLine(
            $commands,
            (float) $header['x'],
            $bandY,
            (float) $header['x'] + (float) $header['w'],
            $bandY
        );

        $this->setFillColor($commands, $colors['white']);
        $this->drawRectFill($commands, (float) $logo['x'], (float) $logo['y'], (float) $logo['w'], (float) $logo['h']);
        $this->setStrokeColor($commands, $colors['stroke_secondary']);
        $this->drawRectStroke($commands, (float) $logo['x'], (float) $logo['y'], (float) $logo['w'], (float) $logo['h']);
        if ($monthlyLogoResource !== null && is_array($monthlyLogo)) {
            [$logoX, $logoY, $logoWidth, $logoHeight] = $this->fitImageInBox(
                (float) $logo['x'],
                (float) $logo['y'],
                (float) $logo['w'],
                (float) $logo['h'],
                (float) ($monthlyLogo['width'] ?? 0),
                (float) ($monthlyLogo['height'] ?? 0),
                2.0
            );
            $this->drawImage(
                $commands,
                $monthlyLogoResource,
                $logoX,
                $logoY,
                $logoWidth,
                $logoHeight
            );
        } else {
            $this->drawTextCentered(
                $commands,
                'F2',
                8.5,
                (float) $logo['x'] + ((float) $logo['w'] / 2),
                (float) $logo['y'] + 24.0,
                'SAMT INFORMATICA',
                $colors['text_muted']
            );
        }

        $headerTextStartX = (float) $logo['x'] + (float) $logo['w'] + 14.0;
        $headerTextRightX = (float) $header['x'] + (float) $header['w'] - 10.0;

        $this->drawTextCentered(
            $commands,
            'F2',
            (float) $fontSizes['title'],
            (float) $header['x'] + ((float) $header['w'] / 2),
            $bandY + 8.0,
            'REPORT MENSILE ASSENZE, RITARDI E CONGEDI',
            $colors['white']
        );
        $this->drawText(
            $commands,
            'F2',
            12.0,
            $headerTextStartX,
            $bandY - 15.0,
            $schoolName,
            $colors['text']
        );
        $this->drawText(
            $commands,
            'F1',
            (float) $fontSizes['subtitle'],
            $headerTextStartX,
            $bandY - 29.0,
            'Report mensile studente',
            $colors['text_muted']
        );
        $this->drawTextRight(
            $commands,
            'F1',
            8.5,
            $headerTextRightX,
            $bandY - 15.0,
            'Codice report: '.$reportCode,
            $colors['text']
        );
        $this->drawTextRight(
            $commands,
            'F1',
            8.5,
            $headerTextRightX,
            $bandY - 29.0,
            'Data generazione: '.$generatedAt,
            $colors['text']
        );
        $this->drawTextRight(
            $commands,
            'F1',
            8.5,
            $headerTextRightX,
            $bandY - 43.0,
            'Mese di riferimento: '.$monthLabel,
            $colors['text_muted']
        );

        // 2) Informazioni studente (griglia 2x2)
        $studentSection = $sections['student_info'];
        $this->drawStudentInfoGrid(
            $commands,
            (float) $studentSection['x'],
            (float) $studentSection['y'],
            (float) $studentSection['w'],
            (float) $studentSection['h'],
            (float) $studentSection['header_h'],
            [
                ['label' => 'Studente', 'value' => $studentName],
                ['label' => 'Classe', 'value' => $classLabel],
                ['label' => 'Mese di riferimento', 'value' => $monthLabel],
                ['label' => 'Anno scolastico', 'value' => $schoolYear],
            ],
            $colors,
            $strokes,
            $fontSizes
        );

        // 3) Quadro sintetico, 4) limite annuale ore, 5) Certificati
        $this->drawKeyValueTable(
            $commands,
            (float) $sections['summary']['x'],
            (float) $sections['summary']['y'],
            (float) $sections['summary']['w'],
            (float) $sections['summary']['h'],
            'Quadro sintetico del mese',
            $summaryRows,
            $colors,
            $strokes,
            $fontSizes
        );
        $this->drawKeyValueTable(
            $commands,
            (float) $sections['hours_40']['x'],
            (float) $sections['hours_40']['y'],
            (float) $sections['hours_40']['w'],
            (float) $sections['hours_40']['h'],
            $hoursSectionTitle,
            $hoursRows,
            $colors,
            $strokes,
            $fontSizes
        );
        $this->drawKeyValueTable(
            $commands,
            (float) $sections['medical']['x'],
            (float) $sections['medical']['y'],
            (float) $sections['medical']['w'],
            (float) $sections['medical']['h'],
            'Certificati e firme',
            $medicalRows,
            $colors,
            $strokes,
            $fontSizes
        );

        // 6) Azioni disciplinari o note
        $actions = $sections['discipline_notes'];
        $this->drawSectionHeader(
            $commands,
            (float) $actions['x'],
            (float) $actions['y'],
            (float) $actions['w'],
            (float) $actions['h'],
            (float) $actions['header_h'],
            'Azioni disciplinari o note',
            $colors,
            $strokes,
            $fontSizes
        );
        $notesPadding = (float) ($actions['padding'] ?? 8.0);
        $notesAreaTop = (float) $actions['y'] + (float) $actions['h'] - (float) $actions['header_h'] - 10.0;
        $notesAreaBottom = (float) $actions['y'] + $notesPadding;
        $lineY = $notesAreaTop;
        $notesX = (float) $actions['x'] + $notesPadding;
        $maxNoteChars = max(
            45,
            (int) floor(((float) $actions['w'] - ($notesPadding * 2.0)) / 4.45)
        );
        $notesFontSize = 7.2;

        if ($notes === []) {
            $this->drawText(
                $commands,
                'F3',
                $notesFontSize,
                $notesX,
                $lineY,
                'Nessuna azione disciplinare o nota nel mese.',
                $colors['text_muted']
            );
        } else {
            foreach ($notes as $note) {
                $wrapped = $this->wrapLine('- '.trim((string) $note), $maxNoteChars);
                foreach ($wrapped as $wrappedLine) {
                    if ($lineY < $notesAreaBottom) {
                        break 2;
                    }
                    $this->drawText(
                        $commands,
                        'F1',
                        $notesFontSize,
                        $notesX,
                        $lineY,
                        $wrappedLine,
                        $colors['text']
                    );
                    $lineY -= 9.3;
                }
                $lineY -= 1.0;
            }
        }

        // 7) Firma tutore
        $signature = $sections['guardian_signature'];
        $this->drawSectionHeader(
            $commands,
            (float) $signature['x'],
            (float) $signature['y'],
            (float) $signature['w'],
            (float) $signature['h'],
            (float) ($signature['header_h'] ?? 16.0),
            'Sezione firma tutore',
            $colors,
            $strokes,
            $fontSizes
        );
        $signatureHeaderHeight = (float) ($signature['header_h'] ?? 16.0);
        $signatureBodyTop = (float) $signature['y'] + (float) $signature['h'] - $signatureHeaderHeight;
        $signatureLeft = (float) $signature['x'] + 10.0;
        $signatureRight = (float) $signature['x'] + (float) $signature['w'] - 10.0;

        $this->drawText(
            $commands,
            'F1',
            (float) $fontSizes['table'],
            $signatureLeft,
            $signatureBodyTop - 16.0,
            'Conferma presa visione da parte del genitore/tutore.',
            $colors['text']
        );
        $this->drawText(
            $commands,
            'F2',
            (float) $fontSizes['label'],
            $signatureLeft,
            $signatureBodyTop - 35.0,
            'Nome e cognome tutore:',
            $colors['text']
        );
        $this->drawText(
            $commands,
            'F2',
            (float) $fontSizes['label'],
            $signatureLeft,
            $signatureBodyTop - 59.0,
            'Data:',
            $colors['text']
        );
        $this->drawText(
            $commands,
            'F2',
            (float) $fontSizes['label'],
            $signatureLeft + 198.0,
            $signatureBodyTop - 59.0,
            'Firma del tutore:',
            $colors['text']
        );

        $this->setStrokeColor($commands, $colors['stroke_primary']);
        $this->setLineWidth($commands, (float) $strokes['signature']);
        $this->drawLine(
            $commands,
            $signatureLeft + 118.0,
            $signatureBodyTop - 38.0,
            $signatureRight,
            $signatureBodyTop - 38.0
        );
        $this->drawLine(
            $commands,
            $signatureLeft + 34.0,
            $signatureBodyTop - 62.0,
            $signatureLeft + 170.0,
            $signatureBodyTop - 62.0
        );
        $this->drawLine(
            $commands,
            $signatureLeft + 300.0,
            $signatureBodyTop - 62.0,
            $signatureRight,
            $signatureBodyTop - 62.0
        );

        // Bordi esterni delle sezioni testuali, stesso colore dei bordi tabella.
        $this->setStrokeColor($commands, $colors['stroke_secondary']);
        $this->setLineWidth($commands, (float) $strokes['inner']);
        $this->drawRectStroke(
            $commands,
            (float) $sections['discipline_notes']['x'],
            (float) $sections['discipline_notes']['y'],
            (float) $sections['discipline_notes']['w'],
            (float) $sections['discipline_notes']['h']
        );
        $this->drawRectStroke(
            $commands,
            (float) $sections['guardian_signature']['x'],
            (float) $sections['guardian_signature']['y'],
            (float) $sections['guardian_signature']['w'],
            (float) $sections['guardian_signature']['h']
        );

        $content = implode("\n", $commands);

        return $this->renderDocument([$content], [
            'F1' => 'Helvetica',
            'F2' => 'Helvetica-Bold',
            'F3' => 'Helvetica-Oblique',
        ], $monthlyImageAssets);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function buildLeaveForwardingDocument(array $payload): string
    {
        $sanitize = function (mixed $value, string $fallback = '-'): string {
            $text = trim((string) $value);

            return $text !== '' ? $text : $fallback;
        };

        $leaveCode = $sanitize($payload['leave_code'] ?? '-', '-');
        $statusLabel = $sanitize($payload['status_label'] ?? '-', '-');
        $generatedAt = $sanitize($payload['generated_at'] ?? date('d/m/Y H:i'), '-');
        $studentName = $sanitize($payload['student_name'] ?? '-', '-');
        $periodStart = $sanitize($payload['period_start'] ?? '-', '-');
        $periodEnd = $sanitize($payload['period_end'] ?? '-', '-');
        $periodLabel = $periodStart === $periodEnd ? $periodStart : $periodStart.' - '.$periodEnd;
        $requestedHours = max((int) ($payload['requested_hours'] ?? 0), 0);
        $requestedHoursLabel = $requestedHours.' '.($requestedHours === 1 ? 'ora' : 'ore');
        $requestedLessonsLabel = $sanitize($payload['requested_lessons_label'] ?? '', '');
        if ($requestedLessonsLabel === '') {
            $requestedLessonsLabel = '-';
        }
        $destination = $sanitize($payload['destination'] ?? '-', '-');
        $reason = $sanitize($payload['reason'] ?? '-', '-');
        $countHours = (bool) ($payload['count_hours'] ?? true);
        $countHoursLabel = $countHours
            ? AnnualHoursLimitLabels::included()
            : AnnualHoursLimitLabels::excludedMasculine();
        $countHoursComment = $sanitize($payload['count_hours_comment'] ?? '', '');
        $workflowComment = $sanitize($payload['workflow_comment'] ?? '', '');
        $documentationPresent = (bool) ($payload['documentation_present'] ?? false);
        $documentationUploadedAt = $sanitize($payload['documentation_uploaded_at'] ?? '', '');
        $guardianSignedBy = $sanitize($payload['guardian_signed_by'] ?? '', '');
        $guardianSignedAt = $sanitize($payload['guardian_signed_at'] ?? '', '');

        $historyEntries = is_array($payload['history'] ?? null) ? $payload['history'] : [];
        $historyLines = [];
        foreach ($historyEntries as $entry) {
            $line = trim((string) $entry);
            if ($line !== '') {
                $historyLines[] = $line;
            }
        }
        if ($historyLines === []) {
            $historyLines[] = 'Nessuna decisione registrata.';
        }

        $detailsRows = [
            ['label' => 'Ore richieste', 'value' => $requestedHoursLabel],
            ['label' => 'Periodi scolastici', 'value' => $requestedLessonsLabel],
            ['label' => 'Destinazione', 'value' => $destination],
            ['label' => 'Motivo', 'value' => $reason],
            ['label' => AnnualHoursLimitLabels::countedLabel(), 'value' => $countHoursLabel],
            ['label' => AnnualHoursLimitLabels::countedNoteLabel(), 'value' => $countHoursComment !== '' ? $countHoursComment : '-'],
        ];

        $verificationRows = [
            ['label' => 'Stato documentazione', 'value' => $documentationPresent ? 'Presente' : 'Assente'],
            ['label' => 'Caricata il', 'value' => $documentationUploadedAt !== '' ? $documentationUploadedAt : '-'],
            ['label' => 'Firma tutore', 'value' => $guardianSignedBy !== '' ? $guardianSignedBy : 'Assente'],
            ['label' => 'Data firma tutore', 'value' => $guardianSignedAt !== '' ? $guardianSignedAt : '-'],
            ['label' => 'Commento workflow', 'value' => $workflowComment !== '' ? $workflowComment : '-'],
        ];

        $pageWidth = 595.0;
        $pageHeight = 842.0;
        $margin = 32.0;
        $contentWidth = $pageWidth - ($margin * 2.0);
        $headerHeight = 88.0;

        $colors = [
            'stroke_primary' => [0.18, 0.30, 0.48],
            'stroke_secondary' => [0.82, 0.86, 0.91],
            'fill_page' => [0.96, 0.97, 0.99],
            'fill_section' => [1.0, 1.0, 1.0],
            'fill_header' => [0.93, 0.96, 0.99],
            'fill_title_band' => [0.18, 0.30, 0.48],
            'fill_alt_row' => [0.965, 0.975, 0.988],
            'text' => [0.12, 0.16, 0.22],
            'text_muted' => [0.36, 0.41, 0.47],
            'white' => [1.0, 1.0, 1.0],
        ];
        $strokes = [
            'outer' => 0.34,
            'inner' => 0.18,
        ];
        $fontSizes = [
            'title' => 15.2,
            'subtitle' => 9.2,
            'section' => 9.4,
            'label' => 8.2,
            'body' => 8.6,
            'table' => 8.2,
            'footer' => 7.4,
        ];

        $pages = [];
        $historyIndex = 0;
        $pageNumber = 1;
        $historyTotal = count($historyLines);

        do {
            $commands = [];

            $this->setFillColor($commands, $colors['fill_page']);
            $this->drawRectFill($commands, 0.0, 0.0, $pageWidth, $pageHeight);

            $headerY = $pageHeight - $margin - $headerHeight;
            $titleBandHeight = 30.0;
            $titleBandY = $headerY + $headerHeight - $titleBandHeight;

            $this->setFillColor($commands, $colors['fill_section']);
            $this->drawRectFill($commands, $margin, $headerY, $contentWidth, $headerHeight);
            $this->setFillColor($commands, $colors['fill_title_band']);
            $this->drawRectFill($commands, $margin, $titleBandY, $contentWidth, $titleBandHeight);

            $this->setStrokeColor($commands, $colors['stroke_secondary']);
            $this->setLineWidth($commands, (float) $strokes['inner']);
            $this->drawRectStroke($commands, $margin, $headerY, $contentWidth, $headerHeight);
            $this->drawLine($commands, $margin, $titleBandY, $margin + $contentWidth, $titleBandY);

            $this->drawText(
                $commands,
                'F2',
                (float) $fontSizes['title'],
                $margin + 12.0,
                $titleBandY + 9.0,
                'INOLTRO RICHIESTA CONGEDO IN DIREZIONE',
                $colors['white']
            );
            $this->drawTextRight(
                $commands,
                'F1',
                (float) $fontSizes['subtitle'],
                $margin + $contentWidth - 12.0,
                $titleBandY + 11.0,
                'Pagina '.$pageNumber,
                $colors['white']
            );
            $this->drawText(
                $commands,
                'F2',
                (float) $fontSizes['subtitle'],
                $margin + 12.0,
                $headerY + 28.0,
                'Pratica '.$leaveCode,
                $colors['text']
            );
            $this->drawTextRight(
                $commands,
                'F1',
                (float) $fontSizes['subtitle'],
                $margin + $contentWidth - 12.0,
                $headerY + 28.0,
                'Generato il: '.$generatedAt,
                $colors['text_muted']
            );

            $this->drawStudentInfoGrid(
                $commands,
                $margin,
                648.0,
                $contentWidth,
                94.0,
                16.0,
                [
                    ['label' => 'Studente', 'value' => $studentName],
                    ['label' => 'Codice congedo', 'value' => $leaveCode],
                    ['label' => 'Periodo', 'value' => $periodLabel],
                    ['label' => 'Stato pratica', 'value' => $statusLabel],
                ],
                $colors,
                $strokes,
                $fontSizes
            );

            $this->drawKeyValueTable(
                $commands,
                $margin,
                474.0,
                $contentWidth,
                162.0,
                'Dettagli richiesta',
                $detailsRows,
                $colors,
                $strokes,
                $fontSizes
            );

            $this->drawKeyValueTable(
                $commands,
                $margin,
                366.0,
                $contentWidth,
                96.0,
                'Verifiche e allegati',
                $verificationRows,
                $colors,
                $strokes,
                $fontSizes
            );

            $historyY = 74.0;
            $historyHeight = 280.0;
            $historyHeaderHeight = 16.0;
            $this->drawSectionHeader(
                $commands,
                $margin,
                $historyY,
                $contentWidth,
                $historyHeight,
                $historyHeaderHeight,
                'Storico workflow e azioni',
                $colors,
                $strokes,
                $fontSizes
            );

            $historyTextY = ($historyY + $historyHeight) - $historyHeaderHeight - 10.0;
            $historyBottomY = $historyY + 12.0;
            $historyTextX = $margin + 10.0;
            $historyMaxChars = max(
                50,
                (int) floor(($contentWidth - 20.0) / ((float) $fontSizes['body'] * 0.51))
            );
            $drawnRows = 0;

            while ($historyIndex < $historyTotal) {
                $entryText = ($historyIndex + 1).'. '.$historyLines[$historyIndex];
                $wrappedLines = $this->wrapLine($entryText, $historyMaxChars);
                $requiredHeight = (count($wrappedLines) * 9.2) + 3.0;
                if (($historyTextY - $requiredHeight) < $historyBottomY) {
                    break;
                }

                foreach ($wrappedLines as $wrappedLine) {
                    $this->drawText(
                        $commands,
                        'F1',
                        (float) $fontSizes['body'],
                        $historyTextX,
                        $historyTextY,
                        $wrappedLine,
                        $colors['text']
                    );
                    $historyTextY -= 9.2;
                }

                $historyTextY -= 3.0;
                $historyIndex++;
                $drawnRows++;
            }

            if ($drawnRows === 0) {
                $this->drawText(
                    $commands,
                    'F3',
                    (float) $fontSizes['body'],
                    $historyTextX,
                    $historyTextY,
                    'Nessuna decisione registrata.',
                    $colors['text_muted']
                );
            }

            $this->setStrokeColor($commands, $colors['stroke_secondary']);
            $this->setLineWidth($commands, (float) $strokes['inner']);
            $this->drawRectStroke($commands, $margin, $historyY, $contentWidth, $historyHeight);

            if ($historyIndex < $historyTotal) {
                $this->drawTextRight(
                    $commands,
                    'F3',
                    (float) $fontSizes['body'],
                    $margin + $contentWidth - 10.0,
                    $historyBottomY,
                    'Continua a pagina successiva',
                    $colors['text_muted']
                );
            }

            $footerLineY = $margin - 3.0;
            $footerTextY = $margin - 13.0;
            $this->setStrokeColor($commands, $colors['stroke_secondary']);
            $this->setLineWidth($commands, 0.18);
            $this->drawLine($commands, $margin, $footerLineY, $margin + $contentWidth, $footerLineY);
            $this->drawText(
                $commands,
                'F1',
                (float) $fontSizes['footer'],
                $margin,
                $footerTextY,
                'Documento generato automaticamente per inoltro alla direzione.',
                $colors['text_muted']
            );
            $this->drawTextRight(
                $commands,
                'F1',
                (float) $fontSizes['footer'],
                $margin + $contentWidth,
                $footerTextY,
                'Pag. '.$pageNumber,
                $colors['text_muted']
            );

            $pages[] = implode("\n", $commands);
            $pageNumber++;
        } while ($historyIndex < $historyTotal);

        return $this->renderDocument($pages, [
            'F1' => 'Helvetica',
            'F2' => 'Helvetica-Bold',
            'F3' => 'Helvetica-Oblique',
        ]);
    }

    /**
     * @param  array<int,string>  $lines
     * @return array<int,string>
     */
    private function buildPages(string $title, array $lines): array
    {
        $pages = [];
        $currentPageLines = [];
        $header = strtoupper(trim($title));
        $rows = $this->wrapLine($header, 88);
        $rows[] = str_repeat('-', 88);
        $rows[] = '';

        foreach ($rows as $row) {
            $currentPageLines[] = $row;
        }

        foreach ($lines as $line) {
            $wrappedLines = $this->wrapLine((string) $line, 88);

            foreach ($wrappedLines as $wrappedLine) {
                if (count($currentPageLines) >= 47) {
                    $pages[] = $this->renderPageContent($currentPageLines);
                    $currentPageLines = [];
                }

                $currentPageLines[] = $wrappedLine;
            }
        }

        if ($currentPageLines !== []) {
            $pages[] = $this->renderPageContent($currentPageLines);
        }

        return $pages;
    }

    /**
     * @param  array<int,string>  $lines
     */
    private function renderPageContent(array $lines): string
    {
        $left = 42;
        $top = 805;
        $lineStep = 16;
        $fontSize = 11;
        $commands = [];

        foreach ($lines as $index => $line) {
            $y = $top - ($index * $lineStep);
            $escaped = $this->escapeText($line);

            $commands[] = "BT /F1 {$fontSize} Tf {$left} {$y} Td ({$escaped}) Tj ET";
        }

        return implode("\n", $commands);
    }

    /**
     * @param  array<int,string>  $pages
     * @param  array<string,string>  $fonts
     * @param  array<string,array{data:string,width:int,height:int,channels:int}>  $images
     */
    private function renderDocument(array $pages, array $fonts, array $images = []): string
    {
        $pageCount = count($pages);
        if ($pageCount === 0) {
            $pages = [''];
            $pageCount = 1;
        }

        $resourceObjectStart = 3 + ($pageCount * 2);
        $fontReferences = [];
        $resourceIndex = 0;
        foreach ($fonts as $fontResource => $fontName) {
            $fontReferences[$fontResource] = [
                'name' => $fontName,
                'object' => $resourceObjectStart + $resourceIndex,
            ];
            $resourceIndex++;
        }

        $imageReferences = [];
        foreach ($images as $imageResource => $imageDefinition) {
            if (! is_array($imageDefinition)) {
                continue;
            }

            $imageData = (string) ($imageDefinition['data'] ?? '');
            $imageWidth = (int) ($imageDefinition['width'] ?? 0);
            $imageHeight = (int) ($imageDefinition['height'] ?? 0);
            $imageChannels = (int) ($imageDefinition['channels'] ?? 3);

            if ($imageData === '' || $imageWidth <= 0 || $imageHeight <= 0) {
                continue;
            }

            $imageReferences[$imageResource] = [
                'object' => $resourceObjectStart + $resourceIndex,
                'data' => $imageData,
                'width' => $imageWidth,
                'height' => $imageHeight,
                'channels' => $imageChannels,
            ];
            $resourceIndex++;
        }

        $fontResourceParts = [];
        foreach ($fontReferences as $resource => $definition) {
            $fontResourceParts[] = '/'.$resource.' '.$definition['object'].' 0 R';
        }
        $fontResourceBlock = implode(' ', $fontResourceParts);
        $imageResourceParts = [];
        foreach ($imageReferences as $resource => $definition) {
            $imageResourceParts[] = '/'.$resource.' '.$definition['object'].' 0 R';
        }
        $imageResourceBlock = $imageResourceParts !== []
            ? ' /XObject << '.implode(' ', $imageResourceParts).' >>'
            : '';

        /** @var array<int,string> $objects */
        $objects = [];

        $pageReferences = [];
        for ($pageIndex = 0; $pageIndex < $pageCount; $pageIndex++) {
            $pageObjectNumber = 3 + ($pageIndex * 2);
            $contentObjectNumber = $pageObjectNumber + 1;
            $pageReferences[] = $pageObjectNumber.' 0 R';

            $objects[$pageObjectNumber] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << '.$fontResourceBlock.' >>'.$imageResourceBlock.' >> /Contents '.$contentObjectNumber.' 0 R >>';
            $content = $pages[$pageIndex];
            $objects[$contentObjectNumber] = '<< /Length '.strlen($content).' >>'."\n".'stream'."\n".$content."\n".'endstream';
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Count '.$pageCount.' /Kids ['.implode(' ', $pageReferences).'] >>';

        foreach ($fontReferences as $definition) {
            $objects[(int) $definition['object']] = '<< /Type /Font /Subtype /Type1 /BaseFont /'.$definition['name'].' >>';
        }
        foreach ($imageReferences as $definition) {
            $channels = (int) ($definition['channels'] ?? 3);
            $colorSpace = match ($channels) {
                1 => '/DeviceGray',
                4 => '/DeviceCMYK',
                default => '/DeviceRGB',
            };
            $imageData = (string) $definition['data'];
            $objects[(int) $definition['object']] = '<< /Type /XObject /Subtype /Image'
                .' /Width '.(int) $definition['width']
                .' /Height '.(int) $definition['height']
                .' /ColorSpace '.$colorSpace
                .' /BitsPerComponent 8'
                .' /Filter /DCTDecode'
                .' /Length '.strlen($imageData)
                .' >>'."\n".'stream'."\n".$imageData."\n".'endstream';
        }

        ksort($objects);
        $maxObjectNumber = (int) max(array_keys($objects));

        $buffer = "%PDF-1.4\n";
        $offsets = [0 => 0];

        for ($objectNumber = 1; $objectNumber <= $maxObjectNumber; $objectNumber++) {
            $object = $objects[$objectNumber] ?? null;
            if ($object === null) {
                continue;
            }

            $offsets[$objectNumber] = strlen($buffer);
            $buffer .= $objectNumber." 0 obj\n".$object."\nendobj\n";
        }

        $xrefOffset = strlen($buffer);
        $buffer .= 'xref'."\n";
        $buffer .= '0 '.($maxObjectNumber + 1)."\n";
        $buffer .= "0000000000 65535 f \n";

        for ($index = 1; $index <= $maxObjectNumber; $index++) {
            $offset = $offsets[$index] ?? 0;
            $flag = array_key_exists($index, $offsets) ? 'n' : 'f';
            $generation = $flag === 'n' ? '00000' : '65535';
            $buffer .= str_pad((string) $offset, 10, '0', STR_PAD_LEFT)." {$generation} {$flag} \n";
        }

        $buffer .= 'trailer << /Size '.($maxObjectNumber + 1).' /Root 1 0 R >>'."\n";
        $buffer .= 'startxref'."\n";
        $buffer .= $xrefOffset."\n";
        $buffer .= '%%EOF';

        return $buffer;
    }

    private function drawSectionHeader(
        array &$commands,
        float $x,
        float $y,
        float $width,
        float $height,
        float $headerHeight,
        string $title,
        array $colors,
        array $strokes,
        array $fontSizes
    ): void {
        $top = $y + $height;
        $headerY = $top - $headerHeight;
        $this->setFillColor($commands, $colors['fill_header']);
        $this->drawRectFill($commands, $x, $headerY, $width, $headerHeight);
        $this->setStrokeColor($commands, $colors['stroke_secondary']);
        $this->setLineWidth($commands, (float) $strokes['inner']);
        $this->drawLine($commands, $x, $headerY, $x + $width, $headerY);
        $this->drawText(
            $commands,
            'F2',
            (float) $fontSizes['section'],
            $x + 8.0,
            $headerY + 4.0,
            strtoupper($title),
            $colors['text']
        );
        $this->setStrokeColor($commands, $colors['stroke_primary']);
        $this->setLineWidth($commands, (float) $strokes['outer']);
    }

    /**
     * @param  array<string,mixed>  $colors
     * @param  array<string,mixed>  $strokes
     * @param  array<string,mixed>  $fontSizes
     */
    private function drawRulesPageHeader(
        array &$commands,
        string $title,
        string $generatedAt,
        int $pageNumber,
        float $pageWidth,
        float $pageHeight,
        float $margin,
        float $headerHeight,
        array $colors,
        array $strokes,
        array $fontSizes,
        ?string $logoResource = null,
        ?array $logoImage = null
    ): float {
        $contentWidth = $pageWidth - ($margin * 2.0);
        $headerY = $pageHeight - $margin - $headerHeight;
        $bandHeight = 30.0;
        $bandY = $headerY + $headerHeight - $bandHeight;

        $this->setFillColor($commands, $colors['fill_page']);
        $this->drawRectFill($commands, 0.0, 0.0, $pageWidth, $pageHeight);

        $this->setFillColor($commands, $colors['fill_section']);
        $this->drawRectFill($commands, $margin, $headerY, $contentWidth, $headerHeight);

        $this->setFillColor($commands, $colors['fill_title_band']);
        $this->drawRectFill($commands, $margin, $bandY, $contentWidth, $bandHeight);

        $this->setStrokeColor($commands, $colors['stroke_secondary']);
        $this->setLineWidth($commands, (float) $strokes['inner']);
        $this->drawRectStroke($commands, $margin, $headerY, $contentWidth, $headerHeight);
        $this->drawLine($commands, $margin, $bandY, $margin + $contentWidth, $bandY);

        $this->drawText(
            $commands,
            'F2',
            (float) $fontSizes['title'],
            $margin + 12.0,
            $bandY + 9.0,
            strtoupper($title),
            $colors['white']
        );
        $this->drawTextRight(
            $commands,
            'F1',
            (float) $fontSizes['subtitle'],
            $margin + $contentWidth - 12.0,
            $bandY + 11.0,
            'Pagina '.$pageNumber,
            $colors['white']
        );
        $logoBoxX = $margin + 12.0;
        $logoBoxY = $headerY + 6.0;
        $logoBoxWidth = 82.0;
        $logoBoxHeight = 40.0;

        $this->setFillColor($commands, $colors['white']);
        $this->drawRectFill($commands, $logoBoxX, $logoBoxY, $logoBoxWidth, $logoBoxHeight);
        $this->setStrokeColor($commands, $colors['stroke_secondary']);
        $this->setLineWidth($commands, (float) $strokes['inner']);
        $this->drawRectStroke($commands, $logoBoxX, $logoBoxY, $logoBoxWidth, $logoBoxHeight);

        if ($logoResource !== null && is_array($logoImage)) {
            [$drawX, $drawY, $drawWidth, $drawHeight] = $this->fitImageInBox(
                $logoBoxX,
                $logoBoxY,
                $logoBoxWidth,
                $logoBoxHeight,
                (float) ($logoImage['width'] ?? 0),
                (float) ($logoImage['height'] ?? 0),
                2.0
            );
            $this->drawImage(
                $commands,
                $logoResource,
                $drawX,
                $drawY,
                $drawWidth,
                $drawHeight
            );
        } else {
            $this->drawTextCentered(
                $commands,
                'F2',
                8.5,
                $logoBoxX + ($logoBoxWidth / 2.0),
                $logoBoxY + 12.0,
                'SAMT INFORMATICA',
                $colors['text_muted']
            );
        }
        $headerTextStartX = $logoBoxX + $logoBoxWidth + 10.0;
        $this->drawText(
            $commands,
            'F2',
            (float) $fontSizes['subtitle'],
            $headerTextStartX,
            $headerY + 28.0,
            'Riepilogo completo configurazioni',
            $colors['text']
        );
        $this->drawTextRight(
            $commands,
            'F1',
            (float) $fontSizes['subtitle'],
            $margin + $contentWidth - 12.0,
            $headerY + 28.0,
            'Generato il: '.$generatedAt,
            $colors['text_muted']
        );

        return $headerY - 12.0;
    }

    /**
     * @param  array<string,mixed>  $colors
     * @param  array<string,mixed>  $fontSizes
     */
    private function drawRulesPageFooter(
        array &$commands,
        int $pageNumber,
        float $margin,
        float $contentWidth,
        array $colors,
        array $fontSizes
    ): void {
        $lineY = $margin - 4.0;
        $textY = $margin - 14.0;

        $this->setStrokeColor($commands, $colors['stroke_secondary']);
        $this->setLineWidth($commands, 0.18);
        $this->drawLine($commands, $margin, $lineY, $margin + $contentWidth, $lineY);

        $this->drawText(
            $commands,
            'F1',
            (float) $fontSizes['footer'],
            $margin,
            $textY,
            'Documento generato automaticamente dal sistema Gestione Assenze.',
            $colors['text_muted']
        );
        $this->drawTextRight(
            $commands,
            'F1',
            (float) $fontSizes['footer'],
            $margin + $contentWidth,
            $textY,
            'Pag. '.$pageNumber,
            $colors['text_muted']
        );
    }

    /**
     * @param  array<int,array{label:string,value:string}>  $fields
     * @param  array<string,mixed>  $colors
     * @param  array<string,mixed>  $strokes
     * @param  array<string,mixed>  $fontSizes
     */
    private function drawStudentInfoGrid(
        array &$commands,
        float $x,
        float $y,
        float $width,
        float $height,
        float $headerHeight,
        array $fields,
        array $colors,
        array $strokes,
        array $fontSizes
    ): void {
        $this->drawSectionHeader(
            $commands,
            $x,
            $y,
            $width,
            $height,
            $headerHeight,
            'Informazioni studente',
            $colors,
            $strokes,
            $fontSizes
        );

        $bodyHeight = $height - $headerHeight;
        if ($bodyHeight <= 0) {
            return;
        }

        $halfWidth = $width / 2.0;
        $halfHeight = $bodyHeight / 2.0;
        $midX = floor($x + $halfWidth);
        $midY = floor($y + $halfHeight);

        $this->setStrokeColor($commands, $colors['stroke_secondary']);
        $this->setLineWidth($commands, (float) $strokes['inner']);
        $this->drawLine($commands, $midX, $y, $midX, $y + $bodyHeight);
        $this->drawLine($commands, $x, $midY, $x + $width, $midY);

        for ($index = 0; $index < 4; $index++) {
            $field = $fields[$index] ?? ['label' => '-', 'value' => '-'];
            $column = $index % 2;
            $row = intdiv($index, 2);
            $cellX = $x + ($column * $halfWidth);
            $cellTop = ($y + $bodyHeight) - ($row * $halfHeight);
            $labelY = $cellTop - 14.0;
            $valueY = $cellTop - 29.0;

            $this->drawText(
                $commands,
                'F2',
                (float) $fontSizes['label'],
                $cellX + 6.0,
                $labelY,
                trim((string) ($field['label'] ?? '-')) ?: '-',
                $colors['text_muted']
            );

            $valueLines = $this->wrapLine(trim((string) ($field['value'] ?? '-')) ?: '-', 34);
            foreach ($valueLines as $lineIndex => $valueLine) {
                if ($lineIndex >= 2) {
                    break;
                }
                $this->drawText(
                    $commands,
                    'F1',
                    (float) $fontSizes['body'],
                    $cellX + 6.0,
                    $valueY - ($lineIndex * 9.5),
                    $valueLine,
                    $colors['text']
                );
            }
        }

        // Bordo esterno tabella, stesso stile delle linee interne.
        $this->setStrokeColor($commands, $colors['stroke_secondary']);
        $this->setLineWidth($commands, (float) $strokes['inner']);
        $this->drawRectStroke($commands, $x, $y, $width, $height);
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @param  array<string,mixed>  $colors
     * @param  array<string,mixed>  $strokes
     * @param  array<string,mixed>  $fontSizes
     */
    private function drawKeyValueTable(
        array &$commands,
        float $x,
        float $y,
        float $width,
        float $height,
        string $title,
        array $rows,
        array $colors,
        array $strokes,
        array $fontSizes
    ): void {
        $headerHeight = 16.0;
        $this->drawSectionHeader(
            $commands,
            $x,
            $y,
            $width,
            $height,
            $headerHeight,
            $title,
            $colors,
            $strokes,
            $fontSizes
        );

        $bodyHeight = $height - $headerHeight;
        if ($bodyHeight <= 0) {
            return;
        }

        $cellPaddingX = 10.0;
        $cellPaddingTop = 9.0;
        $cellPaddingBottom = 7.0;
        $lineHeight = 8.6;

        if ($rows === []) {
            $this->drawText(
                $commands,
                'F3',
                (float) $fontSizes['table'],
                $x + $cellPaddingX,
                $y + ($bodyHeight / 2.0),
                'Nessun dato disponibile.',
                $colors['text_muted']
            );
            $this->setStrokeColor($commands, $colors['stroke_secondary']);
            $this->setLineWidth($commands, (float) $strokes['inner']);
            $this->drawRectStroke($commands, $x, $y, $width, $height);

            return;
        }

        $labelWidth = floor($width * 0.60);
        $maxLabelChars = max(
            8,
            (int) floor(($labelWidth - ($cellPaddingX * 2.0)) / ((float) $fontSizes['table'] * 0.52))
        );
        $maxValueChars = max(
            8,
            (int) floor((($width - $labelWidth) - ($cellPaddingX * 2.0)) / ((float) $fontSizes['table'] * 0.52))
        );

        $preparedRows = [];
        $totalPreferredHeight = 0.0;
        foreach ($rows as $row) {
            $label = trim((string) ($row['label'] ?? '-')) ?: '-';
            $value = trim((string) ($row['value'] ?? '-')) ?: '-';
            $labelLines = array_slice($this->wrapLine($label, $maxLabelChars), 0, 3);
            $valueLines = array_slice($this->wrapLine($value, $maxValueChars), 0, 4);
            $lineCount = max(count($labelLines), count($valueLines), 1);
            $preferredHeight = max(
                30.0,
                $cellPaddingTop + $cellPaddingBottom + ($lineCount * $lineHeight)
            );

            $preparedRows[] = [
                'label_lines' => $labelLines,
                'value_lines' => $valueLines,
                'preferred_height' => $preferredHeight,
            ];
            $totalPreferredHeight += $preferredHeight;
        }

        if ($preparedRows === []) {
            return;
        }

        $scale = $totalPreferredHeight > 0.0
            ? min(1.0, $bodyHeight / $totalPreferredHeight)
            : 1.0;

        $scaledHeights = [];
        $scaledTotal = 0.0;
        foreach ($preparedRows as $rowData) {
            $scaled = (float) $rowData['preferred_height'] * $scale;
            $scaledHeights[] = $scaled;
            $scaledTotal += $scaled;
        }

        if ($scaledHeights !== [] && $scaledTotal < $bodyHeight) {
            $scaledHeights[count($scaledHeights) - 1] += ($bodyHeight - $scaledTotal);
        }

        $this->setStrokeColor($commands, $colors['stroke_secondary']);
        $this->setLineWidth($commands, (float) $strokes['inner']);
        $separatorX = floor($x + $labelWidth);
        $this->drawLine($commands, $separatorX, $y, $separatorX, $y + $bodyHeight);

        $rowTop = $y + $bodyHeight;
        foreach ($preparedRows as $index => $rowData) {
            $rowHeight = $scaledHeights[$index] ?? 0.0;
            $rowBottom = $rowTop - $rowHeight;
            if ($rowBottom < $y) {
                $rowBottom = $y;
            }

            if ($index % 2 === 1) {
                $this->setFillColor($commands, $colors['fill_alt_row']);
                $this->drawRectFill($commands, $x, $rowBottom, $width, $rowHeight);
            }

            if ($index > 0) {
                $this->setStrokeColor($commands, $colors['stroke_secondary']);
                $this->setLineWidth($commands, (float) $strokes['inner']);
                $this->drawLine($commands, $x, $rowTop, $x + $width, $rowTop);
            }

            $labelY = $rowTop - $cellPaddingTop;
            foreach ($rowData['label_lines'] as $line) {
                if ($labelY < ($rowBottom + $cellPaddingBottom)) {
                    break;
                }
                $this->drawText(
                    $commands,
                    'F2',
                    (float) $fontSizes['table'],
                    $x + $cellPaddingX,
                    $labelY,
                    $line,
                    $colors['text']
                );
                $labelY -= $lineHeight;
            }

            $valueY = $rowTop - $cellPaddingTop;
            foreach ($rowData['value_lines'] as $line) {
                if ($valueY < ($rowBottom + $cellPaddingBottom)) {
                    break;
                }
                $this->drawTextRight(
                    $commands,
                    'F1',
                    (float) $fontSizes['table'],
                    $x + $width - $cellPaddingX,
                    $valueY,
                    $line,
                    $colors['text']
                );
                $valueY -= $lineHeight;
            }

            $rowTop = $rowBottom;
        }

        // Bordo esterno tabella, stesso stile delle linee interne.
        $this->setStrokeColor($commands, $colors['stroke_secondary']);
        $this->setLineWidth($commands, (float) $strokes['inner']);
        $this->drawRectStroke($commands, $x, $y, $width, $height);
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @param  array<string,mixed>  $fontSizes
     */
    private function estimateKeyValueTableHeight(
        float $width,
        array $rows,
        array $fontSizes,
        float $headerHeight = 16.0
    ): float {
        if ($rows === []) {
            return $headerHeight + 30.0;
        }

        $cellPaddingX = 10.0;
        $cellPaddingTop = 9.0;
        $cellPaddingBottom = 7.0;
        $lineHeight = 8.6;

        $labelWidth = floor($width * 0.60);
        $maxLabelChars = max(
            8,
            (int) floor(($labelWidth - ($cellPaddingX * 2.0)) / ((float) $fontSizes['table'] * 0.52))
        );
        $maxValueChars = max(
            8,
            (int) floor((($width - $labelWidth) - ($cellPaddingX * 2.0)) / ((float) $fontSizes['table'] * 0.52))
        );

        $height = $headerHeight;
        foreach ($rows as $row) {
            $label = trim((string) ($row['label'] ?? '-')) ?: '-';
            $value = trim((string) ($row['value'] ?? '-')) ?: '-';
            $labelLines = $this->wrapLine($label, $maxLabelChars);
            $valueLines = $this->wrapLine($value, $maxValueChars);
            $lineCount = max(count($labelLines), count($valueLines), 1);
            $height += max(
                30.0,
                $cellPaddingTop + $cellPaddingBottom + ($lineCount * $lineHeight)
            );
        }

        return $height;
    }

    private function setLineWidth(array &$commands, float $width): void
    {
        $commands[] = $this->formatFloat($width).' w';
    }

    /**
     * @param  array{0:float|int,1:float|int,2:float|int}  $rgb
     */
    private function setStrokeColor(array &$commands, array $rgb): void
    {
        $commands[] = $this->formatFloat((float) $rgb[0]).' '
            .$this->formatFloat((float) $rgb[1]).' '
            .$this->formatFloat((float) $rgb[2]).' RG';
    }

    /**
     * @param  array{0:float|int,1:float|int,2:float|int}  $rgb
     */
    private function setFillColor(array &$commands, array $rgb): void
    {
        $commands[] = $this->formatFloat((float) $rgb[0]).' '
            .$this->formatFloat((float) $rgb[1]).' '
            .$this->formatFloat((float) $rgb[2]).' rg';
    }

    private function drawRectStroke(array &$commands, float $x, float $y, float $w, float $h): void
    {
        $commands[] = $this->formatFloat($x).' '
            .$this->formatFloat($y).' '
            .$this->formatFloat($w).' '
            .$this->formatFloat($h).' re S';
    }

    private function drawRectFill(array &$commands, float $x, float $y, float $w, float $h): void
    {
        $commands[] = $this->formatFloat($x).' '
            .$this->formatFloat($y).' '
            .$this->formatFloat($w).' '
            .$this->formatFloat($h).' re f';
    }

    private function drawLine(array &$commands, float $x1, float $y1, float $x2, float $y2): void
    {
        $commands[] = $this->formatFloat($x1).' '
            .$this->formatFloat($y1).' m '
            .$this->formatFloat($x2).' '
            .$this->formatFloat($y2).' l S';
    }

    private function drawImage(
        array &$commands,
        string $imageResource,
        float $x,
        float $y,
        float $width,
        float $height
    ): void {
        if (trim($imageResource) === '' || $width <= 0.0 || $height <= 0.0) {
            return;
        }

        $commands[] = 'q '
            .$this->formatFloat($width).' 0 0 '
            .$this->formatFloat($height).' '
            .$this->formatFloat($x).' '
            .$this->formatFloat($y).' cm /'
            .$imageResource.' Do Q';
    }

    /**
     * @return array{0:float,1:float,2:float,3:float}
     */
    private function fitImageInBox(
        float $boxX,
        float $boxY,
        float $boxWidth,
        float $boxHeight,
        float $imageWidth,
        float $imageHeight,
        float $padding = 0.0
    ): array {
        $innerWidth = max($boxWidth - ($padding * 2.0), 0.0);
        $innerHeight = max($boxHeight - ($padding * 2.0), 0.0);

        if ($innerWidth <= 0.0 || $innerHeight <= 0.0 || $imageWidth <= 0.0 || $imageHeight <= 0.0) {
            return [$boxX, $boxY, max($boxWidth, 0.0), max($boxHeight, 0.0)];
        }

        $scale = min($innerWidth / $imageWidth, $innerHeight / $imageHeight);
        $drawWidth = $imageWidth * $scale;
        $drawHeight = $imageHeight * $scale;
        $drawX = $boxX + $padding + (($innerWidth - $drawWidth) / 2.0);
        $drawY = $boxY + $padding + (($innerHeight - $drawHeight) / 2.0);

        return [$drawX, $drawY, $drawWidth, $drawHeight];
    }

    private function drawText(
        array &$commands,
        string $fontResource,
        float $size,
        float $x,
        float $y,
        string $text,
        ?array $color = null
    ): void {
        if (is_array($color) && count($color) === 3) {
            $this->setFillColor($commands, $color);
        }

        $safeText = $this->normalizePdfText($text);
        $commands[] = 'BT /'.$fontResource.' '.$this->formatFloat($size).' Tf '
            .$this->formatFloat($x).' '.$this->formatFloat($y).' Td '
            .'('.$safeText.') Tj ET';
    }

    private function drawTextCentered(
        array &$commands,
        string $fontResource,
        float $size,
        float $centerX,
        float $y,
        string $text,
        ?array $color = null
    ): void {
        $widthEstimate = $this->estimateTextWidth($text, $size);
        $x = $centerX - ($widthEstimate / 2);

        $this->drawText($commands, $fontResource, $size, $x, $y, $text, $color);
    }

    private function drawTextRight(
        array &$commands,
        string $fontResource,
        float $size,
        float $rightX,
        float $y,
        string $text,
        ?array $color = null
    ): void {
        $widthEstimate = $this->estimateTextWidth($text, $size);
        $x = $rightX - $widthEstimate;
        $this->drawText($commands, $fontResource, $size, $x, $y, $text, $color);
    }

    /**
     * @return array<int,string>
     */
    private function wrapLine(string $line, int $maxLength): array
    {
        $trimmed = trim($line);
        if ($trimmed === '') {
            return [''];
        }

        $wrapped = wordwrap($trimmed, $maxLength, "\n", true);

        return explode("\n", $wrapped);
    }

    /**
     * @return array{data:string,width:int,height:int,channels:int}|null
     */
    private function loadJpegImageAsset(string $path): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $imageInfo = @getimagesize($path);
        if (
            ! is_array($imageInfo)
            || ! isset($imageInfo[0], $imageInfo[1], $imageInfo[2])
            || (int) $imageInfo[2] !== IMAGETYPE_JPEG
        ) {
            return null;
        }

        $binaryData = @file_get_contents($path);
        if ($binaryData === false || $binaryData === '') {
            return null;
        }

        $channels = isset($imageInfo['channels']) ? (int) $imageInfo['channels'] : 3;
        if ($channels < 1 || $channels > 4) {
            $channels = 3;
        }

        return [
            'data' => $binaryData,
            'width' => (int) $imageInfo[0],
            'height' => (int) $imageInfo[1],
            'channels' => $channels,
        ];
    }

    private function escapeText(string $value): string
    {
        $escaped = str_replace('\\', '\\\\', $value);
        $escaped = str_replace('(', '\\(', $escaped);

        return str_replace(')', '\\)', $escaped);
    }

    private function normalizePdfText(string $value): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($value)) ?: '';
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
        if ($converted !== false) {
            $normalized = $converted;
        }

        return $this->escapeText($normalized);
    }

    private function estimateTextWidth(string $value, float $size): float
    {
        $text = trim($value);
        if ($text === '') {
            return 0.0;
        }

        return strlen($text) * ($size * 0.52);
    }

    private function formatFloat(float $value): string
    {
        $formatted = number_format($value, 2, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }
}
