<?php

namespace App\Models;

use App\Enums\SaleStatus;
use Database\Factories\RegistrySaleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A registry-confirmed sale.
 *
 * CLIENT-CONFIRMED (2026-08-15):
 *   - entering a sale is approval; there is no pending state
 *   - `registry_date` is the entry day and decides the calculation month
 *   - a sale is never editable or removable after entry
 *
 * This record is the source of every reward in the system. Direct (₹40), Upline
 * (₹50), Team Target (₹30) and Company Club (₹30) all read from `sqft` here, but
 * they remain four independent engines and must never be derived from each other.
 */
#[Fillable([
    'member_id', 'project_id', 'property_id', 'registry_reference',
    'registry_date', 'sale_date', 'sqft', 'notes',
])]
class RegistrySale extends Model
{
    /** @use HasFactory<RegistrySaleFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'registry_date' => 'date',
            'sale_date' => 'date',
            // Cast as a string, never a float: this value is multiplied by the
            // reward rates and must keep exact decimal semantics.
            'sqft' => 'decimal:2',
            'status' => SaleStatus::class,
        ];
    }

    // -----------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<User, $this> */
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    /**
     * Only sales that count toward rewards.
     *
     * Every calculation engine from Phase 5 onward MUST filter through this
     * scope rather than reading the table directly, so the definition of
     * "approved" lives in exactly one place.
     *
     * @param  Builder<RegistrySale>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        // Table-qualified: `members` also has a `status` column, so any caller
        // that joins it would otherwise hit "column 'status' is ambiguous".
        $query->where($query->qualifyColumn('status'), SaleStatus::Approved);
    }

    /**
     * Sales belonging to a calculation period.
     *
     * The period is driven by `registry_date` — client-confirmed. Phase 5 and
     * everything after it depends on this single definition.
     *
     * @param  Builder<RegistrySale>  $query
     * @param  string  $period  'YYYY-MM'
     */
    public function scopeForPeriod(Builder $query, string $period): void
    {
        [$year, $month] = array_map('intval', explode('-', $period));

        // Qualified for the same reason as scopeApproved: these scopes are used
        // on queries that join other tables.
        $query->whereYear($query->qualifyColumn('registry_date'), $year)
            ->whereMonth($query->qualifyColumn('registry_date'), $month);
    }

    /** @param  Builder<RegistrySale>  $query */
    public function scopeBetweenDates(Builder $query, ?string $from, ?string $to): void
    {
        $column = $query->qualifyColumn('registry_date');

        $query->when($from, fn (Builder $q) => $q->whereDate($column, '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate($column, '<=', $to));
    }

    /** @param  Builder<RegistrySale>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $q) use ($term) {
            $q->where('registry_reference', 'like', "%{$term}%")
                ->orWhereHas('member', function (Builder $m) use ($term) {
                    $m->where('member_code', 'like', "%{$term}%")
                        ->orWhere('name', 'like', "%{$term}%")
                        ->orWhere('mobile', 'like', "%{$term}%");
                })
                ->orWhereHas('property', fn (Builder $p) => $p->where('property_code', 'like', "%{$term}%"));
        });
    }

    /**
     * The calculation period this sale belongs to, as 'YYYY-MM'.
     */
    public function period(): string
    {
        return $this->registry_date->format('Y-m');
    }
}
