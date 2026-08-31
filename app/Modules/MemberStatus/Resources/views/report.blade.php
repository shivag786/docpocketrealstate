{{--
    Member Status Automation — isolated report and payment panel (spec §27,
    plus the client's 2026-08-25 payment rule).

    Read-only for status; the only write is confirming a reward as paid, and
    that goes through the module's AJAX endpoints, which ask
    PaymentEligibilityService before letting anything through.

    It extends the host application's admin layout so it looks like the rest of
    the back office, but it adds nothing to the sidebar and changes no existing
    view. The layout name comes from config.

    The JavaScript at the bottom uses window.App — the application's own helper
    in resources/js/app.js — for requests, toasts and the confirmation dialog, so
    this page carries no front-end machinery of its own.
--}}
@extends($layout)

@php
    use App\Modules\MemberStatus\Enums\CalculatedStatus;

    $filters = array_filter([
        'status' => $status?->value,
        'q' => $search !== '' ? $search : null,
    ]);
@endphp

@section('title', 'Member Status')
@section('page-title', 'Member Status')

@section('breadcrumbs')
    <li class="breadcrumb-item active" aria-current="page">Calculated status</li>
@endsection

@section('page-actions')
    {{-- The shared download control. It carries the filters currently applied,
         so "download" always means "this table, as I am looking at it". --}}
    @include('admin.partials.export-menu', [
        'route' => 'member-status.export',
        'params' => $filters,
        'count' => $rows->total(),
    ])
@endsection

@section('content')
    {{-- What the reader is looking at. This is NOT the members table's status
         column; saying so once here prevents a costly misreading. --}}
    <div class="alert alert-light border d-flex flex-wrap gap-3 justify-content-between align-items-center">
        <div class="small text-muted mb-0">
            Calculated from property-sale activity: a member is
            <strong>Active</strong> for {{ $config->activePeriodDays }} days after their own sale or a
            direct referral's sale, <strong>Pending</strong> until day {{ $config->inactiveThresholdDays() }},
            then <strong>Inactive</strong>. This is the module's own value and does not change the
            member's status anywhere else in the system.
        </div>
        <div class="small text-nowrap">
            <span class="text-muted">Last calculated</span>
            <strong>{{ $lastCalculatedAt?->format('d M Y') ?? 'never' }}</strong>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-3">
        @php($total = array_sum($totals))

        <a href="{{ request()->fullUrlWithQuery(['status' => null, 'page' => null]) }}"
           class="btn btn-sm {{ $status === null ? 'btn-primary' : 'btn-outline-secondary' }}">
            All <span class="badge text-bg-light ms-1">{{ number_format($total) }}</span>
        </a>

        @foreach (CalculatedStatus::cases() as $case)
            <a href="{{ request()->fullUrlWithQuery(['status' => $case->value, 'page' => null]) }}"
               class="btn btn-sm {{ $status === $case ? 'btn-primary' : 'btn-outline-secondary' }}">
                {{ $case->label() }}
                <span class="badge {{ $status === $case ? 'text-bg-light' : $case->badgeClass() }} ms-1">
                    {{ number_format($totals[$case->value] ?? 0) }}
                </span>
            </a>
        @endforeach
    </div>

    <div class="card mb-3">
        <div class="card-body py-3">
            <form method="GET" class="row g-2 align-items-end">
                @if ($status !== null)
                    <input type="hidden" name="status" value="{{ $status->value }}">
                @endif
                <div class="col-12 col-md-4">
                    <label for="q" class="form-label small mb-1">Member</label>
                    <input type="search" id="q" name="q" value="{{ $search }}"
                           placeholder="Code or name" class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-3">
                    <button type="submit" class="btn btn-sm btn-outline-primary w-100 w-md-auto">
                        <i class="bi bi-funnel me-1"></i>Search
                    </button>
                </div>
                @if ($search !== '')
                    <div class="col-6 col-md-2">
                        <a href="{{ request()->fullUrlWithQuery(['q' => null, 'page' => null]) }}"
                           class="btn btn-sm btn-link w-100">Clear</a>
                    </div>
                @endif
            </form>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Member</th>
                        <th>Status</th>
                        <th class="d-none d-lg-table-cell">Last qualifying activity</th>
                        <th class="text-end">Days</th>
                        <th class="d-none d-xl-table-cell">Own sale</th>
                        <th class="d-none d-xl-table-cell">Direct referral</th>
                        <th class="d-none d-lg-table-cell">Status changed</th>
                        <th class="text-end">Unpaid</th>
                        <th class="text-end">Payment</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php($rowStatus = CalculatedStatus::from($row->status))
                        <tr>
                            <td>
                                <span class="fw-semibold">{{ $row->member_code ?? '#'.$row->member_id }}</span>
                                <span class="text-muted d-block small">{{ $row->member_name }}</span>
                            </td>
                            <td><span class="badge {{ $rowStatus->badgeClass() }}">{{ $rowStatus->label() }}</span></td>
                            <td class="d-none d-lg-table-cell">
                                @if ($row->last_activity_at)
                                    {{ \Illuminate\Support\Carbon::parse($row->last_activity_at)->format('d M Y') }}
                                @else
                                    {{-- No activity ever: the clock runs from the joining date instead. --}}
                                    <span class="text-muted">none — since joining
                                        {{ \Illuminate\Support\Carbon::parse($row->joined_at)->format('d M Y') }}</span>
                                @endif
                            </td>
                            <td class="text-end">{{ number_format($row->days_since_activity) }}</td>
                            <td class="d-none d-xl-table-cell">{{ $row->own_sale_at?->format('d M Y') ?? '—' }}</td>
                            <td class="d-none d-xl-table-cell">
                                @if ($row->referral_sale_at)
                                    {{ $row->referral_sale_at->format('d M Y') }}
                                    <span class="text-muted small d-block">by #{{ $row->referral_source_id }}</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="d-none d-lg-table-cell">
                                {{ $row->status_changed_at
                                    ? \Illuminate\Support\Carbon::parse($row->status_changed_at)->format('d M Y')
                                    : '—' }}
                            </td>
                            <td class="text-end">
                                @if ($row->unpaid_count > 0)
                                    <span class="fw-semibold">₹{{ number_format((float) $row->unpaid_amount, 2) }}</span>
                                    <span class="text-muted small d-block">{{ $row->unpaid_count }} reward{{ $row->unpaid_count === 1 ? '' : 's' }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                {{-- Always openable: a blocked member's rewards
                                     are fully visible. Only confirming is withheld. --}}
                                <button type="button"
                                        class="btn btn-sm btn-outline-primary"
                                        data-member-rewards="{{ $row->member_id }}"
                                        data-member-name="{{ $row->member_name }}">
                                    <i class="bi bi-receipt me-1"></i>View
                                </button>

                                @if ($row->payable)
                                    <button type="button"
                                            class="btn btn-sm btn-success"
                                            data-member-rewards="{{ $row->member_id }}"
                                            data-member-name="{{ $row->member_name }}"
                                            @disabled($row->unpaid_count === 0)>
                                        <i class="bi bi-cash-stack me-1"></i>Mark paid
                                    </button>
                                @else
                                    {{-- Disabled buttons do not fire events, so the
                                         explanation lives on a wrapper that does. --}}
                                    <span class="d-inline-block" tabindex="0" data-bs-toggle="tooltip"
                                          title="{{ $rowStatus->label() }}: payment is on hold until a qualifying sale returns this member to Active.">
                                        <button type="button" class="btn btn-sm btn-success" disabled
                                                style="pointer-events: none;">
                                            <i class="bi bi-lock me-1"></i>Mark paid
                                        </button>
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">
                                Nothing calculated yet. Run
                                <code>php artisan member-status:calculate</code>.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rows->hasPages())
            <div class="card-footer bg-white">{{ $rows->links() }}</div>
        @endif
    </div>

    @if ($recentChanges->isNotEmpty())
        <div class="card mt-3">
            <div class="card-header bg-white fw-semibold">Recent status changes</div>
            <ul class="list-group list-group-flush">
                @foreach ($recentChanges as $change)
                    <li class="list-group-item d-flex flex-wrap justify-content-between gap-2">
                        <span>
                            <span class="text-muted">#{{ $change->member_id }}</span>
                            {{ $change->old_status?->label() ?? 'New' }}
                            <i class="bi bi-arrow-right mx-1"></i>
                            <span class="badge {{ $change->new_status->badgeClass() }}">
                                {{ $change->new_status->label() }}
                            </span>
                        </span>
                        <span class="text-muted small">
                            {{ $change->reason }} &middot; {{ $change->effective_at->format('d M Y') }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ---------------------------------------------------------------
         The payment panel. One modal, redrawn from the server after every
         action, so the totals on screen are never a guess.
    ---------------------------------------------------------------- --}}
    <div class="modal fade" id="member-rewards-modal" tabindex="-1" aria-hidden="true"
         aria-labelledby="member-rewards-title">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title mb-0" id="member-rewards-title">Member rewards</h5>
                        <span class="text-muted small" data-panel="subtitle"></span>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body" data-panel="body">
                    <div class="text-center text-muted py-5" data-panel="loading">
                        <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                        Loading rewards…
                    </div>

                    <div data-panel="content" hidden>
                        {{-- Who is being paid. An admin confirming money should
                             not have to trust that they clicked the right row. --}}
                        <div class="row g-3 mb-3">
                            <div class="col-12 col-md-7">
                                <div class="border rounded p-3 h-100">
                                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                        <div>
                                            <div class="fw-semibold" data-panel="member-name"></div>
                                            <div class="text-muted small" data-panel="member-code"></div>
                                        </div>
                                        <span class="badge" data-panel="status-badge"></span>
                                    </div>
                                    <dl class="row mb-0 small">
                                        <dt class="col-5 text-muted fw-normal">Mobile</dt>
                                        <dd class="col-7 mb-1" data-panel="member-mobile"></dd>
                                        <dt class="col-5 text-muted fw-normal">Sponsor</dt>
                                        <dd class="col-7 mb-1" data-panel="member-sponsor"></dd>
                                        <dt class="col-5 text-muted fw-normal">Joined</dt>
                                        <dd class="col-7 mb-1" data-panel="member-joined"></dd>
                                        <dt class="col-5 text-muted fw-normal">Last activity</dt>
                                        <dd class="col-7 mb-0" data-panel="last-activity"></dd>
                                    </dl>
                                </div>
                            </div>

                            <div class="col-12 col-md-5">
                                <div class="border rounded p-3 h-100 d-flex flex-column justify-content-center">
                                    <div class="text-muted small">Unpaid</div>
                                    <div class="fs-4 fw-semibold" data-panel="unpaid-amount"></div>
                                    <div class="text-muted small mt-2">Paid to date</div>
                                    <div class="fw-semibold" data-panel="paid-amount"></div>
                                </div>
                            </div>
                        </div>

                        {{-- The one message that matters when the button is off. --}}
                        <div class="alert alert-warning d-flex gap-2 align-items-start" data-panel="blocked" hidden>
                            <i class="bi bi-exclamation-triangle-fill mt-1"></i>
                            <div data-panel="blocked-reason" class="small"></div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Reward</th>
                                        <th>Month</th>
                                        <th class="text-end">Amount</th>
                                        <th>Status</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody data-panel="rewards"></tbody>
                            </table>
                        </div>

                        <p class="text-muted small mb-0 mt-3" data-panel="empty" hidden>
                            No rewards have been calculated for this member yet.
                        </p>
                    </div>
                </div>

                <div class="modal-footer justify-content-between flex-wrap gap-2">
                    <span class="text-muted small">Confirming a payment cannot be undone.</span>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                            Cancel
                        </button>
                        <button type="button" class="btn btn-success" data-panel="pay-all" hidden>
                            <i class="bi bi-cash-stack me-1"></i>Mark all paid
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
/**
 * Member Status payment panel.
 *
 * Uses window.App.request / window.App.notify from resources/js/app.js — the
 * application's existing AJAX and toast helpers — so this page needed no change
 * to any shared asset and no build step.
 *
 * Every action redraws the panel from the server's response rather than
 * patching the DOM: on a money screen, a stale total is worse than a redraw.
 */
