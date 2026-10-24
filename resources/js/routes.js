// Static URL constants for links and form targets in the UI. Keep them in sync with routes/web.php:
// tests/Feature/FrontendRoutesTest.php fails when a constant here does not resolve to a registered
// route. A path that is not listed here is not linked from the frontend. Capture and SDK paths
// (App\Http\ReservedPaths) never belong here. URLs with parameters come from controller props instead.
export const HOME_URL = '/';
export const LOGIN_URL = '/login';
export const REGISTER_URL = '/register';
export const LOGOUT_URL = '/logout';
// Served by the Horizon package, not by routes/web.php; linked with a plain <a> (it is not an Inertia page).
export const HORIZON_URL = '/horizon';
