<?php

namespace App\Models;

use App\Enums\PropertyStatus;
use Database\Factories\PropertyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A property or site within a project.
 */
#[Fillable(['project_id', 'property_code', 'details', 'status'])]
class Property extends Model
{
    /** @use HasFactory<PropertyFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PropertyStatus::class,
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<RegistrySale, $this> */
    public function sales(): HasMany
    {
        return $this->hasMany(RegistrySale::class);
    }

    /** @param  Builder<Property>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', PropertyStatus::Active);
    }

    /** @param  Builder<Property>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $q) use ($term) {
            $q->where('property_code', 'like', "%{$term}%")
                ->orWhere('details', 'like', "%{$term}%");
        });
    }

    public function isActive(): bool
    {
        return $this->status === PropertyStatus::Active;
    }
}
