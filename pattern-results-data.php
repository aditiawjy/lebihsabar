<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/pattern-results-period.php';
$localAppData = getenv('LOCALAPPDATA');
if (!$localAppData) {
    $userProfile = getenv('USERPROFILE');
    $localAppData = $userProfile ? rtrim($userProfile, '\\/') . '/AppData/Local' : '';
}
$directory = getenv('SABARAJA_RESULTS_DIR') ?: ($localAppData ? $localAppData . '/Sabaraja/results' : '');
$sessionFile = getenv('SABARAJA_SESSION_FILE') ?: ($localAppData ? $localAppData . '/Sabaraja/msports-session.bin' : '');
$date = $_GET['date'] ?? date('Y-m-d');
$league = $_GET['league'] ?? '16';
$leagues = ['vietnam15'=>'10031619', '15'=>'10026214', '16'=>'10031403', '20'=>'10026211',
    'f24-club'=>'10031501', 'f24-international'=>'10031502',
    'f26-club'=>'10034432', 'f26-international'=>'10034496'];
$extraNames = [
    'vietnam15'=>'SABA VIETNAM V LEAGUE 1 Virtual PES 24 - 15 Mins Play',
    'f24-club'=>'E-FOOTBALL F24 ELITE CLUB FRIENDLY - 16 Mins Play',
    'f24-international'=>'E-FOOTBALL F24 INTERNATIONAL FRIENDLY - 16 Mins Play',
    'f26-club'=>'E-FOOTBALL F26 ELITE CLUB FRIENDLY - 16 Mins Play',
    'f26-international'=>'E-FOOTBALL F26 INTERNATIONAL FRIENDLY - 16 Mins Play',
];
$extraLabels = ['vietnam15'=>'Vietnam V League 1 · 15 menit', 'f24-club'=>'E-Football F24 · Elite Club', 'f24-international'=>'E-Football F24 · International',
    'f26-club'=>'E-Football F26 · Elite Club', 'f26-international'=>'E-Football F26 · International'];
