<?php

namespace App\Services;

use App\Models\Property;
use App\Models\RegistrySale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Recording of registry sales.
 *
 * CLIENT-CONFIRMED: entry is approval and a sale is never editable afterwards,
 * so this service intentionally exposes only creation. There is no update() and
 * no delete().
 *
 * IF A CORRECTION WORKFLOW IS EVER REQUIRED it belongs here, and it cannot be
 * built until the business states what happens to rewards already calculated
 * from the sale being corrected. docs/02_BUSINESS_RULES.md §6 currently puts
 * cancellation and refunds out of scope.
 */
class RegistrySaleService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function record(array $data, User $enteredBy): RegistrySale
    {
        // Project and property are optional. When a property IS given, guard the
        // pairing rather than trusting the submitted project_id — a stale or
        // tampered form must not attach a sale to the wrong project.
        if (! empty($data['property_id'])) {
            $property = Property::findOrFail($data['property_id']);

            if ((int) $property->project_id !== (int) ($data['project_id'] ?? 0)) {
                throw new RuntimeException(
                    'The selected property does not belong to the selected project.'
                );
            }
        }

        return DB::transaction(function () use ($data, $enteredBy) {
            $sale = new RegistrySale;
            $sale->fill($data);

            // Never mass-assigned: the recording operator and the approval state
            // are decided by the server, not the form.
            $sale->entered_by = $enteredBy->id;
            $sale->save();

            return $sale;
        });
    }

    /**
     * Totals for a set of filtered sales, used by the history screen.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<RegistrySale>  $query
     * @return array{count: int, sqft: string}
     */
    public function totals($query): array
    {
        $aggregate = (clone $query)
            ->selectRaw('COUNT(*) as sale_count, COALESCE(SUM(sqft), 0) as total_sqft')
            ->reorder()
            ->first();

        return [
            'count' => (int) $aggregate->sale_count,
            // Kept as a string: this figure is Sq.Ft. that later multiplies the
            // reward rates, so it must not pass through a float.
            'sqft' => (string) $aggregate->total_sqft,
        ];
    }
}
