<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        // Lance une exception si un développeur oublie un Eager Loading
        Model::preventLazyLoading(! app()->isProduction());

        Password::defaults(function () {
            $rule = Password::min(8)
                ->letters()
                ->numbers();

            // En production, vérifie si le mot de passe a fuité plus de 3 fois dans le monde
            return app()->isProduction()
                ? $rule->uncompromised(3)
                : $rule;
        });

        // 1. Limiteur général API : 60 requêtes par minute
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?:
                $request->ip());
        });

        // 2. Limiteur Inscription : Max 5 inscriptions toutes les 10 minutes par IP (Anti-Spam bots)
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinutes(10, 5)->by($request->ip());
        });
        
        // 3. Limiteur Uploads : Max 10 uploads par minute par utilisateur (Protection CPU/Disque)
        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?:
                $request->ip());
        });
    }
}
