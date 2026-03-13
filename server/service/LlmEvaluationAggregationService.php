<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';

class LlmEvaluationAggregationService extends BaseLlmService
{
    public function buildSummary($dataset_case_count, $target, $records)
    {
        $execution_count = count($records);
        $pass_count = 0;
        $fail_count = 0;
        $pending_count = 0;
        $score_sum = 0.0;
        $score_count = 0;
        $models = array();
        $failure_buckets = array();
        $definition_stats = array();

        foreach ($records as $record) {
            $models[] = (string)($record['model'] ?? '');
            $status = (string)($record['status'] ?? 'passed');
            if ($status === 'failed') {
                $fail_count++;
            } elseif ($status === 'pending_review') {
                $pending_count++;
            } else {
                $pass_count++;
            }

            foreach ((array)($record['scores'] ?? array()) as $score) {
                $name = (string)($score['eval_name'] ?? $score['score_type'] ?? 'score');
                if (!isset($definition_stats[$name])) {
                    $definition_stats[$name] = array('name' => $name, 'total' => 0, 'pass_count' => 0, 'fail_count' => 0, 'pending_count' => 0, 'avg_score' => null, '_sum' => 0.0, '_count' => 0);
                }
                $definition_stats[$name]['total']++;
                if (($score['passed'] ?? null) === 1 || ($score['passed'] ?? null) === '1') {
                    $definition_stats[$name]['pass_count']++;
                } elseif (($score['passed'] ?? null) === 0 || ($score['passed'] ?? null) === '0') {
                    $definition_stats[$name]['fail_count']++;
                    $bucket = trim((string)($score['score_value_label'] ?? 'failed'));
                    $failure_buckets[$bucket] = ($failure_buckets[$bucket] ?? 0) + 1;
                } else {
                    $definition_stats[$name]['pending_count']++;
                }
                if (isset($score['score_value_numeric']) && $score['score_value_numeric'] !== null) {
                    $score_sum += (float)$score['score_value_numeric'];
                    $score_count++;
                    $definition_stats[$name]['_sum'] += (float)$score['score_value_numeric'];
                    $definition_stats[$name]['_count']++;
                }
            }
        }

        foreach ($definition_stats as &$definition) {
            $definition['avg_score'] = $definition['_count'] > 0 ? round($definition['_sum'] / $definition['_count'], 4) : null;
            unset($definition['_sum'], $definition['_count']);
        }
        unset($definition);

        arsort($failure_buckets);
        $failure_rows = array();
        foreach ($failure_buckets as $label => $count) {
            $failure_rows[] = array('label' => $label, 'count' => $count);
        }

        return array(
            'dataset_case_count' => (int)$dataset_case_count,
            'execution_count' => $execution_count,
            'pass_count' => $pass_count,
            'fail_count' => $fail_count,
            'pending_review_count' => $pending_count,
            'pass_rate' => $execution_count > 0 ? round(($pass_count / $execution_count) * 100, 2) : 0,
            'avg_score' => $score_count > 0 ? round($score_sum / $score_count, 4) : null,
            'models' => array_values(array_filter(array_unique($models))),
            'failure_buckets' => $failure_rows,
            'definition_summaries' => array_values($definition_stats),
            'target' => $target
        );
    }
}
?>
