<?php

namespace App\Http\Controllers;

use App\Domain\MarketData\Services\FxConversionService;
use App\Models\Exchange;
use Inertia\Inertia;
use Inertia\Response;

class CalculatorsController extends Controller
{
    public function index(FxConversionService $fx): Response
    {
        $currencies = config('nadi.currencies', ['IDR', 'USD', 'SGD', 'CNY', 'HKD']);
        $fxRatesToIdr = [];
        foreach ($currencies as $ccy) {
            $fxRatesToIdr[$ccy] = $fx->rate($ccy, 'IDR') ?? ($ccy === 'IDR' ? '1.00000000' : null);
        }

        return Inertia::render('tools/calculators', [
            'exchanges' => Exchange::query()->where('is_active', true)->orderBy('id')->get(['code', 'name', 'currency', 'default_lot_size']),
            'tradingCosts' => config('nadi.trading_costs'),
            'currencies' => $currencies,
            'fxRatesToIdr' => $fxRatesToIdr,
        ]);
    }
}
