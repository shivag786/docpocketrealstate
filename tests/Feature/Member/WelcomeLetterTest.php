<?php

namespace Tests\Feature\Member;

use App\Models\CompanySetting;
use App\Models\Member;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The welcome letter and the admin-controlled rows on it.
 *
 * The single-page assertion is the point of this file. The client asked for a
 * one-page letter with room for a signature and seal, and "it looked like one
 * page when I tried it" is not a guarantee — a longer company address or one
 * more optional row would silently push it to two, and nobody would notice
 * until a member was handed half a letter.
 */
class WelcomeLetterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    /**
     * How many pages dompdf actually produced.
     */
    private function pageCount(Member $member): int
    {
        $pdf = Pdf::loadView('admin.members.documents.letter', [
            'member' => $member,
            'company' => CompanySetting::current()->fresh(),
        ])->setPaper('a4', 'portrait');

        // Render before asking the canvas, or the page count is still zero.
        $pdf->output();

        return $pdf->getDomPDF()->getCanvas()->get_page_count();
    }

    /**
     * The worst case: every optional row on, every value present and long.
     */
    private function heaviestPossibleLetter(): Member
    {
        CompanySetting::current()->forceFill([
            'company_name' => 'Dream Properties Infrastructure & Developers Private Limited',
            'tagline' => 'Building trust, one plot at a time, across Madhya Pradesh',
            'address' => '4th Floor, Vijay Nagar Business Centre, 12 MG Road, Indore, Madhya Pradesh 452001',
            'phone' => '+91 98765 43210',
            'email' => 'customercare@dreamproperties.example',
            'website' => 'www.dreamproperties.example',
            'authority_name' => 'Rajendra Prasad Sharma',
            'authority_designation' => 'Managing Director & Chief Executive',
            'letter_fields' => array_fill_keys(
                array_keys(config('company.letter.fields')),
                true,
            ),
        ])->save();

        $sponsor = Member::factory()->create([
            'name' => 'Priyanka Chandrashekhar Deshpande',
        ]);

        return Member::factory()->sponsoredBy($sponsor)->create([
            'name' => 'Venkataraman Subramanian Krishnamurthy',
            'email' => 'venkataraman.krishnamurthy@averylongdomainname.example',
            'blood_group' => 'AB+',
            'designation' => 'Senior Sales Advisor',
        ]);
    }

    #[Test]
    public function the_letter_is_one_page_with_every_optional_row_switched_on(): void
    {
        $this->assertSame(1, $this->pageCount($this->heaviestPossibleLetter()));
    }

    #[Test]
    public function the_letter_is_one_page_for_a_bare_member_on_a_fresh_install(): void
    {
        // The opposite extreme: nothing configured, no sponsor, no email.
        $member = Member::factory()->root()->create(['email' => null, 'blood_group' => null]);

        $this->assertSame(1, $this->pageCount($member));
    }

    #[Test]
    public function switching_a_row_off_removes_it_from_the_letter(): void
    {
        $member = Member::factory()->create(['designation' => 'Branch Manager']);

        CompanySetting::current()->forceFill([
            'letter_fields' => ['designation' => true],
        ])->save();

        $this->assertStringContainsString('Branch Manager', $this->renderLetter($member));

        CompanySetting::current()->forceFill([
            'letter_fields' => ['designation' => false],
        ])->save();

        $this->assertStringNotContainsString('Branch Manager', $this->renderLetter($member));
    }

    #[Test]
    public function the_rows_that_identify_the_member_can_never_be_switched_off(): void
    {
        $member = Member::factory()->create();

        // Every configurable field off. Name, ID and joining date must survive,
        // because nothing exposes them as toggles in the first place.
        CompanySetting::current()->forceFill([
            'letter_fields' => array_fill_keys(
                array_keys(config('company.letter.fields')),
                false,
            ),
        ])->save();

        $html = $this->renderLetter($member);

        $this->assertStringContainsString($member->name, $html);
        $this->assertStringContainsString($member->member_code, $html);
        $this->assertStringContainsString($member->joining_date->format('d M Y'), $html);
    }

    #[Test]
    public function a_row_switched_on_is_skipped_when_the_member_has_nothing_for_it(): void
    {
        CompanySetting::current()->forceFill([
            'letter_fields' => ['blood_group' => true, 'sponsor' => true],
        ])->save();

        $member = Member::factory()->root()->create(['blood_group' => null]);
        $html = $this->renderLetter($member);

        $this->assertStringNotContainsString('Blood group', $html);
        $this->assertStringNotContainsString('Sponsor', $html);
    }

    #[Test]
    public function an_email_row_switched_on_says_not_recorded_rather_than_going_blank(): void
    {
        CompanySetting::current()->forceFill(['letter_fields' => ['email' => true]])->save();

        $html = $this->renderLetter(Member::factory()->create(['email' => null]));

        $this->assertStringContainsString('Not recorded', $html);
    }

    #[Test]
    public function the_letter_always_reserves_space_for_a_signature_and_a_seal(): void
    {
        $html = $this->renderLetter(Member::factory()->create());

        $this->assertStringContainsString('Company seal', $html);
        $this->assertStringContainsString('Authorised Signatory', $html);
    }

    #[Test]
    public function an_admin_can_change_which_rows_the_letter_prints(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.letter.update'), [
                'fields' => ['designation' => '1', 'blood_group' => '1'],
            ])
            ->assertRedirect(route('admin.settings.letter'))
            ->assertSessionHasNoErrors();

        $fields = CompanySetting::current()->fresh()->letterFields();

        $this->assertTrue($fields['designation']);
        $this->assertTrue($fields['blood_group']);

        // Absent from the payload means OFF. A checkbox posts nothing when it
        // is unticked, so anything else would make a row impossible to hide.
        $this->assertFalse($fields['email']);
        $this->assertFalse($fields['sponsor']);
    }

    private function renderLetter(Member $member): string
    {
        return view('admin.members.documents.letter', [
            'member' => $member->fresh(),
            'company' => CompanySetting::current()->fresh(),
        ])->render();
    }
}
