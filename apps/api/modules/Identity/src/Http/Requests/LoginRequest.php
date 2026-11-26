<?php

declare(strict_types=1);

namespace Modules\Identity\Http\Requests;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Infrastructure\Models\User;

final class LoginRequest extends FormRequest
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 60;

    /** Across all IPs: stops slow, distributed guessing of one account's password. */
    public const MAX_ATTEMPTS_PER_ACCOUNT = 20;

    public const ACCOUNT_DECAY_SECONDS = 3600;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ];
    }

    /** Checks the credentials, with a per-email-and-IP lockout after repeated failures. */
    public function authenticate(): User
    {
        $key = $this->throttleKey();
        $accountKey = 'login-account:'.sha1(Str::lower($this->string('email')->toString()));

        foreach ([[$key, self::MAX_ATTEMPTS], [$accountKey, self::MAX_ATTEMPTS_PER_ACCOUNT]] as [$k, $max]) {
            if (RateLimiter::tooManyAttempts($k, $max)) {
                event(new Lockout($this));

                throw ValidationException::withMessages([
                    'email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($k), 'minutes' => 1]),
                ])->status(429);
            }
        }

        $user = User::query()->where('email', mb_strtolower($this->string('email')->toString()))->first();

        // Always run one hash check, so response time doesn't reveal whether the account exists.
        $valid = Hash::check($this->string('password')->toString(), $user->password ?? self::dummyHash());

        if ($user === null || ! $valid) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            RateLimiter::hit($accountKey, self::ACCOUNT_DECAY_SECONDS);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($key);

        return $user;
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('timing-equaliser');
    }

    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')->toString()).'|'.$this->ip());
    }
}
