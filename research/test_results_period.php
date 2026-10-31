<?php
require __DIR__ . '/../pattern-results-period.php';
function checkPeriod($date, $league, $now, $start, $end) {
    $period = patternResultsPeriod($date, $league, new DateTimeImmutable($now));
    if ($period['periodStart'] !== $start || $period['periodEnd'] !== $end) throw new Exception(json_encode($period));
    return $period;
}
$previous = checkPeriod('2026-10-18','16','2026-10-18T11:59:59+08:00','2026-10-17T12:00:00+08:00','2026-10-18T00:46:00+08:00');
checkPeriod('2026-10-18','16','2026-10-18T12:00:00+08:00','2026-10-18T12:00:00+08:00','2026-10-19T00:46:00+08:00');
checkPeriod('2026-10-18','16','2026-10-18T00:30:00+08:00','2026-10-17T12:00:00+08:00','2026-10-18T00:46:00+08:00');
checkPeriod('2026-10-17','16','2026-10-18T13:00:00+08:00','2026-10-17T12:00:00+08:00','2026-10-18T00:46:00+08:00');
foreach (['15','20'] as $league) checkPeriod('2026-10-18',$league,'2026-10-18T00:30:00+08:00','2026-10-18T00:00:00+08:00','2026-10-19T00:00:00+08:00');
$start = new DateTimeImmutable($previous['periodStart']); $end = new DateTimeImmutable($previous['periodEnd']);
foreach (['2026-10-17T12:00:00+08:00'=>true,'2026-10-18T00:00:00+08:00'=>true,'2026-10-18T00:45:59+08:00'=>true,'2026-10-18T00:46:00+08:00'=>false,'2026-10-17T11:59:59+08:00'=>false] as $time=>$expected) {
    $time = new DateTimeImmutable($time);
    if (($time >= $start && $time < $end) !== $expected) throw new Exception('Boundary filtering failed');
}
echo "L16 complete session, next-day 00:45, pre-noon fallback, noon rollover and L15/L20 calendar boundaries passed.\n";
