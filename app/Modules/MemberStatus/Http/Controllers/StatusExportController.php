<?php

namespace App\Modules\MemberStatus\Http\Controllers;

use App\Modules\MemberStatus\Enums\CalculatedStatus;
use App\Modules\MemberStatus\Services\StatusReportExporter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Downloads of the member status table: CSV, Excel, PDF (client request,
 * 2026-08-25).
 *
 * Read only, behind the same middleware as the report page itself. The filters
 * are read exactly as the page reads them, so "download" always means "this
 * table, as I am looking at it" (spec §30).
 */
class StatusExportController
{
    public function __construct(
        private readonly StatusReportExporter $exporter,
    ) {}

    public function __invoke(Request $request, string $format): StreamedResponse|Response
    {
        $format = strtolower($format);

        if (! StatusReportExporter::supports($format)) {
            throw new NotFoundHttpException('Unknown export format.');
        }

        // tryFrom, not from: an unrecognised status in the query string means
        // "everything", never an exception and never a raw value in a query.
        $status = CalculatedStatus::tryFrom((string) $request->query('status', ''));

        return $this->exporter->download(
            format: $format,
            status: $status,
            search: $request->query('q') === null ? null : (string) $request->query('q'),
            limit: (int) config('member_status.report.export_limit', 5000),
        );
    }
}
