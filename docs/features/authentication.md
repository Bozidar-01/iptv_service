# Authentication (Admin)

**Files:** `app/Http/Controllers/Auth/AuthController.php`, `resources/views/auth/login.blade.php`

- Session-based login with the default `web` guard, using `username` + `password` (optional "remember").
- After `Auth::attempt` succeeds, users whose `role` is not `admin` are logged out immediately and shown "Unauthorized access." Subscribers can't use the panel; they only use `/m3u` and `/play`.
- **Login limit:** after `AuthController::MAX_ATTEMPTS` (5) wrong passwords for one username from one IP, sign-in is refused for a minute ("Too many sign-in attempts. Try again in N seconds."). A successful sign-in clears the count.
- On success the session is regenerated and the user goes to `intended()` or `admin.dashboard`.
- `GET /login` sends an already-logged-in admin straight to the dashboard.
- Logout invalidates the session and regenerates the CSRF token.
- The sign-in page has the language menu and the theme switch under the card. The `locale` and `theme` cookies outlive the session, so the chosen language and theme stay after sign-out ([localization.md](localization.md)). Login error messages are translated.

The admin route group only applies `auth`, not an admin-role check. That's fine today because non-admins can't keep a session, but add a role middleware if that changes.

Default admin (from `AdminSeeder`): `admin` / `admin123`. Change it in any shared environment.
