<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\Member;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Printable member documents: the welcome letter and the ID card.
 *
 * CLIENT REQUEST (2026-08-31): after a member is registered, staff print
 * something to hand them. Both documents carry the same facts — name, member
 * code, mobile, email, joining date, designation — over the company letterhead
 * from Settings.
 *
 * Rendered on demand rather than stored. A member's designation or the company
 * logo may change, and a document generated today should say what is true
 * today; nothing here is a record, it is a printout.
 *
 * Both open INLINE by default, because the operator's next action is Ctrl+P.
 * `?download=1` saves the file instead.
 */
class MemberDocumentController extends Controller
{
    /**
     * A4 portrait, in the units dompdf wants.
     */
    private const LETTER_PAPER = 'a4';

    public function letter(Request $request, Member $member): Response
    {
        return $this->render('letter', $member, $request->boolean('download'), self::LETTER_PAPER);
    }

    /**
     * The ID card.
     *
     * Laid out as front and back at exact CR80 size (85.6 x 54 mm) on an A4
     * sheet with cut guides, NOT as two card-sized pages. Staff print these on
     * the office printer; a CR80 page would come out as a stamp in the corner
     * of an A4 sheet on every printer they actually own.
     */
    public function card(Request $request, Member $member): Response
    {
        return $this->render('card', $member, $request->boolean('download'), self::LETTER_PAPER);
    }

    private function render(string $document, Member $member, bool $download, string $paper): Response
    {
        $member->loadMissing('sponsor:id,name,member_code');

        $pdf = Pdf::loadView("admin.members.documents.{$document}", [
            'member' => $member,
            'company' => CompanySetting::current(),
        ])->setPaper($paper, 'portrait');

        $filename = sprintf(
            '%s-%s-%s.pdf',
            $member->member_code,
            Str::slug($member->name) ?: 'member',
            $document === 'card' ? 'id-card' : 'welcome-letter',
        );

        return $download ? $pdf->download($filename) : $pdf->stream($filename);
    }
}
