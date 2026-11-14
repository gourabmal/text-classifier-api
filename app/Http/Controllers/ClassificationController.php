<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClassifyTextRequest;
use App\Services\ClassificationService;
use Illuminate\Http\JsonResponse;

class ClassificationController extends Controller
{
    public function __construct(private readonly ClassificationService $service) {}

    /**
     * POST /api/classify
     */
    public function __invoke(ClassifyTextRequest $request): JsonResponse
    {
        $result = $this->service->classify($request->validated('text'));

        return response()->json($result->toArray());
    }
}
