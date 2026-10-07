<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StreamLog;

class LogController extends Controller
{
    public function index()
    {
        $logs = StreamLog::with(['user', 'channel'])
            ->orderBy('started_at', 'desc')
            ->paginate(50);
            
        return view('admin.logs.index', compact('logs'));
    }
}
