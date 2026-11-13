<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'Metode tidak didukung.']); exit; }
set_time_limit(180);
$zone = new DateTimeZone('Asia/Singapore');
$today = new DateTimeImmutable((new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d').' 00:00:00', $zone);
$start = $today->modify('-1 day');
$dir = getenv('SABARAJA_RESULTS_DIR') ?: '';
$session = getenv('SABARAJA_SESSION_FILE') ?: '';
$python = getenv('SABARAJA_PYTHON') ?: '';
if (!$dir || !$session || !is_file($python)) { http_response_code(503); echo json_encode(['error'=>'Konfigurasi API belum lengkap.']); exit; }
$matches = []; $missingHt = 0;
foreach ([$start->modify('-1 day'), $start, $today] as $sessionDay) {
    $day = $sessionDay->format('Y-m-d');
    $output = $dir.'/msports-'.$day.'.json';
    $process = proc_open([$python, __DIR__.'/msports-parser/results_api.py', '--date', $day, '--utc-offset', '8', '--output', $output, '--session-file', $session], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, __DIR__, null, ['bypass_shell'=>true]);
    if (!is_resource($process)) { http_response_code(503); echo json_encode(['error'=>'Pengambil API tidak dapat dijalankan.']); exit; }
    fclose($pipes[0]); stream_get_contents($pipes[1]); fclose($pipes[1]); $err = trim(stream_get_contents($pipes[2])); fclose($pipes[2]);
    if (proc_close($process) !== 0) { http_response_code(502); echo json_encode(['error'=>preg_match('/^API menolak sesi \(HTTP (?:401|403)\); perbarui sesi login\.$/D', $err) ? $err : 'Pengambilan API MSports gagal untuk '.$day.'.']); exit; }
    $payload = json_decode(file_get_contents($output), true);
    if (!isset($payload['data']) || !is_array($payload['data'])) { http_response_code(502); echo json_encode(['error'=>'Data API tidak valid untuk '.$day.'.']); exit; }
    foreach ($payload['data'] as $row) {
        $league = trim((string)($row['event_name'] ?? ''));
        if (!preg_match('/^(SABA |E-FOOTBALL )/i', $league) || ($row['st_fs'] ?? '') !== 'Y' || ($row['st_postponed'] ?? '') !== 'N') continue;
        if (($row['st_hs'] ?? '') !== 'Y') { $missingHt++; continue; }
        if (!preg_match('/^(\d+):(\d+)_(\d+):(\d+)$/D', $row['fs_home'] ?? '', $score) || (int)$score[3] < (int)$score[1] || (int)$score[4] < (int)$score[2]) continue;
        try { $time = new DateTimeImmutable($row['match_date']); $time = $time->setTimezone($zone); } catch (Throwable $e) { continue; }
        if ($time < $start || $time >= $today->modify('+1 day')) continue;
        $home = trim(preg_replace('/\s+/', ' ', $row['home_name'] ?? '')); $away = trim(preg_replace('/\s+/', ' ', $row['away_name'] ?? ''));
        if (!$home || !$away) continue;
        $match = ['match_time'=>$time->format('Y-m-d H:i:s'),'home_team'=>$home,'away_team'=>$away,'league'=>$league,'fh_home'=>(int)$score[1],'fh_away'=>(int)$score[2],'ft_home'=>(int)$score[3],'ft_away'=>(int)$score[4],'source_utc_offset'=>'+08:00'];
        $key = strtolower(json_encode([$match['match_time'],preg_replace('/\s*\(V\)/i','',$home),preg_replace('/\s*\(V\)/i','',$away),$league]));
        $matches[$key] = $match;
    }
}
echo json_encode(['start'=>$start->format('Y-m-d'),'end'=>$today->format('Y-m-d'),'timezone'=>'UTC+8','matches'=>array_values($matches),'missingHt'=>$missingHt], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
