{{--
    Member welcome letter — A4 portrait, rendered by dompdf.

    TWO HARD CONSTRAINTS, both client-stated (2026-08-31):

    1. ONE PAGE, always. The vertical budget below is why every measurement here
       is in millimetres and why the prose is short. A4 is 297mm; with 12mm top
       and 9mm bottom margin that leaves 276mm. The worst case — every optional
       row on, a company name and address long enough to wrap, a sponsor —
       measured over two pages at the first attempt, which is why the type and
       spacing here are as tight as they are.
       `tests/Feature/Member/WelcomeLetterTest` renders that worst case and
       asserts the PDF has exactly one page, so this is enforced, not hoped for.

    2. Real space for a signature and a seal. The block at the foot is a fixed
       33mm and sits above the footer, so it can never be squeezed by content
       growing above it. The seal box is a printing guide for a physical rubber
       stamp, not decoration.

    Which optional rows appear is admin-configured — Settings › Welcome Letter.
    A row switched ON is still skipped when the member has nothing to put in it
    (no sponsor, no blood group), because an empty row reads as a mistake.
    Email is the exception and prints "Not recorded", since a blank email looks
    like an oversight rather than a fact.

    Written against dompdf's CSS subset: tables and blocks, no flexbox, no grid.
    Fonts are DejaVu Sans, which dompdf bundles.
--}}
@php
    $logo = $company->logoDataUri();
    $signature = $company->signatureDataUri();

    // Resolved once so the template reads as a list of rows rather than a
    // thicket of nested conditions.
    $show = fn (string $field) => $company->showsOnLetter($field);

    $rows = [];
    $rows[] = ['Member name', $member->name];
    $rows[] = ['Member ID', $member->member_code, 'code'];

    if ($show('company')) {
        $rows[] = ['Company', $company->name()];
    }

    if ($show('designation')) {
        $rows[] = ['Designation', $member->designation];
    }

    if ($show('mobile')) {
        $rows[] = ['Contact number', $member->mobile];
    }

    if ($show('email')) {
        $rows[] = ['Email', $member->email ?: 'Not recorded', $member->email ? null : 'muted'];
    }

    if ($show('blood_group') && $member->blood_group) {
        $rows[] = ['Blood group', $member->blood_group->label()];
    }

    if ($show('sponsor') && $member->sponsor) {
        $rows[] = ['Sponsor', $member->sponsor->name.' ('.$member->sponsor->member_code.')'];
    }

    $rows[] = ['Joining date', $member->joining_date->format('d M Y')];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $member->member_code }} — Welcome Letter</title>
    <style>
        @page { margin: 0; }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 9.5pt;
            line-height: 1.4;
            color: #1f2430;
            margin: 0;
        }

        .sheet { padding: 12mm 16mm 9mm; }

        /* Letterhead --------------------------------------------- ~22mm --- */
        .letterhead { width: 100%; border-collapse: collapse; }
        .letterhead td { vertical-align: middle; }
        .letterhead .logo-cell { width: 26mm; }
        .letterhead .logo-cell img { max-height: 16mm; max-width: 24mm; }

        .company-name {
            font-size: 15pt;
            font-weight: bold;
            letter-spacing: 0.3pt;
            color: #0d2a4d;
        }
        .company-tagline { font-size: 8pt; color: #5b6470; margin-top: 0.4mm; }
        .company-contact { font-size: 7.5pt; color: #5b6470; margin-top: 1mm; line-height: 1.3; }

        .rule { border-bottom: 2px solid #0d2a4d; margin: 3mm 0 0.8mm; }
        .rule-thin { border-bottom: 1px solid #c9d2dd; margin-bottom: 3.5mm; }

        /* Body ---------------------------------------------------- ~30mm --- */
        .meta { font-size: 8.5pt; color: #5b6470; margin-bottom: 3.5mm; }
        .meta .right { float: right; }

        h1 {
            font-size: 12.5pt;
            color: #0d2a4d;
            margin: 0 0 2.5mm;
            letter-spacing: 0.2pt;
        }

        p { margin: 0 0 2.4mm; }

        /* Details -------------------------- 9 rows x ~6.5mm = ~59mm max --- */
        .details {
            width: 100%;
            border-collapse: collapse;
            margin: 3mm 0 3mm;
            font-size: 9pt;
        }
        .details th, .details td {
            border: 1px solid #d7dee7;
            padding: 1.4mm 3.5mm;
            text-align: left;
        }
        .details th {
            width: 38%;
            background: #f2f6fa;
            color: #445061;
            font-weight: normal;
        }
        .details td { font-weight: bold; }
        .details .muted { font-weight: normal; color: #8a94a2; }
        .details .code { color: #0d2a4d; letter-spacing: 0.6pt; }

        /* Signature and seal ------------------------------- fixed 33mm --- */
        .signing { width: 100%; border-collapse: collapse; margin-top: 4mm; }
        .signing td {
            width: 50%;
            height: 33mm;
            vertical-align: bottom;
            padding: 0;
        }

        .seal-box {
            width: 32mm;
            height: 23mm;
            border: 1px dashed #c2ccd8;
            border-radius: 2mm;
        }
        .seal-caption {
            font-size: 7.5pt;
            color: #a2abb8;
            padding-top: 1.2mm;
        }

        .sign-cell { text-align: right; }
        .sign-space { height: 14mm; }
        .sign-space img { max-height: 13mm; max-width: 48mm; }
        .sign-line {
            border-top: 1px solid #1f2430;
            width: 62mm;
            float: right;
            padding-top: 1.2mm;
            font-size: 9.5pt;
            font-weight: bold;
        }
        .sign-role { font-size: 8.5pt; color: #5b6470; }

        /* Footer --------------------------------------------------- ~7mm --- */
        .footer {
            clear: both;
            margin-top: 4mm;
            padding-top: 1.5mm;
            border-top: 1px solid #e2e8f0;
            font-size: 7.5pt;
            color: #8a94a2;
            text-align: center;
        }
    </style>
</head>
<body>
<div class="sheet">

    <table class="letterhead">
        <tr>
            @if ($logo)
                <td class="logo-cell"><img src="{{ $logo }}" alt=""></td>
            @endif
            <td>
                <div class="company-name">{{ $company->name() }}</div>
                @if ($company->tagline)
                    <div class="company-tagline">{{ $company->tagline }}</div>
                @endif
                @if ($company->address || $company->phone || $company->email)
                    <div class="company-contact">
                        {{ $company->address }}
                        @if ($company->phone) <br>Phone: {{ $company->phone }} @endif
                        @if ($company->email) &nbsp;&middot;&nbsp; {{ $company->email }} @endif
                    </div>
                @endif
            </td>
        </tr>
    </table>

    <div class="rule"></div>
    <div class="rule-thin"></div>

    <div class="meta">
        Ref: {{ $member->member_code }}
        <span class="right">Date: {{ now()->format('d M Y') }}</span>
    </div>

    <h1>Welcome to {{ $company->name() }}</h1>

    <p>Dear <strong>{{ $member->name }}</strong>,</p>

    <p>
        We are delighted to welcome you to {{ $company->name() }}. Your registration is
        complete and your membership details are recorded below. Please keep your Member
        ID safe &mdash; it identifies you in every record we hold, and you will be asked
        for it whenever a sale is registered in your name or a reward is paid to you.
    </p>

    <table class="details">
        @foreach ($rows as $row)
            <tr>
                <th>{{ $row[0] }}</th>
                <td class="{{ $row[2] ?? '' }}">{{ $row[1] }}</td>
            </tr>
        @endforeach
    </table>

    <p>
        We look forward to a long and successful association, and wish you every success
        in the months ahead.
    </p>

    {{-- Fixed-height signing block: reserved space a physical signature and
         rubber stamp go into. It sits at a fixed 33mm so content above can
         never squeeze it onto a second page. --}}
    <table class="signing">
        <tr>
            <td>
                <div class="seal-box"></div>
                <div class="seal-caption">Company seal</div>
            </td>
            <td class="sign-cell">
                <div class="sign-space">
                    @if ($signature)
                        <img src="{{ $signature }}" alt="">
                    @endif
                </div>
                <div class="sign-line">
                    {{ $company->authority_name ?: ' ' }}
                </div>
                <div class="sign-role">
                    {{ $company->authority_designation ?: 'Authorised Signatory' }}<br>
                    {{ $company->name() }}
                </div>
            </td>
        </tr>
    </table>

    <div class="footer">
        {{ $company->name() }}
        @if ($company->website) &middot; {{ $company->website }} @endif
        &middot; This letter is computer generated on {{ now()->format('d M Y') }}.
    </div>

</div>
</body>
</html>
