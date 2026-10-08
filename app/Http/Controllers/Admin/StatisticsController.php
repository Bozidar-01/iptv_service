<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\StatisticsService;
use Illuminate\Http\Request;

class StatisticsController extends Controller
{
    protected $statistics;

    public function __construct(StatisticsService $statistics)
    {
        $this->statistics = $statistics;
    }

    public function index(Request $request)
    {
        $report = $this->statistics->report((int) $request->input('days', 30));

        return view('admin.statistics.index', [
            'report' => $report,
            'periods' => StatisticsService::PERIODS,
            'busiestDay' => max($report['per_day']) ?: 1,
            'busiestHour' => max($report['per_hour']) ?: 1,
        ]);
    }
}
