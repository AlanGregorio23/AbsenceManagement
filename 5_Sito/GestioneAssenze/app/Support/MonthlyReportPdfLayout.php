<?php

namespace App\Support;

class MonthlyReportPdfLayout
{
    public const PAGE_WIDTH = 595.0;

    public const PAGE_HEIGHT = 842.0;

    public const MARGIN = 36.0;

    /**
     * @return array<string,mixed>
     */
    public static function spec(): array
    {
        return [
            'page' => [
                'width' => self::PAGE_WIDTH,
                'height' => self::PAGE_HEIGHT,
                'margin' => self::MARGIN,
                'content_width' => 523.0,
                'content_height' => 770.0,
            ],
            'fonts' => [
                'regular' => ['resource' => 'F1', 'name' => 'Helvetica'],
                'bold' => ['resource' => 'F2', 'name' => 'Helvetica-Bold'],
                'italic' => ['resource' => 'F3', 'name' => 'Helvetica-Oblique'],
                'sizes' => [
                    'title' => 15.5,
                    'subtitle' => 10.0,
                    'section' => 10.0,
                    'label' => 8.5,
                    'body' => 9.5,
                    'small' => 7.5,
                    'table' => 8.5,
                ],
            ],
            'strokes' => [
                'outer' => 0.34,
                'inner' => 0.18,
                'signature' => 0.34,
            ],
            'colors' => [
                'stroke_primary' => [0.18, 0.25, 0.36],
                'stroke_secondary' => [0.80, 0.84, 0.89],
                'fill_page' => [1.0, 1.0, 1.0],
                'fill_header' => [0.95, 0.97, 0.99],
                'fill_band' => [0.18, 0.30, 0.48],
                'fill_section' => [1.0, 1.0, 1.0],
                'fill_alt_row' => [0.965, 0.975, 0.988],
                'text' => [0.12, 0.16, 0.22],
                'text_muted' => [0.36, 0.41, 0.47],
                'white' => [1.0, 1.0, 1.0],
            ],
            'sections' => [
                'header' => [
                    'x' => 36.0,
                    'y' => 718.0,
                    'w' => 523.0,
                    'h' => 88.0,
                    'title_band_h' => 24.0,
                    'logo' => ['x' => 48.0, 'y' => 734.0, 'w' => 58.0, 'h' => 58.0],
                ],
                'student_info' => [
                    'x' => 36.0,
                    'y' => 620.0,
                    'w' => 523.0,
                    'h' => 84.0,
                    'header_h' => 16.0,
                ],
                'summary' => [
                    'x' => 36.0,
                    'y' => 496.0,
                    'w' => 523.0,
                    'h' => 112.0,
                    'header_h' => 16.0,
                ],
                'hours_40' => [
                    'x' => 36.0,
                    'y' => 372.0,
                    'w' => 255.0,
                    'h' => 112.0,
                    'header_h' => 16.0,
                ],
                'medical' => [
                    'x' => 304.0,
                    'y' => 372.0,
                    'w' => 255.0,
                    'h' => 112.0,
                    'header_h' => 16.0,
                ],
                'discipline_notes' => [
                    'x' => 36.0,
                    'y' => 214.0,
                    'w' => 523.0,
                    'h' => 146.0,
                    'header_h' => 16.0,
                    'padding' => 8.0,
                ],
                'guardian_signature' => [
                    'x' => 36.0,
                    'y' => 64.0,
                    'w' => 523.0,
                    'h' => 110.0,
                    'header_h' => 16.0,
                    'padding' => 8.0,
                ],
            ],
            'render_order' => [
                'page_background',
                'outer_boxes',
                'header_fills',
                'inner_grid',
                'static_labels',
                'dynamic_values',
                'signature_lines',
            ],
            'pdf_ops' => [
                'rect_stroke' => '{x} {y} {w} {h} re S',
                'rect_fill' => '{x} {y} {w} {h} re f',
                'line' => '{x1} {y1} m {x2} {y2} l S',
                'text' => 'BT /{font} {size} Tf {x} {y} Td ({text}) Tj ET',
            ],
        ];
    }
}
