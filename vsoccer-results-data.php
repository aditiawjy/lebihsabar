<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function vsoccerParseResults(string $html, string $date): array {
    $previous = libxml_use_internal_errors(true);
    try {
        $doc = new DOMDocument();
        if (!$doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) throw new RuntimeException('Format halaman Results tidak dapat dibaca.');
        $xpath = new DOMXPath($doc);
        $table = $xpath->query('//*[@id="tblResults"]')->item(0);
        if (!$table || !preg_match('/Game\s*time\s*\(UTC\s*7\)/i', $table->textContent)) throw new RuntimeException('Format atau zona waktu Results berubah.');
        $rows = [];
        foreach ($xpath->query('.//tr[starts-with(@id,"l_")]', $table) as $tr) {
            if (!preg_match('/^l_(\d+)_(\d+)$/D', $tr->getAttribute('id'), $ids)) continue;
            $cells = $xpath->query('./td', $tr);
            if ($cells->length < 5) continue;
            $clean = static fn($text) => trim(preg_replace('/\s+/u', ' ', $text));
            $time = $clean($cells->item(0)->textContent);
            $league = $clean($cells->item(1)->textContent);
            $teams = preg_split('/\s+vs\.?\s+/iu', $clean($cells->item(2)->textContent), 2);
            $homeScore = $clean($cells->item(3)->textContent); $awayScore = $clean($cells->item(4)->textContent);
            if (!preg_match('/^'.preg_quote($date,'/').' (?:[01]\d|2[0-3]):[0-5]\d$/D', $time) || stripos($league,'V-Soccer ') !== 0 || count($teams) !== 2 || !ctype_digit($homeScore) || !ctype_digit($awayScore)) continue;
            $details = $xpath->query('./following-sibling::tr[@id="details_'.$ids[1].'_'.$ids[2].'"]', $tr)->item(0);
            $ht = null;
            if ($details) foreach ($xpath->query('.//tr', $details) as $detailRow) {
                $detailCells = $xpath->query('./td', $detailRow);
                if ($detailCells->length !== 3 || strcasecmp($clean($detailCells->item(1)->textContent),'1st half') !== 0) continue;
                $homeHt = $clean($detailCells->item(0)->textContent); $awayHt = $clean($detailCells->item(2)->textContent);
                if (ctype_digit($homeHt) && ctype_digit($awayHt) && (int)$homeHt <= (int)$homeScore && (int)$awayHt <= (int)$awayScore) $ht = (int)$homeHt.'–'.(int)$awayHt;
                break;
            }
            $rows[$ids[2]] = ['id'=>$ids[2], 'leagueId'=>$ids[1], 'league'=>$league, 'time'=>$time,
                'home'=>$clean($teams[0]), 'away'=>$clean($teams[1]), 'ht'=>$ht, 'ft'=>(int)$homeScore.'–'.(int)$awayScore];
        }
        $rows = array_values($rows);
        usort($rows, static fn($a,$b)=>strcmp($b['time'],$a['time']) ?: strcmp($a['league'],$b['league']));
        return $rows;
    } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
}
function vsoccerSaveMatches(array $matches): array {
    $rows = [];
    foreach ($matches as $match) {
        if (!preg_match('/^(\d+)–(\d+)$/uD', $match['ft'] ?? '', $ft)) continue;
        $ht = [];
        if (!preg_match('/^(\d+)–(\d+)$/uD', $match['ht'] ?? '', $ht)) $ht = [];
        $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $match['time'] ?? '', new DateTimeZone('Asia/Jakarta'));
        if (!$time || trim($match['home'] ?? '') === '' || trim($match['away'] ?? '') === '' || trim($match['league'] ?? '') === '') continue;
        $rows[] = [
            'match_time'=>$time->setTimezone(new DateTimeZone('Asia/Singapore'))->format('Y-m-d H:i:s'),
            'home_team'=>$match['home'], 'away_team'=>$match['away'], 'league'=>$match['league'],
            'fh_home'=>$ht ? (int)$ht[1] : null, 'fh_away'=>$ht ? (int)$ht[2] : null,
            'ft_home'=>(int)$ft[1], 'ft_away'=>(int)$ft[2],
            'source_utc_offset'=>'+08:00', 'preserve_missing_ht'=>true,
        ];
    }
    return $rows;
}
if (defined('VSOCCER_RESULTS_LIBRARY')) return;

$date = $_GET['date'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || !checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4))) {
    http_response_code(400); echo json_encode(['error'=>'Tanggal tidak valid.']); exit;
}
$cache = sys_get_temp_dir().'/sabaraja-vsoccer-results-'.md5(__DIR__.'|'.$date).'.json';
$cached = is_file($cache) ? json_decode(file_get_contents($cache), true) : null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $query = http_build_query(['ns'=>'prod20191-101527338.1x2aaa.com','locale'=>'en','sportId'=>'234','theDate'=>$date,'tzOffset'=>'7','swapSports'=>'']);
    $curl = curl_init('https://results.bsportsasia.com/?'.$query);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>30,
        CURLOPT_FOLLOWLOCATION=>false, CURLOPT_USERAGENT=>'Mozilla/5.0', CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
    $html = curl_exec($curl); $status = (int)curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl);
    if ($html === false || $status !== 200) { http_response_code(502); echo json_encode(['error'=>'Sumber Results V-Soccer tidak dapat dihubungi.']); exit; }
    try { $matches = vsoccerParseResults($html,$date); }
    catch (Throwable $error) { http_response_code(502); echo json_encode(['error'=>$error->getMessage()],JSON_UNESCAPED_UNICODE); exit; }
    $cached = ['date'=>$date,'updatedAt'=>gmdate('c'),'timezone'=>'UTC+7 / WIB','source'=>'Results V-Soccer','matches'=>$matches];
    $tmp = tempnam(sys_get_temp_dir(),'vsoccer-results-');
    if ($tmp !== false) { file_put_contents($tmp,json_encode($cached,JSON_UNESCAPED_UNICODE),LOCK_EX); if (!@rename($tmp,$cache)) @unlink($tmp); }
}
$result = $cached ?: ['date'=>$date,'updatedAt'=>null,'timezone'=>'UTC+7 / WIB','source'=>'Results V-Soccer','matches'=>[]];
$result['saveMatches'] = vsoccerSaveMatches($result['matches'] ?? []);
echo json_encode($result,JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
