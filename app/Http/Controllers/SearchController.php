<?php

namespace App\Http\Controllers;

use App\Domain\Instruments\Services\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request, GlobalSearchService $search): JsonResponse
    {
        $q = (string) $request->query('q', '');

        return response()->json(
            $search->search($q, $request->user(), app()->getLocale())
        );
    }
}
