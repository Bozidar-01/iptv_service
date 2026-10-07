<?php

use App\Models\Source;
use App\Models\User;
use Illuminate\Support\Facades\File;

/**
 * Every literal key passed to __() / trans_choice() in app/ and resources/views/.
 */
function translationKeysInCode(): array
{
    $files = collect(File::allFiles(resource_path('views')))->merge(File::allFiles(app_path()));
    $pattern = '/(?:__|trans_choice)\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/';

    $keys = [];
    foreach ($files as $file) {
        preg_match_all($pattern, $file->getContents(), $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $keys[] = stripslashes(($match[2] ?? '') !== '' ? $match[2] : $match[1]);
        }
    }

    return array_values(array_unique($keys));
}

it('shows the panel in English by default', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Keep me signed in')
        ->assertSee('<html lang="en"', false);
});

it('switches the language and remembers it in a cookie', function () {
    $this->from('/login')
        ->post(route('locale.update'), ['locale' => 'mk'])
        ->assertRedirect('/login')
        ->assertCookie('locale', 'mk');

    $this->withCookie('locale', 'mk')
        ->get('/login')
        ->assertSee('Остани најавен')
        ->assertSee('<html lang="mk"', false);
});

it('shows every language with its flag and marks the current one', function () {
    $this->withCookie('locale', 'mk')
        ->get('/login')
        ->assertSee('images/flags/gb.svg')
        ->assertSee('images/flags/mk.svg')
        ->assertSeeInOrder(['value="en"', 'value="mk"', 'aria-current="true"'], false);
});

it('rejects unsupported languages', function () {
    $this->post(route('locale.update'), ['locale' => 'de'])
        ->assertSessionHasErrors('locale')
        ->assertCookieMissing('locale');
});

it('ignores an unsupported locale cookie', function () {
    $this->withCookie('locale', 'xx')
        ->get('/login')
        ->assertSee('Keep me signed in');
});

it('translates admin pages, flash messages and relative dates', function () {
    $admin = User::factory()->admin()->create();
    Source::create(['name' => 'Provider', 'type' => 'url', 'url' => 'http://provider.test/list.m3u', 'last_synced_at' => now()->subHours(3)]);

    $this->actingAs($admin)->withCookie('locale', 'mk')
        ->get(route('admin.sources.index'))
        ->assertSee('Додај извор')
        ->assertSee('пред 3 часа')
        ->assertDontSee('Add source');

    $this->actingAs($admin)->withCookie('locale', 'mk')
        ->delete(route('admin.users.destroy', $admin))
        ->assertSessionHas('error', 'Не можете да ја избришете сопствената сметка.');
});

it('translates validation messages and field names', function () {
    $this->actingAs(User::factory()->admin()->create())->withCookie('locale', 'mk')
        ->post(route('admin.users.store'), ['password' => 'secret1', 'role' => 'user', 'status' => 'active', 'max_connections' => 1])
        ->assertSessionHasErrors(['username' => 'Полето корисничко име е задолжително.']);
});

it('uses Macedonian plural forms', function () {
    app()->setLocale('mk');

    expect(trans_choice(':count channel|:count channels', 1))->toBe('1 канал')
        ->and(trans_choice(':count channel|:count channels', 5))->toBe('5 канали');
});

it('has a Macedonian translation for every string in the code', function () {
    $mk = json_decode(File::get(lang_path('mk.json')), true, flags: JSON_THROW_ON_ERROR);
    $missing = array_diff(translationKeysInCode(), array_keys($mk));

    expect($missing)->toBe([]);
});

it('keeps the same placeholders in every Macedonian translation', function () {
    $mk = json_decode(File::get(lang_path('mk.json')), true, flags: JSON_THROW_ON_ERROR);
    $placeholders = function (string $text) {
        preg_match_all('/:[a-z]+/', $text, $m);
        $found = array_unique($m[0]);
        sort($found);

        return $found;
    };

    foreach ($mk as $english => $macedonian) {
        expect($placeholders($macedonian))->toBe($placeholders($english), "Placeholders differ for: {$english}");
    }
});
