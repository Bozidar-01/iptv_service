# Languages (English / Macedonian)

The admin panel and sign-in page are available in **English** (`en`, default) and **Macedonian** (`mk`).

**Files:** `config/app.php` (`locales`), `app/Http/Middleware/SetLocale.php`, `app/Http/Controllers/LocaleController.php`, `resources/views/partials/preferences.blade.php`, `public/images/flags/*.svg`, `lang/mk.json`, `lang/mk/{validation,auth,pagination,passwords}.php`, `tests/Feature/LocaleTest.php`

## How the language is chosen

1. The language menu (bottom of the sidebar and under the sign-in card) shows the current language's flag and name. It opens a list of every language in `config('app.locales')` with its flag (`public/images/flags/<flag>.svg`) and a tick next to the current one. Picking one posts to `POST /locale` (`locale.update`). The menu is a `<details>` element, so it works without JavaScript; a small script closes it on an outside click or Escape. The flags are SVG files instead of emoji because Windows shows emoji flags as letters.
2. `LocaleController@update` checks the value against the keys of `config('app.locales')`, stores it in a `locale` cookie that never expires (encrypted like other cookies), and redirects back. It survives sign-out, so the sign-in page keeps the language too. An unknown value fails validation and no cookie is set.
3. `SetLocale` (appended to the `web` middleware group in `bootstrap/app.php`) reads the cookie on every web request and calls `App::setLocale()`. Missing or unsupported values fall back to `APP_LOCALE` (`en` by default).
4. Carbon follows the app locale automatically, so `diffForHumans()` gives "пред 3 часа", and `translatedFormat('M j, Y')` gives Macedonian month names.

The choice is per browser, not per admin account.

## Where translations live

| What | File | Key style |
|---|---|---|
| UI text, flash messages, custom validation messages | `lang/mk.json` | The English text is the key: `__('Add source')` → `"Add source": "Додај извор"` |
| Laravel's built-in validation messages | `lang/mk/validation.php` | Same keys as Laravel's English file. `attributes` maps form field names (`username` → `корисничко име`) |
| Login throttle / password broker / paginator defaults | `lang/mk/{auth,passwords,pagination}.php` | Laravel's keys |

English needs no files: a missing key prints the key itself, which is already English (Laravel's own English `validation.php` etc. ship with the framework).

### Rules for UI text

- Wrap every user-visible string in `__()`: text, `title`, `placeholder`, `aria-label`, `data-confirm`, `@section('title', __('…'))`, `$submit` labels, `->with('success', __('…'))`.
- Use placeholders, never concatenation: `__('Delete :name?', ['name' => $channel->name])`. The Macedonian text must keep exactly the same placeholders (a test checks this).
- Counts: `trans_choice(':count channel|:count channels', $n, ['count' => number_format($n)])`. Macedonian uses two forms (Laravel's rule: one form when the number ends in 1, otherwise the other): `":count канал|:count канали"`. Exact ranges (`{0} …|{1} …|[2,*] …`) work as well.
- Links or `<code>` inside a sentence: put a placeholder in the sentence and pass escaped HTML, then print with `{!! !!}`:
  `{!! __('No bouquets yet. :link or add a source.', ['link' => '<a href="'.e($url).'">'.e(__('Create one')).'</a>']) !!}`. Escape every dynamic value with `e()`.
- JavaScript text: pass translations from Blade with `@json(__('Copied'))` or `{{ Js::from([...]) }}` and replace `:count` in JS (see `admin/channels/index.blade.php`).
- Dates: `translatedFormat()` instead of `format()` for anything with month or day names.

## Adding or changing a string

1. Write the English text in code with `__()` / `trans_choice()`.
2. Add the same key to `lang/mk.json` with the Macedonian text (the file is sorted alphabetically, case-insensitive).
3. For a new form field, add its name to `attributes` in `lang/mk/validation.php`.
4. Run the tests. `LocaleTest` fails if any literal `__()` / `trans_choice()` key in `app/` or `resources/views/` is missing from `mk.json`, or if placeholders differ.

Changing an English string changes its key, so update the `mk.json` entry too.

## Adding a language

1. Add `'<code>' => ['name' => '<native name>', 'flag' => '<country>']` to `locales` in `config/app.php`, and put the flag at `public/images/flags/<country>.svg` (3:2 or 2:1, shown at 20×14 px).
2. Copy `lang/mk.json` and `lang/mk/` to `lang/<code>.json` and `lang/<code>/` and translate them.
3. Extend `LocaleTest` to cover the new file.

## Not translated

- Plain-text error responses of `/m3u`, `/playlist` and `/play` (read by TV apps, not people in the panel).
- `sources.last_error`: the import error is stored in English when the sync fails.
- Artisan command output and logs.
- Numbers use `number_format()` (comma thousands separator) in both languages.
