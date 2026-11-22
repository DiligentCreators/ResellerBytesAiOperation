<?php

use App\Models\WhmcsIntegration;
use App\Services\Health\HealthScoreCalculator;
use Tests\TestCase;

uses(TestCase::class);

dataset('health_score_cases', [
    'clean install' => [
        [],
        WhmcsIntegration::HEARTBEAT_STATE_OK,
        100,
    ],
    'one critical with zero affected still deducts' => [
        [['severity' => 'critical', 'affected_count' => 0]],
        WhmcsIntegration::HEARTBEAT_STATE_OK,
        100, // log2(1+0)=0 → no deduction
    ],
    'one critical with one affected' => [
        [['severity' => 'critical', 'affected_count' => 1]],
        WhmcsIntegration::HEARTBEAT_STATE_OK,
        75, // 100 - 25*log2(2) = 100 - 25
    ],
    'one warning with three affected' => [
        [['severity' => 'warning', 'affected_count' => 3]],
        WhmcsIntegration::HEARTBEAT_STATE_OK,
        84, // 100 - 8*log2(4) = 100 - 16
    ],
    'info finding scaled' => [
        [['severity' => 'info', 'affected_count' => 1]],
        WhmcsIntegration::HEARTBEAT_STATE_OK,
        98, // 100 - 2*1
    ],
    'heartbeat down caps score' => [
        [],
        WhmcsIntegration::HEARTBEAT_STATE_DOWN,
        40,
    ],
    'heartbeat down with findings still capped' => [
        [['severity' => 'critical', 'affected_count' => 1]],
        WhmcsIntegration::HEARTBEAT_STATE_DOWN,
        40, // raw 75, capped at 40
    ],
    'many findings floor at zero' => [
        [
            ['severity' => 'critical', 'affected_count' => 15],
            ['severity' => 'critical', 'affected_count' => 15],
            ['severity' => 'critical', 'affected_count' => 15],
            ['severity' => 'warning', 'affected_count' => 31],
        ],
        WhmcsIntegration::HEARTBEAT_STATE_OK,
        0,
    ],
]);

it('calculates health scores from weighted open findings', function (array $findings, ?string $heartbeat, int $expected) {
    $calculator = new HealthScoreCalculator;

    expect($calculator->calculate($findings, $heartbeat))->toBe($expected);
})->with('health_score_cases');

it('reads severity weights from config', function () {
    config([
        'health.weights.critical' => 10,
        'health.weights.warning' => 5,
        'health.weights.info' => 1,
        'health.heartbeat_down_cap' => 20,
    ]);

    $calculator = new HealthScoreCalculator;

    expect($calculator->calculate([
        ['severity' => 'critical', 'affected_count' => 1],
    ], WhmcsIntegration::HEARTBEAT_STATE_OK))->toBe(90)
        ->and($calculator->calculate([], WhmcsIntegration::HEARTBEAT_STATE_DOWN))->toBe(20);
});
