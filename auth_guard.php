<?php
/**
 * Shared authentication & CORS guard untuk endpoint sensitif.
 *
 * Pakai: require_once __DIR__ . '/auth_guard.php';
 * lalu panggil guard_request(); di awal endpoint.
 *
 * Konfigurasi via environment:
 *   API_TOKEN              -> token rahasia (WAJIB di-set agar endpoint aktif)
 *   CORS_ALLOWED_ORIGINS   -> daftar origin dipisah koma (opsional)
 *
 * Client memanggil dengan header:  X-API-Key: <API_TOKEN>
 */

if (!function_exists('guard_cors_allowed_origins')) {
    function guard_cors_allowed_origins(): array {
        $raw = getenv('CORS_ALLOWED_ORIGINS') ?: '';
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}

if (!function_exists('guard_apply_cors_headers')) {
    function guard_apply_cors_headers(string $methods = 'POST, OPTIONS'): void {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigins = guard_cors_allowed_origins();
        if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
            header("Access-Control-Allow-Origin: $origin");
            header('Vary: Origin');
        }
        header('Access-Control-Allow-Methods: ' . $methods);
        header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
    }
}

if (!function_exists('guard_require_api_key')) {
    function guard_require_api_key(): void {
        $apiToken = getenv('API_TOKEN') ?: '';
        if ($apiToken === '') {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => 'Server API token is not configured.',
            ]);
            exit;
        }

        $providedToken = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if ($providedToken === '' || !hash_equals($apiToken, $providedToken)) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'error'   => 'Unauthorized',
            ]);
            exit;
        }
    }
}

if (!function_exists('guard_require_same_origin')) {
    /**
     * Tolak request lintas-situs (CSRF / pemanggilan dari domain lain).
     *
     * Cocok untuk endpoint yang dipanggil dari frontend aplikasi sendiri
     * (lewat fetch), di mana API key rahasia TIDAK bisa disembunyikan di JS.
     *
     * Strategi: bandingkan host dari header Origin/Referer dengan HTTP_HOST.
     * Jika Origin/Referer ada tapi beda host -> tolak.
     * Jika keduanya kosong (mis. navigasi langsung) -> diizinkan, karena
     * request lintas-situs dari browser selalu menyertakan Origin/Referer.
     */
    function guard_require_same_origin(): void {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return;
        }

        $candidates = [];
        if (!empty($_SERVER['HTTP_ORIGIN'])) {
            $candidates[] = $_SERVER['HTTP_ORIGIN'];
        }
        if (!empty($_SERVER['HTTP_REFERER'])) {
            $candidates[] = $_SERVER['HTTP_REFERER'];
        }

        foreach ($candidates as $url) {
            $urlHost = strtolower((string)parse_url($url, PHP_URL_HOST));
            $urlPort = parse_url($url, PHP_URL_PORT);
            if ($urlPort) {
                $urlHost .= ':' . $urlPort;
            }
            // Bandingkan dengan dan tanpa port pada HTTP_HOST
            $hostNoPort = strtolower((string)parse_url('http://' . $host, PHP_URL_HOST));
            if ($urlHost !== $host && $urlHost !== $hostNoPort) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'error'   => 'Forbidden: cross-origin request blocked.',
                ]);
                exit;
            }
        }
    }
}

if (!function_exists('guard_same_origin_request')) {
    /**
     * One-shot guard untuk endpoint frontend: batasi method, tangani OPTIONS,
     * lalu wajibkan same-origin (anti-CSRF) — tanpa API key.
     *
     * @param string[] $allowedMethods mis. ['POST'] atau ['GET','POST']
     */
    function guard_same_origin_request(array $allowedMethods = ['POST']): void {
        $methodList = implode(', ', array_merge($allowedMethods, ['OPTIONS']));
        guard_apply_cors_headers($methodList);

        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        if (!in_array($method, $allowedMethods, true)) {
            http_response_code(405);
            echo json_encode([
                'success' => false,
                'error'   => 'Method not allowed',
            ]);
            exit;
        }

        guard_require_same_origin();
    }
}

if (!function_exists('guard_request')) {
    /**
     * One-shot guard: pasang header CORS, tangani preflight OPTIONS,
     * batasi HTTP method, lalu wajibkan API key.
     *
     * @param string[] $allowedMethods mis. ['POST'] atau ['GET','POST']
     */
    function guard_request(array $allowedMethods = ['POST']): void {
        $methodList = implode(', ', array_merge($allowedMethods, ['OPTIONS']));
        guard_apply_cors_headers($methodList);

        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        if (!in_array($method, $allowedMethods, true)) {
            http_response_code(405);
            echo json_encode([
                'success' => false,
                'error'   => 'Method not allowed',
            ]);
            exit;
        }

        guard_require_api_key();
    }
}
