<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialStatement extends Model
{
    protected $fillable = [
        'instrument_id',
        'period_type',
        'fiscal_year',
        'period_end_date',
        'published_at',
        'currency',
        'is_financial_sector',
        'revenue',
        'gross_profit',
        'operating_income',
        'net_income',
        'ebitda',
        'extraordinary_items',
        'total_assets',
        'current_assets',
        'cash_and_equivalents',
        'total_liabilities',
        'current_liabilities',
        'total_debt',
        'total_equity',
        'operating_cash_flow',
        'capex',
        'free_cash_flow',
        'dividends_paid',
        'shares_outstanding',
        'data_source_id',
        'is_demo',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'period_end_date' => 'date',
            'published_at' => 'date',
            'is_financial_sector' => 'boolean',
            'revenue' => 'decimal:4',
            'gross_profit' => 'decimal:4',
            'operating_income' => 'decimal:4',
            'net_income' => 'decimal:4',
            'ebitda' => 'decimal:4',
            'extraordinary_items' => 'decimal:4',
            'total_assets' => 'decimal:4',
            'current_assets' => 'decimal:4',
            'cash_and_equivalents' => 'decimal:4',
            'total_liabilities' => 'decimal:4',
            'current_liabilities' => 'decimal:4',
            'total_debt' => 'decimal:4',
            'total_equity' => 'decimal:4',
            'operating_cash_flow' => 'decimal:4',
            'capex' => 'decimal:4',
            'free_cash_flow' => 'decimal:4',
            'dividends_paid' => 'decimal:4',
            'shares_outstanding' => 'decimal:4',
            'is_demo' => 'boolean',
        ];
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }
}
