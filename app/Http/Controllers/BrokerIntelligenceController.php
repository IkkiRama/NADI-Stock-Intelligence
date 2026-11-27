<?php

namespace App\Http\Controllers;

use App\Domain\BrokerIntelligence\Services\BrokerIntelligenceService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BrokerIntelligenceController extends Controller
{
    public function index(Request $request, BrokerIntelligenceService $service): Response
    {
        $payload = $service->buildWorkspace($request->user(), [
            'window' => $request->query('window', '5D'),
            'symbol' => $request->query('symbol'),
            'exchange' => $request->query('exchange', 'IDX'),
            'category' => $request->query('category', 'all'),
            'currency' => $request->query('currency'),
        ]);

        return Inertia::render('broker-intelligence/index', $payload);
    }

    public function exportCsv(Request $request, BrokerIntelligenceService $service): StreamedResponse
    {
        $payload = $service->buildWorkspace($request->user(), [
            'window' => $request->query('window', '5D'),
            'symbol' => $request->query('symbol'),
            'exchange' => $request->query('exchange', 'IDX'),
        ]);

        $flow = $payload['stockFlow'];
        abort_if(! is_array($flow), 404, 'No EOD broker summary data found for export.');

        $symbol = strtolower((string) ($flow['instrument']['symbol'] ?? 'stock'));
        $window = strtolower((string) ($flow['window'] ?? '5d'));
        $filename = "nadi-broker-flow-{$symbol}-{$window}-".now('UTC')->format('Ymd-His').'.csv';
        $csv = $service->exportStockFlowCsv($flow);

        return response()->streamDownload(function () use ($csv): void {
            echo $csv;
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
