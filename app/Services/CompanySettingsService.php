<?php

namespace App\Services;

use App\Models\CompanySetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Reads and writes the single company_settings row.
 *
 * Controllers orchestrate, services own the rules
 * (docs/03_DATABASE_AND_ARCHITECTURE.md). The two rules worth stating:
 *
 *  - An upload REPLACES the previous file and deletes it. Leaving orphans in
 *    storage would be harmless but unbounded; a logo is replaced casually and
 *    often while an admin is picking one.
 *  - The designation list is normalised on write — trimmed, blanks dropped,
 *    duplicates collapsed case-insensitively — so the member form never renders
 *    an empty option or the same rank twice.
 */
class CompanySettingsService
{
    private const DISK = 'public';

    private const DIRECTORY = 'company';

    public function current(): CompanySetting
    {
        return CompanySetting::current();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(array $data): CompanySetting
    {
        $settings = $this->current();

        return DB::transaction(function () use ($settings, $data) {
            $settings->fill([
                'company_name' => $data['company_name'],
                'tagline' => $data['tagline'] ?? null,
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'website' => $data['website'] ?? null,
                'authority_name' => $data['authority_name'] ?? null,
                'authority_designation' => $data['authority_designation'] ?? null,
                'designations' => $this->parseDesignations($data['designations'] ?? ''),
            ]);

            $settings->logo_path = $this->resolveFile(
                $settings->logo_path,
                $data['logo'] ?? null,
                ! empty($data['remove_logo']),
                'logo',
            );

            $settings->signature_path = $this->resolveFile(
                $settings->signature_path,
                $data['signature'] ?? null,
                ! empty($data['remove_signature']),
                'signature',
            );

            $settings->save();

            return $settings->refresh();
        });
    }

    /**
     * Save which optional rows the welcome letter prints.
     *
     * Kept apart from update() on purpose: that method rewrites the whole
     * company identity from a form that does not carry these checkboxes, so
     * folding the two together would clear the letter configuration every time
     * somebody corrected a phone number.
     *
     * @param  array<string, bool>  $fields
     */
    public function updateLetterFields(array $fields): CompanySetting
    {
        $settings = $this->current();
        $settings->letter_fields = $fields;
        $settings->save();

        return $settings->refresh();
    }

    /**
     * The stored path after applying an upload or a removal.
     *
     * Returns the existing path untouched when neither was requested — which is
     * every save where the admin only edited text.
     */
    private function resolveFile(?string $existing, ?UploadedFile $upload, bool $remove, string $name): ?string
    {
        if ($upload instanceof UploadedFile) {
            $stored = $upload->storeAs(
                self::DIRECTORY,
                $name.'-'.now()->format('YmdHis').'.'.$upload->extension(),
                self::DISK,
            );

            // Only delete the old file once the new one is safely written, so a
            // failed upload never leaves the company with no logo at all.
            if ($stored !== false) {
                $this->deleteFile($existing);

                return $stored;
            }

            return $existing;
        }

        if ($remove) {
            $this->deleteFile($existing);

            return null;
        }

        return $existing;
    }

    private function deleteFile(?string $path): void
    {
        if ($path && Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /**
     * One designation per line, in the order the admin typed them.
     *
     * Case-insensitive de-duplication keeps "Team Leader" and "team leader"
     * from both reaching the member form, where they would look like two ranks
     * and store as two different strings.
     *
     * @return list<string>
     */
    private function parseDesignations(string $raw): array
    {
        $seen = [];
        $list = [];

        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $value = trim($line);

            if ($value === '' || mb_strlen($value) > 100) {
                continue;
            }

            $key = mb_strtolower($value);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $list[] = $value;
        }

        // A list that normalised to nothing would leave the member form with no
        // options at all. Fall back to the configured default rather than save
        // an empty one — the same value the members table defaults to.
        return $list !== [] ? $list : [(string) config('company.designations.default', 'Sales Advisor')];
    }
}
