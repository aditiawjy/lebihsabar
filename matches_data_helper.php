<?php

function sabarajaDataConnectionReady($conn, string $dbError = ''): bool
{
    return $conn instanceof mysqli && $dbError === '';
}

function sabarajaDataCsvPath(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . 'matches.csv';
}

function sabarajaDataCsvAvailable(): bool
{
    return is_readable(sabarajaDataCsvPath());
}

function sabarajaDataNormalizeInt($value): ?int
{
    if ($value === '' || $value === null) {
        return null;
    }

    return is_numeric($value) ? (int) $value : null;
}

function sabarajaDataNormalizeTime(string $value, string $fallback): string
{
    return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : $fallback;
}

function sabarajaDataTimeInRange(string $time, string $from, string $to): bool
{
    if ($from <= $to) {
        return $time >= $from && $time <= $to;
    }

    return $time >= $from || $time <= $to;
}

function sabarajaDataReadCsv(callable $onRow): bool
{
    $csvPath = sabarajaDataCsvPath();
    if (!is_readable($csvPath) || ($handle = fopen($csvPath, 'r')) === false) {
        return false;
    }

    $headers = fgetcsv($handle);
    if (!is_array($headers)) {
        fclose($handle);
        return false;
    }

    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) !== count($headers)) {
            continue;
        }

        $raw = array_combine($headers, $row);
        if ($raw === false) {
            continue;
        }

        $match = [
            'id' => sabarajaDataNormalizeInt($raw['id'] ?? null),
            'match_time' => (string) ($raw['match_time'] ?? ''),
            'home_team' => trim((string) ($raw['home_team'] ?? '')),
            'away_team' => trim((string) ($raw['away_team'] ?? '')),
            'league' => trim((string) ($raw['league'] ?? '')),
            'fh_home' => sabarajaDataNormalizeInt($raw['fh_home'] ?? null),
            'fh_away' => sabarajaDataNormalizeInt($raw['fh_away'] ?? null),
            'ft_home' => sabarajaDataNormalizeInt($raw['ft_home'] ?? null),
            'ft_away' => sabarajaDataNormalizeInt($raw['ft_away'] ?? null),
            'created_at' => (string) ($raw['created_at'] ?? ''),
            'updated_at' => (string) ($raw['updated_at'] ?? ''),
        ];

        if ($match['match_time'] === '' || $match['home_team'] === '' || $match['away_team'] === '') {
            continue;
        }

        $onRow($match);
    }

    fclose($handle);
    return true;
}

function sabarajaDataFormatNumber($value): string
{
    if ($value === null || $value === '') {
        return '--';
    }

    return number_format((float) $value, 0, ',', '.');
}

function sabarajaDataFormatDateLabel($value): string
{
    if (!$value) {
        return 'Belum ada update';
    }

    try {
        $date = new DateTime($value);
        return $date->format('d M Y, H:i');
    } catch (Exception $exception) {
        return 'Format tanggal tidak valid';
    }
}

function sabarajaDataFormatRelativeLabel($value): string
{
    if (!$value) {
        return 'Menunggu data masuk';
    }

    try {
        $date = new DateTime($value);
        $now = new DateTime();
        $diff = $now->getTimestamp() - $date->getTimestamp();

        if ($diff < 0) {
            return 'Data terjadwal';
        }
        if ($diff < 3600) {
            return max(1, (int) floor($diff / 60)) . ' menit lalu';
        }
        if ($diff < 86400) {
            return max(1, (int) floor($diff / 3600)) . ' jam lalu';
        }

        return max(1, (int) floor($diff / 86400)) . ' hari lalu';
    } catch (Exception $exception) {
        return 'Waktu tidak tersedia';
    }
}

function sabarajaDataFetchScalar($conn, string $sql, string $field)
{
    $result = $conn->query($sql);
    if (!$result) {
        return null;
    }

    $row = $result->fetch_assoc();
    return is_array($row) && array_key_exists($field, $row) ? $row[$field] : null;
}

