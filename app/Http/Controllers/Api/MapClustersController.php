<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MapClusterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MapClustersController extends Controller
{
    public function __construct(
        private readonly MapClusterService $clusterService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'north' => ['required', 'numeric'],
            'south' => ['required', 'numeric'],
            'east' => ['required', 'numeric'],
            'west' => ['required', 'numeric'],
            'zoom' => ['required', 'integer', 'between:1,18'],
            'page' => ['nullable', 'string'],
        ]);

        $bounds = [
            'north' => (float) $validated['north'],
            'south' => (float) $validated['south'],
            'east' => (float) $validated['east'],
            'west' => (float) $validated['west'],
        ];

        if ($bounds['north'] <= $bounds['south'] || $bounds['east'] === $bounds['west']) {
            return response()->json([
                'message' => 'Invalid bounds rectangle.',
            ], 422);
        }

        if ($bounds['east'] < $bounds['west']) {
            return response()->json([
                'message' => 'Antimeridian crossing is not supported.',
            ], 400);
        }

        $zoom = (int) $validated['zoom'];
        $page = $validated['page'] ?? 1;

        return response()->json(
            $this->clusterService->query($bounds, $zoom, $page)
        );
    }
}
