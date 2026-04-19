<?php

namespace App\Support;

use App\Models\DelayRule;
use Illuminate\Support\Collection;

class DelayRuleEvaluator
{
    /**
     * @return array{
     *   primary_rule:?DelayRule,
     *   applicable_rules:Collection<int,DelayRule>,
     *   actions:Collection<int,array{type:string,detail:string}>,
     *   info_messages:array<int,string>
     * }
     */
    public static function evaluateForCount(int $count): array
    {
        $applicableRules = self::resolveApplicableRules($count);
        $primaryRule = self::resolvePrimaryRule($applicableRules);
        $actions = self::normalizeActions($applicableRules);
        $infoMessages = $applicableRules
            ->map(fn (DelayRule $rule) => trim((string) ($rule->info_message ?? '')))
            ->filter(fn (string $message) => $message !== '')
            ->unique()
            ->values()
            ->all();

        return [
            'primary_rule' => $primaryRule,
            'applicable_rules' => $applicableRules,
            'actions' => $actions,
            'info_messages' => $infoMessages,
        ];
    }

    /**
     * @return Collection<int,DelayRule>
     */
    public static function resolveApplicableRules(int $count): Collection
    {
        return DelayRule::query()
            ->where('min_delays', '<=', $count)
            ->where(function ($query) use ($count) {
                $query
                    ->whereNull('max_delays')
                    ->orWhere('max_delays', '>=', $count);
            })
            ->orderBy('min_delays')
            ->orderBy('max_delays')
            ->get();
    }

    /**
     * @param  Collection<int,DelayRule>  $applicableRules
     */
    public static function resolvePrimaryRule(Collection $applicableRules): ?DelayRule
    {
        return $applicableRules
            ->sort(function (DelayRule $left, DelayRule $right): int {
                if ($left->min_delays !== $right->min_delays) {
                    return $right->min_delays <=> $left->min_delays;
                }

                $leftMax = $left->max_delays ?? PHP_INT_MAX;
                $rightMax = $right->max_delays ?? PHP_INT_MAX;

                return $leftMax <=> $rightMax;
            })
            ->first();
    }

    /**
     * @param  Collection<int,DelayRule>  $rules
     * @return Collection<int,array{type:string,detail:string}>
     */
    public static function normalizeActions(Collection $rules): Collection
    {
        return $rules
            ->flatMap(fn (DelayRule $rule) => collect($rule->actions ?? []))
            ->map(function ($action): array {
                return [
                    'type' => strtolower(trim((string) ($action['type'] ?? ''))),
                    'detail' => trim((string) ($action['detail'] ?? '')),
                ];
            })
            ->filter(fn (array $action) => $action['type'] !== '' && $action['type'] !== 'none')
            ->unique(fn (array $action) => $action['type'].'|'.$action['detail'])
            ->values();
    }
}