(function () {
    'use strict';

    const modalEl = document.getElementById('member-rewards-modal');
    if (!modalEl || !window.App) return;

    const modal = new bootstrap.Modal(modalEl);
    const el = (name) => modalEl.querySelector(`[data-panel="${name}"]`);
    const base = @json(rtrim(config('member_status.report.prefix', 'admin/member-status'), '/'));

    let currentMemberId = null;
    let currentPanel = null;

    // Tooltips on the disabled Mark Paid buttons.
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((node) => {
        new bootstrap.Tooltip(node);
    });

    const money = (value) => '₹' + Number(value || 0).toLocaleString('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    function setBusy(isBusy) {
        el('loading').hidden = !isBusy;
        el('content').hidden = isBusy;
    }

    function render(panel) {
        currentPanel = panel;

        const { member, status, payment, summary, rewards } = panel;

        el('subtitle').textContent = `${member.code ?? '#' + member.id} · rewards and payments`;
        el('member-name').textContent = member.name ?? '—';
        el('member-code').textContent = member.code ?? '#' + member.id;
        el('member-mobile').textContent = member.mobile ?? '—';
        el('member-sponsor').textContent = member.sponsor ?? 'No sponsor (root)';
        el('member-joined').textContent = member.joined_at;

        el('last-activity').textContent = status.last_activity_at
            ? `${status.last_activity_at} — ${status.last_activity_type} (${status.days_since_activity} days ago)`
            : 'No qualifying sale on record';

        const badge = el('status-badge');
        badge.className = 'badge ' + status.badge_class;
        badge.textContent = status.label;

        el('unpaid-amount').textContent = money(summary.unpaid_amount);
        el('paid-amount').textContent = money(summary.paid_amount);

        el('blocked').hidden = payment.allowed;
        el('blocked-reason').textContent = payment.reason ?? '';

        const payAll = el('pay-all');
        payAll.hidden = !payment.allowed || summary.unpaid === 0;
        payAll.textContent = '';
        payAll.insertAdjacentHTML('beforeend',
            `<i class="bi bi-cash-stack me-1"></i>Mark all paid (${summary.unpaid})`);

        el('empty').hidden = rewards.length > 0;
        el('rewards').replaceChildren(...rewards.map((reward) => row(reward, payment.allowed)));
    }

    function row(reward, payable) {
        const tr = document.createElement('tr');

        const cell = (html) => {
            const td = document.createElement('td');
            td.innerHTML = html;
            return td;
        };

        tr.append(cell(`<span class="badge ${reward.type_badge_class}">${reward.type_label}</span>`));
        tr.append(cell(reward.period));

        const amount = cell(money(reward.amount));
        amount.className = 'text-end fw-semibold';
        tr.append(amount);

        tr.append(cell(
            `<span class="badge ${reward.status_badge_class}">${reward.status_label}</span>` +
            (reward.paid_at ? `<span class="text-muted small d-block">${reward.paid_at}</span>` : '')
        ));

        const action = document.createElement('td');
        action.className = 'text-end';

        if (reward.paid) {
            action.innerHTML = '<span class="text-muted small">—</span>';
        } else if (!payable) {
            action.innerHTML = '<span class="text-muted small"><i class="bi bi-lock"></i> On hold</span>';
        } else {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-sm btn-success';
            button.innerHTML = '<i class="bi bi-check2 me-1"></i>Mark paid';
            button.addEventListener('click', () => pay(reward));
            action.append(button);
        }

        tr.append(action);

        return tr;
    }

    async function load(memberId) {
        currentMemberId = memberId;
        setBusy(true);
        modal.show();

        try {
            const { data } = await window.App.request(`/${base}/members/${memberId}/rewards`);
            render(data);
            setBusy(false);
        } catch (error) {
            modal.hide();
            window.App.notify(error.message, 'danger');
        }
    }

    /**
     * The same dialog the rest of the back office uses, carrying who is being
     * paid and how much — the panel behind it may be scrolled away, so the
     * confirmation has to stand on its own.
     */
    async function pay(reward) {
        const confirmed = await window.App.confirm({
            title: 'Confirm this payment?',
            text: 'This freezes the amount and locks that month against recalculation.',
            confirmText: 'Yes, mark paid',
            variant: 'success',
            details: [
                ['Member', memberLabel()],
                ['Mobile', currentPanel?.member.mobile || '\u2014'],
                ['Status', currentPanel?.status.label || '\u2014'],
                ['Reward', reward.type_label],
                ['Month', reward.period],
                ['Amount', money(reward.amount)],
            ],
        });

        if (confirmed) {
            await send(`/${base}/members/${currentMemberId}/rewards/${reward.id}/pay`);
        }
    }

    async function payAll() {
        const summary = currentPanel?.summary ?? { unpaid: 0, unpaid_amount: 0 };

        const confirmed = await window.App.confirm({
            title: 'Confirm every unpaid reward?',
            text: 'Each one is confirmed separately, and none of them can be undone.',
            confirmText: 'Yes, mark all paid',
            variant: 'success',
            details: [
                ['Member', memberLabel()],
                ['Mobile', currentPanel?.member.mobile || '\u2014'],
                ['Status', currentPanel?.status.label || '\u2014'],
                ['Unpaid rewards', String(summary.unpaid)],
                ['Total', money(summary.unpaid_amount)],
            ],
        });

        if (confirmed) {
            await send(`/${base}/members/${currentMemberId}/rewards/pay-all`);
        }
    }

    function memberLabel() {
        const member = currentPanel?.member;

        return member ? [member.code, member.name].filter(Boolean).join(' \u2014 ') : '\u2014';
    }

    async function send(url) {
        window.App.setLoading(modalEl.querySelector('.modal-content'), true);

        try {
            const response = await window.App.request(url, { method: 'POST' });
            render(response.data);
            window.App.notify(response.message ?? 'Payment confirmed.', 'success');
        } catch (error) {
            // A blocked member, an unfinished month or an already-paid reward
            // all arrive here with a sentence written for an admin to read.
            window.App.notify(error.message, 'warning');

            if (currentMemberId !== null) {
                try {
                    const { data } = await window.App.request(`/${base}/members/${currentMemberId}/rewards`);
                    render(data);
                } catch { /* the toast already said what went wrong */ }
            }
        } finally {
            window.App.setLoading(modalEl.querySelector('.modal-content'), false);
        }
    }

    document.querySelectorAll('[data-member-rewards]').forEach((button) => {
        button.addEventListener('click', () => load(button.dataset.memberRewards));
    });

    el('pay-all').addEventListener('click', () => payAll());

    // Leaving the modal resets it, so the next member never flashes the
    // previous member's numbers.
    modalEl.addEventListener('hidden.bs.modal', () => {
        currentMemberId = null;
        currentPanel = null;
        setBusy(true);
    });
})();
</script>
@endpush
