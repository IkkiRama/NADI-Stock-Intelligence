<!DOCTYPE html>
<html lang="{{ $locale ?? 'id' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NADI Report — {{ $report['report_type'] ?? 'statement' }}</title>
    <style>
        @page { size: A4; margin: 14mm; }
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Inter, Arial, sans-serif;
            color: #263447;
            background: #F7F8FA;
            margin: 0;
            padding: 20px;
            font-size: 12px;
            line-height: 1.45;
        }
        .sheet {
            max-width: 980px;
            margin: 0 auto;
            background: #FFFFFF;
            border: 1px solid #D9E0EA;
            border-radius: 6px;
            padding: 24px 28px;
        }
        .no-print {
            max-width: 980px;
            margin: 0 auto 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
        }
        .btn {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 4px;
            border: 1px solid #CBD5E1;
            background: #FFFFFF;
            color: #263447;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-primary {
            background: #263447;
            color: #FFFFFF;
            border-color: #263447;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #263447;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }
        .title { font-size: 18px; font-weight: 700; margin: 0; }
        .subtitle { color: #5E6C80; font-size: 11px; margin-top: 3px; }
        .demo-banner {
            background: #EFEAF9;
            border: 1px solid #B9A8E9;
            color: #413275;
            padding: 6px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            margin-bottom: 14px;
        }
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 18px;
        }
        .kpi {
            border: 1px solid #E2E7EF;
            border-radius: 4px;
            padding: 8px 10px;
        }
        .kpi-label { font-size: 10px; color: #5E6C80; text-transform: uppercase; letter-spacing: 0.03em; }
        .kpi-val { font-size: 14px; font-weight: 700; margin-top: 3px; font-variant-numeric: tabular-nums; }
        h2 { font-size: 13px; margin: 16px 0 8px; border-bottom: 1px solid #E2E7EF; padding-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 14px; font-size: 11px; }
        th, td { padding: 6px 6px; border-bottom: 1px solid #EEF2F7; text-align: left; }
        th { color: #5E6C80; font-weight: 600; background: #F8FAFC; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .footer {
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px solid #E2E7EF;
            color: #5E6C80;
            font-size: 10px;
        }
        @media print {
            body { background: #FFFFFF; padding: 0; }
            .sheet { border: none; padding: 0; max-width: none; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
@php
    $meta = $report['meta'] ?? [];
    $type = $report['report_type'] ?? 'executive_statement';
    $ccy = $meta['reporting_currency'] ?? 'IDR';
    $val = $report['valuation'] ?? [];
    $sum = $val['summary'] ?? [];
    $dec = $ccy === 'IDR' ? 0 : 2;
    $fmtMoney = fn ($v) => $v === null ? '—' : $ccy . ' ' . number_format((float) $v, $dec, '.', ',');
    $fmtPct = fn ($v) => $v === null ? '—' : number_format(((float) $v) * 100, 2, '.', ',') . '%';
@endphp

<div class="no-print">
    <a href="/reports?type={{ urlencode($type) }}" class="btn">← Back to NADI Reports</a>
    <button type="button" class="btn btn-primary" onclick="window.print()">Print / Save PDF</button>
</div>

<div class="sheet">
    <div class="header">
        <div>
            <p class="title">NADI — Global Stock Intelligence</p>
            <div class="subtitle">
                {{ strtoupper(str_replace('_', ' ', $type)) }} · {{ $meta['portfolio_name'] ?? 'Portfolio' }} · {{ $ccy }} ({{ $meta['cost_basis_method'] ?? 'weighted_average' }})
            </div>
        </div>
        <div class="subtitle" style="text-align: right;">
            <div>Owner: {{ $meta['user_name'] ?? '' }}</div>
            <div>Range: {{ $meta['range'] ?? '1Y' }} · As of: {{ $meta['as_of'] ?? '—' }}</div>
            <div>Generated (UTC): {{ $meta['generated_at_utc'] ?? '' }}</div>
        </div>
    </div>

    @if (!empty($meta['is_demo']))
        <div class="demo-banner">
            DEMO DATA NOTICE: This statement includes simulated demonstration accounts or market data and does not represent live brokerage holdings.
        </div>
    @endif

    @if ($type === 'executive_statement')
        <div class="kpi-grid">
            <div class="kpi"><div class="kpi-label">Total Equity</div><div class="kpi-val">{{ $fmtMoney($sum['total_equity'] ?? 0) }}</div></div>
            <div class="kpi"><div class="kpi-label">Market Value</div><div class="kpi-val">{{ $fmtMoney($sum['market_value'] ?? 0) }}</div></div>
            <div class="kpi"><div class="kpi-label">Cash Balance</div><div class="kpi-val">{{ $fmtMoney($sum['cash_balance'] ?? 0) }}</div></div>
            <div class="kpi"><div class="kpi-label">Total P&L</div><div class="kpi-val">{{ $fmtMoney($sum['total_pnl'] ?? 0) }}</div></div>
        </div>

        <h2>Consolidated Holdings</h2>
        <table>
            <thead>
                <tr>
                    <th>Ticker</th>
                    <th>Name</th>
                    <th>Currency</th>
                    <th class="num">Qty</th>
                    <th class="num">Close</th>
                    <th class="num">Cost ({{ $ccy }})</th>
                    <th class="num">Value ({{ $ccy }})</th>
                    <th class="num">Unrealized P&L</th>
                    <th class="num">Weight</th>
                </tr>
            </thead>
            <tbody>
                @forelse (($val['consolidatedHoldings'] ?? []) as $h)
                    <tr>
                        <td><strong>{{ $h['symbol'] ?? '' }}</strong> · {{ $h['exchange_code'] ?? '' }}</td>
                        <td>{{ $h['name'] ?? '' }}</td>
                        <td>{{ $h['currency'] ?? '' }}</td>
                        <td class="num">{{ number_format((float) ($h['quantity'] ?? 0), 0, '.', ',') }}</td>
                        <td class="num">{{ $h['close'] !== null ? number_format((float) $h['close'], 2, '.', ',') : '—' }}</td>
                        <td class="num">{{ $fmtMoney($h['total_cost_reporting'] ?? 0) }}</td>
                        <td class="num">{{ $fmtMoney($h['market_value_reporting'] ?? 0) }}</td>
                        <td class="num">{{ $fmtMoney($h['unrealized_pnl_reporting'] ?? 0) }}</td>
                        <td class="num">{{ $fmtPct($h['weight_pct'] ?? 0) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9">No active holdings.</td></tr>
                @endforelse
            </tbody>
        </table>
    @elseif ($type === 'performance_brief')
        @php
            $twr = $report['twr'] ?? [];
            $xirr = $report['xirr'] ?? [];
            $bench = $report['benchmark'] ?? [];
            $attr = $report['attribution'] ?? [];
        @endphp
        <div class="kpi-grid">
            <div class="kpi"><div class="kpi-label">Portfolio TWR</div><div class="kpi-val">{{ $fmtPct($twr['twr_total'] ?? null) }}</div></div>
            <div class="kpi"><div class="kpi-label">XIRR (Ann.)</div><div class="kpi-val">{{ $fmtPct($xirr['xirr_annualized'] ?? null) }}</div></div>
            <div class="kpi"><div class="kpi-label">{{ $bench['code'] ?? 'Benchmark' }}</div><div class="kpi-val">{{ $fmtPct($bench['benchmark_return'] ?? null) }}</div></div>
            <div class="kpi"><div class="kpi-label">Excess Return</div><div class="kpi-val">{{ $fmtPct($bench['excess_return'] ?? null) }}</div></div>
        </div>
        <h2>Stock Performance Attribution</h2>
        <table>
            <thead>
                <tr>
                    <th>Ticker</th>
                    <th class="num">Local Price</th>
                    <th class="num">FX Translation</th>
                    <th class="num">Dividends</th>
                    <th class="num">Fees/Taxes</th>
                    <th class="num">Total P&L ({{ $ccy }})</th>
                    <th class="num">Contribution</th>
                </tr>
            </thead>
            <tbody>
                @forelse (($attr['by_stock'] ?? []) as $s)
                    <tr>
                        <td><strong>{{ $s['symbol'] ?? '' }}</strong> · {{ $s['sector'] ?? '' }}</td>
                        <td class="num">{{ $fmtMoney($s['local_price_effect_rep'] ?? 0) }}</td>
                        <td class="num">{{ $fmtMoney($s['fx_effect_rep'] ?? 0) }}</td>
                        <td class="num">{{ $fmtMoney($s['dividend_rep'] ?? 0) }}</td>
                        <td class="num">{{ $fmtMoney($s['fee_tax_rep'] ?? 0) }}</td>
                        <td class="num">{{ $fmtMoney($s['total_pnl_rep'] ?? 0) }}</td>
                        <td class="num">{{ $fmtPct($s['contribution_pct'] ?? null) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7">No attribution data available for selected window.</td></tr>
                @endforelse
            </tbody>
        </table>
    @elseif ($type === 'dividend_tax_schedule')
        @php $div = $report['dividends'] ?? []; $ds = $div['summary'] ?? []; @endphp
        <div class="kpi-grid">
            <div class="kpi"><div class="kpi-label">Realized YTD (Net)</div><div class="kpi-val">{{ $fmtMoney($ds['realized_net_ytd_rep'] ?? 0) }}</div></div>
            <div class="kpi"><div class="kpi-label">Realized All-Time</div><div class="kpi-val">{{ $fmtMoney($ds['realized_net_all_time_rep'] ?? 0) }}</div></div>
            <div class="kpi"><div class="kpi-label">Projected 12M (Est. Net)</div><div class="kpi-val">{{ $fmtMoney($ds['projected_annual_net_rep'] ?? 0) }}</div></div>
            <div class="kpi"><div class="kpi-label">Yield on Cost</div><div class="kpi-val">{{ $fmtPct($ds['portfolio_yield_on_cost'] ?? null) }}</div></div>
        </div>
        <h2>Projected Annual Dividend Profile (Estimate)</h2>
        <table>
            <thead>
                <tr>
                    <th>Ticker</th>
                    <th>Basis</th>
                    <th class="num">Shares</th>
                    <th class="num">Tax Rate</th>
                    <th class="num">Est. Annual Net ({{ $ccy }})</th>
                    <th class="num">Yield MV</th>
                    <th class="num">YoC</th>
                </tr>
            </thead>
            <tbody>
                @forelse (($div['holdings'] ?? []) as $h)
                    <tr>
                        <td><strong>{{ $h['symbol'] ?? '' }}</strong></td>
                        <td>{{ $h['estimation_basis'] ?? '' }}</td>
                        <td class="num">{{ number_format((float) ($h['quantity'] ?? 0), 0, '.', ',') }}</td>
                        <td class="num">{{ $h['tax_rate_pct'] ?? '0' }}%</td>
                        <td class="num">{{ $fmtMoney($h['projected_annual_net_rep'] ?? 0) }}</td>
                        <td class="num">{{ $fmtPct($h['yield_on_mv'] ?? null) }}</td>
                        <td class="num">{{ $fmtPct($h['yield_on_cost'] ?? null) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7">No holdings.</td></tr>
                @endforelse
            </tbody>
        </table>
    @elseif ($type === 'risk_rebalancing_plan')
        @php $rk = $report['risk'] ?? []; $reb = $report['rebalance'] ?? []; @endphp
        <div class="kpi-grid">
            <div class="kpi"><div class="kpi-label">Max Drawdown</div><div class="kpi-val">{{ $fmtPct($rk['max_drawdown'] ?? null) }}</div></div>
            <div class="kpi"><div class="kpi-label">Volatility (Ann.)</div><div class="kpi-val">{{ $fmtPct($rk['annualized_volatility'] ?? null) }}</div></div>
            <div class="kpi"><div class="kpi-label">Sharpe Ratio</div><div class="kpi-val">{{ $rk['sharpe_ratio'] ?? '—' }}</div></div>
            <div class="kpi"><div class="kpi-label">Est. Rebalance Fees</div><div class="kpi-val">{{ $fmtMoney($reb['total_estimated_fees_rep'] ?? 0) }}</div></div>
        </div>
        <h2>Lot-Aware Rebalancing Simulation Plan</h2>
        <table>
            <thead>
                <tr>
                    <th>Ticker</th>
                    <th class="num">Current</th>
                    <th class="num">Target</th>
                    <th>Action</th>
                    <th class="num">Lots / Shares</th>
                    <th class="num">Est. Gross ({{ $ccy }})</th>
                    <th class="num">Post Weight</th>
                </tr>
            </thead>
            <tbody>
                @forelse (($reb['rows'] ?? []) as $r)
                    <tr>
                        <td><strong>{{ $r['symbol'] ?? '' }}</strong></td>
                        <td class="num">{{ $r['current_weight_pct'] ?? '0' }}%</td>
                        <td class="num">{{ $r['target_weight_pct'] ?? '0' }}%</td>
                        <td>{{ strtoupper($r['action'] ?? '') }}</td>
                        <td class="num">{{ ($r['suggested_lots'] ?? 0) > 0 ? ($r['suggested_lots'].' lot ('.$r['suggested_shares'].')') : '—' }}</td>
                        <td class="num">{{ $fmtMoney($r['estimated_gross_rep'] ?? 0) }}</td>
                        <td class="num">{{ $r['post_weight_pct'] ?? '0' }}%</td>
                    </tr>
                @empty
                    <tr><td colspan="7">No rebalancing rows.</td></tr>
                @endforelse
            </tbody>
        </table>
    @elseif ($type === 'tax_realized_ledger')
        @php $ld = $report['ledger'] ?? []; $lt = $ld['totals'] ?? []; @endphp
        <div class="kpi-grid">
            <div class="kpi"><div class="kpi-label">Deposits</div><div class="kpi-val">{{ $fmtMoney($lt['deposits_rep'] ?? 0) }}</div></div>
            <div class="kpi"><div class="kpi-label">Buys Gross</div><div class="kpi-val">{{ $fmtMoney($lt['buy_gross_rep'] ?? 0) }}</div></div>
            <div class="kpi"><div class="kpi-label">Sells Gross</div><div class="kpi-val">{{ $fmtMoney($lt['sell_gross_rep'] ?? 0) }}</div></div>
            <div class="kpi"><div class="kpi-label">Fees & Taxes</div><div class="kpi-val">{{ $fmtMoney($lt['fees_taxes_rep'] ?? 0) }}</div></div>
        </div>
        <h2>Transaction & Fee Register</h2>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Account</th>
                    <th>Type</th>
                    <th>Ticker</th>
                    <th class="num">Qty</th>
                    <th class="num">Gross</th>
                    <th class="num">Net Cash ({{ $ccy }})</th>
                </tr>
            </thead>
            <tbody>
                @forelse (($ld['transactions'] ?? []) as $tx)
                    <tr>
                        <td>{{ $tx['trade_date'] ?? '' }}</td>
                        <td>{{ $tx['account_name'] ?? '' }}</td>
                        <td>{{ $tx['transaction_type'] ?? '' }}</td>
                        <td>{{ $tx['symbol'] ?: '—' }}</td>
                        <td class="num">{{ $tx['quantity'] !== null ? number_format((float) $tx['quantity'], 0, '.', ',') : '—' }}</td>
                        <td class="num">{{ $tx['transaction_currency'] ?? '' }} {{ number_format((float) ($tx['gross_amount'] ?? 0), 2, '.', ',') }}</td>
                        <td class="num">{{ $fmtMoney($tx['net_cash_reporting'] ?? 0) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7">No transactions recorded in selected window.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif

    <div class="footer">
        @foreach (($report['disclaimers'] ?? []) as $msg)
            <div>• {{ $msg }}</div>
        @endforeach
    </div>
</div>
</body>
</html>
