# Member Status Automation — integration guide

An isolated module that calculates **ACTIVE / PENDING / INACTIVE** for every member
from property-sale activity, and holds payments to members who are not ACTIVE.

It is **not wired into the application**. Nothing in the existing codebase was
modified to build it: no controller, model, migration, route, view, config or
provider file was changed, renamed or deleted. Until you perform step 1 below,
the module is inert — no routes, no command, no listener, no writes.

Everything the module needs to work is already in place except that one
registration line, which is deliberately left for you (spec §35).

---

## 1. What the module does

A member is ACTIVE while there has been **qualifying activity** in the last
90 days, PENDING for the next 90, and INACTIVE from day 180.

```
0–89 days     ACTIVE
90–179 days   PENDING
180+ days     INACTIVE
```

Qualifying activity is **only**:

* the member's own valid property sale, or
* a valid property sale by a member they **personally referred** (level 1).

A sale therefore gives activity to exactly two people: the seller, and the
seller's direct sponsor. For `Shiva → A → A1 → A2`, a sale by A2 is activity for
A2 and A1 only. A and Shiva get nothing from it.

Any qualifying activity **resets** the clock from its own date. A member with no
sales at all is measured from their **joining date**, so a new member is ACTIVE
and never starts life as PENDING.

---

## 2. Files created

