<?php
declare(strict_types=1);
namespace Allfather\Loopia\Core;

/**
 * One operator scope shape at every service boundary.
 *
 * Some owners read `operator_id`, others `actor_type`/`actor_id`. Passing one array between them
 * used to work only when a caller remembered to fill both halves. This resolves the two spellings
 * to one identity and refuses (null) when they disagree, name a non-operator actor, or are missing,
 * so a mismatched array can never be read as a different operator by the next service.
 */
final class OperatorScope
{
    /** @return array{tenant_id:string,workspace_id:string,operator_id:string,actor_type:string,actor_id:string}|null */
    public static function normalize(array $scope): ?array
    {
        $operator = $scope['operator_id'] ?? null;
        $actorType = $scope['actor_type'] ?? null;
        $actorId = $scope['actor_id'] ?? null;
        if ($actorType !== null && $actorType !== 'OPERATOR') {
            return null;
        }
        if ($operator === null && $actorType === 'OPERATOR') {
            $operator = $actorId;
        }
        if ($actorId !== null && $actorId !== $operator) {
            return null;
        }
        foreach ([$scope['tenant_id'] ?? null, $scope['workspace_id'] ?? null, $operator] as $value) {
            if (!is_string($value) || $value === '' || strlen($value) > 64) {
                return null;
            }
        }

        return ['tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'operator_id' => $operator, 'actor_type' => 'OPERATOR', 'actor_id' => $operator];
    }
}