if (!isset($leagues[$league])) {
    http_response_code(400); echo json_encode(['error'=>'League tidak valid.']); exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || !checkdate((int)substr($date,5,2), (int)substr($date,8,2), (int)substr($date,0,4))) {
    http_response_code(400);
    echo json_encode(['error'=>'Tanggal tidak valid.']); exit;
}
$file = $directory . '/msports-' . $date . '.json';
$previousDate = (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
$previousFile = $directory . '/msports-' . $previousDate . '.json';
$period = patternResultsPeriod($date, ($_GET['period'] ?? '') === 'day' ? 'day' : $league);
$periodStart = new DateTimeImmutable($period['periodStart']);
$periodEnd = new DateTimeImmutable($period['periodEnd']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    set_time_limit(90);
    $python = getenv('SABARAJA_PYTHON') ?: '';
    if ($python === '' && $localAppData !== '') {
        $pythonCandidates = glob($localAppData . '/Programs/Python/Python*/python.exe') ?: [];
        rsort($pythonCandidates, SORT_NATURAL);
        $python = $pythonCandidates[0] ?? '';
    }
    if ($python === '' || !is_file($python)) {
        http_response_code(503);
        echo json_encode(['error'=>'Python tidak ditemukan. Atur SABARAJA_PYTHON ke python.exe yang terpasang.']); exit;
    }
    if ($directory === '') {
        http_response_code(503);
        echo json_encode(['error'=>'Folder data Results tidak dapat ditentukan. Atur SABARAJA_RESULTS_DIR.']); exit;
    }
    if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
        http_response_code(503);
        echo json_encode(['error'=>'Folder data Results tidak dapat dibuat.']); exit;
    }
    // The provider's Results date represents a noon-to-noon session.
    // A calendar day therefore needs the previous session and the current one.
    foreach ([$previousDate, $date] as $apiDate) {
    $outputFile = $directory . '/msports-' . $apiDate . '.json';
    $process = proc_open([$python, __DIR__.'/msports-parser/results_api.py', '--date', $apiDate,
        '--utc-offset', '8', '--output', $outputFile, '--session-file', $sessionFile],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, __DIR__, null, ['bypass_shell'=>true]);
    if (!is_resource($process)) {
        http_response_code(503); echo json_encode(['error'=>'Pengambil Results tidak dapat dijalankan.']); exit;
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        http_response_code(502);
        $detail = trim($stderr);
        $safeError = '/^(?:API menolak sesi \(HTTP (?:401|403)\); perbarui sesi login\.|API mengembalikan HTTP \d+\.|Koneksi ke API Results gagal atau timeout\.|Respons API bukan JSON yang valid\.|API belum mengembalikan hasil sukses \(status bukan 1\)\.|Struktur data Results berubah; respons tidak disimpan\.|Konfigurasi sesi .*|Konfigurasi sesi belum lengkap: [A-Z0-9_, ]+)$/D';
        $reason = preg_match($safeError, $detail) ? $detail : 'Pengambil API gagal tanpa detail aman.';
        echo json_encode(['error'=>'Pembaruan API gagal: '.$reason.' Data tersimpan tetap tersedia.']); exit;
    }
    }
}
$data = is_file($file) ? json_decode(file_get_contents($file), true) : null;
$previous = is_file($previousFile) ? json_decode(file_get_contents($previousFile), true) : null;
$merged = [];
foreach (array_merge($previous['data'] ?? [], $data['data'] ?? []) as $match) {
    $key = ($match['events'] ?? '') . '|' . ($match['no_partai'] ??
        (($match['match_date'] ?? '') . '|' . ($match['home_name'] ?? '') . '|' . ($match['away_name'] ?? '')));
    $merged[$key] = $match;
}
$rows = [];
$saveMatches = [];
require_once __DIR__.'/pattern-h2h.php';
$h2hStats = patternH2hStats();
usort($merged, static fn($a,$b)=>strcmp($a['match_date'] ?? '',$b['match_date'] ?? ''));
foreach ($merged as $match) {
    if ((string)($match['events'] ?? '') !== $leagues[$league]) continue;
    try {
        $time = (new DateTimeImmutable($match['match_date']))->setTimezone(new DateTimeZone('Asia/Singapore'));
    } catch (Exception $e) { continue; }
    if ($time < $periodStart || $time >= $periodEnd) continue;
    $validScore = preg_match('/^(\d+):(\d+)_(\d+):(\d+)$/D', $match['fs_home'] ?? '', $score);
    $finished = ($match['st_fs'] ?? '') === 'Y' && ($match['st_postponed'] ?? '') === 'N';
    $htRecorded = ($match['st_hs'] ?? '') === 'Y';
    if ($validScore && $finished && $htRecorded && (int)$score[3] >= (int)$score[1] && (int)$score[4] >= (int)$score[2]
        && trim($match['home_name'] ?? '') !== '' && trim($match['away_name'] ?? '') !== '') {
        $saveMatches[] = [
            'match_time'=>$time->format('Y-m-d H:i:s'),
            'home_team'=>preg_replace('/\s+/', ' ', trim($match['home_name'])),
            'away_team'=>preg_replace('/\s+/', ' ', trim($match['away_name'])),
            'league'=>$match['event_name'],
            'source_utc_offset'=>'+08:00',
            'fh_home'=>(int)$score[1], 'fh_away'=>(int)$score[2],
            'ft_home'=>(int)$score[3], 'ft_away'=>(int)$score[4]
        ];
    }
    $h2h = $h2hStats[($match['event_name'] ?? '').'|'.patternH2hKey($match['home_name'] ?? '',$match['away_name'] ?? '')] ?? null;
    if ($h2h) {
        $h2h = ['recentMeetings'=>patternH2hRecentBefore($h2h,$time->format('c'),5), 'profile'=>patternH2hProfile($h2h,$time->format('c'))];
    }
    $rows[] = [
        'h2h'=>$h2h,
        'time'=>$time->format('c'),
        'home'=>trim(preg_replace('/\s*\(V\)/i', '', $match['home_name'] ?? '')),
        'away'=>trim(preg_replace('/\s*\(V\)/i', '', $match['away_name'] ?? '')),
        'status'=>($match['st_postponed'] ?? '') === 'Y' ? 'Ditunda' : ($finished ? 'Selesai' : 'Belum selesai'),
        'ht'=>$validScore && $htRecorded ? $score[1].'–'.$score[2] : null,
        'ft'=>$validScore && $finished ? $score[3].'–'.$score[4] : null,
    ];
}
usort($rows, static function($a,$b) { return strcmp($b['time'], $a['time']); });
$times = [];
foreach ([$previousFile, $file] as $snapshotFile) if (is_file($snapshotFile)) $times[] = filemtime($snapshotFile);
echo json_encode(array_merge($period, ['date'=>$date, 'league'=>$league, 'updatedAt'=>$times ? gmdate('c', min($times)) : null,
    'snapshotFilesAvailable'=>count($times), 'snapshotFilesExpected'=>2,
    'eventId'=>$leagues[$league], 'name'=>$extraNames[$league] ?? ('League ' . $league),
    'displayName'=>$extraLabels[$league] ?? ('League ' . $league), 'apiOnly'=>isset($extraNames[$league]), 'extraCard'=>isset($extraNames[$league]) && $league !== 'vietnam15',
    'matches'=>$rows, 'saveMatches'=>$saveMatches, 'source'=>'MSports Results', 'timezone'=>'UTC+8']), JSON_UNESCAPED_UNICODE);
