# CHANGELOG

Chronological project history. Claude must append an entry after each meaningful task/phase.

## Entry format

### YYYY-MM-DD — Phase X — Task
**Added**
- 

**Changed**
- 

**Database**
- 

**Tests**
- 

**Manual verification**
- 

**Issues**
- 

**Decision**
- 

**Next**
- 

---

### 2026-09-01 — Per-engine payment locks, the Company Club month-end gate, and a payment cut-off

**Changed**
- **A paid reward now locks its OWN engine, not the whole month.** Client-confirmed
  after they asked that Company Club payment status and Team Target payment status
  never be mixed. The rule lives on `CalculationRunType::lockedBy()`;
  `CalculationRunService::assertPeriodNotPaid()` and `periodIsPaid()` both take the
  engine being asked about, and `anyRewardPaid()` is the one remaining period-wide
  read — explicitly documented as NOT a lock.

  Team Sales is the single cross-engine entry, and it is the old reasoning applied
  honestly rather than an exception: it pays nobody, so it can never be locked by
  its own payment, but Target's verdict is read off its rollup.
- **A rebuild is now partial.** `PeriodRecalculationService::recalculate()` returns
  `['completed' => …, 'locked' => …]` instead of throwing on the first paid reward.
  The unlocked engines rebuild; the frozen ones are reported by name. Company Club's
  automatic path returns null for a paid month rather than throwing, because raising
  there would roll back four engines that were free to run.
- **A late sale into a partly locked month is a warning, not an error.** Most of the
  month absorbed it and one engine did not; calling that a failure hid the rebuild
  that did happen, and calling it a clean success hid the engine now out of step.
- **Company Club cannot be calculated until its month has ended.** The one engine
  that carries this gate, for a reason belonging to it alone: a Club share is the
  pool DIVIDED between the eligible members, so it FALLS when a member joins the
  eligible list, not only when a sale lands. Committing on the 10th publishes an
  amount certain to move, and a member who watched their share shrink has every
  reason to dispute it. Preview stays open all month — it writes nothing and says
  it is an estimate.
- **Payment waits for an entry window past month end.** New
  `config('rewards.payment_cutoff_days')`, default 5. Month end is not the same as
  every sale in the month having been entered: registry paperwork for the last days
  arrives during the first days of the next, and a sale keyed in after payment lands
  against a locked engine and can never be credited. This is the actual fix for
  "a late sale rewriting an amount somebody has already been paid" — it makes late
  paperwork land BEFORE the freeze rather than after it. Set to 0 to restore the
  previous behaviour.
- **Reports and Audit Logs removed from the sidebar** at the client's request. They
  were the last two undelivered items, so the disabled-item branch and every `phase`
  key went with them — nothing in the nav is now scaffolding.

**Database**
- None. The lock was always derived from `reward_ledger.status`; only the query
  changed.

**Tests**
- Rewrote the five tests that pinned the period-wide lock. Each now asserts the
  engine that froze and the engines that followed their sales, including the
  client's own case: a paid Company Club share leaves Team Targets free.
- Added: a month still running cannot be calculated (service and screen, including a
  forced POST); a month that has ended can; a month that has ended still waits for
  its entry window, and a sale arriving inside the window is absorbed normally.
- Fixed a latent flake in `CompanyClubIncomeTest::no_level_numbering_appears_anywhere_on_the_page`.
  It cut the haystack at the first "Income Distribution", which falls in the
  `<title>` — so the whole layout was scanned, and the test failed whenever the
  random CSRF token happened to contain "L1".."L5". It now scans `<main>` only.
- 670 pass.

**Decision**
- Locking per engine narrows a protection, so it was worth stating plainly what is
  NOT weakened: an amount somebody has been paid is still never rewritten. What the
  period-wide lock added beyond that was not protection but coupling.
- The month-end gate deliberately does not extend to the other engines. Direct,
  Upline and Target rewards only ever grow as sales arrive; showing one mid-month is
  honest. Company Club is the only engine where a figure can fall.

**Next**
- Direct and Upline still have no Mark Paid screen; payment is wired on Target and
  Company Club only.

---

### 2026-08-31 — Sign-in convenience, change password, readable password storage

**Added**
- **Sign-in email is pre-filled** from `config('company.login.default_email')`
  (`LOGIN_DEFAULT_EMAIL`, defaulting to the seeded admin address), so a
  single-operator install only types a password. `old()` still wins after a
  failed attempt, and the field stays editable so a second operator can sign in
  on the same machine. Focus now starts on the password.
- **Show/hide eye toggle** on every password field — one generic
  `[data-password-toggle]` handler in `app.js`, used by the sign-in form and by
  all three fields on the change-password form.
- **Settings › Password.** Change your own password: current password required,
  minimum 8 characters with a letter and a number, confirmed, and rate-limited
  to 6 attempts a minute.
- **`php artisan app:set-password`** — interactive recovery from the server for
  an account whose password is not known.

**Changed — CLIENT DECISION, read this before "fixing" it**
- **Passwords are now also stored unencrypted**, in `users.password_plain`, and
  shown on Settings › Password. The client asked for this, was told in writing
  what it costs — anyone who can read the database or a backup has the operator's
  real password, and any account where it was reused — and confirmed.

  What did NOT change: `users.password` is still the bcrypt hash and is still
  the only column authentication reads. `users.password_plain` is a convenience
  copy. **Dropping the column breaks nothing.** A test asserts sign-in is
  unaffected when the readable copy is deliberately corrupted.

  It is excluded from model serialisation, so it does not leak into JSON
  responses or logs that happen to include a user.

**Database**
- `users.password_plain` — nullable. NULL for an account whose password predates
  the column; a hash cannot be reversed, so those stay NULL until the password
  is next set. The existing admin account was backfilled with the seeded
  default after verifying it signs in.

**Config**
- `company.login.default_email` — reads `LOGIN_DEFAULT_EMAIL`, documented in
  `.env.example`. Set it empty for a blank field. Note it publishes a valid
  account name on a public page: a deliberate trade for a small back office.

**Tests**
- `ChangePasswordTest` (14) — the change flow, wrong current password, mismatch,
  reuse, weak passwords, plus the readable copy being written, displayed, not
  serialised, not written on a rejected change, and not used for authentication.
- Full suite: 662 tests, 660 passing (the two pre-existing Company Club date
  failures are unchanged).

**Decision**
- `Auth::logoutOtherDevices()` was written into the change-password flow and
  then removed. It only ends other sessions when Laravel's `AuthenticateSession`
  middleware is active, which this application does not use — without it the
  call rewrites the hash and silently ends nothing. The screen now says plainly
  that other sessions continue until they expire, rather than claiming a
  protection that is not there.
- Password changes are self-service only. There is no user-management screen,
  and an admin able to rewrite another operator's password from here could lock
  them out with nothing recording that a person rather than that operator did it.

**Next**
- If the readable-password decision is ever revisited: drop
  `users.password_plain`, delete `User::setPassword()`'s second column and
  `readablePassword()`, and remove the panel on Settings › Password. Nothing
  else depends on it.

---

### 2026-08-31 — Welcome letter controls, one-page guarantee, developer system reset

**Added**
- **Settings › Welcome Letter.** Six switches controlling which optional rows the
  letter prints: designation, contact number, email, blood group, sponsor and
  company name. Stored on `company_settings.letter_fields`, merged over the
  defaults in `config/company.php` so a field added later switches on for every
  existing install with no data migration.
- **Settings › Developer** — a one-click system reset that empties every
  business table so the system can be handed over fresh after online testing.
  Three guards: the `DEVELOPER_TOOLS` env flag, the existing admin role
  middleware, and a typed `RESET` confirmation enforced server-side.
- `SystemResetService`, with an explicit children-first table list and a
  `PRESERVES` guard that a test asserts does not intersect `CLEARS`.
- Settings is now a tabbed section — Company / Welcome Letter / Developer — with
  matching children in the sidebar.

**Changed**
- **The welcome letter is guaranteed to fit one A4 page.** The first attempt at
  the worst case (every row on, a company name and address long enough to wrap,
  a sponsor) rendered as TWO pages; type sizes and spacing were tightened until
  it fits, and the signing block is a fixed 33mm that content above cannot
  squeeze. `WelcomeLetterTest` renders that worst case and asserts a page count
  of exactly 1, so the guarantee is enforced rather than assumed.
- The letter now reserves a marked **seal box** alongside the signature line,
  for the physical rubber stamp.
- A row switched ON is still skipped when the member has nothing to put in it
  (no sponsor, no blood group). Email is the exception and prints
  "Not recorded", because a blank there reads as an oversight, not a fact.

**Database**
- `company_settings.letter_fields` — nullable JSON. NULL means "never
  configured" and reads as the config defaults, so installs predating the
  column behave exactly as before.

**Config**
- `company.letter.fields` — the default row switches.
- `company.developer_tools` — reads `DEVELOPER_TOOLS`, false by default and
  documented in `.env.example`.

**Tests**
- `WelcomeLetterTest` (8) — the one-page guarantee at both extremes, toggles on
  and off, rows that cannot be hidden, empty-value skipping.
- `SystemResetTest` (10) and `SystemResetDisabledTest` (4) — the gate, the typed
  confirmation, what is cleared, and what must survive.
- Full suite: 648 tests, 646 passing (the two pre-existing Company Club date
  failures documented in the previous entry are unchanged).

**Issues**
- None new. The two Company Club failures remain and are still unrelated.

**Decision**
- **The developer gate is per-request middleware, not an `if` around the route
  definitions.** The `if` version was written first and looked stricter — the
  route would not exist at all. It is wrong: route registration happens once and
  `php artisan route:cache` freezes the flag's value into
  `bootstrap/cache/routes-*.php`, so a deployment that cached its routes while
  the flag was on would keep serving the reset page after the flag was turned
  off — precisely the go-live moment the flag exists for. The middleware reads
  the flag on every request and returns 404, not 403, so nothing confirms the
  page is there.
- **Name, Member ID and joining date are not toggleable.** A letter that cannot
  say who it is for, what their code is, or when they joined is not a welcome
  letter.
- **The reset keeps `users` and both settings tables.** Losing the admin login
  would lock the operator out of the panel they had just cleared, and the
  company branding is configuration rather than data.
- The member-code restart is not special-cased: `MemberCodeGenerator` reads
  `MAX(sequence_number)` from an empty `members` table and returns the
  configured `start_at`, so codes resume at DPRS101 on their own.

**Next**
- Turn `DEVELOPER_TOOLS` off in `.env` at go-live, after the final reset.

---

### 2026-08-31 — Member profile fields, plot location, company settings, printable member documents

Four client requests delivered together, because three of them meet on the same
screens and the fourth needs the company identity the third one stores.

**Added**
- **Blood group and designation on a member.** Blood group is optional and picked
  from the eight ABO/Rh groups (`App\Enums\BloodGroup`). Designation is required,
  defaults to **Sales Advisor**, and is chosen from an admin-editable list.
- **Block name and plot number on sale entry.** The project stays a dropdown; the
  block and plot are typed. The block field suggests blocks already recorded
  against the chosen project (`BlockSearchController`), so repeated entry
  converges on one spelling without ever refusing a new block. Promoted out of
  the collapsed "optional" accordion into a visible **Plot location** card,
  alongside the registry date which already defaults to today.
