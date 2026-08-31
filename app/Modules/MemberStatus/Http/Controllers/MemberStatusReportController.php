<?php

namespace App\Modules\MemberStatus\Http\Controllers;

use App\Modules\MemberStatus\Enums\CalculatedStatus;
use App\Modules\MemberStatus\Repositories\StatusHistoryRepository;
use App\Modules\MemberStatus\Services\StatusReportService;
use App\Modules\MemberStatus\Support\StatusConfig;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The optional, isolated status report (spec §27).
 *
 * READ ONLY. There is no action here that changes a status: statuses are
 * calculated by the scheduled command and by the sale event, never by someone
 * opening a page (spec §30). The existing admin panel is not modified in any
 * way — this is an additional page, behind the same auth/role middleware, that
 * exists only while `member_status.report.enabled` is true.
 *
 * It is a plain controller rather than an extension of the application's own
 * base controller so that removing the module cannot leave a dangling parent.
 */
class MemberStatusReportController
{
    public function __construct(
        private readonly StatusReportService $report,
        private readonly StatusHistoryRepository $history,
        private readonly StatusConfig $config,
    ) {}

    public function index(Request $request): View
    {
        // Only a value that matches the enum is accepted; anything else falls
        // back to "all", so a hand-edited query string cannot reach the query.
        $status = CalculatedStatus::tryFrom((string) $request->query('status', ''));

        $perPage = (int) config('member_status.report.per_page', 25);

        $rows = $this->report->page(
            status: $status,
            search: $request->query('q') === null ? null : (string) $request->query('q'),
            perPage: $perPage,
        )->withQueryString();

        return view('member-status::report', [
            'rows' => $rows,
            'status' => $status,
            'search' => (string) $request->query('q', ''),
            'totals' => $this->report->totals(),
            'lastCalculatedAt' => $this->report->lastCalculatedAt(),
            'recentChanges' => $this->history->recent(10),
            'config' => $this->config,
            'layout' => (string) config('member_status.report.layout', 'layouts.admin'),
        ]);
    }
}
