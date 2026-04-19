<?php

namespace App\Http\Middleware;

use App\Models\Absence;
use App\Models\Delay;
use App\Models\Leave;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => fn () => $this->serializeUser($request->user()),
            ],
            'notifications' => fn () => $this->buildNotificationCenter($request),
            'global_search' => fn () => $this->buildGlobalSearchIndex($request),
        ];
    }

    private function serializeUser($user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'surname' => $user->surname,
            'email' => $user->email,
            'role' => $user->role,
            'email_verified_at' => $user->email_verified_at,
            'avatar_url' => $user->avatar_url,
        ];
    }

    private function buildNotificationCenter(Request $request): array
    {
        $user = $request->user();
        if (! $user) {
            return [
                'items' => [],
                'unread_count' => 0,
            ];
        }

        $items = $user->notifications()
            ->latest()
            ->limit(8)
            ->get()
            ->map(function ($notification) {
                $payload = is_array($notification->data) ? $notification->data : [];

                return [
                    'id' => (string) $notification->id,
                    'title' => (string) ($payload['title'] ?? 'Nuova notifica'),
                    'body' => (string) ($payload['body'] ?? ''),
                    'url' => $payload['url'] ?? null,
                    'icon' => (string) ($payload['icon'] ?? 'system'),
                    'action_label' => (string) ($payload['action_label'] ?? 'Apri'),
                    'action_type' => (string) ($payload['action_type'] ?? 'open'),
                    'is_read' => $notification->read_at !== null,
                    'created_at' => $notification->created_at?->format('d/m/Y H:i'),
                ];
            })
            ->values()
            ->all();

        return [
            'items' => $items,
            'unread_count' => (int) $user->notifications()
                ->whereNull('read_at')
                ->count(),
        ];
    }

    private function buildGlobalSearchIndex(Request $request): array
    {
        $user = $request->user();
        if (! $user) {
            return [];
        }

        if ($user->role !== 'student') {
            return [];
        }

        $absenceModel = new Absence;
        $delayModel = new Delay;
        $leaveModel = new Leave;

        return collect($absenceModel->getAbsence($user))
            ->merge($delayModel->getDelay($user))
            ->merge($leaveModel->getLeave($user))
            ->sortByDesc('date')
            ->take(140)
            ->values()
            ->map(function (array $item) {
                $id = trim((string) ($item['id'] ?? ''));
                $type = trim((string) ($item['tipo'] ?? 'Richiesta'));
                $date = trim((string) ($item['data'] ?? $item['date'] ?? ''));
                $rawDate = trim((string) ($item['date'] ?? ''));
                $reason = trim((string) ($item['motivo'] ?? ''));
                $duration = trim((string) ($item['durata'] ?? ''));
                $destination = trim((string) ($item['destinazione'] ?? $item['destination'] ?? ''));
                $idWithoutLeadingZero = preg_replace('/^([A-Za-z]-)0+(\d+)$/', '$1$2', $id) ?: $id;

                $tokenList = collect([
                    $id,
                    $idWithoutLeadingZero,
                    str_replace('-', '', $id),
                    $type,
                    $date,
                    str_replace('/', '-', $date),
                    $rawDate,
                    $reason,
                    $duration,
                    $destination,
                ])->filter(fn ($value) => trim((string) $value) !== '')
                    ->implode(' ');

                return [
                    'key' => $id !== '' ? 'request-'.$id : 'request-'.md5($tokenList),
                    'type' => 'request',
                    'icon' => match (strtolower($type)) {
                        'assenza' => 'absence',
                        'ritardo' => 'delay',
                        'congedo' => 'leave',
                        default => 'search',
                    },
                    'label' => $id !== '' ? $id : ($type !== '' ? $type : 'Richiesta'),
                    'subtitle' => trim($type.' '.$date.' '.$reason),
                    'tokens' => $tokenList,
                    'url' => $id !== ''
                        ? route('student.history', ['open' => $id])
                        : route('student.history'),
                ];
            })
            ->all();
    }
}
