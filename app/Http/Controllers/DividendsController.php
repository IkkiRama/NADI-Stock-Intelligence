<?php

namespace App\Http\Controllers;

use App\Domain\Dividends\Services\DividendIncomeService;
use App\Domain\Portfolio\Services\PortfolioValuationService;
use App\Models\BrokerageAccount;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DividendsController extends Controller
{
    public function index(
        Request $request,
        PortfolioValuationService $valuation,
        DividendIncomeService $dividends,
    ): Response {
        $user = $request->user();
        $portfolio = $user->ensureDefaultPortfolio();

        $accountId = $request->query('account_id') ? (int) $request->query('account_id') : null;
        if ($accountId !== null) {
            $owned = BrokerageAccount::query()
                ->where('id', $accountId)
                ->where('user_id', $user->id)
                ->exists();
            if (! $owned) {
                $accountId = null;
            }
        }

        $currencies = config('nadi.currencies', ['IDR', 'USD', 'SGD', 'CNY', 'HKD']);
        $reportingCurrency = strtoupper((string) $request->query('currency', $portfolio->reporting_currency));
        if (! in_array($reportingCurrency, $currencies, true)) {
            $reportingCurrency = $portfolio->reporting_currency ?: 'IDR';
        }

        $taxParam = $request->query('tax_pct');
        $overrideTaxPct = (is_numeric($taxParam) && (float) $taxParam >= 0 && (float) $taxParam <= 50)
            ? (float) $taxParam
            : null;

        $valuationData = $valuation->summarize($user, $portfolio, $accountId, $reportingCurrency);
        $dividendData = $dividends->analyze($user, $portfolio, $valuationData, $accountId, $overrideTaxPct);

        return Inertia::render('portfolio/dividends', [
            'portfolio' => $valuationData['portfolio'],
            'accounts' => $valuationData['accounts'],
            'currencies' => $currencies,
            'filters' => [
                'account_id' => $accountId,
                'currency' => $reportingCurrency,
                'tax_pct' => $overrideTaxPct !== null ? number_format($overrideTaxPct, 1, '.', '') : '',
            ],
            'dividends' => $dividendData,
        ]);
    }
}
