<?php

return [
    'auto_nihil' => [
        'timeout_days' => (int) env('SAND_CASTING_AUTO_NIHIL_TIMEOUT_DAYS', 5),
        'activation_date' => env('SAND_CASTING_AUTO_NIHIL_ACTIVATION_DATE', '2026-10-09'),
    ],
];