Everything lives under `app/Modules/MemberStatus/` and is autoloaded by the
existing `App\` → `app/` PSR-4 mapping, so **composer.json was not touched**.

```
app/Modules/MemberStatus/
├── MemberStatusServiceProvider.php     the single wiring point (NOT registered)
├── Config/member_status.php            all thresholds and switches
├── Contracts/
│   ├── MemberProvider.php              where members and referrals come from
│   ├── PropertySaleProvider.php        where valid sales come from
│   └── RewardGateway.php               where reward amounts and payment live
├── Adapters/
│   ├── EloquentMemberProvider.php      read-only adapter for `members`
│   ├── EloquentPropertySaleProvider.php read-only adapter for `registry_sales`
│   └── EloquentRewardGateway.php       reads `reward_ledger`, pays via the app's
│                                       own RewardPaymentService
├── Services/
│   ├── MemberStatusEngine.php          the 90/180 rules — pure, no database
│   ├── StatusRecalculationService.php  read → decide → write the module tables
│   ├── SaleActivityRecorder.php        one sale → seller + direct sponsor
│   ├── PaymentEligibilityService.php   the payment gate — the only place it lives
│   ├── RewardPanelService.php          one member's rewards, as the modal shows them
│   ├── StatusReportService.php         read model for the report page
│   ├── StatusReportExporter.php        CSV / Excel / PDF of the table
│   └── StatusTransitionLogger.php      logs every transition
├── Repositories/
│   ├── StatusActivityRepository.php
│   ├── StatusSnapshotRepository.php
│   └── StatusHistoryRepository.php
├── Models/                             the module's own three Eloquent models
├── Data/                               MemberRecord, SaleRecord, QualifyingActivity,
│                                       StatusResult, StatusOutcome, RecalculationSummary
├── Enums/                              CalculatedStatus, ActivityType
├── Support/                            StatusConfig, SchemaMap, Clock,
│                                       XlsxWriter, PdfTableWriter
├── Console/Commands/CalculateMemberStatusCommand.php
├── Jobs/RecalculateMemberStatusJob.php
├── Events/PropertySaleConfirmed.php
├── Listeners/RecordQualifyingActivity.php
├── Http/Controllers/
│   ├── MemberStatusReportController.php  the report page
│   ├── MemberRewardController.php        AJAX: rewards, pay, pay-all
│   └── StatusExportController.php        AJAX-free downloads
├── Routes/web.php                      loaded only when the report is enabled
├── Resources/views/report.blade.php
└── README.md
```

Migrations (new files only, in Laravel's standard location so `php artisan
migrate` finds them without any registration):

```
database/migrations/2026_08_25_090000_create_member_status_activity_table.php
database/migrations/2026_08_25_090100_create_member_status_snapshot_table.php
database/migrations/2026_08_25_090200_create_member_status_history_table.php
```

Tests (new files only; they run in the existing suite):

```
tests/Unit/MemberStatus/MemberStatusEngineTest.php
tests/Feature/MemberStatus/*.php
```

**No existing file was modified.** `ModuleIsolationTest` asserts this and fails
if it stops being true.

---

## 3. Database tables created

Three new tables. No existing table is altered, and no foreign key points at
`members` or `registry_sales` — the module must never be able to block a delete
in the existing system, and member/sale data may later come from a different
provider.

| Table | Purpose |
| --- | --- |
| `member_status_activity` | ledger of qualifying events: one row per member per sale that counted for them |
| `member_status_snapshot` | the module's own current status, one row per member |
| `member_status_history` | audit trail, written only when a status actually changes |

Run them with the usual command:

```bash
php artisan migrate
```

`members.status` is **not** read and **not** written by anything in the module.
The calculated value lives only in `member_status_snapshot` (spec §21).

---

## 4. How to provide member data

The engine never touches an Eloquent model. It reads
`App\Modules\MemberStatus\Contracts\MemberProvider`, which returns `MemberRecord`
objects carrying four facts: id, sponsor id, joining date, and display name/code.

The shipped `EloquentMemberProvider` reads the `members` table **read only**, and
takes its table and column names from config, so nothing has to be renamed:

```php
// app/Modules/MemberStatus/Config/member_status.php
'schema' => [
    'members' => [
        'table' => 'members',
        'id' => 'id',
        'sponsor' => 'sponsor_id',
        'joined_at' => 'joining_date',
        'name' => 'name',
        'code' => 'member_code',
        'deleted_at' => 'deleted_at',   // null if the table has no soft deletes
    ],
    ...
],
```

To read members from somewhere else entirely, bind your own implementation:

```php
$this->app->bind(MemberProvider::class, YourMemberProvider::class);
```

Soft-deleted members are excluded automatically.

---

## 5. How to provide referral data

The same provider answers it, with one method:

```php
$members->directReferralIds($memberId);   // level 1 only
$members->sponsorIdOf($memberId);         // one step up, or null
```

The Eloquent adapter implements these as a single `where sponsor_id = ?` and a
single column read. There is **no recursion anywhere in the module**, and the
`MemberProvider` interface deliberately offers no way to ask for a whole
downline — an interface that could express it would invite the rule the
specification forbids (spec §3).

---

## 6. How to provide property-sale data

`App\Modules\MemberStatus\Contracts\PropertySaleProvider` is the **only** place
that decides whether a sale is valid (spec §11). The shipped adapter defines it
as:

```
status IN (configured qualifying statuses)   AND not soft-deleted
```

```php
'sales' => [
    'qualifying_statuses' => ['approved'],
],
'schema' => [
    'sales' => [
        'table' => 'registry_sales',
        'id' => 'id',
        'member' => 'member_id',
        'status' => 'status',
        'date' => 'registry_date',   // the date the inactivity clock runs from
        'deleted_at' => null,
    ],
],
```

The application currently has exactly one sale state — entering a sale is
approving it — so the list holds a single value. **If cancellation, reversal or
a draft state is ever added to the application, no module code changes**: just
keep the new state out of `qualifying_statuses`, and sales in that state stop
producing activity immediately.

To read sales from elsewhere, bind your own implementation:

```php
$this->app->bind(PropertySaleProvider::class, YourSaleProvider::class);
```

---

## 7. How to trigger sale activity

Two independent paths. Either alone keeps statuses correct; together they give
immediate updates plus a nightly safety net.

### a) Nightly (no application change at all)

`member-status:calculate` recalculates everybody from the real sales. Nothing
needs to dispatch anything.

### b) Immediate, on sale entry (one line — **not applied**)

Dispatch the module's event after a sale is created. This is the only change to
existing code the module would ever need, and it has deliberately **not** been
made:

```php
// app/Services/RegistrySaleService.php, after the sale is created
event(\App\Modules\MemberStatus\Events\PropertySaleConfirmed::make(
    $sale->id,
    $sale->member_id,
    $sale->registry_date,
));
```

The listener re-reads the sale through the `PropertySaleProvider` and ignores
anything it cannot confirm is valid, so the event is never taken as proof that a
sale happened (spec §30).

To keep it off the request cycle, dispatch the job instead:

```php
\App\Modules\MemberStatus\Jobs\RecalculateMemberStatusJob::forSale($sale->id)->dispatch();
```

Either way, exactly two members are recalculated: the seller and their direct
sponsor.

---

## 8. How to run the status calculation

```bash
# everybody, as of today
php artisan member-status:calculate

# what would change, writing nothing — run this first against live data
php artisan member-status:calculate --dry-run

# reproduce a decision for a past date
php artisan member-status:calculate --as-of=2026-06-30

# a single member
php artisan member-status:calculate --member=101
```

To schedule it daily, add to `routes/console.php`:

```php
Schedule::command('member-status:calculate')->dailyAt('01:00');
```

Members are processed in batches (`member_status.batch.chunk_size`, default
500), and each batch costs a fixed number of queries regardless of network size.

---

## 9. How to configure 90 / 180 days

Everything is in `app/Modules/MemberStatus/Config/member_status.php`. No
threshold is hard-coded anywhere else in the module — a unit test asserts the
shipped defaults, and other tests run the engine at 30/15 days to prove the
numbers really come from config.

```php
'active_period_days' => 90,     // ACTIVE  -> PENDING after this many days
'pending_period_days' => 90,    // PENDING -> INACTIVE after this many more
'allow_inactive_reactivation' => true,
'new_member' => ['measure_from_joining_date' => true, 'grace_days' => 0],
```

The INACTIVE threshold is **derived** (`active + pending = 180`), so the two
numbers can never contradict a third setting.

Or set them per environment:

```dotenv
MEMBER_STATUS_ACTIVE_PERIOD_DAYS=90
MEMBER_STATUS_PENDING_PERIOD_DAYS=90
MEMBER_STATUS_ALLOW_INACTIVE_REACTIVATION=true
MEMBER_STATUS_REPORT_ENABLED=false
```

To edit the config in the normal place instead of inside the module:

```bash
php artisan vendor:publish --tag=member-status-config
```

**`allow_inactive_reactivation`** decides spec §26 test 9: with `true`, a new
qualifying sale lifts an INACTIVE member back to ACTIVE; with `false`, INACTIVE
is terminal until a human intervenes. PENDING → ACTIVE always happens and is not
configurable, because that is the business rule.

---

## 10. Integration step 1 — registering the module

One line in `bootstrap/providers.php`, which **has not been changed**:

```php
return [
    App\Providers\AppServiceProvider::class,
    App\Modules\MemberStatus\MemberStatusServiceProvider::class,   // <- add this
];
```

That gives you: the config key, the two interface bindings, the
`member-status:calculate` command, the module's views, and the sale-event
listener. It binds nothing the application already binds and overrides nothing.

Until then the module still runs — its tests register the provider themselves,
which is how the whole thing was verified without touching the application.

---

## 11. The payment gate and the payment panel

**The rule (client, 2026-08-25).** A member who is not ACTIVE can be looked at in
full — every reward, every amount, paid and unpaid — but an admin **may not
confirm a payment** to them.

### Where it lives

`Services/PaymentEligibilityService` is the only place the rule is written.
Three things ask it the same question, so they can never disagree:

| Asks | What it does with the answer |
| --- | --- |
| the report table | renders Mark Paid enabled, or locked with a tooltip |
| the payment modal | shows a warning banner instead of the confirm buttons |
| the POST endpoint | refuses with 422 and the reason |

The third is the one that matters: a hand-crafted request is refused exactly as
the button is. A disabled button is a courtesy, not the rule (spec §30).

### Which statuses block

```php
'payment' => [
    'blocked_statuses' => ['PENDING', 'INACTIVE'],
    'block_when_unknown' => false,
],
```

PENDING is the rule as you stated it. INACTIVE is included because a member
silent for twice as long cannot reasonably be payable when a PENDING one is not
— remove it here if the business decides otherwise. `block_when_unknown` covers
a member the module has never calculated: false by default, so switching the
module on cannot silently stop payments that work today.

### How a payment is actually made

The module **does not pay anybody**. It calls the application's own
`App\Services\RewardPaymentService::pay()` — the same code path the existing
Targets and Company Club screens use, with the same row locking, the same
"month must be over" rule and the same `paid_at` / `paid_by` audit fields.
That service was not modified. So the host's own refusals still apply on top of
the gate:

```
member is PENDING            -> refused by this module
month has not finished       -> refused by the application, message passed through
reward already paid          -> refused by the application, message passed through
```

### The panel

`/admin/member-status` → **View** or **Mark paid** on any row opens a modal
showing who is being paid (code, name, mobile, sponsor, joined, calculated
status, last qualifying activity), what they are owed, and every reward line.

* AJAX throughout, using `window.App.request` from `resources/js/app.js` — the
  helper the application already ships, so no asset was changed and no build
  step was added
* toasts via `window.App.notify`, the same bottom-right toast the rest of the
  back office uses
* a **Cancel** button, and a confirm prompt before anything irreversible
* **Mark paid** per reward, plus **Mark all paid** for the member
* the whole panel is redrawn from the server's response after every action, so
  the totals on screen are never a guess
* responsive: the table drops its least important columns on small screens and
  the modal scrolls rather than overflowing

Endpoints (all behind the same auth/role middleware as the page):

```
GET  /admin/member-status/members/{member}/rewards
POST /admin/member-status/members/{member}/rewards/{reward}/pay
POST /admin/member-status/members/{member}/rewards/pay-all
```

### Applying the same rule to the EXISTING reward screens

Not done, by your instruction. The existing Targets and Company Club Mark Paid
buttons are untouched and still behave exactly as they do today. When you want
the rule there too, it is one call per screen:

```php
$eligibility = app(PaymentEligibilityService::class)->check($reward->member_id);

if ($eligibility->blocked()) {
    return back()->with('error', $eligibility->reason);
}
```

---

## 12. Downloading the table (CSV / Excel / PDF)

The **Download** button on the report offers all three. Each carries the filters
currently applied — status pill and search — so a download always means "this
table, as I am looking at it".

```
GET /admin/member-status/export/csv?status=PENDING&q=DPRS1
GET /admin/member-status/export/xlsx
GET /admin/member-status/export/pdf
```

Columns: member code, name, status, last qualifying activity, days since
activity, own-sale activity, direct-referral activity, status changed at, joined.

**No composer package was added for any of this.**

* **CSV** streams, with a UTF-8 BOM so Excel does not mangle non-ASCII names.
* **Excel** is a real `.xlsx` — a proper Office Open XML package written by
  `Support\XlsxWriter`, with a frozen bold header and an autofilter. Not an HTML
  table with a spreadsheet extension, which is what makes Excel warn on open.
* **PDF** is a real `.pdf` written by `Support\PdfTableWriter`: landscape A4,
  zebra rows, page numbers, and a subtitle recording which filters produced it.
  Base-14 Helvetica, so no font is embedded and the file stays small; the rupee
  sign is written as `Rs.` because that font cannot render it.

A single download is capped at `member_status.report.export_limit` (default
5,000 rows) — an export is built in memory, and a runaway one would take the
request down with it.

---

## 13. How to connect it to the existing admin panel later

The module ships the report and payment panel described in §11 and §12: member,
calculated status, last qualifying activity, days since activity, own-sale
activity, direct-referral activity, the date the status changed, what is unpaid,
and the gated Mark Paid control (spec §27).

It is **off by default**, because routes are the one thing that could collide
with the existing application:

```dotenv
MEMBER_STATUS_REPORT_ENABLED=true
```

It then appears at `/admin/member-status`, behind the same middleware as the
rest of the back office (`web`, `auth`, `active`, `role:admin,manager`). The
prefix, route-name prefix, middleware stack and layout are all config values.

The sidebar was **not** modified. To add a link later, one entry in
`resources/views/layouts/partials/sidebar.blade.php`:

```blade
<a class="nav-link" href="{{ route('member-status.index') }}">
    <i class="bi bi-activity me-2"></i>Member Status
</a>
```

---

## 14. How to connect it to the existing member status later

Nothing does this today, and nothing should until the calculated values have
been reviewed against live data. When you decide to, the shape of the change is:

```php
// A NEW listener/service you would write — not part of this module.
// Read the module's value, write the application's.
$calculated = app(StatusRecalculationService::class)->currentStatus($memberId);

$member->status = $calculated === CalculatedStatus::Active
    ? MemberStatus::Active
    : MemberStatus::Inactive;   // PENDING has no equivalent in the existing enum
```

Three things to settle **before** doing that, because they are business
decisions and not technical ones:

1. **PENDING has no equivalent** in `App\Enums\MemberStatus`. Either it maps to
   Active (generous), to Inactive (strict), or the application's enum gains a
   third case — which is a change to existing code.
2. `StoreRegistrySaleRequest` currently **rejects a sale by an inactive member**.
   Connecting the two statuses means an automatically-inactivated member can no
   longer have sales entered — which is very likely intended, but it changes how
   the business operates the day it goes live.
3. Company Club excludes an inactive seller's sales from its pool. Automatic
   inactivation would therefore start moving money. Recalculate a closed period
   only with that in mind.

Recommended sequence: run `--dry-run` daily for a while, read the report, then
decide.

---

## 15. How to remove the module safely

Nothing depends on it, so removal is:

```bash
php artisan migrate:rollback --step=3     # or drop the three tables
rm -rf app/Modules/MemberStatus
rm -rf tests/Feature/MemberStatus tests/Unit/MemberStatus
rm database/migrations/2026_08_25_09*_create_member_status_*.php
rm MEMBER_STATUS_INTEGRATION.md
```

Plus, if you had integrated it: remove the provider line from
`bootstrap/providers.php`, the `event(PropertySaleConfirmed…)` line from the sale
service, and the sidebar entry. The existing application is unaffected either
way — it never referenced any of it.

---

## 16. Test coverage

```bash
php artisan test --filter=MemberStatus
```

| Spec test | Where |
| --- | --- |
| 1 — own sale | `QualifyingActivityTest::test_1…` |
| 2 — direct referral sale | `QualifyingActivityTest::test_2…` |
| 3 — level-2 sale does not reach the grandparent | `QualifyingActivityTest::test_3…` |
| 4 — 90 days → PENDING | `MemberStatusEngineTest::test_4…`, `StatusLifecycleTest` |
| 5 — 180 days → INACTIVE | `MemberStatusEngineTest::test_5…`, `StatusLifecycleTest` |
| 6 — activity during PENDING → ACTIVE | `MemberStatusEngineTest::test_6…` |
| 7 — activity before 90 days resets the clock | `MemberStatusEngineTest::test_7…` |
| 8 — activity at day 179 avoids INACTIVE | `MemberStatusEngineTest::test_8…` |
| 9 — reactivation from INACTIVE is configurable | `MemberStatusEngineTest::test_9…`, `StatusLifecycleTest` |

Plus: new-member rules, the day boundaries 89/90/179/180, joining-date floors,
sale validity, idempotent recording, the batch command, the report page and its
authorisation, and `HostDataUntouchedTest`, which captures `members` and
`registry_sales` before and after a full run and fails on any difference.

The payment gate and the downloads have their own cases:

| Behaviour | Where |
| --- | --- |
| an ACTIVE member can be paid, through the application's own service | `PaymentGateTest` |
| a PENDING member cannot, and the ledger is left untouched | `PaymentGateTest` |
| an INACTIVE member cannot either | `PaymentGateTest` |
| a blocked member's rewards are still fully visible | `PaymentGateTest` |
| which statuses block is configuration, not code | `PaymentGateTest` |
| a reward id swapped for another member's is refused | `PaymentGateTest` |
| the host's own refusals (already paid, unfinished month) still apply | `PaymentGateTest` |
| guests reach none of it | `PaymentGateTest`, `StatusExportTest` |
| CSV, a real zip-container .xlsx, and a real .pdf | `StatusExportTest` |
| downloads carry the applied filters | `StatusExportTest` |
