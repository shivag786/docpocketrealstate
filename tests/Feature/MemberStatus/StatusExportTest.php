<?php

namespace Tests\Feature\MemberStatus;

use App\Models\Member;
use App\Models\RegistrySale;
use App\Models\User;
use App\Modules\MemberStatus\Services\StatusRecalculationService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use ZipArchive;

/**
 * CSV / Excel / PDF downloads of the status table (client request, 2026-08-25).
 *
 * All three are generated in-process with no new composer dependency, so the
 * tests check the actual bytes: a real zip container for .xlsx, a real PDF
 * header for .pdf, and the filters carried into both.
 */
class StatusExportTest extends MemberStatusTestCase
{
    protected bool $reportEnabled = true;

    private User $admin;

    private Member $active;

    private Member $lapsed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        $this->active = Member::factory()->root()->create([
            'name' => 'Shiva Kumar',
            'joining_date' => '2026-08-01',
        ]);

        $this->lapsed = Member::factory()->root()->create([
            'name' => 'Long Gone',
            'joining_date' => '2025-01-01',
        ]);

        RegistrySale::factory()->withoutDetails()->forMember($this->active)
            ->create(['registry_date' => '2026-08-20', 'sale_date' => '2026-08-20']);

        app(StatusRecalculationService::class)->recalculateAll(CarbonImmutable::parse('2026-08-25'));
    }

    private function download(string $format, array $query = [])
    {
        return $this->actingAs($this->admin)
            ->get('/admin/member-status/export/'.$format.($query === [] ? '' : '?'.http_build_query($query)));
    }

    #[Test]
    public function it_downloads_the_table_as_csv(): void
    {
        $response = $this->download('csv')->assertOk();

        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment;', $response->headers->get('content-disposition'));

        $body = $response->streamedContent();

        $this->assertStringContainsString('Member code', $body);
        $this->assertStringContainsString('Shiva Kumar', $body);
        $this->assertStringContainsString('Long Gone', $body);

        // The BOM, without which Excel mangles non-ASCII names.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
    }

    #[Test]
    public function it_downloads_a_real_xlsx_workbook(): void
    {
        $response = $this->download('xlsx')->assertOk();

        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('content-type'),
        );

        $bytes = $response->getContent();

        // "PK" — a real Office Open XML package, not an HTML table wearing an
        // .xlsx extension.
        $this->assertStringStartsWith('PK', $bytes);

        $path = tempnam(sys_get_temp_dir(), 'xlsxtest');
        file_put_contents($path, $bytes);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'The workbook should open as a zip archive.');

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $workbook = $zip->getFromName('xl/workbook.xml');
        $zip->close();
        @unlink($path);

        $this->assertNotFalse($sheet);
        $this->assertStringContainsString('Shiva Kumar', $sheet);
        $this->assertStringContainsString('Member code', $sheet);
        $this->assertStringContainsString('Member Status', (string) $workbook);
    }

    #[Test]
    public function it_downloads_a_real_pdf(): void
    {
        $response = $this->download('pdf')->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));

        $bytes = $response->getContent();

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertStringContainsString('%%EOF', $bytes);
        $this->assertStringContainsString('Shiva Kumar', $bytes);
        $this->assertStringContainsString('/Type /Catalog', $bytes);
    }

    #[Test]
    public function a_download_carries_the_filters_the_admin_applied(): void
    {
        $body = $this->download('csv', ['status' => 'INACTIVE'])->assertOk()->streamedContent();

        $this->assertStringContainsString('Long Gone', $body);
        $this->assertStringNotContainsString('Shiva Kumar', $body);

        $searched = $this->download('csv', ['q' => 'Shiva'])->assertOk()->streamedContent();

        $this->assertStringContainsString('Shiva Kumar', $searched);
        $this->assertStringNotContainsString('Long Gone', $searched);
    }

    #[Test]
    public function an_unknown_format_is_not_a_download(): void
    {
        $this->actingAs($this->admin)->get('/admin/member-status/export/exe')->assertNotFound();
    }

    #[Test]
    public function a_guest_cannot_download_member_data(): void
    {
        $this->get('/admin/member-status/export/csv')->assertRedirect(route('login'));
    }

    #[Test]
    public function the_report_page_offers_all_three_formats(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/member-status')
            ->assertOk()
            ->assertSee('export/csv', false)
            ->assertSee('export/xlsx', false)
            ->assertSee('export/pdf', false);
    }
}
