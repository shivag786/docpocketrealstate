<?php

namespace Tests\Feature\Reward;

use App\Models\Member;
use App\Models\RegistrySale;
use App\Models\User;
use App\Services\TargetRewardService;
use App\Services\TeamSalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * CSV / Excel / PDF downloads on the report tables (client request, 2026-08-25).
 *
 * Every format is generated in-process with no composer package involved, so
 * these check the actual bytes: a real zip container for .xlsx and a real PDF
 * header for .pdf.
 *
 * THE MONTH IS THE POINT. A sheet of figures that does not say which month it
 * covers is dangerous, so every case asserts the period reaches both the
 * filename and the inside of the file.
 */
class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-06';

    private User $admin;

    private Member $winner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        $this->winner = Member::factory()->create(['name' => 'Rahul Sharma']);
        $laggard = Member::factory()->create(['name' => 'Quiet Member']);

        RegistrySale::factory()->forMember($this->winner)->sqft('5200.00')
            ->inPeriod(self::PERIOD, 10)->create();

        RegistrySale::factory()->forMember($laggard)->sqft('800.00')
            ->inPeriod(self::PERIOD, 12)->create();

        app(TeamSalesService::class)->calculate(self::PERIOD, $this->admin);
        app(TargetRewardService::class)->calculate(self::PERIOD, $this->admin);
    }

    private function sheetOf(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $bytes);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'The workbook should open as a zip archive.');

        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        return $sheet;
    }

    // -----------------------------------------------------------------
    // Targets — one, two and three month
    // -----------------------------------------------------------------

    #[Test]
    public function the_achieved_target_list_downloads_as_csv_with_its_month(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.targets.export', ['format' => 'csv', 'period' => self::PERIOD, 'level' => 1, 'achieved' => 1]))
            ->assertOk();

        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));

        // The month is in the filename AND in the file.
        $this->assertStringContainsString(
            'target-1-month-achieved-2026-06.csv',
            $response->headers->get('content-disposition'),
        );

        $body = $response->streamedContent();

        $this->assertStringContainsString('One Month Target', $body);
        $this->assertStringContainsString('Month: '.self::PERIOD, $body);
        $this->assertStringContainsString('Rahul Sharma', $body);
        $this->assertStringContainsString('50000.00', $body);
        $this->assertStringNotContainsString('Quiet Member', $body);
    }

    #[Test]
    public function the_not_reached_list_is_a_separate_download(): void
    {
        $body = $this->actingAs($this->admin)
            ->get(route('admin.targets.export', ['format' => 'csv', 'period' => self::PERIOD, 'level' => 1, 'achieved' => 0]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Quiet Member', $body);
        $this->assertStringNotContainsString('Rahul Sharma', $body);
    }

    #[Test]
    public function the_target_list_downloads_as_a_real_workbook_carrying_the_month(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.targets.export', ['format' => 'xlsx', 'period' => self::PERIOD, 'level' => 1]))
            ->assertOk();

        $bytes = $response->getContent();

        $this->assertStringStartsWith('PK', $bytes);

        $sheet = $this->sheetOf($bytes);

        $this->assertStringContainsString('Rahul Sharma', $sheet);
        $this->assertStringContainsString('Month: '.self::PERIOD, $sheet);
        $this->assertStringContainsString('Winning prize', $sheet);
    }

    #[Test]
    public function the_target_list_downloads_as_a_real_pdf_carrying_the_month(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.targets.export', ['format' => 'pdf', 'period' => self::PERIOD, 'level' => 1]))
            ->assertOk();

        $bytes = $response->getContent();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertStringContainsString('%%EOF', $bytes);
        $this->assertStringContainsString('Rahul Sharma', $bytes);
        $this->assertStringContainsString('Month: '.self::PERIOD, $bytes);
    }

    #[Test]
    public function each_target_level_downloads_its_own_population(): void
    {
        foreach ([1, 2, 3] as $level) {
            $this->actingAs($this->admin)
                ->get(route('admin.targets.export', ['format' => 'csv', 'period' => self::PERIOD, 'level' => $level]))
                ->assertOk();
        }
    }

    // -----------------------------------------------------------------
    // Direct Sale report
    // -----------------------------------------------------------------

    #[Test]
    public function the_direct_sale_report_downloads_the_filtered_table(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.rewards.direct-sales.export', [
                'format' => 'csv',
                'range' => 'custom',
                'from' => self::PERIOD.'-01',
                'to' => self::PERIOD.'-30',
            ]))
            ->assertOk();

        $this->assertStringContainsString(
            'direct-sales-2026-06.csv',
            $response->headers->get('content-disposition'),
        );

        $body = $response->streamedContent();

        $this->assertStringContainsString('Direct Sale Report', $body);
        $this->assertStringContainsString('Month: '.self::PERIOD, $body);
        $this->assertStringContainsString('Rahul Sharma', $body);
        $this->assertStringContainsString('5200.00', $body);
        // Sq.Ft. x rate, worked out on the row.
        $this->assertStringContainsString('208000.00', $body);
    }

    #[Test]
    public function a_direct_sale_download_honours_the_member_filter(): void
    {
        $body = $this->actingAs($this->admin)
            ->get(route('admin.rewards.direct-sales.export', [
                'format' => 'csv',
                'member_id' => $this->winner->id,
            ]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Rahul Sharma', $body);
        $this->assertStringNotContainsString('Quiet Member', $body);
    }

    // -----------------------------------------------------------------
    // Guards
    // -----------------------------------------------------------------

    #[Test]
    public function an_unknown_format_is_not_a_download(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/targets/export/exe')
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->get('/admin/rewards/direct-sales/export/exe')
            ->assertNotFound();
    }

    #[Test]
    public function a_guest_cannot_download_any_report(): void
    {
        $this->get(route('admin.targets.export', ['format' => 'csv']))->assertRedirect(route('login'));
        $this->get(route('admin.rewards.direct-sales.export', ['format' => 'csv']))->assertRedirect(route('login'));
        $this->get(route('admin.company-club.eligible.export', ['format' => 'csv']))->assertRedirect(route('login'));
    }

    #[Test]
    public function the_pages_offer_the_three_formats(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.targets.achieved', ['period' => self::PERIOD, 'level' => 1]))
            ->assertOk()
            ->assertSee('export/csv', false)
            ->assertSee('export/xlsx', false)
            ->assertSee('export/pdf', false);

        $this->actingAs($this->admin)
            ->get(route('admin.rewards.direct-sales'))
            ->assertOk()
            ->assertSee('direct-sales/export/pdf', false);
    }

    #[Test]
    public function the_company_club_eligible_list_downloads_even_before_it_is_calculated(): void
    {
        // No Company Club run for this month: the download is an empty table
        // rather than an invented one, and still says which month it covers.
        $body = $this->actingAs($this->admin)
            ->get(route('admin.company-club.eligible.export', ['format' => 'csv', 'period' => self::PERIOD]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Company Club', $body);
        $this->assertStringContainsString('Month: '.self::PERIOD, $body);
        $this->assertStringContainsString('not calculated', $body);
    }
}