- **Company Settings screen** (Administration › Settings) — company name,
  tagline, logo, address, phone, email, website, authorised signatory and
  signature image, plus the designation list. Fills the Phase 16 placeholder
  that was sitting disabled in the sidebar.
- **Welcome letter and ID card as PDFs**, per member. Both reachable from a Print
  menu on the member profile, and offered directly after registration. Both open
  inline (the next action is Ctrl+P); `?download=1` saves instead.

**Changed**
- The sidebar brand shows the configured company name and logo instead of
  `config('app.name')` and a fixed icon.
- The **Property / Plot** dropdown is retired from sale entry, replaced by the
  typed block and plot. Sales recorded before today keep their `property_id`,
  and the sale detail screen shows that row only when there is one. Properties /
  Sites remains a managed screen in its own right.
- Sales history searches block and plot, and the Project column shows the plot
  location.
- An update that omits `designation` keeps the member's current one. Every
  member holds a designation from the day they join, so there is nothing to
  clear, and a partial update correcting a mobile number must not be rejected
  over a field it never touched. A designation an admin has since removed from
  the list also stays valid for members already holding it.

**Database**
- `members`: `blood_group` (nullable), `designation` (NOT NULL, defaults to the
  configured **Sales Advisor**, so existing rows backfill to it).
- `registry_sales`: `block_name`, `plot_number`, both nullable, plus a
  `(project_id, block_name)` index for the suggestion lookup.
- `company_settings`: new single-row table, same shape as `company_club_settings`.

**Dependencies**
- `barryvdh/laravel-dompdf` ^3.1. The existing `PdfTableWriter` stays where it
  is — it renders report tables and cannot place an image, which a letterhead
  needs. Images reach dompdf as base64 data URIs rather than paths, because the
  files live under `storage/` and dompdf's chroot defaults to `public/`.

**Tests**
- Full suite: 627 tests, 625 passing. No new failures.

**Manual verification**
- Both PDFs render with and without a logo uploaded.
- Settings save normalises the designation list: trimmed, blanks dropped,
  duplicates collapsed case-insensitively, default always kept and first.
- Block lookup returns a project's blocks and declines to guess without one.

**Issues**
- Two **pre-existing** Company Club test failures, unrelated to this work and
  only reproducible on the 29th–31st of a month:
  `CompanyClubTest::periods_are_calculated_independently` and
  `CompanyClubIncomeTest::the_month_filter_switches_periods`. Both build two
  periods as `now()->subMonths(2)` and `now()->subMonth()`. PHP's month
  arithmetic overflows, so on 2026-08-31 the first lands on 2026-07-01 and the
  second on 2026-07-31 — the same period. The fix belongs in the tests
  (`->startOfMonth()` before subtracting), not in the engine.

**Decision**
- Designations live in `company_settings` as JSON, not in an enum and not in a
  child table: the client will rename ranks, an enum would need a deployment,
  and a table would add a join to the member form for a short ordered list of
  strings. Members store the chosen string, so renaming a rank does not rewrite
  the designation on members already issued a printed card.
- The ID card prints as an A4 sheet with front and back at exact CR80 size and
  cut guides, not as CR80 pages. Staff print on an office printer, where a CR80
  page comes out as a stamp in the corner of an A4 sheet.

**Next**
- Nothing blocking. A member photo on the ID card is the obvious follow-up if
  the client wants one.

---

### 2026-08-27 — Upline hidden from the back office (the engine keeps paying)

Client: *"no need of upline any where. hide from their."* Confirmed the same day
that this means **hide the screens, not stop paying**, and that **Company Club is
untouched**.

Two unrelated things are called "upline" in this system, and only the first was
touched: the **Upline Reward** (₹50 per Sq.Ft., pooled and split among up to 5
active uplines) and the **sponsor chain** (the tree, the member profile's chain
tab, `ValidSponsor`, and the Company Club's own separate upward walk). Removing
the second would have taken the sponsor tree and Company Club down with it.

**Added**
- `config('rewards.visibility')` — one flag per engine, and the single switch
  behind all of this. `RewardType::isVisible()`, `::visible()` and
  `::visibleValues()` are what every screen asks, so hiding an engine is a config
  change rather than an edit in a dozen views.
- `HiddenUplineTest` — 6 cases holding both halves of the arrangement: the word
  "upline" appears on **none** of fifteen operator screens (loaded for real and
  searched, so a panel nobody thought of cannot slip through), the engine still
  writes ₹50,000 for a 1,000 Sq.Ft. sale, a new sale still rebuilds it,
  reconciliation still shows AND still checks it, and one config line brings
  every screen back.

**Changed** — Upline is now absent from:
- the sidebar; the dashboard card and the "All rewards" headline total;
- the Calculation Center's engine card, its single-engine button and its run
  history;
- every Reward Ledger surface — rows, filters, the by-engine split, totals,
  downloads, the entry page and the member statement;
- the member profile's Upline Reward tab and its Overview performance line;
- the sale detail page's pool row and its explorer link.
- `/admin/rewards/upline` and `/admin/rewards/upline/explain/{member}` now 404.
  The routes and the pages are left in place: this is a screen switched off, not
  a feature removed.
- The member profile tab **"Sponsor / Upline" is now "Sponsor Chain"** — the
  sponsor chain is not the reward and is unchanged, but the word went with the
  rest. Its reward-specific column ("Within upline limit") and footnotes are
  hidden with the engine. Two other display strings were reworded the same way:
  the Calculation Center's Company Club rule ("5 active sponsor levels") and the
  Company Club tree tooltip.

**Database**
- None. No row was deleted and no figure moved.

**Tests**
- Tests that cover the Upline screens now switch the flag on and go on covering
  them in full, so what a restore would bring back is known to work.
  `UplineExplorerTest` gained two cases proving the pages 404 and the sidebar
  entry disappears while the flag is off.
- Whole suite green: 627 tests.

**Issues**
- **The ledger's totals are deliberately no longer the whole of what the system
  owes.** A Sq.Ft. still carries ₹140 of reward before targets (40 + 50 + 50);
  the ledger now reports ₹90 of it. Reconciliation is where the full figure
  stays visible.

**Decision**
- **RECONCILIATION IS THE ONE SCREEN THAT STILL SHOWS A HIDDEN ENGINE**, marked
  with a "Hidden" badge and a line saying it is still calculated. A reward that
  is still being written but is exempt from every check is money moving where
  nothing is watching — that is worse than either showing it or stopping it. The
  Calculation Center points at that screen without naming the engine.
- **A flag, not a deletion.** The engine, its service, its tables, its report and
  its explorer are all intact, so the decision is reversible with one line and
  the figures come back whole. Deleting would have thrown away six phases of
  work over a display preference.

**Next**
- Phase 14 — Reports.

---

### 2026-08-26 — Phase 13 — Reward Ledger & reconciliation

The phase's exit condition is "every amount is explainable". Four engines write
to one `reward_ledger` table and, until now, nothing read it as a whole: each
reward report showed its own engine, so no screen could answer what a member or
a month actually owed, and nothing ever re-checked that the table agreed with
the runs that produced it.

**Added**
- **The complete ledger** (`/admin/ledger`) — every rupee from all four engines
  in one table, with filters for month, engine, payment status and member,
  sortable columns, server-side paging and CSV / Excel / PDF downloads. Opens on
  the current month; a member search lifts that automatically and looks across
  every month, because pinning a search to one month makes search look broken.
- **The entry page** (`/admin/ledger/{id}`) — one amount explained in full: the
  member, the engine, the month, the arithmetic, the source record with a link
  to it, the run that wrote it, the rest of that run, and the member's other
  rewards for the same month. This is the screen the phase's exit condition
  rests on.
- **Reconciliation** (`/admin/ledger/reconciliation`) — eight checks over one
  month, each stated in the terms the engine it tests actually promises:
  1. every amount belongs to a completed run of its own month and engine;
  2. every source record still exists;
  3. Direct and Target amounts multiply out exactly;
  4. every pool was shared out in full, to within rounding;
  5. no member was paid twice from the same source;
  6. each engine's ledger total matches the total its run recorded;
  7. the Direct ledger equals the month's approved sales;
  8. every confirmed payment names an admin and a date.
  Each check prints why it exists and lists the rows it is unhappy about.
- **A member statement** (`/admin/ledger/member/{member}`) — one member's whole
  reward history by engine and by month, reached from a new "Reward Ledger" tab
  on the member profile. `04_UI_UX_SPECIFICATION.md` listed that tab from the
  start; this is the first phase that could fill it.
- **Mark Paid for Direct and Upline.** Target and Company Club were given their
  own controls when they were built, so two of the four engines could calculate
  a reward that could never be confirmed. The ledger's control works for all
  four and delegates to the existing `RewardPaymentService` — one definition of
  what payment means, one month-end rule, one lock. "Mark all paid" is one
  engine at a time, deliberately.
- `App\Services\RewardLedgerService` — source resolution and the eight checks.
- `App\Http\Controllers\Admin\RewardLedgerController`, four views under
  `resources/views/admin/ledger/`.

**Changed**
- The sidebar's Reward Ledger entry is a real link with a submenu (Complete
  Ledger, Reconciliation) instead of a disabled "P13" badge.
- The member profile links out to the statement, alongside Sales and Targets.

**Database**
- None. `reward_ledger` already carried member, type, source, period, run, the
  frozen rate and the payment fields — the traceability this phase reports on
  was designed in from Phase 5 and needed reading, not extending.

**Tests**
- `RewardLedgerTest` — 22 cases: the four engines on one page, the opening
  month, search lifting the month, type and status filters, totals covering the
  whole filtered set rather than the page, a direct reward tracing to its sale,
  an upline share tracing to its seller, Mark Paid for Direct and for Upline, a
  month still running refusing payment, paying one engine at a time, a rejected
  unknown reward type, double payment refused, all three download formats with
  the month in the filename, and filters carried into the file.
- `LedgerReconciliationTest` — 19 cases. **Every check is tested twice: that it
  passes on a healthy month, and that it catches the fault it claims to catch.**
  Faults are injected with raw UPDATE statements, because the engines cannot
  produce them — which is the point.
- Whole suite green: 613 tests.

**Manual verification**
- Routes registered and resolving (`route:list --path=ledger`), Pint clean.

**Issues**
- The duplicate check can only be verified in its passing state: the unique
  index makes the failing case unconstructable through any route. The test
  asserts the index rejects the insert alongside it, so both halves are covered.
- The export ceiling is 5,000 rows, matching the Direct Sale report.

**Decision**
- **A check that cries wolf is worse than no check.** The Calculation Center
  already had to remove one false alarm on live data, so no check here is one
  rule imposed on four engines. `sqft × rate = amount` is asserted only for
  Direct and Target, because Upline and Company Club store the pool's inputs on
  the row and pay a share of it — an operator who checked the multiplication on
  an upline row would otherwise conclude the system had underpaid. Those two are
  reconciled pool by pool instead, with a paisa a row of rounding slack.
