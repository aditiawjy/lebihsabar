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
    $secondHalfByHt = [];
    if ($h2h) {
        $allPreviousMeetings = patternH2hRecentBefore($h2h, $time->format('c'), PHP_INT_MAX);
        $currentZeroStreak = 0; $maxZeroStreak = 0; $runZeroStreak = 0;
        $currentUnder15Streak = 0; $maxUnder15Streak = 0; $runUnder15Streak = 0;
        $currentUnder25Streak = 0; $maxUnder25Streak = 0; $runUnder25Streak = 0;
        $currentDrawStreak = 0; $maxDrawStreak = 0; $runDrawStreak = 0;
        $halfStreaks = ['ht'=>['current'=>0,'record'=>0,'run'=>0,'open'=>true], 'second'=>['current'=>0,'record'=>0,'run'=>0,'open'=>true]];
        $isDraw = static function($meeting): bool {
            return preg_match('/^(\d+)–(\d+)$/uD', $meeting['ft'] ?? '', $score) === 1 && (int)$score[1] === (int)$score[2] && ($meeting['totalGoals'] ?? null) !== null;
        };
        foreach ($allPreviousMeetings as $meeting) {
            $htKnown = isset($meeting['htHome'], $meeting['htAway'], $meeting['totalGoals']);
            $htGoals = $htKnown ? $meeting['htHome'] + $meeting['htAway'] : null;
            foreach (['ht'=>$htKnown && $htGoals === 0, 'second'=>$htKnown && $meeting['totalGoals'] - $htGoals === 0] as $half=>$zero) {
                if ($zero) {
                    $halfStreaks[$half]['run']++;
                    $halfStreaks[$half]['record'] = max($halfStreaks[$half]['record'], $halfStreaks[$half]['run']);
                    if ($halfStreaks[$half]['open']) $halfStreaks[$half]['current']++;
                } else {
                    $halfStreaks[$half]['run'] = 0;
                    $halfStreaks[$half]['open'] = false;
                }
            }
            if (($meeting['totalGoals'] ?? null) === 0) {
                $runZeroStreak++;
                $maxZeroStreak = max($maxZeroStreak, $runZeroStreak);
            } else $runZeroStreak = 0;
            if (isset($meeting['totalGoals']) && $meeting['totalGoals'] < 2) {
                $runUnder15Streak++;
                $maxUnder15Streak = max($maxUnder15Streak, $runUnder15Streak);
            } else $runUnder15Streak = 0;
            if (isset($meeting['totalGoals']) && $meeting['totalGoals'] < 3) {
                $runUnder25Streak++;
                $maxUnder25Streak = max($maxUnder25Streak, $runUnder25Streak);
            } else $runUnder25Streak = 0;
            if ($isDraw($meeting)) {
                $runDrawStreak++;
                $maxDrawStreak = max($maxDrawStreak, $runDrawStreak);
            } else $runDrawStreak = 0;
        }
        foreach ($allPreviousMeetings as $meeting) {
            if (($meeting['totalGoals'] ?? null) !== 0) break;
            $currentZeroStreak++;
        }
        $h2h['zeroStreakRecord'] = $maxZeroStreak;
        $h2h['zeroStreakCurrent'] = $currentZeroStreak;
        foreach ($allPreviousMeetings as $meeting) {
            if (!isset($meeting['totalGoals']) || $meeting['totalGoals'] >= 2) break;
            $currentUnder15Streak++;
        }
        $h2h['under15StreakRecord'] = $maxUnder15Streak;
        $h2h['under15StreakCurrent'] = $currentUnder15Streak;
        foreach ($allPreviousMeetings as $meeting) {
            if (!isset($meeting['totalGoals']) || $meeting['totalGoals'] >= 3) break;
            $currentUnder25Streak++;
        }
        $h2h['under25StreakRecord'] = $maxUnder25Streak;
        $h2h['under25StreakCurrent'] = $currentUnder25Streak;
        foreach ($allPreviousMeetings as $meeting) {
            if (!$isDraw($meeting)) break;
            $currentDrawStreak++;
        }
        $h2h['drawStreakRecord'] = $maxDrawStreak;
        $h2h['drawStreakCurrent'] = $currentDrawStreak;
        $h2h['htZeroStreak'] = ['current'=>$halfStreaks['ht']['current'], 'record'=>$halfStreaks['ht']['record']];
        $h2h['secondHalfZeroStreak'] = ['current'=>$halfStreaks['second']['current'], 'record'=>$halfStreaks['second']['record']];
        $normalizeTeam = static fn($name) => strtolower(trim(preg_replace('/\s+/', ' ', preg_replace('/\s*\(V\)/i', '', $name))));
        foreach ($h2h['meetings'] ?? [] as $meeting) {
            if ($meeting['time'] >= $time->format('c') || $meeting['htHome'] === null || $meeting['htAway'] === null || $normalizeTeam($meeting['home']) !== $normalizeTeam($match['home_name'] ?? '') || $normalizeTeam($meeting['away']) !== $normalizeTeam($match['away_name'] ?? '')) continue;
            $htKey = $meeting['htHome'].':'.$meeting['htAway'];
            if (!isset($secondHalfByHt[$htKey])) $secondHalfByHt[$htKey] = ['total'=>0,'goals'=>0];
            $secondHalfByHt[$htKey]['total']++;
            if ($meeting['totalGoals'] > $meeting['htHome'] + $meeting['htAway']) $secondHalfByHt[$htKey]['goals']++;
        }
        $h2h['lastMeeting'] = patternH2hLatestBefore($h2h,$time->format('c'));
        $h2h['recentMeetings'] = patternH2hRecentBefore($h2h,$time->format('c'),5);
        unset($h2h['meetings']);
    }
    $rows[] = [
        'secondHalfByHt'=>$secondHalfByHt,
        'h2h'=>$h2h ?: null,
        'h2hZeroStreak'=>count($h2h['recentMeetings'] ?? []) >= 3 && array_reduce(array_slice($h2h['recentMeetings'], 0, 3), static fn($all, $meeting) => $all && ($meeting['totalGoals'] ?? null) === 0, true),
        'h2hZeroStreakIncludingMatch'=>$finished && $validScore && (int)$score[3] === 0 && (int)$score[4] === 0 && count($h2h['recentMeetings'] ?? []) >= 2 && array_reduce(array_slice($h2h['recentMeetings'], 0, 2), static fn($all, $meeting) => $all && ($meeting['totalGoals'] ?? null) === 0, true),
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
if (!defined('TEAM_ZERO_HISTORY_LIBRARY')) define('TEAM_ZERO_HISTORY_LIBRARY',true);
require_once __DIR__.'/team-zero-history.php';
$snapshotFiles = glob($directory.'/msports-????-??-??.json') ?: [];
$visibleDays = array_fill_keys(array_map(static fn($row) => substr($row['time'],0,10),$rows),true);
$recordSignals = [];
$nearRecordSignals = [];
foreach (['zero','under15'] as $metric) {
    $history = teamZeroHistoryCached(__DIR__.'/matches.csv', $snapshotFiles, $metric);
    $signals = []; $near = [];
    foreach ($history['records'] as $record) {
        if ((string)$record['league'] !== (string)$league) continue;
        $key = json_encode([(string)$league,strtolower($record['name'])]);
        foreach ($history['daily'][$key] ?? [] as $day=>$count) {
            if (!isset($visibleDays[$day]) || $count <= 0 || $record['max'] <= 0) continue;
            $signal = ['team'=>$record['name'],'date'=>$day,'count'=>$count,'record'=>$record['max'],'recordDates'=>$record['dates']];
            if ($count >= $record['max']) $signals[] = $signal;
            elseif ($count / $record['max'] >= 0.8) $near[] = $signal;
        }
    }
    usort($signals,static fn($a,$b) => $b['count'] <=> $a['count'] ?: strcmp($a['team'],$b['team']));
    $recordSignals[$metric] = $signals;
    $nearRecordSignals[$metric] = $near;
}
require_once __DIR__.'/pattern-no-goal.php';
require_once __DIR__.'/pattern-under15-streak.php';
$streakLeagueNames = ['15'=>'SABA CLUB FRIENDLY Virtual PES 21 - 15 Mins Play', '16'=>'SABA INTERNATIONAL FRIENDLY Virtual FC 24 - 16 Mins Play', '20'=>'SABA INTERNATIONAL FRIENDLY Virtual PES 21 - 20 Mins Play'];
$streakLeagueName = $extraNames[$league] ?? $streakLeagueNames[$league] ?? '';
$under15StreakThresholds = $streakLeagueName === '' ? [] : patternUnder15StreakThresholds(__DIR__.'/matches.csv', $streakLeagueName, in_array($league, ['16','vietnam15'], true));
$zeroStreakThresholds = $streakLeagueName === '' ? [] : patternUnder15StreakThresholds(__DIR__.'/matches.csv', $streakLeagueName, in_array($league, ['16','vietnam15'], true), 0);
$csvUnderHistory = teamZeroHistoryCached(__DIR__.'/matches.csv', [], 'under15');
$under15Thresholds = [];
foreach ($csvUnderHistory['records'] as $record) {
    if ((string)$record['league'] === (string)$league && $record['max'] > 0) $under15Thresholds[strtolower($record['name'])] = $record['max'];
}
foreach ([$previousFile, $file] as $snapshotFile) if (is_file($snapshotFile)) $times[] = filemtime($snapshotFile);
echo json_encode(array_merge($period, ['date'=>$date, 'league'=>$league, 'updatedAt'=>$times ? gmdate('c', min($times)) : null,
    'snapshotFilesAvailable'=>count($times), 'snapshotFilesExpected'=>2,
    'zeroRecordSignals'=>$recordSignals['zero'],
    'under15RecordSignals'=>$recordSignals['under15'],
    'zeroNearRecordSignals'=>$nearRecordSignals['zero'], 'under15NearRecordSignals'=>$nearRecordSignals['under15'],
    'noGoalThresholds'=>in_array($league,['15','16','20'],true)?patternNoGoalThresholds(null,$league):null,
    'under15Thresholds'=>$under15Thresholds,
    'under15StreakThresholds'=>$under15StreakThresholds,
    'historyLeagueName'=>$streakLeagueName,
    'zeroStreakThresholds'=>$zeroStreakThresholds,
    'eventId'=>$leagues[$league], 'name'=>$extraNames[$league] ?? ('League ' . $league),
    'displayName'=>$extraLabels[$league] ?? ('League ' . $league), 'apiOnly'=>isset($extraNames[$league]), 'extraCard'=>isset($extraNames[$league]) && $league !== 'vietnam15',
    'matches'=>$rows, 'saveMatches'=>$saveMatches, 'source'=>'MSports Results', 'timezone'=>'UTC+8']), JSON_UNESCAPED_UNICODE);