function sabarajaDataDefaultSummaryPayload(): array
{
    return [
        'dataSource' => 'unavailable',
        'summaryStatusLabel' => 'Snapshot database belum tersedia',
        'summaryUpdatedLabel' => 'Cek koneksi database atau file CSV untuk memuat statistik',
        'cards' => [
            'totalMatches' => [
                'value' => '--',
                'detail' => 'Snapshot data belum tersedia',
            ],
            'totalLeagues' => [
                'value' => '--',
                'detail' => 'Kompetisi unik yang tersimpan',
            ],
            'totalClubs' => [
                'value' => '--',
                'detail' => 'Gabungan home dan away team unik',
            ],
            'over25Ratio' => [
                'value' => '--',
                'detail' => 'Persentase match finish di atas 2.5 gol',
            ],
        ],
    ];
}

function sabarajaDataBuildSummaryPayload($conn, string $dbError = ''): array
{
    $payload = sabarajaDataDefaultSummaryPayload();

    if (sabarajaDataConnectionReady($conn, $dbError)) {
        $totalMatches = (int) (sabarajaDataFetchScalar($conn, 'SELECT COUNT(*) AS total FROM matches', 'total') ?: 0);
        $totalLeagues = (int) (sabarajaDataFetchScalar($conn, "SELECT COUNT(DISTINCT league) AS total FROM matches WHERE league IS NOT NULL AND league <> ''", 'total') ?: 0);
        $totalClubs = (int) (sabarajaDataFetchScalar(
            $conn,
            "SELECT COUNT(*) AS total FROM (SELECT home_team AS team FROM matches WHERE home_team IS NOT NULL AND home_team <> '' UNION SELECT away_team AS team FROM matches WHERE away_team IS NOT NULL AND away_team <> '') AS unique_teams",
            'total'
        ) ?: 0);
        $over25Matches = (int) (sabarajaDataFetchScalar($conn, 'SELECT COUNT(*) AS total FROM matches WHERE COALESCE(ft_home, 0) + COALESCE(ft_away, 0) > 2', 'total') ?: 0);
        $lastUpdatedAt = sabarajaDataFetchScalar($conn, 'SELECT MAX(updated_at) AS last_updated FROM matches', 'last_updated');

        $over25Ratio = $totalMatches > 0 ? round(($over25Matches / $totalMatches) * 100) : null;
        $payload['dataSource'] = 'database';
        $payload['cards']['totalMatches'] = [
            'value' => sabarajaDataFormatNumber($totalMatches),
            'detail' => $totalMatches > 0 ? 'Semua match yang tersimpan di database' : 'Belum ada match yang tersimpan',
        ];
        $payload['cards']['totalLeagues'] = [
            'value' => sabarajaDataFormatNumber($totalLeagues),
            'detail' => $totalLeagues > 0 ? 'Kompetisi dengan data aktif di sistem' : 'Belum ada liga aktif',
        ];
        $payload['cards']['totalClubs'] = [
            'value' => sabarajaDataFormatNumber($totalClubs),
            'detail' => $totalClubs > 0 ? 'Tim unik yang pernah tampil di database' : 'Belum ada klub yang tercatat',
        ];
        $payload['cards']['over25Ratio'] = [
            'value' => $over25Ratio === null ? '--' : $over25Ratio . '%',
            'detail' => $totalMatches > 0
                ? sabarajaDataFormatNumber($over25Matches) . ' dari ' . sabarajaDataFormatNumber($totalMatches) . ' match berakhir over 2.5'
                : 'Belum ada sampel untuk menghitung rasio',
        ];
        $payload['summaryStatusLabel'] = $totalMatches > 0
            ? 'Database aktif dan statistik berhasil dimuat'
            : 'Database aktif, tetapi isi tabel masih kosong';
        $payload['summaryUpdatedLabel'] = $lastUpdatedAt
            ? 'Update terakhir ' . sabarajaDataFormatDateLabel($lastUpdatedAt) . ' (' . sabarajaDataFormatRelativeLabel($lastUpdatedAt) . ')'
            : 'Belum ada timestamp update yang tersedia';

        return $payload;
    }

    $csvAvailable = sabarajaDataCsvAvailable();
    if ($csvAvailable) {
        $totalMatches = 0;
        $over25Matches = 0;
        $leagues = [];
        $clubs = [];
        $lastUpdatedAt = null;

        sabarajaDataReadCsv(function (array $match) use (&$totalMatches, &$over25Matches, &$leagues, &$clubs, &$lastUpdatedAt): void {
            $totalMatches++;

            if ($match['league'] !== '') {
                $leagues[$match['league']] = true;
            }
            if ($match['home_team'] !== '') {
                $clubs[$match['home_team']] = true;
            }
            if ($match['away_team'] !== '') {
                $clubs[$match['away_team']] = true;
            }

            $totalGoals = ($match['ft_home'] ?? 0) + ($match['ft_away'] ?? 0);
            if ($match['ft_home'] !== null && $match['ft_away'] !== null && $totalGoals > 2) {
                $over25Matches++;
            }

            foreach (['updated_at', 'created_at', 'match_time'] as $timeField) {
                $candidate = $match[$timeField] ?? '';
                if ($candidate !== '' && ($lastUpdatedAt === null || $candidate > $lastUpdatedAt)) {
                    $lastUpdatedAt = $candidate;
                }
            }
        });

        $totalLeagues = count($leagues);
        $totalClubs = count($clubs);
        $over25Ratio = $totalMatches > 0 ? round(($over25Matches / $totalMatches) * 100) : null;

        $payload['dataSource'] = 'csv';
        $payload['cards']['totalMatches'] = [
            'value' => sabarajaDataFormatNumber($totalMatches),
            'detail' => $totalMatches > 0 ? 'Snapshot diambil dari file matches.csv' : 'CSV aktif, tetapi belum ada match tersimpan',
        ];
        $payload['cards']['totalLeagues'] = [
            'value' => sabarajaDataFormatNumber($totalLeagues),
            'detail' => $totalLeagues > 0 ? 'Kompetisi unik yang terbaca dari CSV' : 'Belum ada liga pada CSV',
        ];
        $payload['cards']['totalClubs'] = [
            'value' => sabarajaDataFormatNumber($totalClubs),
            'detail' => $totalClubs > 0 ? 'Tim unik yang terbaca dari file CSV' : 'Belum ada klub pada CSV',
        ];
        $payload['cards']['over25Ratio'] = [
            'value' => $over25Ratio === null ? '--' : $over25Ratio . '%',
            'detail' => $totalMatches > 0
                ? sabarajaDataFormatNumber($over25Matches) . ' dari ' . sabarajaDataFormatNumber($totalMatches) . ' match CSV berakhir over 2.5'
                : 'Belum ada sampel untuk menghitung rasio',
        ];
        $payload['summaryStatusLabel'] = 'Mode CSV aktif, statistik dimuat tanpa koneksi database';
        $payload['summaryUpdatedLabel'] = $lastUpdatedAt
            ? 'Snapshot CSV terakhir ' . sabarajaDataFormatDateLabel($lastUpdatedAt) . ' (' . sabarajaDataFormatRelativeLabel($lastUpdatedAt) . ')'
            : 'File CSV terbaca, tetapi timestamp belum tersedia';

        return $payload;
    }

    if ($dbError !== '') {
        $payload['summaryStatusLabel'] = 'Koneksi database gagal dimuat';
        $payload['summaryUpdatedLabel'] = $dbError;
    } else {
        $payload['summaryStatusLabel'] = 'Sumber data belum tersedia';
        $payload['summaryUpdatedLabel'] = 'Database tidak aktif dan file matches.csv tidak ditemukan.';
    }

    return $payload;
}

