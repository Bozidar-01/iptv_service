<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditService;
use App\Services\UserCsvService;
use App\Services\UserService;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Users as CSV: export what the list shows, import many users at once. Admins and resellers (a reseller's own users).
 */
class UserCsvController extends Controller
{
    protected $csv;

    protected $users;

    protected $audit;

    public function __construct(UserCsvService $csv, UserService $users, AuditService $audit)
    {
        $this->csv = $csv;
        $this->users = $users;
        $this->audit = $audit;
    }

    /** The users the list filters match, as a CSV download; `passwords=1` adds the passwords. */
    public function export(Request $request)
    {
        $actor = $request->user();
        $filters = array_filter($request->only(['search', 'status', 'reseller']), 'is_scalar');
        $withPasswords = $request->boolean('passwords');
        $query = $this->users->filteredQuery($filters, $actor);

        if ($withPasswords) {
            $this->audit->record(AuditLog::USERS_EXPORTED, null, ['count' => (clone $query)->count(), 'passwords' => true]);
        }

        return response()->streamDownload(function () use ($query, $actor, $withPasswords) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads the accents
            $this->csv->export($query, $actor->isAdmin(), $withPasswords, fn (array $row) => fputcsv($out, $row));
            fclose($out);
        }, 'users-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function form()
    {
        return view('admin.users.import');
    }

    /** A CSV with the column names and one example row. */
    public function template()
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, UserCsvService::COLUMNS);
            fputcsv($out, ['maria', '', now()->addMonth()->toDateString(), '1', 'Sports|Movies', 'maria@example.com', 'active']);
            fclose($out);
        }, 'users-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function import(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'max:1024', 'mimes:csv,txt']], ['file.required' => __('Choose a CSV file.')]);
        $checkOnly = $request->boolean('check_only');

        try {
            $rows = $this->csv->parse($request->file('file'));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $result = $this->csv->import($request->user(), $rows, $checkOnly);

        if (!$checkOnly && $result['created']) {
            $this->audit->record(AuditLog::USERS_IMPORTED, null, ['count' => count($result['created']), 'credits_spent' => $result['credits']]);
        }

        return back()->with('import', $result + ['checked' => $checkOnly]);
    }
}
