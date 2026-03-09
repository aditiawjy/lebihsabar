<?php

class AntigravityManager {
    private $config;
    private $usageFile;
    private $usageData;

    public function __construct() {
        $configFile = __DIR__ . '/config.php';
        if (file_exists($configFile)) {
            $this->config = require $configFile;
        } else {
            $this->config = ['accounts' => []];
        }
        
        // Coba load akun dari OpenCode Plugin (GitHub Antigravity)
        $this->loadOpenCodeAccounts();
        
        $this->usageFile = __DIR__ . '/usage.json';
        $this->loadUsage();
    }

    private function loadOpenCodeAccounts() {
        // Path default untuk Windows user 'user'
        $paths = [
            'C:/Users/user/.config/opencode/antigravity-accounts.json',
            getenv('USERPROFILE') . '/.config/opencode/antigravity-accounts.json'
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                $content = file_get_contents($path);
                $data = json_decode($content, true);
                
                if (isset($data['accounts']) && is_array($data['accounts'])) {
                    $newAccounts = [];
                    foreach ($data['accounts'] as $idx => $acc) {
                        $newAccounts[] = [
                            'id' => 'opencode_' . $idx,
                            'email' => $acc['email'] ?? 'Unknown',
                            'api_key' => 'managed_by_opencode',
                            'provider' => 'gemini', // Default plugin provider
                            'tier' => 'pro',
                            'daily_limit' => 1500 // Estimasi kuota harian Gemini Pro
                        ];
                    }
                    
                    if (!empty($newAccounts)) {
                        $this->config['accounts'] = $newAccounts;
                        $this->config['source'] = 'OpenCode Plugin (GitHub)';
                    }
                }
                break;
            }
        }
    }

    // Getter untuk config
    public function getConfig() {
        return $this->config;
    }

    // Load database usage sederhana (JSON)
    private function loadUsage() {
        if (file_exists($this->usageFile)) {
            $this->usageData = json_decode(file_get_contents($this->usageFile), true);
        } else {
            $this->usageData = [];
        }
    }

    // Simpan usage ke JSON
    private function saveUsage() {
        file_put_contents($this->usageFile, json_encode($this->usageData, JSON_PRETTY_PRINT));
    }

    // Inti dari "Antigravity": Mencari akun yang masih fresh
    public function getActiveAccount($provider = 'gemini') {
        $today = date('Y-m-d');

        foreach ($this->config['accounts'] as $acc) {
            if ($acc['provider'] !== $provider) continue;

            $id = $acc['id'];
            
            // Cek usage hari ini
            $currentUsage = $this->usageData[$id][$today] ?? 0;
            
            // Jika usage masih di bawah limit, gunakan akun ini
            if ($currentUsage < $acc['daily_limit']) {
                return $acc;
            }
        }

        throw new Exception("SEMUA AKUN HABIS! Antigravity gagal menahan beban.");
    }

    // Fungsi Wrapper untuk memanggil AI (Opencode connect ke sini)
    public function chat($prompt, $provider = 'gemini') {
        try {
            // 1. Pilih Akun
            $account = $this->getActiveAccount($provider);
            
            // 2. Request API Asli (jika ada API Key valid)
            if (strpos($account['api_key'], 'sk-') === 0 || strlen($account['api_key']) > 20) {
                $responseContent = $this->callGeminiApi($account['api_key'], $prompt, $account['model'] ?? 'gemini-pro');
            } else {
                // Fallback Simulasi jika API Key dummy
                $responseContent = "Simulasi: API Key tidak valid. Edit config.php untuk menggunakan key asli. (Akun: " . $account['email'] . ")";
            }
            
            // 3. Catat Penggunaan (Quota Monitoring)
            // Asumsi 1 request = 1 poin (bisa disesuaikan dengan token count nanti)
            $this->recordUsage($account['id'], 1);
            
            return [
                'status' => 'success',
                'account_used' => $account['email'],
                'data' => $responseContent
            ];

        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    private function callGeminiApi($apiKey, $prompt, $model) {
        // Map model names to API endpoints
        $apiModel = 'gemini-pro'; // Default
        if (stripos($model, 'flash') !== false) $apiModel = 'gemini-1.5-flash';
        elseif (stripos($model, 'pro') !== false) $apiModel = 'gemini-1.5-pro';

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$apiModel}:generateContent?key={$apiKey}";
        
        $data = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $error = json_decode($result, true);
            throw new Exception("API Error ($httpCode): " . ($error['error']['message'] ?? $result));
        }

        $response = json_decode($result, true);
        return $response['candidates'][0]['content']['parts'][0]['text'] ?? "No response text.";
    }

    private function recordUsage($accountId, $cost) {
        $today = date('Y-m-d');
        if (!isset($this->usageData[$accountId])) {
            $this->usageData[$accountId] = [];
        }
        if (!isset($this->usageData[$accountId][$today])) {
            $this->usageData[$accountId][$today] = 0;
        }
        
        $this->usageData[$accountId][$today] += $cost;
        $this->saveUsage();
    }
}
