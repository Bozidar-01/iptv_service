# Light / dark mode

Admins pick **Light**, **Dark** or **System** (follow the OS setting, the default) with the three icon buttons (sun / moon / monitor) next to the language menu, at the bottom of the sidebar and under the sign-in card.

**Files:** `app/Support/Theme.php`, `app/Http/Controllers/ThemeController.php`, `resources/views/partials/preferences.blade.php`, `public/css/admin.css` (theme tokens at the top), `bootstrap/app.php`, `tests/Feature/ThemeTest.php`

## How it works

- The choice is stored in a `theme` cookie (`light` or `dark`, 5 years). "System" deletes the cookie. It is per browser and survives sign-out.
- `Theme::current()` reads the cookie (anything other than `light`/`dark`/`system` counts as `system`). Both page shells (`layouts/admin`, `auth/login`) print it as `<html data-theme="…">`, so the page is rendered in the right theme from the start, with no flash of the wrong colours.
- With JavaScript, the buttons switch at once: the script in `partials/preferences` sets `data-theme`, writes the cookie and updates `aria-pressed`, without a request. Without JavaScript the buttons submit the form to `POST /theme` (`theme.update`), and `ThemeController` sets or clears the same cookie and redirects back.
- Because the script writes it, the `theme` cookie is excluded from cookie encryption (`encryptCookies(except: [Theme::COOKIE])` in `bootstrap/app.php`). It holds no sensitive data.

## CSS

Light values are on `:root`. The dark values are defined twice in `admin.css` and **must stay identical**:

```css
@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) { /* dark tokens */ } }
:root[data-theme="dark"] { /* same dark tokens */ }
```

So `system` follows the OS, `light` blocks the OS dark mode, and `dark` forces it. Components only use the CSS variables, so a new token needs a light value and the same dark value in both blocks. The sidebar is dark in both themes.

## Checking UI changes

Look at new or changed pages in both themes. Click the sun and moon buttons; there's no need to change the OS setting.