function sabarajaDataLoadMatchRows($conn, string $dbError = '', array $options = []): array
{
    $today = date('Y-m-d');
    $dateFrom = $options['date_from'] ?? $today;
    $dateTo = $options['date_to'] ?? $today;
    $timeFrom = sabarajaDataNormalizeTime((string) ($options['time_from'] ?? '00:00'), '00:00');
    $timeTo = sabarajaDataNormalizeTime((string) ($options['time_to'] ?? '23:59'), '23:59');
    $league = trim((string) ($options['league'] ?? ''));
    $limit = max(0, (int) ($options['limit'] ?? 0));
    $requireFt = !empty($options['require_ft']);

    $rows = [];

    if (sabarajaDataConnectionReady($conn, $dbError)) {
        $dateFromSql = $conn->real_escape_string($dateFrom);
        $dateToSql = $conn->real_escape_string($dateTo);
        $timeFromSql = $conn->real_escape_string($timeFrom . ':00');
        $timeToSql = $conn->real_escape_string($timeTo . ':59');
        $isOvernight = $timeFrom > $timeTo;

        $where = " WHERE DATE(match_time) >= '$dateFromSql' AND DATE(match_time) <= '$dateToSql'";
        if ($requireFt) {
            $where .= ' AND ft_home IS NOT NULL AND ft_away IS NOT NULL';
        }
        if ($isOvernight) {
            $where .= " AND (TIME(match_time) >= '$timeFromSql' OR TIME(match_time) <= '$timeToSql')";
        } else {
            $where .= " AND (TIME(match_time) >= '$timeFromSql' AND TIME(match_time) <= '$timeToSql')";
        }
        if ($league !== '') {
            $leagueSql = $conn->real_escape_string($league);
            $where .= " AND league = '$leagueSql'";
        }

        $limitClause = $limit > 0 ? ' LIMIT ' . $limit : '';
        $query = "SELECT id, match_time, home_team, away_team, league, fh_home, fh_away, ft_home, ft_away, created_at, updated_at FROM matches $where ORDER BY match_time ASC$limitClause";
        $result = $conn->query($query);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $row['id'] = sabarajaDataNormalizeInt($row['id'] ?? null);
                $row['fh_home'] = sabarajaDataNormalizeInt($row['fh_home'] ?? null);
                $row['fh_away'] = sabarajaDataNormalizeInt($row['fh_away'] ?? null);
                $row['ft_home'] = sabarajaDataNormalizeInt($row['ft_home'] ?? null);
                $row['ft_away'] = sabarajaDataNormalizeInt($row['ft_away'] ?? null);
                $rows[] = $row;
            }
        }

        return $rows;
    }

    if (!sabarajaDataCsvAvailable()) {
        return [];
    }

    sabarajaDataReadCsv(function (array $match) use (&$rows, $dateFrom, $dateTo, $timeFrom, $timeTo, $league, $limit, $requireFt): void {
        $matchDate = substr($match['match_time'], 0, 10);
        $matchTime = substr($match['match_time'], 11, 5);

        if ($matchDate < $dateFrom || $matchDate > $dateTo) {
            return;
        }
        if ($league !== '' && ($match['league'] ?? '') !== $league) {
            return;
        }
        if ($matchTime !== '' && !sabarajaDataTimeInRange($matchTime, $timeFrom, $timeTo)) {
            return;
        }
        if ($requireFt && ($match['ft_home'] === null || $match['ft_away'] === null)) {
            return;
        }

        $rows[] = $match;
        if ($limit > 0 && count($rows) >= $limit) {
            return;
        }
    });

    if ($limit > 0 && count($rows) > $limit) {
        $rows = array_slice($rows, 0, $limit);
    }

    return $rows;
}
