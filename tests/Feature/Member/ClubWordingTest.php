<?php

namespace Tests\Feature\Member;

use App\Models\CompanyClubSetting;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The admin UI says "Company Club", never "Root".
 *
 * Client, 2026-08-19: *"keep company instead of root."* A member with no sponsor
 * has always sat directly under the Company Club - "root" was internal wording
 * for the same fact, and it made the member screens disagree with the Company
 * Club module about what is above a sponsorless member.
 *
 * The name is read from the Company Club settings rather than hard-coded, so
 * renaming the club renames it everywhere.
 */
class ClubWordingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    /**
     * Only the page's own markup, with the sidebar stripped.
     *
     * The sidebar legitimately contains the words Company Club as a menu entry,
     * which would make every assertion here pass for the wrong reason.
     */
    private function body(string $url): string
    {
        $html = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();

        $start = strpos($html, '<main');

        return $start === false ? $html : substr($html, $start);
    }

    #[Test]
    public function the_member_list_labels_a_sponsorless_member_as_company_club(): void
    {
        Member::factory()->root()->create(['name' => 'Under The Club']);

        $body = $this->body(route('admin.members.index'));

        $this->assertStringContainsString('Company Club', $body);
        $this->assertStringNotContainsString('>Root<', $body);
        $this->assertStringNotContainsString('Root only', $body);
    }

    #[Test]
    public function the_member_profile_shows_company_club_instead_of_a_root_level(): void
    {
        $member = Member::factory()->root()->create();

        $body = $this->body(route('admin.members.show', $member));

        $this->assertStringContainsString('Company Club', $body);
        $this->assertStringNotContainsString('root member', $body);
    }

    #[Test]
    public function the_member_form_says_a_blank_sponsor_means_company_club(): void
    {
        $body = $this->body(route('admin.members.create'));

        $this->assertStringContainsString('directly under Company Club', $body);
        $this->assertStringNotContainsString('root member', $body);
    }

    #[Test]
    public function the_sponsor_tree_counts_members_under_the_club(): void
    {
        Member::factory()->root()->create();

        $body = $this->body(route('admin.tree.index'));

        $this->assertStringContainsString('directly under Company Club', $body);
        $this->assertStringNotContainsString('Back to roots', $body);
    }

    #[Test]
    public function renaming_the_club_renames_it_on_the_member_screens(): void
    {
        // The whole point of reading it from settings rather than hard-coding.
        CompanyClubSetting::current()->update(['display_name' => 'Corporate Club']);

        $member = Member::factory()->root()->create();

        $body = $this->body(route('admin.members.show', $member));

        $this->assertStringContainsString('Corporate Club', $body);
    }

    #[Test]
    public function a_member_with_a_sponsor_is_not_labelled_with_the_club(): void
    {
        // The label belongs only to members who really do sit under the Club.
        $sponsor = Member::factory()->root()->create(['name' => 'The Sponsor']);
        $child = Member::factory()->sponsoredBy($sponsor)->create(['name' => 'The Child']);

        $body = $this->body(route('admin.members.show', $child));

        $this->assertStringContainsString($sponsor->member_code, $body);
        $this->assertStringNotContainsString('sits directly under', $body);
    }
}
