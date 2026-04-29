<?php

namespace Tests\Support;

use App\Models\OperationLog;

trait AssertsInfoOperationLogs
{
    protected function assertInfoOperationLogExists(
        string $action,
        string $entity,
        ?int $entityId = null
    ): void {
        $query = OperationLog::query()
            ->where('action', $action)
            ->where('entity', $entity)
            ->where('level', 'INFO');

        if ($entityId !== null) {
            $query->where('entity_id', $entityId);
        }

        $this->assertTrue(
            $query->exists(),
            sprintf(
                'Missing INFO operation log action [%s] entity [%s] entity_id [%s].',
                $action,
                $entity,
                $entityId !== null ? (string) $entityId : 'any'
            )
        );
    }
}