- **Only Direct is compared against raw sales**, for the same reason.
- **Reconciliation never writes.** A report that could repair what it measures
  could hide a fault by fixing it. Two tests hold the line: one asserts a broken
  month is unchanged by reconciling it, and one asserts the service contains no
  write path at all.

**Next**
- Phase 14 — Reports.

---

### 2026-08-25 — Downloads on every report, and one confirmation dialog

Client request: *"show sweetalert when mark paid. show the proper details of the
person inside popup"* and *"show pdf, excel, csv option of datatable … like
direct sales datatable, one month two month three month, company club eligible
members. when download then perid month also be there."*

**Added**
- **CSV / Excel / PDF downloads** on the Direct Sale report, all three Target
  screens (achieved and not-reached, per level) and the Company Club eligible
  member list — plus the Member Status report, which already had them.
- `App\Support\Export\TableExport` — one place that turns a title, a subtitle,
  headings and rows into any of the three formats. `XlsxWriter` and
  `PdfTableWriter` moved here out of the Member Status module, so the whole
  application shares one implementation instead of two.
- `resources/views/admin/partials/export-menu.blade.php` — the download control,
  shared by every page that offers one.
- **The period travels with every download**, in three places: the filename
  (`target-1-month-achieved-2026-07.pdf`), a context line inside the file, and
  the PDF's subtitle. A month's figures with no month on them get paid twice.
- **SweetAlert2** for every confirmation in the back office. `window.App.confirm()`
  takes a title, a sentence and a list of labelled details, so a Mark Paid dialog
  now shows WHO is being paid — member code, name, mobile, target/reward, month,
  amount — instead of a browser prompt that can only ask "are you sure?".

**Changed**
- The two generic confirmation handlers (`[data-confirm]` in app.js,
  `[data-confirm-submit]` in sale-entry.js) now open the shared dialog. That one
  change upgrades every existing confirmation — sale entry, Mark Paid on Target
  and Company Club, Mark all paid, recalculation, settings — with no per-page
  work. Both replay the submit after confirming, because the dialog is async.
- Target and Company Club Mark Paid buttons carry `data-confirm-details`, so the
  person and the amount are in the dialog.
- The Member Status payment modal uses the same dialog.

**Database**
- None.

**Tests**
- `ReportExportTest` — 11 cases: each format on each page, the month in the
  filename and inside the file, filters carried into the download, achieved and
  not-reached as separate files, unknown formats 404, guests redirected, and an
  uncalculated Company Club month exporting an empty table rather than an
  invented one.
- Whole suite green: 572 tests.

**Issues**
- **A demo seeder re-run broke 17 ledger links, and they were repaired.** The
  July demo seeder deletes and re-inserts its own sales. Between its first run
  and a re-run, a July Company Club reward was marked paid, which locks the
  month against recalculation — so the delete/insert went through and the
  rebuild did not, leaving 17 direct-reward rows pointing at sale ids that no
  longer existed. Every one was repointed to the identical replacement sale
  (same member, same Sq.Ft., same month); no amount changed and no payment was
  touched. The seeder now checks both months for a paid reward BEFORE deleting
  anything and refuses with an explanation, so the sequence cannot repeat.

**Decision**
- The export writers moved OUT of the Member Status module rather than being
  copied. The module now uses the application's shared utilities, which is a
  deliberate step away from "lift the folder out and it is gone" — worth it to
  avoid two implementations of a PDF writer.

**Next**
- The remaining tables (Sales History, Upline ledger, Team Sales) can take the
  same partial and the same `TableExport` call whenever they are wanted.

### 2026-08-25 — Team Target: the winning prizes are fixed amounts

Client request: *"here need to update winning prize of target month. 50000 for
one month, 2 lakh win- 2 month, 7 lakh winning amount - 3 month target"*.

**Changed**
- The three targets now win FIXED PRIZES instead of threshold × ₹30:

  | Target | Threshold | Window | Was | Now |
  |---|---|---|---|---|
  | 1 | 5,000 Sq.Ft. | 1 month | ₹150,000 | **₹50,000** |
  | 2 | 10,000 Sq.Ft. | 2 months | ₹300,000 | **₹200,000** |
  | 3 | 35,000 Sq.Ft. | 3 months | ₹1,050,000 | **₹700,000** |

  Thresholds and month counts are unchanged. Only the prize moved.
- `TargetLevel::reward()` now READS `rewards.targets.*.reward` instead of
  multiplying threshold × rate. The prize is the authoritative figure; nothing
  derives it any more.
- `TargetLevel::rate()` is now a DERIVED per-level value (prize ÷ threshold =
  ₹10 / ₹20 / ₹20), kept only so `sqft × rate = amount` still holds on every
  `reward_ledger` row, which is what reconciliation reads. The three prizes
  cannot be expressed as one shared rate, which is why the single Target rate
  had to go.
- `rewards.rates.target` (30) is marked SUPERSEDED in config. Nothing reads it —
  `RewardType::Target->rate()` has no callers — and it was left in place rather
  than removed so that method's return value does not silently change.
- Docblocks on `TargetLevel` and `TargetRewardService`, and the rule text in
  `02_BUSINESS_RULES.md` §3 / §3.1, `01_MASTER_DEVELOPMENT_PLAN.md` Phase 8,
  `06_TESTING_AND_ACCEPTANCE.md` and `PROJECT_STATE.md`.

**Database**
- None. No migration, no column, no data change. Every verdict already freezes
  its own threshold, rate and prize onto `target_calculations`, and every ledger
  row already carries its own amount, so **runs that have already happened keep
  the figures they were calculated with**.

**Tests**
- New: `the_confirmed_prizes_and_their_derived_rates_stay_consistent` pins the
  client's three figures and asserts `sqft × rate = prize` for each level, so a
  prize edited without its rate fails the suite instead of quietly breaking
  reconciliation.
- Updated 20 assertions across `TargetRewardTest`, `MultiMonthTargetTest`,
  `CalculationCenterTest`, `RecalculationTest` and `TargetPagesTest`. Each was
  read in context — `300000.00` meant "two Target 1 prizes" in one place and
  "the Target 2 prize" in another.
- Whole suite green: 560 tests.

**Issues**
- **Unpaid past periods will change amount if recalculated.** A month whose
  target rewards are still `posted` recalculates at the new prizes — a member
  who was showing ₹150,000 will show ₹50,000. Paid rewards are safe: payment
  locks the period against recalculation, so anything already confirmed keeps
  its old figure and its ledger row is untouched.

**Decision**
- The prize replaced the rate rather than the rate being changed to suit, because
  50,000/5,000 = 10 while 200,000/10,000 = 20 — no single rate reproduces all
  three. Making the prize authoritative is what the client actually described:
  a winning amount per target, not a price per Sq.Ft.

**Next**
- If the client also wants the THRESHOLDS revisited, that is a separate change:
  the ladder's difficulty is now decoupled from its prizes.

### 2026-08-25 — Company Club: the direct-member pool

Client request: *"one more calculation box ... total sales sqft * 30 rs
multiply. elegible members are, who directly attached with company club. show
equal amount"*, plus *"can you make that boxes colour seperate"*.

**Added**
- A second pool on the Company Club overview, shown as its own card: the same
  eligible Sq.Ft. as the main pool × ₹30, split equally between the ACTIVE
  members attached directly to the Club (the members with no sponsor).
- `CompanyClubCalculationService::directClubPool()` — the arithmetic, in the
  write-nothing service beside `compute()`, returning the same shape (pool,
  count, share, distributed, residual).
- `CompanyClubTreeService::directClubMemberCount()` / `directClubMembers()`, and
  `active_direct_club_members` on the network summary.
- `rewards.company_club.direct_rate` (30), with the rule written out in config.
- `.figure-tile` / `.calc-card` SCSS: a colour per step of a formula, and a
  colour per calculation card, so the two pools are told apart before they are
  read. Nine muted tones, including teal/purple/indigo/pink for the new card.

**Changed**
- The existing live-pool tiles moved from four identical grey boxes to the new
  coloured `.figure-tile`.

**Database**
- None. The direct pool is a reported figure and writes no ledger row.

**Tests**
- `the_overview_shows_the_direct_club_pool_split_between_direct_members`
- `the_direct_club_pool_excludes_inactive_direct_members`
- Full suite: 484 passed.

**Decision**
- The Sq.Ft. base is deliberately SHARED with the main pool, so the
  inactive-seller exclusion applies to both and the two can never disagree about
  how big the month was.
- Inactive direct members are excluded from the divisor, consistent with every
  other engine in the module. The page says so out loud when it happens, because
  the count would otherwise contradict "Directly under the Club" above it.
- Rate lives in config, not `company_club_settings`: the main rate is editable
  because runs freeze it, and nothing freezes this one yet.

**Next**
- Confirm with the client whether this pool should become a real distribution
  (its own run + ledger rows) or stay a reported figure.

---

### 2026-08-19 — Phase 11: Company Club

Client confirmations that shaped it: **"50 rs"** for the rate, and *"i think it
recalculation will help to keep update and need to show past or previous date of
calculation. so admin never confused about it."*

**Added**
- `app/Services/CompanyClubCalculationService.php` — the arithmetic. **Writes
  nothing at all**, which is how Preview is guaranteed not to create a financial
  row: the object that computes has no access to the ledger, no run and no
  transaction. Preview and the real calculation call the same method.
- `app/Services/CompanyClubTreeService.php` — the upward walk (active sponsors
  only, inactive skipped without consuming a level, cap of 5 ACTIVE levels, the
  Club never a level) plus lazy tree loading.
- `app/Services/CompanyClubService.php` — preview, calculate, recalculate, run
  codes, settings.
- `app/Services/CompanyClubReportService.php` — every read the screens make.
- Models `CompanyClubSetting`, `CompanyClubCalculationRun`, `CompanyClubReward`,
  `CompanyClubEligibilityPath`.
- Controllers `CompanyClubController`, `CompanyClubReportController`,
  `CompanyClubSettingsController`; request
  `CompanyClub/UpdateCompanyClubSettingsRequest`.
- Nine Blade views under `resources/views/admin/company-club/` plus the
  `_run-status`, `_period-filter` and `_calculation-tree` partials.
- `resources/js/company-club.js` — lazy tree expansion over the standard AJAX
  envelope.
- `Money::inr()` — Indian digit grouping at exactly 2 decimals
  (`25,00,000.00`, `1,47,058.82`), as the specification writes every figure.

**Changed**
- `config/rewards.php` — `company_club` rate **30 → 50**, with a new
  `company_club` block documenting the whole rule and the override.
- `app/Services/PeriodRecalculationService.php` — **the one existing engine file
  touched.** Company Club now runs last in the period rebuild, guarded by "only
  if this month already has a completed run".
- `resources/views/layouts/partials/sidebar.blade.php` — the disabled Phase 11
  placeholder becomes a seven-item submenu.
- `resources/views/admin/calculations/index.blade.php` — Company Club leaves
  "Not built yet" and becomes a described link out to its own module.
- `resources/views/admin/sales/show.blade.php` — "not yet built" becomes the
  sale's real contribution, or an explicit "excluded — seller inactive".
