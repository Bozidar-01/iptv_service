<?php

use App\Models\AuditLog;
use App\Models\Bouquet;
use App\Models\CreditTransaction;
use App\Models\User;
use App\Services\UserCsvService;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['username' => 'boss']);
    $this->sports = Bouquet::create(['name' => 'Sports']);
    $this->movies = Bouquet::create(['name' => 'Movies']);
    $this->reseller = User::factory()->create(['username' => 'resA', 'role' => 'reseller', 'credits' => 5, 'max_customers' => null, 'max_customer_connections' => null]);
    $this->reseller->bouquets()->attach($this->sports);
    $this->other = User::factory()->create(['username' => 'resB', 'role' => 'reseller']);
});

function csvFile(string $content, string $name = 'users.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

function importCsv(User $actor, string $content, bool $check = false)
{
    return test()->actingAs($actor)->post(route('admin.users.import.store'), ['file' => csvFile($content)] + ($check ? ['check_only' => 1] : []));
}

describe('export', function () {
    it('lists the users with their bouquets, for an admin with the reseller', function () {
        $user = User::factory()->create(['username' => 'maria', 'owner_id' => $this->reseller->id, 'expiration_date' => '2027-01-31', 'email' => 'm@example.com', 'max_connections' => 2]);
        $user->bouquets()->attach([$this->sports->id, $this->movies->id]);

        $csv = $this->actingAs($this->admin)->get(route('admin.users.export'))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();
        $rows = array_map('str_getcsv', explode("\n", trim(ltrim($csv, "\xEF\xBB\xBF"))));

        expect($rows[0])->toBe(['username', 'role', 'status', 'email', 'expiration_date', 'max_connections', 'bouquets', 'trial', 'created_at', 'reseller']);
        $maria = collect($rows)->firstWhere(0, 'maria');
        expect($maria[3])->toBe('m@example.com')->and($maria[4])->toBe('2027-01-31')->and($maria[5])->toBe('2')->and($maria[6])->toBe('Movies|Sports')->and($maria[9])->toBe('resA')
            ->and($csv)->not->toContain('password');
    });

    it('follows the list filters and keeps a reseller to their own users', function () {
        User::factory()->create(['username' => 'mine1', 'owner_id' => $this->reseller->id]);
        User::factory()->create(['username' => 'mine2', 'owner_id' => $this->reseller->id, 'status' => 'disabled']);
        User::factory()->create(['username' => 'theirs', 'owner_id' => $this->other->id]);

        $all = $this->actingAs($this->reseller)->get(route('admin.users.export'))->streamedContent();
        expect($all)->toContain('mine1')->toContain('mine2')->not->toContain('theirs')->not->toContain('reseller');

        $disabled = $this->actingAs($this->reseller)->get(route('admin.users.export', ['status' => 'disabled']))->streamedContent();
        expect($disabled)->toContain('mine2')->not->toContain('mine1');
    });

    it('adds the passwords only when asked, and records that', function () {
        User::factory()->create(['username' => 'maria', 'owner_id' => $this->reseller->id, 'password_plain' => 'secret99']);

        expect($this->actingAs($this->reseller)->get(route('admin.users.export'))->streamedContent())->not->toContain('secret99');

        $with = $this->actingAs($this->reseller)->get(route('admin.users.export', ['passwords' => 1]))->streamedContent();
        expect($with)->toContain('secret99')->and(AuditLog::latest('id')->first()->action)->toBe(AuditLog::USERS_EXPORTED);
    });

    it('stops spreadsheets from running formulas in a cell', function () {
        User::factory()->create(['username' => '=cmd|calc', 'owner_id' => $this->reseller->id]);

        expect($this->actingAs($this->reseller)->get(route('admin.users.export'))->streamedContent())->toContain("'=cmd|calc")
            ->and(UserCsvService::safe('+1'))->toBe("'+1")->and(UserCsvService::safe('plain'))->toBe('plain');
    });
});

describe('import', function () {
    it('creates the users with the bouquets and made-up passwords', function () {
        $csv = "username,password,expiration_date,max_connections,bouquets,email,status\n"
            ."anna,secret1,2027-02-01,2,Sports|Movies,anna@example.com,active\n"
            ."ben,,2027-03-01,,Sports,,disabled\n";

        $result = importCsv($this->admin, $csv)->assertSessionHas('import')->getSession()->get('import');

        expect($result['created'])->toHaveCount(2)->and($result['errors'])->toBe([]);
        $anna = User::where('username', 'anna')->first();
        $ben = User::where('username', 'ben')->first();
        expect($anna->max_connections)->toBe(2)->and($anna->password_plain)->toBe('secret1')->and($anna->bouquets->pluck('name')->sort()->values()->all())->toBe(['Movies', 'Sports'])
            ->and($anna->owner_id)->toBeNull()->and($anna->role)->toBe('user')
            ->and($ben->status)->toBe('disabled')->and($ben->max_connections)->toBe(1)->and($ben->password_plain)->toMatch('/^[a-z0-9]{8}$/')->and($result['created'][1]['password'])->toBe($ben->password_plain)
            ->and(AuditLog::latest('id')->first()->action)->toBe(AuditLog::USERS_IMPORTED);
    });

    it('reports bad rows and still creates the good ones', function () {
        User::factory()->create(['username' => 'taken']);
        $csv = "username;password;bouquets;expiration_date\n"
            ."good;secret1;Sports;2027-01-01\n"
            .";secret1;;\n"
            ."taken;secret1;;\n"
            ."short;abc;;\n"
            ."nobouquet;secret1;Nope;\n"
            ."baddate;secret1;;not-a-date\n"
            ."good;secret1;;\n";

        $result = importCsv($this->admin, "\xEF\xBB\xBF".$csv)->getSession()->get('import');

        expect(array_column($result['created'], 'username'))->toBe(['good'])
            ->and(collect($result['errors'])->pluck('line')->all())->toBe([3, 4, 5, 6, 7, 8])
            ->and(collect($result['errors'])->pluck('message')->last())->toBe('This username is in the file twice.')
            ->and(collect($result['errors'])->firstWhere('line', 5)['message'])->toContain('at least 6')
            ->and(collect($result['errors'])->firstWhere('line', 6)['message'])->toBe('Unknown bouquet: Nope')
            ->and(User::where('username', 'short')->exists())->toBeFalse();
    });

    it('only checks when asked, and creates nothing', function () {
        $result = importCsv($this->admin, "username,password\nanna,secret1\n", check: true)->getSession()->get('import');

        expect($result['checked'])->toBeTrue()->and($result['created'])->toHaveCount(1)->and(User::where('username', 'anna')->exists())->toBeFalse()->and(AuditLog::where('action', AuditLog::USERS_IMPORTED)->count())->toBe(0);
    });

    it('makes a reseller\'s users theirs, with only their bouquets, and charges credits', function () {
        $csv = "username,expiration_date,bouquets\n"
            .'a1,'.now()->addMonth()->toDateString().",Sports\n"
            .'a2,'.now()->addMonths(2)->toDateString().",Movies\n"
            ."a3,,Sports\n";

        $result = importCsv($this->reseller, $csv)->getSession()->get('import');

        expect(array_column($result['created'], 'username'))->toBe(['a1'])
            ->and(collect($result['errors'])->pluck('message')->all())->toContain('Unknown bouquet: Movies')
            ->and(User::where('username', 'a1')->first()->owner_id)->toBe($this->reseller->id)
            ->and($this->reseller->fresh()->credits)->toBe(4)
            ->and(CreditTransaction::where('reason', CreditTransaction::NEW_SUBSCRIPTION)->count())->toBe(1);
        // a reseller with credits has to give an end date (a3 has none)
        expect(collect($result['errors'])->firstWhere('username', 'a3')['message'])->toContain('required');
    });

    it('stops creating when the credits run out or the limit is reached', function () {
        $this->reseller->forceFill(['credits' => 2, 'max_customers' => 5])->save();
        $date = now()->addMonth()->toDateString();

        $result = importCsv($this->reseller, "username,expiration_date\nb1,$date\nb2,$date\nb3,$date\n")->getSession()->get('import');

        expect(array_column($result['created'], 'username'))->toBe(['b1', 'b2'])->and($result['errors'][0]['message'])->toBe('Not enough credits: this needs 1, you have 0.')
            ->and($this->reseller->fresh()->credits)->toBe(0);

        $this->reseller->forceFill(['credits' => null, 'max_customers' => 3])->save();
        $result = importCsv($this->reseller, "username\nc1\nc2\n")->getSession()->get('import');
        expect(array_column($result['created'], 'username'))->toBe(['c1'])->and($result['errors'][0]['message'])->toBe('You have reached your limit of 3 users.');
    });

    it('counts credits and the limit in a check without spending', function () {
        $this->reseller->forceFill(['credits' => 1])->save();
        $date = now()->addMonth()->toDateString();

        $result = importCsv($this->reseller, "username,expiration_date\nd1,$date\nd2,$date\n", check: true)->getSession()->get('import');

        expect($result['created'])->toHaveCount(1)->and($result['errors'])->toHaveCount(1)->and($result['credits'])->toBe(1)
            ->and($this->reseller->fresh()->credits)->toBe(1)->and(User::whereIn('username', ['d1', 'd2'])->count())->toBe(0);
    });

    it('caps a reseller\'s streams per user', function () {
        $this->reseller->forceFill(['credits' => null, 'max_customer_connections' => 2])->save();

        $result = importCsv($this->reseller, "username,max_connections\ne1,2\ne2,3\n")->getSession()->get('import');

        expect(array_column($result['created'], 'username'))->toBe(['e1'])->and($result['errors'][0]['line'])->toBe(3);
    });

    it('refuses an unusable file', function () {
        $this->actingAs($this->admin)->post(route('admin.users.import.store'), [])->assertSessionHasErrors('file');
        importCsv($this->admin, '')->assertSessionHas('error', 'The file is empty.');
        importCsv($this->admin, "name,pass\nx,y\n")->assertSessionHas('error', 'The first row must have the column names, including username.');
        importCsv($this->admin, "username\n".implode("\n", array_map(fn ($i) => "u$i", range(1, 2001))))->assertSessionHas('error');
        expect(User::where('username', 'u1')->exists())->toBeFalse();
    });

    it('is for staff only, with a template to start from', function () {
        $this->actingAs($this->reseller)->get(route('admin.users.import'))->assertOk()->assertSee('Import users');
        $template = $this->actingAs($this->admin)->get(route('admin.users.import.template'))->streamedContent();
        expect($template)->toContain('username,password,expiration_date,max_connections,bouquets,email,status');

        auth()->logout();
        $this->get(route('admin.users.import'))->assertRedirect(route('login'));
        $this->get(route('admin.users.export'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('admin.users.import'))->assertRedirect(route('login'));
    });

    it('is linked from the users page', function () {
        $this->actingAs($this->admin)->get(route('admin.users.index'))->assertSee(route('admin.users.import'), false)->assertSee(route('admin.users.export'), false);
    });
});
