<?php

declare(strict_types=1);

namespace Modules\Identity;

use App\Support\Identity\UserLookup;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
use Modules\Identity\Infrastructure\EloquentUserLookup;
use Modules\Identity\Infrastructure\Models\User;

final class IdentityServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->bind(UserLookup::class, EloquentUserLookup::class);
    }

    protected function bootModule(): void
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        // Reset links open the web app, which posts the token back to the API.
        ResetPassword::createUrlUsing(fn (CanResetPassword $user, string $token): string => $frontend.'/reset-password?'.http_build_query([
            'token' => $token,
            'email' => $user->getEmailForPasswordReset(),
        ]));

        // Verification links point at the signed API route, which redirects to the web app.
        VerifyEmail::createUrlUsing(fn (User $notifiable): string => URL::temporarySignedRoute(
            'identity.verification.verify',
            now()->addMinutes((int) Config::get('auth.verification.expire', 60)),
            ['id' => $notifiable->getKey(), 'hash' => sha1($notifiable->getEmailForVerification())],
        ));
    }
}
