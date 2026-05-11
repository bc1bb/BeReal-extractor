<?php
declare(strict_types=1);
require_once __DIR__ . '/_lib.php';

/**
 * Pure aggregation over the unified post stream.
 * Returns a single associative array consumed by index.php.
 */
function compute_stats(): array {
    $stream = unified_stream();
    if (!$stream) {
        return ['empty' => true];
    }

    $first = $stream[0]['takenAt'];
    $last  = end($stream)['takenAt'];
    reset($stream);

    $byMonth = [];
    $byHour  = array_fill(0, 24, 0);
    $byDow   = array_fill(0, 7, 0);
    $retakes = [];
    $late = 0; $onTime = 0; $lateUnknown = 0;
    $delaysSec = [];
    $uniqueDays = [];
    $tz = display_tz();

    foreach ($stream as $p) {
        if (!$p['takenAt']) continue;
        try {
            $dt = new DateTime($p['takenAt']);
            $dt->setTimezone($tz);
        } catch (Exception $e) {
            continue;
        }
        $byMonth[$dt->format('Y-m')] = ($byMonth[$dt->format('Y-m')] ?? 0) + 1;
        $byHour[(int)$dt->format('G')]++;
        $byDow[(int)$dt->format('w')]++;
        $uniqueDays[$dt->format('Y-m-d')] = true;
        $r = (int)($p['retakes'] ?? 0);
        $retakes[$r] = ($retakes[$r] ?? 0) + 1;
        if ($p['isLate'] === true)   $late++;
        elseif ($p['isLate'] === false) $onTime++;
        else                            $lateUnknown++;

        if (!empty($p['berealMoment'])) {
            try {
                $bm = new DateTime($p['berealMoment']);
                $delta = $dt->getTimestamp() - $bm->getTimestamp();
                if ($delta >= 0 && $delta < 86400) $delaysSec[] = $delta;
            } catch (Exception $e) {
                // skip malformed
            }
        }
    }

    ksort($byMonth);
    $totalPosts = count($stream);

    try {
        $firstDt = (new DateTime((string)$first))->setTimezone($tz);
        $lastDt  = (new DateTime((string)$last))->setTimezone($tz);
        $spanDays = max(1, (int)$firstDt->diff($lastDt)->days + 1);
    } catch (Exception $e) {
        $spanDays = max(1, count($uniqueDays));
    }
    $activeDays = count($uniqueDays);

    sort($delaysSec);
    $median = $delaysSec ? $delaysSec[(int)floor(count($delaysSec) / 2)] : 0;
    $avg    = $delaysSec ? array_sum($delaysSec) / count($delaysSec)    : 0;

    // Photo-analysis cache (analyze.py output) — optional.
    $cache = cache_data();
    $dark = 0; $faces = 0; $withFace = 0; $totalAnalyzed = 0; $postPhotos = 0;
    foreach ($cache as $rel => $m) {
        $totalAnalyzed++;
        if (strpos($rel, 'Photos/post/') === 0) {
            $postPhotos++;
            if (!empty($m['dark'])) $dark++;
            $fc = isset($m['faces']) ? count($m['faces']) : 0;
            $faces += $fc;
            if ($fc > 0) $withFace++;
        }
    }

    // Coarse location clustering — round to ~1 km grid.
    $locs = [];
    foreach ($stream as $p) {
        if (!empty($p['location'])) {
            $k = sprintf('%.2f,%.2f', $p['location']['latitude'], $p['location']['longitude']);
            $locs[$k] = ($locs[$k] ?? 0) + 1;
        }
    }
    arsort($locs);
    $topLocs = array_slice($locs, 0, 8, true);

    return [
        'empty'          => false,
        'first'          => $first,
        'last'           => $last,
        'total'          => $totalPosts,
        'spanDays'       => $spanDays,
        'activeDays'     => $activeDays,
        'byMonth'        => $byMonth,
        'byHour'         => $byHour,
        'byDow'          => $byDow,
        'retakes'        => $retakes,
        'late'           => $late,
        'onTime'         => $onTime,
        'medianDelaySec' => $median,
        'avgDelaySec'    => $avg,
        'photoCache' => [
            'analyzed'   => $totalAnalyzed,
            'post'       => $postPhotos,
            'dark'       => $dark,
            'withFace'   => $withFace,
            'totalFaces' => $faces,
        ],
        'topLocations' => $topLocs,
        'locations'    => $locs,
    ];
}
