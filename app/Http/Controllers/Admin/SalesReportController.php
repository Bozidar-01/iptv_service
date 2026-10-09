<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SalesReportService;
use Illuminate\Http\Request;

class SalesReportController extends Controller
{
    protected $sales;

    public function __construct(SalesReportService $sales)
    {
        $this->sales = $sales;
    }

    public function index(Request $request)
    {
        $report = $this->sales->report((int) $request->input('days', 30));

        return view('admin.sales.index', [
            'report' => $report,
            'periods' => SalesReportService::PERIODS,
            'busiestMonth' => max($report['months']) ?: 1,
            'lowDays' => SalesReportService::LOW_DAYS,
        ]);
    }
}
