<?php

namespace App\Services;

use App\Exceptions\InsufficientCredits;
use App\Exceptions\ResellerLimitReached;
use App\Models\Bouquet;
use App\Models\CreditTransaction;
use App\Models\Sale;
use App\Models\User;
use App\Support\Credits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Subscribers in and out as CSV: an export for the accounting (what the list shows, optionally with passwords) and an
 * import that creates many users at once with the same rules as the user form (reseller limits, bouquets they hold, credits).
 */
class UserCsvService
{
    public const MAX_ROWS = 2000;

    /** Columns of the import, in the template's order. */
    public const COLUMNS = ['username', 'password', 'expiration_date', 'max_connections', 'bouquets', 'email', 'status'];

    protected $users;

    protected $credits;

    public function __construct(UserService $users, CreditService $credits)
    {
        $this->users = $users;
        $this->credits = $credits;
    }

    /** A cell a spreadsheet would read as a formula gets a quote in front (CSV injection). */
    public static function safe(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /**
     * Write the users as CSV through $write(array $row). Names of bouquets are joined with "|".
     *
     * @param  Builder<User>  $users
     */
    public function export(Builder $users, bool $isAdmin, bool $withPasswords, callable $write): void
    {
        $header = ['username', 'role', 'status', 'email', 'expiration_date', 'max_connections', 'bouquets', 'trial', 'created_at'];
        if ($isAdmin) {
            $header[] = 'reseller';
        }
        if ($withPasswords) {
            $header[] = 'password';
        }
        $write($header);

        (clone $users)->with(['bouquets:id,name', 'owner:id,username'])->orderBy('username')->chunk(500, function ($chunk) use ($write, $isAdmin, $withPasswords) {
            foreach ($chunk as $user) {
                $row = [
                    self::safe($user->username), $user->role, $user->status, self::safe($user->email), $user->expiration_date?->toDateString(),
                    $user->max_connections, self::safe($user->bouquets->pluck('name')->sort()->join('|')), $user->is_trial ? 'yes' : 'no', $user->created_at?->toDateTimeString(),
                ];
                if ($isAdmin) {
                    $row[] = self::safe($user->owner?->username);
                }
                if ($withPasswords) {
                    $row[] = self::safe($user->password_plain);
                }
                $write($row);
            }
        });
    }

    /**
     * Read an uploaded CSV (comma or semicolon, with a header row) into rows keyed by column name.
     *
     * @return list<array<string, string>>
     *
     * @throws RuntimeException when the file can't be used as a whole
     */
    public function parse(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'rb');
        $first = (string) fgets($handle);
        rewind($handle);
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

        $header = fgetcsv($handle, 0, $delimiter);
        if (!$header) {
            throw new RuntimeException(__('The file is empty.'));
        }
        $header = array_map(fn ($h) => Str::lower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header);
        if (!in_array('username', $header, true)) {
            throw new RuntimeException(__('The first row must have the column names, including username.'));
        }

        $rows = [];
        while (($cells = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($cells === [null] || count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                throw new RuntimeException(__('The file has more than :count users. Split it up.', ['count' => number_format(self::MAX_ROWS)]));
            }
            $rows[] = array_map(fn ($c) => trim((string) $c), array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), '')));
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Create the users. Each row is checked like the user form (and a reseller's limits and credits apply); a bad row is reported and skipped,
     * the rest are created. With $checkOnly nothing is created and credits are only counted.
     *
     * @param  list<array<string, string>>  $rows
     * @return array{created: list<array{username: string, password: string}>, errors: list<array{line: int, username: string, message: string}>, credits: int}
     */
    public function import(User $actor, array $rows, bool $checkOnly = false): array
    {
        $bouquets = ($actor->isAdmin() ? Bouquet::query() : $actor->bouquets())->pluck('bouquets.id', 'bouquets.name')->mapWithKeys(fn ($id, $name) => [Str::lower($name) => $id])->all();
        $seen = [];
        $created = $errors = [];
        $credits = 0;
        $pending = 0;

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $username = $row['username'] ?? '';
            $fail = function (string $message) use (&$errors, $line, $username) {
                $errors[] = ['line' => $line, 'username' => $username, 'message' => $message];
            };

            // Before the database check, so a repeat in the file is named as such even after the first one was created
            if ($username !== '' && isset($seen[Str::lower($username)])) {
                $fail(__('This username is in the file twice.'));

                continue;
            }

            $validator = Validator::make($row, [
                'username' => ['required', 'string', 'max:255', 'unique:users,username'],
                'password' => ['nullable', 'string', 'min:6', 'max:255'],
                'expiration_date' => [$actor->usesCredits() ? 'required' : 'nullable', 'date'],
                'max_connections' => ['nullable', 'integer', 'min:1', 'max:'.($actor->isReseller() && $actor->max_customer_connections ? $actor->max_customer_connections : 1000)],
                'email' => ['nullable', 'email', 'max:255'],
                'status' => ['nullable', 'in:active,disabled'],
            ]);
            if ($validator->fails()) {
                $fail($validator->errors()->first());

                continue;
            }
            $seen[Str::lower($username)] = true;

            $bouquetIds = [];
            foreach (preg_split('/[|;]/', $row['bouquets'] ?? '', -1, PREG_SPLIT_NO_EMPTY) as $name) {
                $id = $bouquets[Str::lower(trim($name))] ?? null;
                if ($id === null) {
                    $fail(__('Unknown bouquet: :name', ['name' => trim($name)]));

                    continue 2;
                }
                $bouquetIds[] = $id;
            }

            $password = ($row['password'] ?? '') !== '' ? $row['password'] : Str::lower(Str::random(8));
            $expires = ($row['expiration_date'] ?? '') !== '' ? Carbon::parse($row['expiration_date']) : null;
            $data = [
                'username' => $username, 'password' => $password, 'status' => ($row['status'] ?? '') ?: 'active', 'email' => ($row['email'] ?? '') ?: null,
                'max_connections' => (int) (($row['max_connections'] ?? '') ?: 1), 'expiration_date' => $expires, 'role' => 'user',
                'owner_id' => $actor->isReseller() ? $actor->id : null, 'bouquets' => array_values(array_unique($bouquetIds)),
            ];

            try {
                if ($checkOnly) {
                    $cost = $actor->isReseller() && $actor->usesCredits() ? Credits::months(null, $expires) : 0;
                    if ($actor->isReseller() && $actor->max_customers !== null && $actor->customers()->count() + $pending >= $actor->max_customers) {
                        throw new ResellerLimitReached;
                    }
                    if ($actor->isReseller() && $actor->usesCredits() && $credits + $cost > $actor->credits) {
                        throw new InsufficientCredits($credits + $cost, $actor->credits);
                    }
                    $credits += $cost;
                    $pending++;
                    $created[] = ['username' => $username, 'password' => $password];

                    continue;
                }

                $spent = DB::transaction(function () use ($actor, $data, $expires) {
                    $spent = 0;
                    if ($actor->isReseller()) {
                        $reseller = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                        if (!$reseller->canAddCustomer()) {
                            throw new ResellerLimitReached;
                        }
                        $spent = $reseller->usesCredits() ? Credits::months(null, $expires) : 0;
                    }
                    $user = $this->users->createUser($data);
                    app(SaleService::class)->record($user, null, $user->expiration_date, Sale::NEW, $actor);
                    if ($spent > 0) {
                        $this->credits->charge($actor, $spent, CreditTransaction::NEW_SUBSCRIPTION, $user);
                    }

                    return $spent;
                });
                $credits += $spent;
                $created[] = ['username' => $username, 'password' => $password];
            } catch (ResellerLimitReached) {
                $fail(__('You have reached your limit of :count users.', ['count' => $actor->max_customers]));
            } catch (InsufficientCredits $e) {
                $fail(__('Not enough credits: this needs :needed, you have :balance.', ['needed' => $e->needed, 'balance' => $e->balance]));
            }
        }

        return ['created' => $created, 'errors' => $errors, 'credits' => $credits];
    }
}