- `resources/scss/app.scss` — calculation-tree and network-root styling.
- Documentation reconciled to ₹50 in `02_BUSINESS_RULES.md` §5 and §8,
  `05_CALCULATION_ENGINE_SPEC.md` §E, `00_PROJECT_README.md`,
  `07_CLAUDE_WORKFLOW_PROMPT.md`, `09_PHASE_PROMPTS.md`,
  `app/Services/README.md` and the `RegistrySale` docblock.
- `tests/Feature/Admin/DashboardAccessTest.php` — the "unbuilt screens say when
  they arrive" test moved from Company Club to Reward Ledger (Phase 13).

**Database**
- 4 new tables: `company_club_settings`, `company_club_calculation_runs`,
  `company_club_rewards`, `company_club_eligibility_paths`.
- No change to `members`, `registry_sales`, `reward_ledger` or
  `calculation_runs`. `CalculationRunType::CompanyClub` and
  `RewardType::CompanyClub` already existed and were finally used.
- Ledger rows use `source_type = 'company_club_pool'`, `source_id = 0` — the
  source is the whole month, not one record. The existing unique index
  `(member_id, reward_type, source_type, source_id, period)` therefore enforces
  **one Company Club reward per member per month** in the database.

**Tests**
- 79 new (51 engine + 28 screens). Suite: **454 passing, 1,607 assertions**,
  0 failures — the 379 that existed before are untouched.
- Covering all 20 requested acceptance items, plus: the calculation service is
  structurally incapable of writing; the Company Club walk is asserted to agree
  with the Upline walk (a tripwire against silent divergence, since the code is
  deliberately duplicated); running Company Club leaves the Direct and Upline
  ledgers byte-identical; editing the rate cannot rewrite a recorded run; the
  database itself refuses a second reward for one member in one month.

**Manual verification**
- Live August 2026 calculated as `CC-2026-08-0001`: **12,050.50 Sq.Ft. × ₹50 =
  ₹6,02,525.00**, 7 unique recipients, **₹86,075.00 each, residual ₹0.00**.
  Ledger sums to ₹6,02,525.00 across exactly 7 rows.
- RS16's chain verified end to end: RS15 L1, RS14 L2, RS13 L3, **RS12 skipped
  (inactive)**, RS11 L4, RS10 L5 — five ACTIVE levels across six hops, stopping
  at RS10's null sponsor without the Club ever counting as a level. RS11 and
  RS10 each qualified through 3 separate branches and were paid once.
- `npm run build` clean; the engine figure was predicted by hand during the
  Phase 1 audit and reproduced exactly by the implementation.

**Decision**
- **The rate is ₹50 and the money IS distributed**, overriding five places in
  the repo that said ₹30 and informational-only. This also answers the
  long-standing open question 11. Cost per Sq.Ft. is now ₹140 before targets
  (40 + 50 + 50); raised with the client and confirmed.
- **First calculation explicit, every later one automatic.** Spec §16 requires
  preview-then-commit; the client wants figures kept current. Both hold: nothing
  writes until an admin commits a month, and from then on that month rebuilds
  itself on sale entry. A month nobody has calculated is left alone.
- **Superseded runs keep their figures.** Recalculation clears the detail rows
  but the run snapshot survives with its code, pool, recipient count, timestamp
  and admin — which is what lets every screen show the previous calculation.
- **The upward walk is duplicated rather than shared with `UplineRewardService`.**
  Sharing would let a change to the upline rule silently move Company Club
  money. A test asserts the two agree today.
- **Company Club is the only engine that consults the seller's status.** Its
  total Sq.Ft. can legitimately be lower than the Direct total; the screens say
  so rather than leaving it to be discovered.

**Issues**
- **Rounding remainder policy still unconfirmed** (raised in
  `03_COMPANY_CLUB_DECISIONS.md`). Shares are rounded half-up individually and
  any residual is displayed, following the Phase 6 upline precedent. No
  adjustment entry or last-recipient sweep was invented.
- **Void/reversal workflow not built.** A paid month simply refuses to
  recalculate, as elsewhere in the system.
- **Indian digit grouping is used on Company Club screens only.** The older
  screens use Western grouping. `Money::inr()` is central, so applying it
  app-wide is a one-line change per view if wanted.

