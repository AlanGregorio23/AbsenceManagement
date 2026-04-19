<?php

namespace App\Observers;

use App\Models\OperationLog;
use App\Services\NotificationDispatcher;
use App\Services\NotificationRecipientResolver;

class OperationLogObserver
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationRecipientResolver $recipients
    ) {}

    public function created(OperationLog $log): void
    {
        $level = strtoupper(trim((string) $log->level));
        if (! in_array($level, ['WARNING', 'ERROR'], true)) {
            return;
        }

        $eventKey = $level === 'ERROR'
            ? 'admin_system_errors'
            : 'admin_system_warnings';
        $title = $level === 'ERROR'
            ? 'Errore di sistema'
            : 'Warning di sistema';
        $actionLabel = OperationLog::actionLabel((string) $log->action);
        $entityLabel = OperationLog::entityLabel((string) $log->entity);
        $body = $actionLabel;

        if ($entityLabel !== '-') {
            $body .= ' su '.$entityLabel;
        }

        $this->dispatcher->notifyUsers(
            $this->recipients->admins(),
            $eventKey,
            [
                'title' => $title,
                'body' => $body.'.',
                'url' => route('admin.error-logs'),
                'icon' => 'admin',
                'mail_subject' => $title,
            ]
        );
    }
}
