<?php
function patternResultsPeriod(string $date, string $league, ?DateTimeImmutable $now = null): array {
    $zone = new DateTimeZone('Asia/Singapore');
    $now = ($now ?? new DateTimeImmutable('now', $zone))->setTimezone($zone);
    $day = new DateTimeImmutable($date . ' 00:00:00', $zone);
    $session = in_array($league, ['16','vietnam15'], true);
    // Before the noon opening, today's calendar view shows the last League 16 / Vietnam session.
    if ($session && $date === $now->format('Y-m-d') && (int)$now->format('H') < 12) $day = $day->modify('-1 day');
    $start = $session ? $day->setTime(12, 0) : $day;
    $last = $session ? $day->modify('+1 day')->setTime(0, 45, 59) : $day->setTime(23, 59, 59);
    return ['sessionDate'=>$session ? $day->format('Y-m-d') : null,
        'periodStart'=>$start->format('c'), 'periodEnd'=>$last->modify('+1 second')->format('c'),
        'periodLabel'=>($session ? 'Sesi ' : '') . $start->format('d/m/Y H:i') . ' – ' . $last->format('d/m/Y H:i') . ' UTC+8'];
}
