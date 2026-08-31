{{--
    Member ID card — front and back at exact CR80 size (85.6 x 54 mm), laid out
    on one A4 sheet with cut guides.

    NOT two card-sized pages: staff print these on the office printer, and a
    CR80 page comes out as a stamp in the corner of an A4 sheet on every printer
    they actually own. Cut along the guides and the result is a card that fits a
    standard holder.

    dompdf CSS subset applies here as it does to the letter — tables and blocks,
    no flexbox, no grid. Every company value is optional and guarded.
--}}
@php
    $logo = $company->logoDataUri();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $member->member_code }} — ID Card</title>
    <style>
        @page { margin: 0; }

        body {
            font-family: "DejaVu Sans", sans-serif;
            color: #1f2430;
            margin: 0;
            font-size: 8pt;
        }

        .sheet { padding: 18mm 20mm; }

        .instructions {
            font-size: 8pt;
            color: #8a94a2;
            margin-bottom: 6mm;
        }

        .card-label {
            font-size: 7.5pt;
            color: #8a94a2;
            margin-bottom: 1.5mm;
            letter-spacing: 0.5pt;
        }

        /* The cut guide is the dashed box; the card sits flush inside it. */
        .card {
            width: 85.6mm;
            height: 54mm;
            border: 1px dashed #b9c3cf;
            margin-bottom: 9mm;
            padding: 0;
        }

        .card-inner { padding: 3.5mm 4.5mm; }

        /* ---- Front -------------------------------------------------- */
        .brand { width: 100%; border-collapse: collapse; }
        .brand td { vertical-align: middle; padding: 0; }
        .brand .logo { width: 14mm; }
        .brand .logo img { max-height: 11mm; max-width: 13mm; }
        .brand .name {
            font-size: 10.5pt;
            font-weight: bold;
            color: #0d2a4d;
            letter-spacing: 0.2pt;
        }
        .brand .tag { font-size: 6.5pt; color: #6b7684; }

        .brand-rule { border-bottom: 1.5px solid #0d2a4d; margin: 2.2mm 0 3mm; }

        .member-name {
            font-size: 13pt;
            font-weight: bold;
            color: #12203a;
            margin-bottom: 0.8mm;
        }

        .member-role {
            font-size: 8.5pt;
            color: #2f6fb2;
            margin-bottom: 3mm;
        }

        .id-strip { width: 100%; border-collapse: collapse; }
        .id-strip td { padding: 0; vertical-align: bottom; }
        .id-code {
            font-size: 12pt;
            font-weight: bold;
            color: #0d2a4d;
            letter-spacing: 1pt;
        }
        .id-caption { font-size: 6.5pt; color: #8a94a2; letter-spacing: 0.6pt; }
        .joined { font-size: 7.5pt; color: #445061; text-align: right; }

        /* ---- Back --------------------------------------------------- */
        .back-title {
            font-size: 8pt;
            font-weight: bold;
            color: #0d2a4d;
            margin-bottom: 2mm;
        }

        .back-details { width: 100%; border-collapse: collapse; font-size: 7.5pt; }
        .back-details td { padding: 0.9mm 0; vertical-align: top; }
        .back-details .label { width: 24mm; color: #8a94a2; }
        .back-details .value { font-weight: bold; color: #1f2430; }

        .back-note {
            margin-top: 2.5mm;
            padding-top: 1.8mm;
            border-top: 1px solid #dfe6ee;
            font-size: 6.5pt;
            color: #6b7684;
            line-height: 1.4;
        }

        .back-sign {
            margin-top: 2mm;
            font-size: 6.5pt;
            color: #6b7684;
            text-align: right;
        }
        .back-sign .line {
            border-top: 1px solid #8a94a2;
            width: 30mm;
            padding-top: 0.8mm;
            float: right;
        }
    </style>
</head>
<body>
<div class="sheet">

    <div class="instructions">
        {{ $company->name() }} &middot; ID card for {{ $member->name }} ({{ $member->member_code }}).
        Print at 100% scale &mdash; do not use &ldquo;fit to page&rdquo; &mdash; then cut along the dashed lines.
    </div>

    {{-- ---------------------------------------------------------- Front --}}
    <div class="card-label">FRONT</div>
    <div class="card">
        <div class="card-inner">
            <table class="brand">
                <tr>
                    @if ($logo)
                        <td class="logo"><img src="{{ $logo }}" alt=""></td>
                    @endif
                    <td>
                        <div class="name">{{ $company->name() }}</div>
                        @if ($company->tagline)
                            <div class="tag">{{ $company->tagline }}</div>
                        @endif
                    </td>
                </tr>
            </table>

            <div class="brand-rule"></div>

            <div class="member-name">{{ $member->name }}</div>
            <div class="member-role">{{ $member->designation }}</div>

            <table class="id-strip">
                <tr>
                    <td>
                        <div class="id-caption">MEMBER ID</div>
                        <div class="id-code">{{ $member->member_code }}</div>
                    </td>
                    <td class="joined">
                        Joined<br>
                        <strong>{{ $member->joining_date->format('d M Y') }}</strong>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    {{-- ----------------------------------------------------------- Back --}}
    <div class="card-label">BACK</div>
    <div class="card">
        <div class="card-inner">
            <div class="back-title">Member details</div>

            <table class="back-details">
                <tr>
                    <td class="label">Mobile</td>
                    <td class="value">{{ $member->mobile }}</td>
                </tr>
                <tr>
                    <td class="label">Email</td>
                    <td class="value">{{ $member->email ?: '—' }}</td>
                </tr>
                <tr>
                    <td class="label">Blood group</td>
                    <td class="value">{{ $member->blood_group?->label() ?? '—' }}</td>
                </tr>
                @if ($member->sponsor)
                    <tr>
                        <td class="label">Sponsor</td>
                        <td class="value">{{ $member->sponsor->member_code }}</td>
                    </tr>
                @endif
            </table>

            <div class="back-note">
                This card remains the property of {{ $company->name() }} and must be
                returned on request.
                @if ($company->address)
                    If found, please return to: {{ $company->address }}
                @endif
                @if ($company->phone)
                    ({{ $company->phone }})
                @endif
            </div>

            <div class="back-sign">
                <div class="line">
                    {{ $company->authority_designation ?: 'Authorised Signatory' }}
                </div>
            </div>
        </div>
    </div>

</div>
</body>
</html>
