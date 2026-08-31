# Member Status Automation

Isolated module. It calculates **ACTIVE / PENDING / INACTIVE** for every member
from property-sale activity, stores that in its own tables, and holds payments
to members who are not ACTIVE.

**It is not registered.** `bootstrap/providers.php` was not modified, so until
somebody adds `MemberStatusServiceProvider` the module does nothing at all. See
`MEMBER_STATUS_INTEGRATION.md` in the project root for every integration point.

## The rule

```
                    qualifying activity
                            |
        0–89 days        ACTIVE
        90–179 days      PENDING
        180+ days        INACTIVE
```

Qualifying activity is the member's **own** valid sale, or a valid sale by a
member they **personally referred**. One level. A sale gives activity to the
seller and the seller's direct sponsor, and to nobody above them.

Any activity resets the clock from its own date. A member with no sales is
measured from their joining date, so nobody starts life as PENDING.

## Shape

```
Contracts/          MemberProvider, PropertySaleProvider — the host boundary
Adapters/           read-only implementations for `members` / `registry_sales`
Services/
  MemberStatusEngine            the 90/180 rules. Pure: no database, no container
  StatusRecalculationService    read activity -> decide -> write module tables
  SaleActivityRecorder          one sale -> seller + direct sponsor, never further
  PaymentEligibilityService     the payment gate. The only place that rule lives
  StatusReportExporter          the table as CSV / .xlsx / .pdf, no new packages
Repositories/       the three module tables
Config/             every threshold and switch (nothing is hard-coded)
```

Read `MemberStatusEngine` first: it is the whole business rule in one pure
function, and it can be exercised with no framework at all.

## The payment gate

A member who is not ACTIVE can be looked at in full — every reward, every
amount, paid and unpaid — but an admin may not confirm a payment to them.

The disabled button, the modal's warning and the POST endpoint all ask
`PaymentEligibilityService` the same question, so the UI and the rule can never
drift apart. The payment itself goes through the application's OWN
`RewardPaymentService`, so locking, period rules and audit fields are identical
to the existing screens.

## What it never does

* write `members.status` — the calculated value lives only in
  `member_status_snapshot`, so the two can be compared before anyone commits to
  connecting them
* write any existing table while calculating status — `HostDataUntouchedTest`
  captures `members` and `registry_sales` before and after a full run and fails
  on any difference. The single exception is deliberate and admin-initiated:
  confirming a payment updates `reward_ledger`, through the host's own service
* walk further than one referral level
* define "valid sale" anywhere except `PropertySaleProvider`
* pay anybody itself — it delegates to the host's `RewardPaymentService`

## Running it

```bash
php artisan migrate                                   # three new tables
php artisan member-status:calculate --dry-run         # calculate, write nothing
php artisan member-status:calculate                   # for real
php artisan test --filter=MemberStatus                # 75 tests
```
