<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\MarginAggregatorService;

/**
 * MarginDashboardController — Phase 5A
 *
 * Admin-only controller for the margin dashboard.
 * Protected by role:admin middleware via route group.
 *
 * @see docs/plans/phase-5a-admin-margin-dashboard.md
 */
class MarginDashboardController extends Controller
{
    public function __construct(
        private readonly MarginAggregatorService $aggregator,
    ) {
    }

    public function index()
    {
        $data = $this->aggregator->aggregate(
            now()->year,
            now()->month,
        );

        // BRL formatting helper: "R$ 1.234,56"
        $brl = fn (string $value): string => 'R$ '.number_format((float) $value, 2, ',', '.');

        return view('admin.margin-dashboard', [
            ...$data,
            'brl_total_revenue' => $brl($data['total_revenue']),
            'brl_total_payout' => $brl($data['total_payout']),
            'brl_net_margin' => $brl($data['net_margin']),
        ]);
    }
}
