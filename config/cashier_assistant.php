<?php

return [
    'max_tools_per_request' => (int) env('CASHIER_ASSISTANT_MAX_TOOLS', 5),
    'planner' => env('CASHIER_ASSISTANT_PLANNER', 'llm'),
    'heuristic_planner_in_testing' => (bool) env('CASHIER_ASSISTANT_HEURISTIC_IN_TESTING', true),
    'min_period_year' => (int) env('CASHIER_ASSISTANT_MIN_PERIOD_YEAR', 2000),
];
