<?php

namespace App\Http\Controllers;

use App\Services\HealthService;

class HealthController extends Controller
{
    /**
     * GET /health: 200 when the scheduler and queue are running, 503 otherwise.
     * Point an uptime monitor (e.g. UptimeRobot) at it.
     */
    public function __invoke(HealthService $health)
    {
        $status = $health->status();

        return response()->json($status, $status['ok'] ? 200 : 503);
    }
}
