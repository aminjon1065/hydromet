<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * One password policy for the whole portal, so an administrator
         * creating an account is held to the same rule as any later change.
         *
         * Length does most of the work here; the character-class requirements
         * are the conventional minimum. Laravel's `uncompromised()` check is
         * deliberately not enabled: it calls an external service on every
         * validation, and a portal that cannot create an account because a
         * third party is unreachable is worse than one that accepts a merely
         * long password.
         *
         * Hydromet has not stated a password policy
         * (docs/08-hydromet-input-checklist.md, section 6), so this is a
         * defensible default rather than an approved rule.
         */
        Password::defaults(static fn (): Password => Password::min(12)->letters()->numbers());
    }
}
