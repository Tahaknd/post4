<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * The gate alone decides, in every environment. The parent also lets everybody in when APP_ENV is
     * `local`, which on a LAN-reachable dev stack would publish the dashboard (job payloads, failed
     * jobs) to anyone; here a local environment still needs a signed-in administrator.
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(fn ($request) => Gate::check('viewHorizon', [$request->user()]));
    }

    /**
     * Only a signed-in administrator may open Horizon: the decision is the stored `is_admin` flag, so there is
     * no second source of truth (an email list in config) to keep in step. `=== true` is strict on purpose: a
     * guest has no user (null), and a loose check on a missing value must never read as "allowed".
     *
     * The nullable parameter is what lets Laravel call the closure for a guest at all (it denies guests
     * without asking when the user is not optional); the answer for them is false.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user = null): bool => $user?->is_admin === true);
    }
}
