<?php
return [
    'accounts' => [
        [
            'id' => 'acc_01',
            'email' => 'akun_utama@gmail.com',
            'api_key' => 'sk-antigravity-key-01',
            'provider' => 'gemini',
            'model' => 'Gemini 3 Pro',
            'tier' => 'pro',
            'daily_limit' => 1000
        ],
        [
            'id' => 'acc_02',
            'email' => 'akun_sampingan@gmail.com',
            'api_key' => 'sk-antigravity-key-02',
            'provider' => 'gemini',
            'model' => 'Gemini 3 Flash',
            'tier' => 'flash',
            'daily_limit' => 15000
        ],
        [
            'id' => 'acc_03',
            'email' => 'akun_cadangan@gmail.com',
            'api_key' => 'sk-antigravity-key-03',
            'provider' => 'gemini',
            'model' => 'Gemini 3 Flash',
            'tier' => 'flash',
            'daily_limit' => 15000
        ]
    ]
];
