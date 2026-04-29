<?php

namespace App\Http\Requests\Auth;

use App\Models\LoginSecuritySetting;
use App\Models\OperationLog;
use App\Models\User;
use App\Support\SystemSettingsResolver;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    private const DEFAULT_MAX_ATTEMPTS_PER_IP = 25;

    /**
     * @var array{max_attempts:int,decay_seconds:int}|null
     */
    private ?array $resolvedLoginSecuritySettings = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => Str::lower(trim((string) $this->input('email'))),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:filter'],
            'password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => "L'email è obbligatoria.",
            'email.email' => "L'email deve essere valida.",
            'password.required' => 'La password è obbligatoria.',
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $credentials = [
            'email' => (string) $this->input('email'),
            'password' => (string) $this->input('password'),
            'active' => true,
        ];

        if (! Auth::attempt($credentials, $this->boolean('remember'))) {
            $this->hitRateLimiters();

            $candidate = User::query()
                ->where('email', (string) $this->input('email'))
                ->first();

            if ($candidate && ! (bool) $candidate->active && Auth::validate([
                'email' => (string) $this->input('email'),
                'password' => (string) $this->input('password'),
            ])) {
                $this->logFailedLogin('auth.login.failed_inactive_user', $candidate, ['reason' => 'inactive_user']);

                throw ValidationException::withMessages([
                    'email' => 'Account disattivato. Contatta un amministratore.',
                ]);
            }

            $this->logFailedLogin('auth.login.failed_invalid_credentials', $candidate, ['reason' => 'invalid_credentials']);

            throw ValidationException::withMessages([
                'email' => 'Le credenziali non corrispondono ai nostri record.',
            ]);
        }

        $this->clearRateLimiters();
        $this->logSuccessfulLogin(Auth::user());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $emailTooManyAttempts = RateLimiter::tooManyAttempts(
            $this->throttleKey(),
            $this->maxAttempts()
        );

        $ipTooManyAttempts = RateLimiter::tooManyAttempts(
            $this->ipThrottleKey(),
            $this->maxAttemptsPerIp()
        );

        if (! $emailTooManyAttempts && ! $ipTooManyAttempts) {
            return;
        }

        event(new Lockout($this));

        $seconds = max(
            RateLimiter::availableIn($this->throttleKey()),
            RateLimiter::availableIn($this->ipThrottleKey())
        );
        $candidate = User::query()
            ->where('email', (string) $this->input('email'))
            ->first();

        $this->logFailedLogin('auth.login.blocked_rate_limited', $candidate, [
            'throttle_seconds' => $seconds,
            'blocked_by' => array_values(array_filter([
                $emailTooManyAttempts ? 'email+ip' : null,
                $ipTooManyAttempts ? 'ip' : null,
            ])),
            'max_attempts' => $this->maxAttempts(),
            'max_attempts_per_ip' => $this->maxAttemptsPerIp(),
        ]);

        throw ValidationException::withMessages([
            'email' => "Troppi tentativi di accesso. Riprova tra {$seconds} secondi.",
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        $ipAddress = (string) ($this->ip() ?? 'unknown');

        return Str::transliterate((string) $this->string('email').'|'.$ipAddress);
    }

    public function ipThrottleKey(): string
    {
        return 'login-ip|'.(string) ($this->ip() ?? 'unknown');
    }

    private function hitRateLimiters(): void
    {
        $decaySeconds = $this->decaySeconds();

        RateLimiter::hit($this->throttleKey(), $decaySeconds);
        RateLimiter::hit($this->ipThrottleKey(), $decaySeconds);
    }

    private function clearRateLimiters(): void
    {
        RateLimiter::clear($this->throttleKey());
    }

    private function maxAttempts(): int
    {
        return $this->resolvedLoginSecuritySettings()['max_attempts'];
    }

    private function maxAttemptsPerIp(): int
    {
        return max(1, (int) config('auth.login.max_attempts_per_ip', self::DEFAULT_MAX_ATTEMPTS_PER_IP));
    }

    private function decaySeconds(): int
    {
        return $this->resolvedLoginSecuritySettings()['decay_seconds'];
    }

    /**
     * @return array{max_attempts:int,decay_seconds:int}
     */
    private function resolvedLoginSecuritySettings(): array
    {
        if ($this->resolvedLoginSecuritySettings !== null) {
            return $this->resolvedLoginSecuritySettings;
        }

        $setting = SystemSettingsResolver::loginSecuritySetting();
        $this->resolvedLoginSecuritySettings = [
            'max_attempts' => LoginSecuritySetting::sanitizeMaxAttempts((int) $setting->max_attempts),
            'decay_seconds' => LoginSecuritySetting::sanitizeDecaySeconds((int) $setting->decay_seconds),
        ];

        return $this->resolvedLoginSecuritySettings;
    }

    private function logSuccessfulLogin(?Authenticatable $user): void
    {
        if (! $user instanceof User) {
            return;
        }

        $action = $user->hasRole('admin')
            ? 'auth.login.admin.succeeded'
            : 'auth.login.succeeded';

        OperationLog::record(
            $user,
            $action,
            'auth',
            (int) $user->id,
            [
                'email' => $user->email,
                'target_role' => $user->role,
            ],
            'INFO',
            $this
        );
    }

    private function logFailedLogin(string $action, ?User $candidate = null, array $payload = []): void
    {
        if (! $this->shouldLogFailedLogin($candidate)) {
            return;
        }

        $resolvedAction = $this->resolveFailedLoginAction($action, $candidate);

        OperationLog::record(
            $candidate,
            $resolvedAction,
            'auth',
            $candidate?->id,
            array_merge(
                [
                    'email' => (string) $this->input('email'),
                    'target_role' => $candidate?->role,
                ],
                $payload
            ),
            'INFO',
            $this
        );
    }

    private function shouldLogFailedLogin(?User $candidate): bool
    {
        return $candidate?->hasRole('admin') ?? false;
    }

    private function resolveFailedLoginAction(string $action, ?User $candidate): string
    {
        if (! ($candidate?->hasRole('admin') ?? false)) {
            return $action;
        }

        return str_replace('auth.login.', 'auth.login.admin.', $action);
    }
}
