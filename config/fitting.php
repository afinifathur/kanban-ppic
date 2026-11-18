<?php

return [
    'auto_nihil' => [
        'timeout_days' => (int) env('FITTING_AUTO_NIHIL_TIMEOUT_DAYS', 5),
        'activation_date' => env('FITTING_AUTO_NIHIL_ACTIVATION_DATE'),
    ],
];
