<?php

namespace App\Services;

/** Native PHP port of II Core B1's deterministic synthetic scenario evaluator. */
class ScenarioEvaluationService
{
    public function evaluate(float $observed, float $minimum, float $falsificationBelow): array
    {
        if ($falsificationBelow > $minimum) {
            throw new \InvalidArgumentException('Falsification boundary must not exceed support threshold.');
        }

        $blockers = [];
        $falsified = $observed < $falsificationBelow;
        if ($falsified) {
            $blockers[] = 'THESIS_FALSIFIED_BY_OBSERVATION';
        } elseif ($observed < $minimum) {
            $blockers[] = 'THESIS_SUPPORT_THRESHOLD_NOT_MET';
        }

        return [
            'schema' => 'II_CORE_B1_LARAVEL_V1',
            'thesis_id' => 'USER_SCENARIO',
            'research_state' => $blockers === [] ? 'RESEARCH_REVIEW_READY' : 'HOLD',
            'thesis_falsified' => $falsified,
            'evidence_ids_used' => ['USER_HYPOTHETICAL'],
            'blocking_reasons' => $blockers,
            'decision_authority' => 'NONE_SYNTHETIC_ONLY',
            'production_admission_authorized' => false,
            'valuation_authorized' => false,
            'trading_authorized' => false,
            'outcome_learning_validated' => false,
        ];
    }
}
