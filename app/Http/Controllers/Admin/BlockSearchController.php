<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RegistrySale;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AJAX block-name lookup for the sale entry form.
 *
 * Blocks are not a managed list — they are whatever has been typed against a
 * project so far (see the block/plot migration). This endpoint exists so that
 * repeated entry converges on one spelling: an admin who types "Bl" is shown
 * "Block C" if somebody has already recorded a sale in it, and is free to type
 * something new if not.
 *
 * Deliberately NOT a validation source. Nothing here constrains what may be
 * saved; a project's first sale in a new block must always be possible.
 */
class BlockSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        // Blocks belong to a project. Without one there is nothing meaningful to
        // suggest — a list of every block in the company would be noise, not help.
        if (empty($validated['project_id'])) {
            return ApiResponse::success([], 'Select a project to see its blocks.');
        }

        $term = trim($validated['q'] ?? '');

        $blocks = RegistrySale::query()
            ->where('project_id', $validated['project_id'])
            ->whereNotNull('block_name')
            ->where('block_name', '!=', '')
            ->when($term !== '', fn ($query) => $query->where('block_name', 'like', "%{$term}%"))
            ->distinct()
            ->orderBy('block_name')
            ->limit(config('members.search_limit'))
            ->pluck('block_name')
            ->values();

        return ApiResponse::success($blocks);
    }
}
