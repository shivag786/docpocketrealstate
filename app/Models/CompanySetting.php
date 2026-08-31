<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Company identity. Exactly one row exists.
 *
 * `current()` is the only way anything should read this, for the same reason
 * CompanyClubSetting::current() is: it creates the row from config on first
 * use, so a fresh install and a seeded one behave identically and no caller —
 * least of all a Blade template — has to handle a missing row.
 *
 * Nothing here is read by a reward engine. It is the letterhead.
 */
class CompanySetting extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'designations' => 'array',
            'letter_fields' => 'array',
        ];
    }

    /**
     * The single settings row, created from config on first use.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'company_name' => (string) config('company.name', config('app.name')),
            'designations' => config('company.designations.options', []),
        ]);
    }

    public function name(): string
    {
        return $this->company_name !== '' && $this->company_name !== null
            ? $this->company_name
            : (string) config('app.name');
    }

    /**
     * The designations a member may be given, in display order.
     *
     * The configured default is always present and always first, even if an
     * admin removed it from the list — `members.designation` defaults to it at
     * the database level, so a list without it would produce members holding a
     * rank the form cannot display.
     *
     * @return list<string>
     */
    public function designationOptions(): array
    {
        $default = (string) config('company.designations.default', 'Sales Advisor');

        $options = collect($this->designations ?? config('company.designations.options', []))
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->values();

        return $options
            ->reject(fn (string $value) => strcasecmp($value, $default) === 0)
            ->prepend($default)
            ->all();
    }

    /**
     * The optional welcome-letter rows, as field => bool.
     *
     * Merged over the configured defaults rather than returned raw, so a field
     * added to config later is switched on for every existing install without a
     * data migration, and a stale key left in the database cannot introduce a
     * row the letter has no template for.
     *
     * @return array<string, bool>
     */
    public function letterFields(): array
    {
        $defaults = (array) config('company.letter.fields', []);
        $stored = (array) ($this->letter_fields ?? []);

        $fields = [];

        foreach ($defaults as $key => $default) {
            $fields[$key] = array_key_exists($key, $stored)
                ? (bool) $stored[$key]
                : (bool) $default;
        }

        return $fields;
    }

    /**
     * Whether one optional row is printed on the welcome letter.
     *
     * An unknown field is false, never true: a typo in a template must drop a
     * row, not silently print something nobody chose to show.
     */
    public function showsOnLetter(string $field): bool
    {
        return $this->letterFields()[$field] ?? false;
    }

    /**
     * Browser URL for the logo, or null when none has been uploaded.
     *
     * Screens use this. The PDFs do NOT — see absolutePath().
     */
    public function logoUrl(): ?string
    {
        return $this->fileUrl($this->logo_path);
    }

    public function signatureUrl(): ?string
    {
        return $this->fileUrl($this->signature_path);
    }

    /**
     * On-disk path for a stored image, for the PDF renderer.
     *
     * dompdf is given a filesystem path rather than a URL on purpose: fetching
     * its own site over HTTP to render a PDF would fail behind basic auth, on a
     * host that cannot resolve its own domain, and whenever the queue runs
     * somewhere the web server is not.
     */
    public function absolutePath(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($path) ? $disk->path($path) : null;
    }

    public function logoFile(): ?string
    {
        return $this->absolutePath($this->logo_path);
    }

    public function signatureFile(): ?string
    {
        return $this->absolutePath($this->signature_path);
    }

    /**
     * A stored image as a base64 data URI, for the PDF templates.
     *
     * dompdf is handed the bytes inline rather than a path or a URL. A path
     * would have to escape dompdf's chroot, which defaults to public/ while
     * these files live under storage/; a URL would mean the renderer fetching
     * its own site over HTTP, which fails behind basic auth and on any host
     * that cannot resolve its own domain. Inline bytes have neither problem.
     *
     * Returns null when nothing is uploaded — every template must render
     * without a logo, because a fresh install has none.
     */
    public function dataUri(?string $path): ?string
    {
        $file = $this->absolutePath($path);

        if ($file === null) {
            return null;
        }

        $mime = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => null,
        };

        if ($mime === null) {
            return null;
        }

        $bytes = @file_get_contents($file);

        return $bytes === false ? null : 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    public function logoDataUri(): ?string
    {
        return $this->dataUri($this->logo_path);
    }

    public function signatureDataUri(): ?string
    {
        return $this->dataUri($this->signature_path);
    }
    private function fileUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return Storage::disk('public')->exists($path)
            ? Storage::disk('public')->url($path)
            : null;
    }
}