**Added later the same day — Income Distribution screen** (client request:
*"show company club and their direct members and their downlines. show sales
member and their 5 active upline members as a tree ... keep total sales from
seller member there. and do not write any l2 l1 type information"*)
- `admin/company-club/income` — two trees on one page. **Sales and the members
  they paid**: every seller with their summed Sq.Ft. for the month and the
  sponsors above them the sale made eligible, each with their amount. **Network**:
  the Club as root, its direct members, and their downlines, each node carrying
  own sales, whole-branch total and reward.
- **No level numbering anywhere on the screen**, by request. The nesting says who
  is above whom. A test asserts `L1`/`L2`/`Level 1` never appear on the page.
- Skipped inactive sponsors ARE shown, greyed and struck through — a chain that
  silently jumped over somebody would look broken rather than simple. Inactive
  sellers appear too, marked "not counted".
- **Depth-limited with load-more.** Three levels render immediately; deeper
  branches load one at a time over AJAX. **A collapsed branch still shows its
  full branch total** — the picture is partial, the figures never are. Tested.
- Cost is flat: three queries for the whole tree regardless of size, asserted by
  a test that fails if it ever starts walking per node.
- `CompanyClubReportService::incomeTree()`, `incomeBranch()`, `sellerChains()`;
  views `income`, `_income-node`, `_income-children`; 17 further tests.

**Next**
- Phase 12 (Calculation Center) or Phase 13 (Reward Ledger — where Direct and
  Upline get their Mark Paid screens; Company Club already has its own).

---

### 2026-08-18 — Phases 9 & 10: Targets 2 and 3, and a visible sale date

Client: *"two months target value is 10000 and three months target is 35000. no
need to make any option from admin. once complete the first then next target and
given month work."* Plus: *"give me a feature to insert old data into sales to do
testing"* — answered as *"just give a date calendar into sale entry, so past date
and current date I can put; by default current date will be selected."*

**The admin settings screen is no longer needed.** Phase 9 was blocked on it
because Targets 2 and 3 were documented as admin-configured. The client dropped
that in the same breath as confirming the values, so all three targets are now
fixed constants in `config/rewards.php`. Every verdict still freezes its own
threshold and rate onto the row, so editing a constant cannot rewrite history.

| Target | Threshold | Window | Reward |
|---|---|---|---|
| 1 | 5,000 Sq.Ft. | 1 month | ₹150,000 |
| 2 | 10,000 Sq.Ft. | 2 months | ₹300,000 |
| 3 | 35,000 Sq.Ft. | 3 months | ₹1,050,000 |

**Two rules were not in the documentation and were confirmed before building:**
a member who reaches the threshold early is **paid immediately** rather than
waiting for the window to close, and a window that closes short **resets to zero
and opens a fresh block** rather than sliding forward a month at a time. Windows
therefore never overlap — a month belongs to exactly one attempt, which is what
the confirmed "never a rolling window" means once a target spans months.

**The rate for Targets 2 and 3 was never separately stated.** It is taken as ₹30,
the confirmed "Target ₹30" of the four rates in `02_BUSINESS_RULES.md` §8 — a
rate for the Target engine rather than for Target 1 alone. It reproduces the
₹300,000 / ₹1,050,000 that `config/rewards.php` has carried since Phase 1. Flagged
rather than assumed silently.

**Added**
- `App\Enums\TargetLevel` — threshold, window length, rate, reward and the next
  rung, in one place. `TargetRewardService::LEVEL` (a hardcoded `1`) is gone
- `App\Enums\TargetOutcome` — achieved / in progress / missed. **Derived, never
  stored**: `achieved` stays the single source of the binary verdict and the
  once-ever guard hangs off it, so a row is in progress when it is not achieved
  and its period has not reached `window_end`. Two columns able to disagree about
  the same fact would be worse than a computed one
- `target_calculations` gains `window_start`, `window_end`, `window_months` and
  `cumulative_sqft`. `achieved_sqft` keeps its meaning — the team figure for THIS
  month — and `cumulative_sqft` is the window-to-date total the threshold is
  tested against. Existing Target 1 rows backfilled to a one-month window
- `TargetRewardService::windowMonths()` — the month-by-month build-up behind a
  multi-month verdict. "11,200 of 10,000" is unreadable until you can see it was
  4,000 then 7,200
- The three targets each get a sidebar entry with Achieved / Not Reached, and the
  report pages take a `level` parameter. The level pills carry a count, so an
  empty level says so rather than looking broken

**The engine now REPLAYS history instead of reading its own previous rows.**
This is the significant change. A Target 1 verdict was a statement about one
month and could be computed from that month alone. A Target 2 verdict cannot: it
depends on which target the member is on, when their window opened, and what has
accumulated inside it. The obvious approach — read last month's stored verdict
and carry it forward — breaks the moment a sale is **back-dated**, which is
precisely the feature added alongside it: every later verdict would be wrong
while still looking authoritative. Instead `replay()` rebuilds each member's whole
progression from `team_calculations` and keeps only the period being written. The
stored rows are an output of the ladder, never an input to it.

**The cost, paid explicitly:** rebuilding one month invalidates the months after
it. `PeriodRecalculationService` now cascades **Target only** across every later
period — Direct, Upline and Team Sales each describe one month and are untouched.
If any month in the cascade holds a paid reward the whole rebuild is refused up
front rather than half-applied.

**Changed**
- Team Sales must now be calculated for **every month with sales up to the
  period**, not just the period itself. A multi-month window reaches backwards, so
  an un-rolled-up earlier month would silently contribute zero and could turn an
  achievement into a miss. The error names the months
- A quiet month inside an open window is still recorded, so the accumulated total
  does not appear from nowhere when the window closes. A member on the one-month
  target with no sales is still not recorded — otherwise every member would land
  on the "not reached" page every month
- The Calculation Center's target row covers all three levels and is renamed
  **Team Targets**; Two and Three Month Target are gone from "not built yet"
- One target run covers all three levels: every member is measured against
  whichever one they are on, so splitting by level would mean three runs that each
  had to know about the other two

**Sale entry — the date is now on the form**
- The registry date existed but sat inside the collapsed "additional detail"
  accordion and started **empty**, so recording a past sale looked impossible. It
  is now a labelled date picker in the main form, **prefilled with today**, capped
  at today, and sits beside a note saying it decides the reward month and that
  saving rebuilds that month and re-judges every month after it
- No new bulk tool was built: the client asked for the date picker specifically,
  and back-dating already worked server-side

**Tests**
- 20 new tests (379 total, 1,306 assertions, all passing)
- New `MultiMonthTargetTest` (14): the window opens the month after the previous
  target and not before; accumulation across months; a quiet month mid-window is
  recorded; reaching it early pays at once and opens the next target immediately;
  a closed-short window resets to zero and opens a fresh block; **windows never
  overlap** — 3,000 then 6,000 then 5,000 must not pay, where a rolling window
  would; the reward is fixed at the threshold on every target; the Target 2
  surplus does not carry into Target 3; the whole ladder pays 150,000 + 300,000 +
  1,050,000 and then stops measuring; Target 3 across three months; **back-dating
  re-judges every later month**; the cascade is refused when a later month is
  paid; the engine refuses when an earlier month with sales was never rolled up
- Target pages: the level switcher shows each target's own population, all three
  levels render, an unknown level falls back to the first, and a multi-month
  verdict shows its month-by-month build-up
- Sale entry: the form offers a date picker defaulting to today, and a back-dated
  sale is rewarded in the month it is dated
- Four existing tests changed expectation rather than being repaired — they
  asserted that a member who achieved Target 1 gets **no verdict** the following
  month, which was true only while Target 2 did not exist. They now assert the
  member is measured against Target 2 from that month

**Manual verification**
- **The new engine reproduces the live data exactly.** Replaying June, July and
  August against the stored rows gives the same 7 / 7 / 11 members measured, the
  same single achiever, and the same ₹150,000. No live figure moved
- All three target pages, both tabs, the sale entry form and the Calculation
  Center return 200 on live data
- At `?level=2` the sidebar opens the Two Month Target group with Achieved
  active, and leaves the other two collapsed; Company Club (P11) and Reward Ledger
  (P13) are still correctly marked as not built
- The sale-entry date field renders in the main form (line 370) ahead of the
  optional accordion (line 432), prefilled with today and capped at today

**Decision**
- No settings screen, and no bulk test-data generator. Both were on the table and
  both were declined by the client in favour of fixed constants and a date picker

**Next**
- Phase 11 (Company Club) is the only reward engine left. Phase 13's Reward Ledger
  is where Direct and Upline get their Mark Paid screens

---

### 2026-08-18 — Calculation Center rewritten; reward reports moved out of it

Raised by the client: *"I cannot understand the calculation first page. While I am
clicking and opening I am not understanding what the page needs to say"* and
*"why is the upline page connected with the calculation page"*. Both had the same
root cause — the screen still described the Phase 5 workflow.

**The page was built for a workflow that no longer exists.** Until 2026-08-17 an
operator picked a month and pressed four **"Calculate X"** buttons. Since sale
entry started rebuilding every engine automatically, all four are already done by
the time anyone opens the page: every card sat in its "Already calculated — run
#12" state with nothing to press, and the page never said what it was for.

**Changed**
- The Calculation Center is now a **state** page, not a **do-work** page. It opens
  by saying nothing here needs pressing day to day, then shows each engine twice —
  **worked out from the sales as they stand now**, beside **what its last run
  actually stored**. Agreement is the normal case and is stated; disagreement is
  flagged on the engine and on the month
- One **Rebuild this month** button replaces the four separate ones, running all
  four engines in dependency order through `PeriodRecalculationService`. Four
  buttons let an operator run Team Sales without re-running Target after it, which
  silently judges this month's targets against an older rollup. Single-engine runs
  are kept but demoted behind a closed disclosure that says why
- The month now carries its own state — **Still running / Month over / Locked by a
  payment**, and **In step / Out of step / Never calculated** — with a sentence
  under it explaining what that means for the figures and for payment
- Months whose figures have drifted from their sales are listed at the top, the
  one thing on the page that actually needs an operator. Normally empty

**Removed — text that was no longer true**
- *"Recalculation is not available until Phase 12"* on all four cards. It has been
  automatic since 2026-08-17
- The controller docblock claiming *"PHASE 5 SCOPE: only Calculate Direct is
  wired"* — all four have been wired since Phase 8
- The sidebar tagging Calculations as **Phase 12**; it shipped in Phase 5
- The Team Sales card advertising targets of *"5,000 / 10,000 / 35,000 Sq.Ft."*.
  Only 5,000 is client-confirmed; Targets 2 and 3 are admin-configured and their
  numbers have never been agreed. The "not built yet" entries now say that instead
- *"Calculate All — Phase 12"*, which Rebuild now delivers

**Moved — reward reports out of the machine room**
The sidebar's **Upline Rewards** pointed at `/admin/calculations/upline`, so
opening a reward report dropped the operator inside Calculations, highlighted
**Calculations** in the menu and produced the breadcrumb *Calculations › Upline*.
The Upline, Team Sales and Direct-ledger reports had been written into
`CalculationController` for convenience in Phases 5–7; Direct Sale, built later,
was correctly given its own home under `rewards/`. Two reports of identical
purpose therefore lived in two different places.

- New `RewardReportController` holds `directLedger`, `uplineLedger`,
  `uplineExplain`, `teamSales` and `teamContributors`
- New URLs: `/admin/rewards/upline`, `/admin/rewards/upline/explain/{member}`,
  `/admin/rewards/team-sales`, `/admin/rewards/team-sales/contributors/{member}`,
  `/admin/rewards/direct-ledger`. Views moved to `resources/views/admin/rewards/`
- **The five old URLs redirect**, so bookmarks and links already sent to the
  client still land on the right page. They are named `admin.moved.*` — an unnamed
  route inside a `->name()` group inherits the bare prefix and several would then
  collide on the same name
- Breadcrumbs on the moved pages now read *Rewards › …* rather than linking back
  into Calculations, and their empty states say "check the calculation state for
  this month" instead of "run the calculation", which is no longer a thing an
  operator does
- **Team Sales** was a delivered screen reachable only from inside the Calculation
  Center. It now has its own sidebar entry under Rewards. This is a deliberate
  addition to the navigation in `04_UI_UX_SPECIFICATION.md`, whose list predates
  the screen existing

**Target is deliberately NOT compared, and this was found on live data.** The
first build compared every engine's live preview against its stored run, and
August immediately reported the target engine as mismatched: live ₹0 against a
stored ₹150,000. Neither figure is wrong. Achievement pays **once per member
ever**, so RS4 — who won in that very month — is graduated and is no longer
measured, and a fresh preview of a month that produced a winner reports zero
forever. Comparing them would raise a false alarm on every month that ever had an
achiever. The Target row now shows what the month recorded, with the count
currently being measured and an explanation, and carries a neutral **Verdict
recorded** badge. What the verdict actually rests on is the Team Sales figure
above it, which *is* compared. A test pins this so it cannot regress into a false
alarm.

**Added**
- `PeriodRecalculationService::periodStatus()` — one definition of "is this month
  level with its sales", now shared by the single-period check and
  `stalePeriods()`, which was rewritten to use it. Judged on Sq.Ft. against the
  **Direct** run, because Direct is the only engine whose stored total is the
  plain sum of the period's approved sales; the other three derive theirs through
  the network or a threshold, so a difference there would not mean a sale went
  missing
- `POST /admin/calculations/rebuild` → `CalculationController@rebuild`

**Tests**
- 8 new tests (360 total, 1,204 assertions, all passing)
- The page leads with what it is for and no longer carries the four stale claims;
  live and stored are shown side by side and agree after a run; a sale arriving
  without a recalculation behind it is flagged on the engine and in the month
  list; Rebuild runs all four engines and the recorded order is Direct → Upline →
  Team Sales → Target; Rebuild is refused once the month holds a paid reward and
  supersedes nothing; an invalid period is rejected; a month with a target winner
  is **not** reported as a mismatch; the five old report URLs redirect to the new
  ones; the menu sends Upline Rewards to `/admin/rewards/upline`
- `the_center_offers_the_wired_engines_and_marks_the_rest` was replaced rather
  than repaired — it asserted the presence of the four "Calculate" buttons and the
  Phase 12 markers, which is precisely what the client could not read

**Manual verification**
- Live, all three months in step: June 2,300.00, July 3,500.00, August 11,500.50
  Sq.Ft., each matching its Direct run exactly; 0 stale periods
- August page renders at 200 with Direct ₹460,020.00, Upline ₹287,500.01 and Team
  Sales 11,500.50 Sq.Ft. all reading **Matches the sales**, the month reading
  **Still running / In step with its sales**, and the target showing ₹150,000.00
  under **Verdict recorded**
- All five moved report pages return 200; all five old URLs return 302 to the
  correct new address, query string preserved
- On `/admin/rewards/upline` the sidebar now highlights **Upline Rewards** rather
  than Calculations, and the breadcrumb reads *Rewards › Upline Reward*

**Decision**
- Reward **reports** live under `rewards/`; the Calculation Center holds only
  engine state and the controls that rebuild it. Any new reward report belongs
  under `rewards/` and should use `ResolvesReportFilters`

**Next**
- Phase 9 (Two Month Target) still needs the admin Settings screen first — its
  threshold and rate are admin-configured and cannot be built against config
  constants. Unchanged by this task

---

### 2026-08-17 — Sales History brought up to the report standard

**Changed**
- Sales History now opens on **today**, matching the Direct Sale report
- Quick ranges (Today / Last 7 days / This month / All time), a member dropdown,
  page sizes **25 / 50 / 150 / 250 / 500 / 1000**, and sortable Registry no. /
  Date / Member / Sq.Ft. columns
- Each row now shows the direct reward the sale earned (`Sq.Ft. × ₹40`), and the
  filtered totals carry a reward figure beside the Sq.Ft.
- Four tonal stat tiles and the shared `data-table` styling; the Project/Site and
  Entered-by columns fold away on small screens rather than crushing the table
- Filters travel with every paging and sorting link

**Added**
- `ResolvesReportFilters` trait — one definition of the date presets, page sizes
  and sort whitelist, now shared by Sales History and Direct Sale. The two pages
  answer "which dates, how many rows, sorted by what" identically because they
  ask the same code

**The today-default has a deliberate exception.** A request carrying a search
term, member, project or period is looking for something specific, so it searches
every date instead of being pinned to today — otherwise search would look broken.
Explicit dates always win over both. Two tests cover the exception directly.

**Fixed**
- The member profile's new "Sales" tab link passed `member=RS4`, which the
  controller does not read, so it silently listed every member's sales. Now
  `member_id`. Introduced in the previous commit and caught while wiring the
  member dropdown

**Tests**
- 9 new tests (352 total, 1,163 assertions, all passing)
- The page opens on today and hides an older sale; quick ranges widen past it;
  a search and a member filter both still reach a sale from months ago; each row
  shows its direct reward (1,250.50 × 40 = 50,020.00); all six page sizes offered
  and an unlisted one rejected; sorting; an unknown sort column ignored; paging
  keeps filters
- `history_is_paginated` was implicitly relying on the factory's random dates
  landing in range. It now dates its fixtures explicitly, so the page size decides
  the result rather than the calendar

**Manual verification**
- Live: 1 sale today, 6 this month, 11 all time, 2 for RS4 — matching the
  database. All-time totals read 13,700.50 Sq.Ft. and ₹548,020.00, reconciling
  with the ledger

---

### 2026-08-17 — Direct Sale report, live dashboard, UI pass

**Added**
- **Direct Sale** report under Rewards (`/admin/rewards/direct-sales`). Opens on
  **today's** entries, because that is the question an operator has during the day
- Every row works the reward out in the open: `Sq.Ft. × ₹40`, with totals covering
  the whole filtered set rather than the visible page
- Filters: date range (with Today / Last 7 days / This month / All time presets),
  member (defaulting to all), rows per page (**25 / 50 / 150 / 250 / 500 / 1000**),
  and sortable Date / Member / Sq.Ft. / Reward columns. Every filter survives
  paging and sorting
- `DashboardMetricsService` — real member, sales, reward and target figures
- Sales-trend column chart, six months, built from HTML boxes so it reflows at
  every width with no JavaScript; the month in progress is hatched rather than
  drawn short, and a `<details>` table underneath carries the same data
- Top sellers, latest sales, and a stale-month warning on the dashboard

**Changed**
- **Build-phase markers removed from everything delivered.** The dashboard's eight
  "Available in Phase N" tiles now carry real numbers; the member profile's
  Performance panel shows this month's own/team Sq.Ft., target progress and the
  three reward amounts; the sale detail shows what that sale actually earned.
  Items that genuinely do not exist yet still say when they arrive, so no menu
  entry is a dead end
- The topbar's permanently-disabled "Global search available from Phase 2" box is
  now a working member search rather than relabelled dead chrome
- Member profile's disabled Sales/Targets/Reward-Ledger tabs became links to the
  screens that now exist
- `RegistrySale::approved()`, `forPeriod()` and `betweenDates()` are table-
  qualified. `members` also has a `status` column, so any caller joining it hit
  "column 'status' is ambiguous" — found by the dashboard's top-sellers query
- Premium Bootstrap pass: hero band, tonal stat cards, reward engine cards with
  paid/outstanding meters, sortable table styling, softer elevation

**Decision — Bootstrap, not Tailwind.** Tailwind was removed in Phase 1 in favour
of Bootstrap 5, and every one of the ~35 existing Blade views is written in
Bootstrap. Adding Tailwind back would put two CSS resets and two utility
vocabularies on the same page: the cost lands as specificity conflicts and roughly
doubled payload, not polish. The premium look is built from Bootstrap's own tokens.

**Chart colour** is `#2a78d6`, validated for lightness band, chroma, colour-vision
separation and contrast against the white card surface. The brand `#1b4d8f` failed
the lightness band as a data mark and is kept for chrome only.

**Tests**
- 18 new tests (343 total, 1,135 assertions, all passing)
- Direct Sale: opens on today; the row multiplication is exact (1,234.56 × 40 =
  49,382.40); the total covers all 30 matching sales while page one shows 25; the
  member filter defaults to everyone; explicit date ranges; every documented page
  size offered and an undocumented one rejected; pagination keeps filters; sorting
  both directions; an unknown sort column is ignored rather than trusted; a
  malformed date does not break the page
- Dashboard: real figures rather than placeholders, no phase markers on delivered
  features, unbuilt features still say when they arrive, chart offers a table view

**Manual verification**
- Dashboard against live data: 16 members (15 active, 10 leaders), 11 sales /
  13,700.50 Sq.Ft., Direct ₹548,020, Upline ₹434,999.99, Target ₹150,000, trend
  Jun 2,300 → Jul 3,500 → Aug 7,900.50
- Direct Sale page: 1 sale today, 6 this month, 11 all time — matching the
  database, with the all-time total reading ₹548,020.00 against 13,700.50 Sq.Ft.

**Issues**
- Company Club (Phase 11) is the only dashboard figure still absent, because the
  engine does not exist. It is not shown as an empty tile

---

### 2026-08-17 — Live recalculation and payment confirmation

**Defect reported by the client:** a sale entered after a month had been
calculated never reached any reward. Confirmed on live data — sale #11 was entered
at 16:35, four hours after the August target run closed at 12:42. **₹256,020 of
direct rewards were unpaid and invisible** across five August sales.

The engines always read the database; the results were frozen snapshots and a
completed run refused to run again. Recalculation was deferred to Phase 12, which
made ordinary data entry silently wrong.

**Added**
- `PeriodRecalculationService` — rebuilds all four engines for a period in
  dependency order (Team Sales before Target) inside one transaction, so a month
  is never left with a fresh Direct total beside a stale Target verdict
- Entering a sale now recalculates its month immediately. No button to remember
- `RewardPaymentService` — Mark Paid confirmation, disabled until the month ends
- `LedgerStatus::Paid`, `CalculationRunStatus::Superseded`
- Mark Paid per achiever and Mark All Paid per month on the target screens, with
  paid-by and paid-at shown; a manual Recalculate control for older months
- `stalePeriods()` — reports any month whose stored figures no longer match its sales

**Changed**
- `CalculationRunService::execute()` takes an optional `clear` callback. Supplying
  it switches from first-time calculation to recalculation: previous results are
  deleted and previous runs marked superseded rather than the second run being
  refused
- Each engine gained `recalculate()`, declaring which of its own rows to discard
- Target screens show whether a month is provisional, payable, or locked

**Database**
- `reward_ledger` gains `paid_at`, `paid_by`, and a `(period, status)` index
- `calculation_runs` gains `superseded_at`

**Tests**
- 24 new tests (325 total, 1,062 assertions, all passing)
- The reported defect is pinned: a sale entered after a calculation is picked up,
  and entering one through the form recalculates with no explicit call
- Recalculating three times leaves exactly one set of results, not duplicates
- A target achievement can appear and disappear while the month is unpaid
- A paid reward locks its whole month; a sale into a locked month is still
  recorded and the reason reported rather than swallowed
- Mark Paid is disabled while a month is running and available once it is over

**Manual verification**
- All three live months recalculated. **August Direct went from ₹60,000 on
  1,500 Sq.Ft. to ₹316,020 on 7,900.50** — the missing ₹256,020 is now on the ledger
- Every approved sale now has a direct reward: 0 missing, and the ledger totals
  ₹548,020 against 13,700.50 Sq.Ft. × ₹40 exactly
- RS4's August target figure corrected from a stale 5,000.50 to 6,200.50
- 12 superseded runs kept as history; `stalePeriods()` reports nothing
- August shows "still running — provisional" with Mark Paid disabled, as today is
  2026-08-17 and the month is not over

**Decision**
- Client-confirmed 2026-08-17: figures recalculate on every sale entry and stay
  provisional until month end; Mark Paid is disabled by default and needs explicit
  admin confirmation
- The lock is period-wide, not per reward type: one confirmed payment freezes the
  whole month. The four engines describe one month between them, so recalculating
  Team Sales after a target reward was paid would move the ground the payment
  stood on
- The sale is the fact and the figures are derived, so a recalculation failure
  never rolls back a sale — the reason is surfaced instead
- **Assumption flagged:** "mark paid button will be disable default" is implemented
  as disabled while the month is still running, unlocking once it ends. This reads
  "until month end" as the intent; say so if a different gate was meant

**Issues**
- Direct and Upline rewards have no Mark Paid screen yet — payment is wired on the
  ledger generally but surfaced only on the target pages, as asked. The Reward
  Ledger screen (Phase 13) is where the other two belong

---

### 2026-08-17 — Phase 8 — Target 1 (One Month Target)

**Added**
- `TargetRewardService` — tests each member's team Sq.Ft. for a calendar month against
  5,000 and pays ₹150,000 on achievement
- `target_calculations` table: one verdict per member per period, with the threshold
  and rate frozen onto every row
- **One Month Target** in the sidebar with two pages beneath it — Achieved and Not
  Reached — sharing a month filter, tab badges and summary tiles
- A per-member page that draws their whole team as a tree with each member's Sq.Ft.,
  plus that member's own sale shown separately in a smaller font
- Target history table: every month a member has been measured
- Calculate One Month Target wired into the Calculation Center with a live preview

**Changed**
- Sidebar gained **submenu support** — a nav item with `children` renders as a
  Bootstrap collapse group; the parent highlights when either page is open
- The Team Sales report's Target column now links to the verdict instead of showing a
  "Phase 8" badge
- `config/rewards.php` targets rewritten with the confirmed rules; Targets 2 and 3
  carry a `rate` seeded from Target 1's ₹30 and explicitly marked unconfirmed

**Database**
- New `target_calculations`. Unique on `(member_id, period)` — one verdict per month —
  and on `(member_id, achieved_level)`, where `achieved_level` holds the target level
  on a win and NULL on a miss. Since MySQL permits unlimited NULLs in a unique index,
  misses never collide but a second achievement of the same target is impossible
- `team_calculations.target_sqft / achieved / reward_amount` remain NULL and are now
  dead columns — the verdict lives in `target_calculations`

**Tests**
- 47 new tests (301 total, 975 assertions, all passing)
- The client's own example is pinned: 7,000 against 5,000 pays ₹150,000 and explicitly
  not ₹210,000
- Calendar-month boundary: 3,000 on 30 June plus 3,000 on 1 July reaches 5,000 in a
  rolling window but achieves neither month
- A mid-month joiner is measured against the full 5,000, not a pro-rated figure
- Paying once is proven twice — the engine skips prior achievers, and a direct insert
  bypassing the engine is rejected by the database

**Manual verification**
- Runs #10-12 against live June/July/August data. June and July: 7 measured, 0
  achievers (best teams 2,300 and 3,500). August: 11 measured, 1 achiever
- **RS4 did 5,000.50 Sq.Ft. and was paid ₹150,000, not ₹150,015** — the 0.50 surplus
  discarded exactly as confirmed. Ledger row: `sqft 5,000.00 × rate 30.00 = 150,000.00`
- The contribution tree for RS4 totals 5,000.50, matching the measured figure
- Both pages rendered against live data: RS4 appears on Achieved with its own-sale
  figure and on neither Not Reached nor any later month

**Issues**
- None found in Phase 8

**Decision**
- Client-confirmed 2026-08-17 (docs/02_BUSINESS_RULES.md §3.1): calendar-month
  periods with no pro-rating; reward fixed at the threshold; surplus discarded; every
  member measured, not only Team Leaders; one active target at a time with unlimited
  retries on failure and a single lifetime payment on achievement; Targets 2 and 3
  admin-configured; member status not consulted
- The Target engine reads the Team Sales run rather than recomputing the rollup, so
  the two reports can never disagree. Team Sales must therefore be run first
- The ledger records the THRESHOLD Sq.Ft. so `sqft × rate = amount` holds on every row

**Next**
- Phase 9 (Two Month Target) needs the admin settings screen first, since its
  threshold and rate are admin-configured and must carry effective-from dates

---

### 2026-08-15 — Phase 7 — Team Sales Engine

**Added**
- `TeamSalesService` — own approved Sq.Ft. plus every connected downline's, at any
  depth, for every leader. Rolls the whole network up in a single recursive query rather
  than walking each branch separately
- `team_calculations` table: own, direct-team and total-team Sq.Ft. per leader per
  period, plus a contributor count
- Team sales report and a contributors page naming every member whose sales rolled up
  into a leader's figure, with their depth
- `CalculationRunType::TeamSales` — a run type that produces no reward
- Calculate Team Sales wired into the Calculation Center, badged "no payout"

**Changed**
- `CalculationRunType::rewardType()` is now nullable, because Team Sales pays nobody

**Database**
- New `team_calculations`, unique on (leader, period). `target_sqft`, `achieved` and
  `reward_amount` exist but stay null — they belong to Phases 8-10

**Tests**
- 17 new tests (254 total, 800 assertions, all passing)
- The Rahul/A/B/C sample from the development plan reconciles exactly: Rahul 5,000
  (own 1,000, direct team 2,500), A 3,500, B 500 solo, C 1,500
- Independence proven: C's single sale appears in C's, A's and Rahul's totals while only
  C owns it
- No depth limit: a sale 8 links below the root counts in full
- Structural test that the engine touches neither `RewardLedger` nor the reward engines

**Manual verification**
- Ran team sales for June, July and August on the live network
- June: 7 leaders, company total 2,300 Sq.Ft.
- Reconciliation confirmed in SQL — actual approved sales 2,300.00 equals the sum of
  `own_sqft` across leaders, while the sum of `total_team_sqft` is 15,300.00, inflated by
  chain height exactly as designed and warned about in the report

**Issues**
- None found. Two behaviours flagged as unconfirmed rather than defects: an inactive
  member's sales still count toward their leader's team total, and inactive members
  still receive a team calculation of their own. Both matter from Phase 8, where these
  figures start paying money.

**Decision**
- Team Sales is a measurement layer, not a reward engine — it writes no ledger rows
- Overlap between leaders is intentional; the company figure counts each sale once and
  the UI warns against summing the team column

**Next**
- Phase 8 — Target 1. **BLOCKED**: the cycle start rule, fixed-vs-scaling reward and
  failure behaviour are undefined, and Targets 2 and 3 have no documented reward amount.

---

### 2026-08-15 — Phase 6 — Upline Reward Engine

**Added**
- `UplineRewardService` — pool = seller's monthly own Sq.Ft. × ₹50, divided equally among
  the actual eligible upline count, maximum 5
- Compression: walking up from the seller, inactive members are skipped and the walk
  continues past them until 5 active uplines are found
- `upline_calculations` table recording the full working behind every share — whose sales
  made the pool, its size, the eligible count, the receiver's level and the raw chain
  depth, so compression is auditable
- `Money::divide()` and `Money::round()`, half-up to 2 decimals, now that the rounding
  rule is confirmed
- Calculate Upline wired into the Calculation Center, with a preview that surfaces the
  rounding residual before the run
- Upline reward ledger page showing the distribution detail per seller
- Upline Reward tab activated on the member profile; Upline Rewards enabled in the sidebar

**Changed**
- `reward_ledger` duplicate-protection index now includes `period`. Direct rewards are
  sourced from a sale id, unique to one period; upline rewards are sourced from a seller,
  which recurs monthly. Without `period` the second month would have collided with the
  first. Protection within a period is unchanged.
- `Money`'s "divide must not exist" guard replaced by a full division and rounding suite

**Database**
- New `upline_calculations` table, unique on (period, seller, receiver)
- `reward_ledger_source_unique` extended to include `period`

**Tests**
- 33 new tests (228 total, 697 assertions, all passing)
- The acceptance matrix runs as a data provider: pool 75,000 with 5/4/3/2/1 uplines gives
  15,000 / 18,750 / 25,000 / 37,500 / 75,000 each, and 0 uplines produces no calculation
- Compression covered three ways: a skipped upline replaced from deeper in the chain, a
  divisor that drops when no replacement exists, and an entirely inactive chain paying
  nothing
- Regression test for the period index: the same (receiver, seller) pair pays in two
  consecutive months
- Structural test that the engine references no Target or Team service

**Manual verification**
- Recorded 1,500 Sq.Ft. for RS6, whose chain is RS6 → RS5 → RS4
- Preview showed a ₹75,000 pool; the run produced 2 shares of ₹37,500, matching the
  acceptance matrix for 2 eligible uplines
- `upline_calculations` recorded both shares with pool 75,000, eligible count 2, levels
  1 and 2, chain depths 1 and 2
- The seller RS6 received no upline share from their own sale
- A second upline run was refused and the ledger still held exactly 2 rows

**Issues**
- None found in this phase.

**Decision**
- Eligibility is active members only, with compression: skip inactive, keep walking
- Shares are rounded off (half-up, 2 decimals)
- The rounding residual is surfaced in the preview rather than silently absorbed —
  3 × ₹16,666.67 = ₹50,000.01 against a ₹50,000 pool

**Next**
- Phase 7 — Team Sales: own + all connected downline approved sales, calculated
  independently for each Team Leader. Not started, and not blocked.

---

### 2026-08-15 — Correction — Simplified sale entry

Client correction requested after Phase 5. Supersedes Phase 4 decision #3.

**Changed**
- Sale entry now requires only a member and a Sq.Ft. figure
- The direct sale amount is shown live beside the Sq.Ft. field as the operator types
  (display only — the server remains the financial source of truth)
- Project, property, registry number, registry date and notes moved into a collapsed
  "Property & registry details" accordion, which auto-opens if any of those fields has a
  validation error so the operator can see what to fix
- Form redesigned: larger inputs, icon-prefixed input groups, a highlighted selected-member
  card, and a Clear button that also resets the member and the live amount
- Sq.Ft. field is now numeric-only as it is typed — non-numeric keys are blocked, a
  second decimal point is prevented, and the value is capped at 2 decimals

**Database**
- `registry_sales.project_id`, `property_id` and `registry_reference` are now nullable.
  Foreign keys were dropped and re-added around the change; the existing sale row was
  verified intact afterwards
- `registry_reference` keeps its UNIQUE index — MySQL permits many NULLs, so it still
  blocks duplicates whenever a number is supplied
- `registry_date` deliberately stays NOT NULL: it decides the reward month, and the
  application fills it with the entry day when the form omits it

**Tests**
- 7 new tests (195 total, 580 assertions, all passing)
- Existing `required_fields_are_enforced` replaced by `only_member_and_sqft_are_required`,
  which also asserts the now-optional fields raise no errors

**Manual verification**
- Recorded a sale from member RS4 with 2,000 Sq.Ft. and nothing else — stored with null
  project, property and registry number, dated today, flash confirming
  "direct reward ₹80,000.00"
- `abc123` rejected: "Sq.Ft. must be a number — digits and a decimal point only."
- A property submitted without a project rejected: "Select the project this property
  belongs to."
- Sales history and the sale detail page render correctly with the null details, falling
  back to "#2" where a registry number would be

**Issues**
- **Risk accepted by the client:** the unique registry number was the duplicate-sale
  guard. Sales entered without one have no duplicate protection, and because sales are
  approved on entry and permanent, a double entry becomes a permanent double reward. No
  replacement guard was invented — a same-member/same-Sq.Ft./same-day warning would be
  the natural candidate but needs confirming as a business rule.

**Decision**
- Member + Sq.Ft. is the whole required form; all other sale detail is optional
- Optional never means unvalidated — every supplied value is still checked

**Next**
- Phase 6 — Upline Reward. Still blocked on upline eligibility and the rounding rule.

---

### 2026-08-15 — Phase 5 — Direct Reward Engine

**Added**
- `DirectRewardService` — own approved sale Sq.Ft. × ₹40, writing one ledger row per
  sale so every rupee traces to a specific registry
- `App\Support\Money` — exact decimal arithmetic over bcmath, values passed as strings.
  `divide()` is deliberately absent until the upline rounding rule is confirmed
- `CalculationRunService` — period validation, duplicate protection, transactional
  execution, and failure recording that survives the rollback
- `calculation_runs` and `reward_ledger` tables with `RewardType`, `LedgerStatus`,
  `CalculationRunType` and `CalculationRunStatus` enums
- Calculation Center rendering all five controls from the UI spec, with Direct wired and
  the rest labelled by delivering phase
- Period preview showing what a run would produce before committing it
- Run detail page and a direct reward ledger grouped by member
- Direct Reward tab activated on the member profile

**Changed**
- Sidebar: Calculations enabled
- Member profile passes calculated direct rewards through

**Database**
- `reward_ledger` stores `sqft`, `rate` and `amount` as DECIMAL, with the rate frozen per
  row so historical runs stay reproducible if a configured rate ever changes
- Unique index `reward_ledger(member_id, reward_type, source_type, source_id)` — the
  database itself makes paying the same source twice impossible
- Ledger and run foreign keys are `restrictOnDelete`; no financial row can be orphaned

**Tests**
- 49 new tests (188 total, 541 assertions, all passing) — the first unit tests in the
  project
- Acceptance cases from `06_TESTING_AND_ACCEPTANCE.md` verified exactly: 1,500 × 40 =
  60,000, and multiple sales summed (1,750.50 → 70,020.00)
- Target independence is enforced two ways: a behavioural test, and a structural test
  asserting `DirectRewardService` has no reference to the Target or Team services
- Float-error cases pinned: 0.1 + 0.2 = 0.30, and 27.625 × 40 = 1105.00 where a float
  yields 1104.9999999999998

**Manual verification**
- Calculation Center previewed 2026-08 as 1,500.00 Sq.Ft. → ₹60,000.00 without writing
  anything
- Running it produced run #1 (completed, 1 entry) and one ledger row:
  member RS1, source `registry_sale #1`, sqft 1500.00, rate 40.00, amount 60000.00
- A second run was refused — "has already been calculated for 2026-08" — with the ledger
  still holding exactly one row
- Run detail, direct ledger and the member profile tab all show ₹60,000.00

**Issues**
- None found in this phase.

**Decision**
- One ledger row per sale rather than per member per period, for traceability
- `calculation_runs` and `reward_ledger` built now (nominally Phases 12/13) because the
  Direct engine needed somewhere to write. `CalculationRunService` is the minimum
  lifecycle; Phase 12 extends it without changing the contract
- Recalculation is not available yet: a completed run blocks a second one for the same
  period and type. Controlled recalculation is Phase 12.

**Next**
- Phase 6 — Upline Reward. **BLOCKED** on two answers: what makes an upline "eligible",
  and the rounding rule when the pool divides unevenly. Both change payout amounts.

---

### 2026-08-15 — Phase 4 — Projects, Properties & Registry Sales

**Added**
- Projects: CRUD with search, status filter and pagination; deletion blocked once the
  project has properties or recorded sales
- Properties/Sites: CRUD scoped to a project, with a property code unique within its
  project rather than globally; deletion blocked once sales exist
- Registry sales: compact daily entry form, sales history with search, project filter
  and date range, and a sale detail page
- `RegistrySaleService` — recording inside a transaction, with the project/property
  pairing re-checked server-side rather than trusted from the form
- AJAX member lookup and a property dropdown that depends on the chosen project
- `RegistrySale` scopes `approved()`, `forPeriod()`, `betweenDates()` and `search()` —
  the single definitions of "approved" and "period" for every later engine
- Filtered totals on the history screen covering all matching sales, not just the page

**Changed**
- Sidebar: Projects, Properties, Daily Sales and Sales History enabled
- Layout footer no longer hard-codes "Phase 1"

**Database**
- `projects`, `properties` and `registry_sales` created
- `registry_sales.sqft` is `DECIMAL(12,2)` and cast as a string, never a float, because
  it multiplies the reward rates
- `registry_reference` is unique — the duplicate-sale guard
- Foreign keys to member, project and property are restricted on delete so a sale can
  never lose its source; `entered_by` is nulled on delete so removing a staff account
  does not destroy sale records
- Indexes on `registry_date`, `sale_date`, `status`, `(member_id, registry_date)` and
  `(status, registry_date)` for the monthly engines from Phase 5

**Tests**
- 41 new tests (139 total, 407 assertions, all passing)
- Includes a test proving the period follows `registry_date` when `sale_date` falls in a
  different month, and one asserting no edit/update/destroy routes exist

**Manual verification**
- Created project "Green Valley Enclave" with PLOT-A1 and PLOT-A2
- Property lookup AJAX returned only that project's active sites
- Recorded 1,500.00 Sq.Ft. for RS1 — stored approved, entered_by set, period 2026-08
- Duplicate registry number rejected: "A sale with this registry number has already
  been recorded"; future registry date rejected; neither row was inserted
- History search, totals and date-range filter all correct
- Project deletion blocked once the project had a sale

**Issues**
- `RegistrySaleFactory` could generate a `sale_date` after its `registry_date` when a
  test overrode only the registry date. Fixed to hold the same invariant the form
  enforces. Test-only, but it would have seeded misleading fixtures for Phases 5–7.
- `properties.status` values are undefined in the documentation. Active/Inactive is
  used, governing only selectability for new sales. Availability tracking
  (Available/Sold) was NOT invented and needs confirmation if wanted.

**Decision**
- Entry is approval; no pending state
- `registry_date` is the entry day and decides the reward month
- Project and property both required, and must match each other
- Registry sales are permanent — no edit, no delete. The client accepted that a
  mistyped sale is uncorrectable; a correction workflow cannot be designed until the
  business states what happens to rewards already calculated from it.

**Next**
- Phase 5 — Direct Reward: own approved Sq.Ft. × ₹40. Not started. No outstanding
  business questions block it.

---

### 2026-08-15 — Phase 3 — Sponsor Tree & Network UX

**Added**
- `MemberTreeService` — roots, one-level children, batched branch totals, level and
  path-to-root calculation, paginated downline with per-member level, and tree search.
  Descendant walks use recursive CTEs with a depth guard against corrupt cycles
- Sponsor tree page with AJAX lazy loading: only the roots load initially and each
  expansion fetches exactly one more level, with per-node loading states
- Tree controls: member search with jump-to, focus (re-root at a member), view sponsor,
  expand-loaded, collapse all, level filter, back to roots
- Expandable member cards showing level, direct count, total team, active team and status
- "View Full Downline" — every descendant as a paginated, depth-filterable listing
- Member profile rebuilt with the tab set from the UI spec: Overview, Sponsor/Upline,
  Direct Team, Full Tree; the five reward tabs render disabled with their phase number
- Tree card, connector and responsive styling

**Changed**
- Sidebar: Sponsor Tree enabled
- `MemberController::show()` now supplies level and branch totals

**Database**
- None. Phase 3 required no schema change: recursive CTEs cover the hierarchy against
  the documented `members` table. The materialized path floated in the initial analysis
  was deliberately not added.

**Tests**
- 37 new tests (98 total, 290 assertions, all passing)
- Includes an explicit assertion that the tree page renders no member rows in its
  initial HTML, which is what makes the lazy-loading claim verifiable

**Manual verification**
- Tree page loads with no member data inlined; roots arrive over AJAX
- Children endpoint returns one level only, with correct branch totals per node
- Focus on RS6 returned level 2 and expansion path [RS4, RS5]
- Search for "kumar" returned RS5 at level 1 and RS6 at level 2
- Downline for RS4 listed 5 members across levels 1–2; depth filter of 1 correctly
  excluded the level-2 member
- Member profile shows the complete upline chain and the tabbed layout

**Issues**
Two defects found and fixed:
1. `Member::ancestors()` traversed the loaded `sponsor` relation, so a caller
   eager-loading it without `sponsor_id` — as the member profile did — silently
   truncated the chain to one level. It now walks `sponsor_id` and re-queries full
   models. Phases 6 and 7 derive money from this chain, so it was fixed at the root
   rather than patched at the call site. Regression test added.
2. Pagination was rendering Laravel's default Tailwind markup against a Bootstrap 5 UI,
   affecting the member list since Phase 2. `Paginator::useBootstrapFive()` now set.

**Decision**
- No denormalised tree structure. Recursive CTEs meet the requirement without deviating
  from the documented schema; revisit in Phase 7 only with measurements.
- No "expand entire network" control, deliberately — it would defeat lazy loading.

**Next**
- Phase 4 — Projects, Properties/Sites and Registry Sales. **Blocked**: the definition of
  an "approved" sale and whether the period follows `sale_date` or `registry_date` must
  be confirmed first.

---

### 2026-08-15 — Phase 2 — Member Management & Sponsor Assignment

**Added**
- `Member` model with soft deletes, sponsor/direct-referral relationships, `ancestors()`
  and `descendantIds()` tree walks, and search/active/roots scopes
- `MemberStatus` enum (Active / Inactive)
- Member CRUD: list with server-side search, status and position filters and pagination;
  create; edit; detail page with member card, upline chain and direct referral listing
- `MemberCodeGenerator` — allocates codes under a row lock inside the creating
  transaction, with unique constraints as the backstop and a retry on a lost race
- `MemberService` — creation, update and deletion rules in one place
- `ValidSponsor` validation rule — blocks self-sponsorship and any sponsor drawn from
  the member's own downline, at any depth
- AJAX sponsor search endpoint returning a capped, ranked list; the edited member and
  its whole downline are excluded from results
- `sponsor-picker.js` — debounced search with request cancellation, built on the
  Phase 1 `App.request` helper
- `config/members.php` — member code prefix, padding, start number, and listing limits

**Changed**
- Sidebar: Members enabled; navigation active state now matches child routes
- `resources/js/app.js` imports the sponsor picker module

**Database**
- New `members` table: the documented columns plus `sequence_number` backing the member
  code. Unique on `member_code`, `sequence_number`, `mobile`, `email`. Indexed on
  `sponsor_id`, `status`, `joining_date` and `(status, sponsor_id)`. Soft deletes.

**Tests**
- 38 new tests (61 total, 184 assertions, all passing)
- Covers CRUD and validation, the full sponsor-validation matrix required by
  `06_TESTING_AND_ACCEPTANCE.md`, member code generation including prefix changes and
  soft-deleted codes, and the sponsor search endpoint

**Manual verification**
- RS1 created as a root member; RS2 and RS3 created beneath it
- Self-sponsorship and circular assignment both rejected with clear messages
- Member detail shows the Root badge, Team Leader status, referral list, upline chain
  with level numbers, and a blocked delete with its reason
- Sponsor search returns the standard envelope and excludes the downline correctly

**Issues**
- One defect found and fixed during the phase: `MemberService::create()` passed
  `member_code` and `sequence_number` through `Member::create()`, but both are
  deliberately excluded from `#[Fillable]` so no form can alter them, so mass assignment
  silently dropped them. The service now assigns them directly after `fill()`.

**Decision**
- Member ID = admin-settable prefix + plain sequential number; issued codes are permanent
- Root members allowed, multiple independent trees permitted
- Sponsor changes allowed only while the member has no sales, enforced through a single
  `canChangeSponsor()` extension point that Phase 4 will tighten
- Member statuses limited to Active and Inactive

**Next**
- Phase 3 — Sponsor Tree & Network UX with AJAX lazy loading. Not started.

---

### 2026-08-15 — Phase 1 — Foundation & Admin Authentication

**Added**
- Laravel 13.25.0 application scaffolded into the project root (documentation preserved)
- Admin authentication: login, logout, "remember me", rate limiting (5 attempts per
  email+IP), `last_login_at` tracking
- `UserRole` (admin/manager) and `UserStatus` (active/inactive) enums, cast on the User model
- `EnsureUserHasRole` (`role:` alias) and `EnsureUserIsActive` (`active` alias) middleware —
  a user deactivated mid-session is logged out on their next request
- Bootstrap 5.3.8 admin shell: fixed sidebar with the full navigation from the UI spec,
  topbar with operator menu, flash messages, responsive off-canvas below `lg`
- `App\Support\ApiResponse` — the single AJAX envelope `{success, message, data, errors}`
  used by every endpoint from here on
- `BaseFormRequest` returning validation failures in that envelope for AJAX requests
- Global JSON exception rendering in `bootstrap/app.php` (401/403/404/422/500)
- Front-end helper in `resources/js/app.js`: `request()`, `setLoading()`,
  `showFormErrors()`, `notify()`, sidebar toggle, destructive-action confirmation
- `config/rewards.php` — the four confirmed rates in one place, with every unresolved
  business question recorded inline next to the value it affects
- `app/Services/README.md` — service-layer contract and the phase each service arrives in
- `AdminUserSeeder` (idempotent; will not reset an existing account's password)
- Protected dashboard rendering the four confirmed reward rules and environment details

**Changed**
- Front-end stack switched from Tailwind (Laravel 13 default) to Bootstrap 5, per
  `04_UI_UX_SPECIFICATION.md`
- `config/app.php` timezone now reads `APP_TIMEZONE` (was hardcoded `UTC`); set to `Asia/Kolkata`
- `phpunit.xml` points at MySQL `real_state_test` rather than SQLite — engine parity is
  required before financial logic lands in Phases 5–13

**Database**
- `users` extended with `role` (indexed, default `manager`), `status` (indexed, default
  `active`) and `last_login_at`
- Databases created: `real_state`, `real_state_test`
- Seeded `admin@realstate.test` (role `admin`) — password must be changed before production

**Tests**
- 23 tests, 72 assertions, all passing (PHPUnit 12.5.33)
- Covers authentication, inactive-account rejection, rate limiting, role/auth route
  protection, mid-session deactivation, and the AJAX envelope contract

**Manual verification**
- Guest → `/admin/dashboard` redirects to `/login`
- Login succeeds, redirects to the dashboard, and records `last_login_at`
- Dashboard shows ₹40 / ₹50 / ₹30 / ₹30 sourced from `config/rewards.php`
- KPI tiles deliberately blank, labelled with the phase that will fill them
- Unauthenticated AJAX returns 401 in the standard envelope
- Vite production build succeeds

**Issues**
- Port 8000 is held by pre-existing `global_life_new` dev servers; this project runs on
  port 8001. No other project was modified or stopped.
- A pre-existing PHP 8.3.31 + Composer install at `C:\php83` was found after `C:\php84`
  had been set up, making the latter redundant.

**Decision**
- Members do not authenticate. Only Admin/Manager users log in, per `02_BUSINESS_RULES.md` §7.
- No reward figure is displayed anywhere until the engine that computes it exists.

**Next**
- Phase 2 — Member Management and Sponsor assignment. Not started; awaiting approval.
- No Phase 2 business questions are outstanding. Questions blocking Phases 4, 6, 8–11
  are listed in `PROJECT_STATE.md`.

---

## Initial Documentation
**Added**
- Master development plan
- Business rules
- Database and architecture
- UI/UX specification
- Calculation engine
- Testing/acceptance
- Claude workflow
- Project state tracking

**Decision**
Direct ₹40, Upline ₹50, Target ₹30 and Company Club ₹30 remain independent calculations.

**Next**
Start Phase 1 only.
