<?php

return [
    'masterdata' => [
        'driver' => env('LOST_WAX_MASTERDATA_DRIVER', 'database'),
        'connection' => env('LOST_WAX_MASTERDATA_CONNECTION', 'masterdata_kpi'),
        'table' => env('LOST_WAX_MASTERDATA_TABLE', 'md_items'),
        'code_column' => env('LOST_WAX_MASTERDATA_CODE_COLUMN', 'code'),
        'name_column' => env('LOST_WAX_MASTERDATA_NAME_COLUMN', 'name'),
        'search_columns' => ['code', 'name'],
        'fallback_items' => [],
    ],

    'families' => [
        '1' => 'Fitting Stainless 304',
        '2' => 'Fitting Stainless 316',
        '3' => 'Flange Stainless 304',
        '4' => 'Flange Stainless 316',
        '6' => 'Iron Flange',
    ],

    'stages' => [
        'layer_1' => 'Lapisan 1',
        'layer_2' => 'Lapisan 2',
        'layer_3' => 'Lapisan 3',
        'layer_4' => 'Lapisan 4',
        'layer_5' => 'Lapisan 5',
        'layer_6' => 'Lapisan 6',
        'layer_7' => 'Lapisan 7',
        'oven' => 'Oven',
    ],

    'aging' => [
        'stages' => [
            'layer_1' => [
                'min_hours' => 4,
                'max_hours' => 6,
                'buffer_hours' => 8,
            ],
            'layer_2' => [
                'min_hours' => 4,
                'max_hours' => 6,
                'buffer_hours' => 8,
            ],
            'layer_3' => [
                'min_hours' => 6,
                'max_hours' => 6,
                'buffer_hours' => 8,
            ],
            'layer_4' => [
                'min_hours' => 6,
                'max_hours' => 6,
                'buffer_hours' => 8,
            ],
            'layer_5' => [
                'min_hours' => 8,
                'max_hours' => 8,
                'buffer_hours' => 10,
            ],
            'layer_6' => [
                'min_hours' => 8,
                'max_hours' => 8,
                'buffer_hours' => 10,
            ],
            'layer_7' => [
                'min_hours' => 24,
                'max_hours' => 24,
                'buffer_hours' => 26,
            ],
        ],
        'min_hours' => (float) env('LOST_WAX_AGING_MIN_HOURS', 4),
        'max_hours' => (float) env('LOST_WAX_AGING_MAX_HOURS', 6),
        'min_scan_interval_minutes' => (int) env('LOST_WAX_MIN_SCAN_INTERVAL_MINUTES', 20),
    ],

    'printer_name' => env('THERMAL_PRINTER_NAME', 'TSC TE200'),
    'print_agent_token' => env('PRINT_AGENT_TOKEN', 'peroniks_print_token_2026'),

    'scanner_emails' => [
        'spvlapisan@peroniks.com',
    ],
];
