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

    public static function pdf(array $report, array $filterOptions): string
    {
        $report['display_filters'] = $report['filters'];
        $programNames = array_column($filterOptions['programs'], 'name', 'id');
        $yearNames = array_column($filterOptions['years'], 'label', 'id');
        $reviewerNames = array_column($filterOptions['reviewers'], 'full_name', 'id');
        $statusNames = ['submitted' => 'Submitted', 'in_screening' => 'In Screening', 'documents_incomplete' => 'Documents Incomplete', 'interview_scheduled' => 'Interview Scheduled', 'accepted' => 'Accepted', 'declined' => 'Declined', 'enrolled' => 'Enrolled'];
        if (($report['display_filters']['program_id'] ?? '') !== '') $report['display_filters']['program_id'] = $programNames[$report['display_filters']['program_id']] ?? 'Selected program';
        if (($report['display_filters']['year_id'] ?? '') !== '') $report['display_filters']['year_id'] = $yearNames[$report['display_filters']['year_id']] ?? 'Selected academic year';
        if (($report['display_filters']['reviewer_id'] ?? '') !== '') $report['display_filters']['reviewer_id'] = $reviewerNames[$report['display_filters']['reviewer_id']] ?? 'Selected reviewer';
        if (($report['display_filters']['status'] ?? '') !== '') $report['display_filters']['status'] = $statusNames[$report['display_filters']['status']] ?? $report['display_filters']['status'];
        if (($report['display_filters']['documents'] ?? '') !== '') $report['display_filters']['documents'] = $report['display_filters']['documents'] === 'complete' ? 'All verified' : 'Needs attention';
        if (($report['filters']['date_range'] ?? '') !== '') $report['display_filters']['date_range'] = ['last7' => 'Last 7 Days', 'last30' => 'Last 30 Days', 'term' => 'This Term', 'custom' => 'Custom Range'][$report['filters']['date_range']] ?? 'Selected range';
        $dateRangeLabels = ['' => 'Any date', 'last7' => 'Last 7 days', 'last30' => 'Last 30 days', 'term' => 'This Term', 'custom' => 'Custom Range'];
        ob_start();
        require dirname(__DIR__) . '/resources/views/staff/reports-pdf.php';
        $html = (string) ob_get_clean();
        require_once dirname(__DIR__) . '/vendor/autoload.php';
        $options = new Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        return $dompdf->output();
    }
}
