<?php
/**
 * tg_send.php — relay pengiriman pesan Telegram untuk Live Match Signal dashboard.
 * Dipanggil dashboard.js saat ada sinyal pattern baru. Dedup server-side (TTL 2 jam)
 * berdasarkan 'key' agar tidak spam tiap refresh.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const TG_TOKEN = '8498249768:AAHuJNth3fhRlR4CBSfvb6eYOFnTzRVR0YA';
const TG_CHAT  = '6801623296';
const TG_TTL   = 7200; // detik

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$text = trim((string)($body['text'] ?? ''));
$key  = trim((string)($body['key'] ?? ''));
if ($text === '') { echo json_encode(['ok' => false, 'error' => 'empty text']); exit; }

// --- Dedup server-side -------------------------------------------------------
$store = __DIR__ . '/cache/tg_sent.json';
if (!is_dir(__DIR__ . '/cache')) @mkdir(__DIR__ . '/cache', 0775, true);
$now = time();
$sent = is_file($store) ? (json_decode(@file_get_contents($store), true) ?: []) : [];
foreach ($sent as $k => $ts) { if ($now - $ts > TG_TTL) unset($sent[$k]); } // purge lama
if ($key !== '' && isset($sent[$key])) { echo json_encode(['ok' => true, 'skipped' => true]); exit; }

// --- Kirim ke Telegram -------------------------------------------------------
$url = 'https://api.telegram.org/bot' . TG_TOKEN . '/sendMessage';
$payload = ['chat_id' => TG_CHAT, 'text' => $text, 'parse_mode' => 'HTML'];

$ok = false; $resp = null;
if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    $ok = $resp !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);
} else {
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($payload),
        'timeout' => 20,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $ok = $resp !== false && strpos((string)$resp, '"ok":true') !== false;
}

if ($ok && $key !== '') {
    $sent[$key] = $now;
    @file_put_contents($store, json_encode($sent), LOCK_EX);
}

echo json_encode(['ok' => $ok]);
