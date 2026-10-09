<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BackupService;
use Illuminate\Http\Request;
use Throwable;

/**
 * The backups on every disk, and a check that the newest one is complete and readable (admins).
 */
class BackupController extends Controller
{
    protected $backups;

    public function __construct(BackupService $backups)
    {
        $this->backups = $backups;
    }

    public function index()
    {
        $disks = [];
        foreach ($this->backups->disks() as $disk) {
            try {
                $disks[] = ['name' => $disk, 'main' => $disk === config('iptv.backup.disk'), 'files' => $this->backups->list($disk), 'error' => null];
            } catch (Throwable $e) {
                report($e);
                $disks[] = ['name' => $disk, 'main' => $disk === config('iptv.backup.disk'), 'files' => [], 'error' => __('This disk can not be read right now.')];
            }
        }

        return view('admin.backups.index', [
            'disks' => $disks,
            'lastBackup' => $this->backups->lastSuccess(),
            'lastVerified' => $this->backups->lastVerified(),
            'staleHours' => BackupService::STALE_HOURS,
        ]);
    }

    /** Check the newest backup on every disk. */
    public function verify(Request $request)
    {
        try {
            $results = $this->backups->verifyAll($request->user());
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', __('The check could not run: :message', ['message' => mb_substr($e->getMessage(), 0, 200)]));
        }

        return back()->with('backup_check', $results);
    }
}
