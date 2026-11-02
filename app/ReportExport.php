<?php
declare(strict_types=1);

final class ReportExport
{
    public static function csv(array $report): string
    {
        $output = fopen('php://temp/maxmemory:2097152', 'w+b');
        if ($output === false) throw new RuntimeException('Could not prepare the report export.');

        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['Reference', 'Applicant', 'Program', 'Academic year', 'Submitted (UTC)', 'Documents verified', 'Required documents', 'Reviewer', 'Status']);
        if (!empty($report['truncated'])) {
            fputcsv($output, ['NOTICE', 'Only the first 5,000 of ' . (int) $report['total'] . ' matching applications are included. Narrow report filters for a complete export.']);
        }
        foreach ($report['rows'] as $row) {
            $cells = [$row['reference_number'], $row['full_name'], $row['program_name'], $row['year_label'], $row['submitted_at'], $row['verified_documents'], $row['required_documents'], $row['reviewer_name'], $row['status']];
            fputcsv($output, array_map(static function ($value): string {
                $cell = (string) ($value ?? '');
                return preg_match('/^[\x00-\x20\p{Z}]*[=+\-@]/u', $cell) ? "'" . $cell : $cell;
            }, $cells));
        }
        rewind($output);
        $bytes = stream_get_contents($output);
        fclose($output);
        if (!is_string($bytes)) throw new RuntimeException('Could not read the prepared report export.');
        return $bytes;
    }

    public static function pdf(array $report, array $filterOptions, array $user = [], bool $includeSignatureLines = false, string $orientation = 'landscape'): string
    {
        $reportOrientation = in_array($orientation, ['portrait', 'landscape'], true) ? $orientation : 'landscape';
        $programs = $filterOptions['programs'];
        $years = $filterOptions['years'];
        $reviewers = $filterOptions['reviewers'];
        $title = 'Applications report';
        $reportPrintView = true;
        $reportPdfView = true;
        require_once dirname(__DIR__) . '/vendor/autoload.php';
        $stylesheet = file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');
        if (!is_string($stylesheet)) throw new RuntimeException('The report stylesheet could not be loaded.');
        $stylesheetDocument = (new Sabberworm\CSS\Parser($stylesheet))->parse();
        $stripPageRules = static function (Sabberworm\CSS\CSSList\CSSList $list) use (&$stripPageRules): void {
            $contents = [];
            foreach ($list->getContents() as $item) {
                if ($item instanceof Sabberworm\CSS\Property\AtRule && strtolower($item->atRuleName()) === 'page') continue;
                if ($item instanceof Sabberworm\CSS\CSSList\CSSList) $stripPageRules($item);
                if ($item instanceof Sabberworm\CSS\RuleSet\RuleSet) $item->removeMatchingDeclarations('page');
                $contents[] = $item;
            }
            $contentsProperty = new ReflectionProperty(Sabberworm\CSS\CSSList\CSSList::class, 'contents');
            $contentsProperty->setValue($list, $contents);
        };
        $stripPageRules($stylesheetDocument);
        $stylesheet = '@page { margin: 4mm; }' . $stylesheetDocument->render();
        $stylesheetHref = 'data:text/css;base64,' . base64_encode($stylesheet);
        ob_start();
        require dirname(__DIR__) . '/resources/views/staff/reports-pdf.php';
        $html = (string) ob_get_clean();
        $options = new Dompdf\Options();
        $options->setDefaultMediaType('print');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', $reportOrientation);
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
        $canvas->page_text(34, $canvas->get_height() - 24, 'AdmitFlow · Admission Office · Confidential, for authorized staff only', $font, 7, [0.07, 0.20, 0.29]);
        $canvas->page_text($canvas->get_width() - 105, $canvas->get_height() - 24, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7, [0.07, 0.20, 0.29]);
        return $dompdf->output();
    }
}
