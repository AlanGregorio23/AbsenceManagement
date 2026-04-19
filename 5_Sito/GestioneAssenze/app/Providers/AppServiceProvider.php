<?php

namespace App\Providers;

use App\Models\Absence;
use App\Models\Delay;
use App\Models\Leave;
use App\Models\LoginSecuritySetting;
use App\Models\MedicalCertificate;
use App\Models\MonthlyReport;
use App\Models\OperationLog;
use App\Models\User;
use App\Observers\AbsenceObserver;
use App\Observers\DelayObserver;
use App\Observers\LeaveObserver;
use App\Observers\MedicalCertificateObserver;
use App\Observers\MonthlyReportObserver;
use App\Observers\OperationLogObserver;
use App\Observers\UserObserver;
use App\Support\SystemSettingsResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        $this->registerPasswordRecoveryRateLimiters();

        Absence::observe(AbsenceObserver::class);
        MedicalCertificate::observe(MedicalCertificateObserver::class);
        Delay::observe(DelayObserver::class);
        Leave::observe(LeaveObserver::class);
        MonthlyReport::observe(MonthlyReportObserver::class);
        OperationLog::observe(OperationLogObserver::class);
        User::observe(UserObserver::class);

        Vite::prefetch(concurrency: 3);
    }

    private function registerPasswordRecoveryRateLimiters(): void
    {
        RateLimiter::for('password-forgot', function (Request $request): array {
            return $this->passwordRecoveryLimits($request, 'forgot');
        });

        RateLimiter::for('password-reset', function (Request $request): array {
            return $this->passwordRecoveryLimits($request, 'reset');
        });
    }

    /**
     * @return array<int, Limit>
     */
    private function passwordRecoveryLimits(Request $request, string $context): array
    {
        $settings = SystemSettingsResolver::loginSecuritySetting();
        $ipAddress = (string) ($request->ip() ?? 'unknown');
        $email = Str::lower(trim((string) $request->input('email')));
        $identifier = $email !== '' ? $email : 'no-email';

        if ($context === 'forgot') {
            $maxAttempts = LoginSecuritySetting::sanitizeForgotPasswordMaxAttempts(
                (int) $settings->forgot_password_max_attempts
            );
            $decaySeconds = LoginSecuritySetting::sanitizeForgotPasswordDecaySeconds(
                (int) $settings->forgot_password_decay_seconds
            );
        } else {
            $maxAttempts = LoginSecuritySetting::sanitizeResetPasswordMaxAttempts(
                (int) $settings->reset_password_max_attempts
            );
            $decaySeconds = LoginSecuritySetting::sanitizeResetPasswordDecaySeconds(
                (int) $settings->reset_password_decay_seconds
            );
        }

        return [
            Limit::perSecond($maxAttempts, $decaySeconds)->by($context.'|'.$identifier.'|'.$ipAddress),
            Limit::perSecond(max($maxAttempts, 10), $decaySeconds)->by($context.'|ip|'.$ipAddress),
        ];
    }
}
