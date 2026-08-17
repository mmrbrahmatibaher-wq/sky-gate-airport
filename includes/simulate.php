<?php
declare(strict_types=1);

/**
 * Simulation helpers for ATL ops dashboard.
 * Clock helpers (atl_tz / atl_now / atl_now_ts / atl_now_str / atl_parse_ts) live in bootstrap.php
 * and MUST NOT be redeclared here (would fatal on every tick).
 *
 * Tick advances flight phases from Atlanta wall-clock and keeps gate occupancy high:
 * quietest → max 2 free gates per hall; otherwise 1 free; always ≥1 free; free gates remain assignable.
 * Gate fill is ORDERLY: max 2 new occupations per terminal per tick, ordered by scheduled_time
 * (no sudden mass assignment when density or time-of-day changes).
 */

/**
 * Simulation tick — advances flight phases and burns realistic fuel on departures.
 * Fuel: uplift from tanks; prefer tanks >10%; alert below 20%; spill over to next tank.
 */

function flight_phase_chain(string $type): array
{
    if ($type === 'arr') {
        return ['Scheduled', 'On Time', 'Landing', 'Taxi to Gate', 'Deboarding', 'Cleaning', 'Ready at Gate', 'Arrived'];
    }
    return ['Scheduled', 'On Time', 'Boarding', 'Final Call', 'Pushback', 'Taxi to Runway', 'Takeoff', 'Departed'];
}

function next_status(string $type, string $current): ?string
{
    $chain = flight_phase_chain($type);
    $i = array_search($current, $chain, true);
    if ($i === false) {
        return $chain[0];
    }
    if ($i >= count($chain) - 1) {
        return null;
    }
    return $chain[$i + 1];
}

function phase_duration_sec(string $status): int
{
    // Scaled sim times: Deboarding ≈ 10 min gate hold, Cleaning short, then free for next flight
    $map = [
        'Scheduled' => 40, 'On Time' => 35, 'Boarding' => 55, 'Final Call' => 22,
        'Pushback' => 18, 'Taxi to Runway' => 25, 'Takeoff' => 12, 'Departed' => 9999,
        'Landing' => 15, 'Taxi to Gate' => 28, 'Deboarding' => 600, 'Cleaning' => 180,
        'Ready at Gate' => 20, 'Arrived' => 9999, 'Delayed' => 45,
    ];
    return $map[$status] ?? 30;
}

function jet_a_kg_to_gal(float $kg): float
{
    return $kg / (0.804 * 3.78541);
}

function sim_push_fuel_alerts(PDO $pdo): void
{
    // Notify when any tank hits 40% or below (fuel running low) — throttle per tank ~30 min
    $tanks = $pdo->query('SELECT id, name, level_pct, low_threshold_pct FROM fuel_tanks ORDER BY level_pct ASC')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tanks as $t) {
        $pct = (int)$t['level_pct'];
        if ($pct > 40) {
            continue;
        }
        $title = $pct <= 20 ? 'Critical fuel level' : 'Fuel running low';
        $type = $pct <= 20 ? 'danger' : 'warning';
        $critical = $pct <= 20 ? 1 : 0;
        $msg = $t['name'] . ' is at ' . $pct . '% — fuel is running low. Pipeline top-up recommended.';
        try {
            // Anti-spam: skip if same tank warned in last 30 minutes
            $chk = $pdo->prepare("SELECT id FROM notifications WHERE title=? AND message LIKE ? AND created_at > " . sql_dt_minus(sql_now(), 30) . " LIMIT 1");
            $chk->execute([$title, $t['name'] . '%']);
            if ($chk->fetchColumn()) {
                continue;
            }
            $pdo->prepare("INSERT INTO notifications (type, title, message, is_read, is_critical, created_at) VALUES (?, ?, ?, 0, ?, " . sql_now() . ")")
                ->execute([$type, $title, $msg, $critical]);
        } catch (Throwable $e) {
            // ignore
        }
    }
}

/**
 * Deduct fuel. Prefer tanks with level > 10%. When a tank would fall below 10%,
 * take what is available above 10% then continue on the next tank.
 */
function sim_uplift_fuel_for_flight(PDO $pdo, array $flight): void
{
    if (($flight['type'] ?? '') !== 'dep') {
        return;
    }
    $maxKg = 0.0;
    if (!empty($flight['aircraft_id'])) {
        $st = $pdo->prepare('SELECT max_fuel_kg FROM aircraft WHERE id = ?');
        $st->execute([(int)$flight['aircraft_id']]);
        $maxKg = (float)$st->fetchColumn();
    }
    if ($maxKg <= 0) {
        $maxKg = 20000;
    }
    $fraction = $maxKg >= 70000 ? 0.68 : ($maxKg >= 40000 ? 0.58 : 0.52);
    $remaining = jet_a_kg_to_gal($maxKg * $fraction);

    // Prefer tanks still above 10%, highest level first; then any remaining
    $tanks = $pdo->query("SELECT id, name, capacity_gal, level_pct FROM fuel_tanks WHERE fuel_type IN ('jet_a','saf') AND level_pct > 0 ORDER BY (level_pct > 10) DESC, level_pct DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tanks as $t) {
        if ($remaining <= 0) {
            break;
        }
        $cap = max(1.0, (float)$t['capacity_gal']);
        $level = (int)$t['level_pct'];
        $availableGal = ($cap * $level) / 100.0;
        // Keep a 10% reserve on this tank when other tanks exist — switch earlier
        $reserveGal = ($cap * 10) / 100.0;
        $usable = max(0.0, $availableGal - $reserveGal);
        if ($usable <= 0 && count($tanks) > 1) {
            // only drain into reserve if this is the last usable tank
            continue;
        }
        if ($usable <= 0) {
            $usable = $availableGal;
        }
        $take = min($usable, $remaining);
        if ($take <= 0) {
            continue;
        }
        $newLevel = max(0, (int)round((($availableGal - $take) / $cap) * 100));
        $pdo->prepare('UPDATE fuel_tanks SET level_pct = ?, updated_at = ' . sql_now() . ' WHERE id = ?')
            ->execute([$newLevel, $t['id']]);
        $remaining -= $take;
    }
    // If still remaining, drain absolute last drops ignoring reserve
    if ($remaining > 0) {
        $tanks2 = $pdo->query('SELECT id, capacity_gal, level_pct FROM fuel_tanks WHERE level_pct > 0 ORDER BY level_pct ASC')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tanks2 as $t) {
            if ($remaining <= 0) {
                break;
            }
            $cap = max(1.0, (float)$t['capacity_gal']);
            $availableGal = ($cap * (int)$t['level_pct']) / 100.0;
            $take = min($availableGal, $remaining);
            $newLevel = max(0, (int)round((($availableGal - $take) / $cap) * 100));
            $pdo->prepare('UPDATE fuel_tanks SET level_pct = ?, updated_at = ' . sql_now() . ' WHERE id = ?')
                ->execute([$newLevel, $t['id']]);
            $remaining -= $take;
        }
    }
}


function status_from_schedule(string $type, string $scheduled, bool $intl = false, ?string $current = null): string
{
    $sched = atl_parse_ts($scheduled);
    if (!$sched) {
        return $current ?: 'Scheduled';
    }
    // Minutes relative to Atlanta now: negative = still in the future
    $mins = (atl_now_ts() - $sched) / 60.0;

    if ($type === 'dep') {
        // Anything meaningfully past scheduled departure is DONE
        if ($mins >= 15) return 'Departed';
        if ($mins >= 8) return 'Takeoff';
        if ($mins >= 2) return 'Taxi to Runway';
        if ($mins >= -3) return 'Pushback';
        if ($mins >= -10) return 'Final Call';
        if ($mins >= -($intl ? 40 : 30)) return 'Boarding';
        if ($mins >= -($intl ? 55 : 45)) return 'On Time';
        return 'Scheduled';
    }

    // arrivals
    if ($mins >= 55) return 'Arrived';
    if ($mins >= 40) return 'Ready at Gate';
    if ($mins >= 25) return 'Cleaning';
    if ($mins >= 10) return 'Deboarding';
    if ($mins >= 2) return 'Taxi to Gate';
    if ($mins >= -5) return 'Landing';
    if ($mins >= -20) return 'On Time';
    return 'Scheduled';
}

function sim_advance_flights(PDO $pdo): void
{
    $nowStr = atl_now_str('Y-m-d H:i:s');
    // 1) HARD rule: departure scheduled >15 min ago → Departed (no 9am taxi at 6pm)
    $pdo->prepare("UPDATE flights SET status='Departed', phase_started_at=" . sql_now() . ", updated_at=" . sql_now() . "
        WHERE is_tomorrow=0 AND type='dep'
          AND status NOT IN ('Departed','Cancelled')
          AND scheduled_time <= " . sql_dt_minus("?", 15) . "")->execute([$nowStr]);
    // 2) HARD rule: arrival scheduled >55 min ago → Arrived
    $pdo->prepare("UPDATE flights SET status='Arrived', phase_started_at=" . sql_now() . ", updated_at=" . sql_now() . "
        WHERE is_tomorrow=0 AND type='arr'
          AND status NOT IN ('Arrived','Cancelled')
          AND scheduled_time <= " . sql_dt_minus("?", 55) . "")->execute([$nowStr]);

    // Free gates tied to finished flights (respect min hold when possible)
    $done = $pdo->query("SELECT id, gate_id, status FROM flights
        WHERE is_tomorrow=0 AND status IN ('Departed','Arrived') AND gate_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($done as $f) {
        $gid = (int)$f['gate_id'];
        if ($gid <= 0) continue;
        if (gate_min_hold_ok($pdo, $gid)) {
            $pdo->prepare("UPDATE gates SET status='available', current_flight_id=NULL, occupied_since=NULL
                WHERE id=? AND (current_flight_id=? OR current_flight_id IS NULL)")
                ->execute([$gid, (int)$f['id']]);
        }
    }

    // 3) Active / upcoming: set status strictly from Atlanta clock
    $rows = $pdo->query("SELECT id, type, status, scheduled_time, is_international, gate_id, delay_minutes
        FROM flights
        WHERE is_tomorrow=0 AND status NOT IN ('Departed','Arrived','Cancelled')
        ORDER BY scheduled_time ASC
        LIMIT 800")->fetchAll(PDO::FETCH_ASSOC);

    $upd = $pdo->prepare('UPDATE flights SET status=?, phase_started_at=' . sql_if('status=?', 'phase_started_at', sql_now()) . ', updated_at=' . sql_now() . ' WHERE id=?');

    foreach ($rows as $f) {
        $cur = (string)($f['status'] ?? 'Scheduled');
        $intl = (int)($f['is_international'] ?? 0) === 1;
        $target = status_from_schedule(
            (string)($f['type'] ?? 'dep'),
            (string)($f['scheduled_time'] ?? ''),
            $intl,
            $cur
        );

        // Delayed only if still before active phase window; once past, force schedule status
        if ($cur === 'Delayed') {
            $mins = (atl_now_ts() - atl_parse_ts((string)$f['scheduled_time'])) / 60.0;
            if ($mins < -20) {
                continue; // stay delayed until nearer
            }
            // near/past: drop delay and follow clock
        }

        if ($target === $cur) {
            continue;
        }

        if (($f['type'] ?? '') === 'dep' && $target === 'Boarding' && $cur !== 'Boarding') {
            sim_uplift_fuel_for_flight($pdo, $f);
        }

        $upd->execute([$target, $target, (int)$f['id']]);

        if (!empty($f['gate_id']) && in_array($target, ['Boarding','Final Call','Pushback','Landing','Taxi to Gate','Deboarding','Cleaning','Ready at Gate'], true)) {
            $pdo->prepare("UPDATE gates SET status='occupied', current_flight_id=?, occupied_since=COALESCE(occupied_since, " . sql_now() . ") WHERE id=?")
                ->execute([(int)$f['id'], (int)$f['gate_id']]);
        }
        if (in_array($target, ['Departed','Arrived'], true) && !empty($f['gate_id']) && gate_min_hold_ok($pdo, (int)$f['gate_id'])) {
            $pdo->prepare("UPDATE gates SET status='available', current_flight_id=NULL, occupied_since=NULL WHERE id=? AND current_flight_id=?")
                ->execute([(int)$f['gate_id'], (int)$f['id']]);
            sim_assign_next_flight_to_gate($pdo, (int)$f['gate_id']);
        }
    }

    sim_release_stale_gates($pdo);
}



/** After gate frees, assign next scheduled flight for same terminal if waiting. */

/** Gate stays assigned at least ~45 min after opening (occupied_since) for realistic turnaround. */
function gate_min_hold_ok(PDO $pdo, int $gateId): bool
{
    if ($gateId <= 0) return true;
    $st = $pdo->prepare('SELECT occupied_since FROM gates WHERE id=?');
    $st->execute([$gateId]);
    $since = $st->fetchColumn();
    if (!$since) return true;
    return (time() - strtotime((string)$since)) >= (45 * 60);
}

function sim_assign_next_flight_to_gate(PDO $pdo, int $gateId): void
{
    if ($gateId <= 0) {
        return;
    }
    $gate = $pdo->prepare('SELECT id, code, terminal, status FROM gates WHERE id=?');
    $gate->execute([$gateId]);
    $g = $gate->fetch(PDO::FETCH_ASSOC);
    if (!$g || $g['status'] !== 'available') {
        return;
    }
    $terminal = $g['terminal'] ?? '';

    // 1) Prefer flights already assigned to THIS gate that are approaching / waiting
    $st = $pdo->prepare("SELECT id FROM flights
        WHERE is_tomorrow=0 AND gate_id=? AND status IN ('Scheduled','On Time','Landing','Taxi to Gate','Boarding')
        ORDER BY " . sql_field_order("status", ["Taxi to Gate","Landing","Boarding","On Time","Scheduled"]) . ", scheduled_time ASC
        LIMIT 1");
    $st->execute([$gateId]);
    $fid = $st->fetchColumn();

    // 2) Else: next flight at same terminal with no free gate yet (gate_id points to occupied/other or null)
    if (!$fid && $terminal !== '') {
        $st = $pdo->prepare("SELECT f.id FROM flights f
            LEFT JOIN gates g2 ON g2.id = f.gate_id
            WHERE f.is_tomorrow=0
              AND f.status IN ('Scheduled','On Time','Landing','Taxi to Gate')
              AND (f.gate_id IS NULL OR g2.status = 'available' OR g2.current_flight_id IS NULL OR g2.current_flight_id != f.id)
              AND EXISTS (
                  SELECT 1 FROM gates gx WHERE gx.id = f.gate_id AND gx.terminal = ?
              )
            ORDER BY f.scheduled_time ASC
            LIMIT 1");
        // Prefer flights whose planned gate is this one, else same terminal planned gates that are free
        $st = $pdo->prepare("SELECT f.id FROM flights f
            INNER JOIN gates fg ON fg.id = f.gate_id
            WHERE f.is_tomorrow=0
              AND fg.terminal = ?
              AND f.status IN ('Scheduled','On Time','Landing','Taxi to Gate','Boarding')
              AND (fg.current_flight_id IS NULL OR fg.current_flight_id = f.id OR fg.status = 'available')
              AND f.id NOT IN (SELECT current_flight_id FROM gates WHERE current_flight_id IS NOT NULL)
            ORDER BY CASE WHEN f.gate_id = ? THEN 0 ELSE 1 END, f.scheduled_time ASC
            LIMIT 1");
        $st->execute([$terminal, $gateId]);
        $fid = $st->fetchColumn();
    }

    // 3) Last resort: any same-terminal flight waiting without an occupied gate
    if (!$fid && $terminal !== '') {
        $st = $pdo->prepare("SELECT f.id FROM flights f
            LEFT JOIN gates fg ON fg.id = f.gate_id
            WHERE f.is_tomorrow=0
              AND f.status IN ('Landing','Taxi to Gate','On Time','Scheduled')
              AND (fg.terminal = ? OR f.gate_id IS NULL)
              AND f.id NOT IN (SELECT current_flight_id FROM gates WHERE current_flight_id IS NOT NULL)
            ORDER BY f.scheduled_time ASC
            LIMIT 1");
        $st->execute([$terminal]);
        $fid = $st->fetchColumn();
        if ($fid) {
            // Re-point flight to this free gate for max utilization
            $pdo->prepare('UPDATE flights SET gate_id=?, updated_at=' . sql_now() . ' WHERE id=?')->execute([$gateId, $fid]);
        }
    }

    if (!$fid) {
        return;
    }
    $pdo->prepare('UPDATE gates SET status=?, current_flight_id=?, occupied_since=' . sql_now() . ' WHERE id=? AND status=?')
        ->execute(['occupied', $fid, $gateId, 'available']);
}

/** Release gates whose flight already finished but gate still locked. */
function sim_release_stale_gates(PDO $pdo): void
{
    $rows = $pdo->query("SELECT g.id AS gate_id, g.current_flight_id, f.status, f.phase_started_at, f.scheduled_time
        FROM gates g
        INNER JOIN flights f ON f.id = g.current_flight_id
        WHERE g.status = 'occupied' AND f.status IN ('Arrived','Departed')")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $schedTs = atl_parse_ts((string)($r['scheduled_time'] ?? ''));
        $longPast = $schedTs && (atl_now_ts() - $schedTs) > 3600; // >1h past schedule → free even if min-hold not met
        if (!$longPast && !gate_min_hold_ok($pdo, (int)$r['gate_id'])) continue;
        $pdo->prepare('UPDATE gates SET status=?, current_flight_id=NULL, occupied_since=NULL WHERE id=?')
            ->execute(['available', $r['gate_id']]);
        sim_assign_next_flight_to_gate($pdo, (int)$r['gate_id']);
    }
    // After Cleaning lasting past hold window, treat as ready to free when flight moves to Arrived via chain
    // Extra hold: if status is Ready at Gate for > phase duration * 2, free for next
    $hold = $pdo->query("SELECT g.id AS gate_id, f.id AS flight_id, f.status, f.phase_started_at
        FROM gates g INNER JOIN flights f ON f.id = g.current_flight_id
        WHERE g.status='occupied' AND f.status='Ready at Gate'
          AND f.phase_started_at IS NOT NULL
          AND " . (is_sqlite() ? "(strftime('%s','now') - strftime('%s', f.phase_started_at))" : "TIMESTAMPDIFF(SECOND, f.phase_started_at, NOW())") . " > 50")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($hold as $r) {
        $pdo->prepare('UPDATE flights SET status=?, phase_started_at=' . sql_now() . ' WHERE id=?')
            ->execute(['Arrived', $r['flight_id']]);
        $pdo->prepare('UPDATE gates SET status=?, current_flight_id=NULL, occupied_since=NULL WHERE id=?')
            ->execute(['available', $r['gate_id']]);
        sim_assign_next_flight_to_gate($pdo, (int)$r['gate_id']);
    }
}

function sim_terminal_density(PDO $pdo): void
{
    if (!(int)$pdo->query('SELECT COUNT(*) FROM terminal_zones')->fetchColumn()) {
        return;
    }
    $pdo->exec("UPDATE terminal_zones SET
        density_pct = LEAST(98, GREATEST(5, density_pct + FLOOR(RAND()*7) - 3)),
        wait_minutes = LEAST(40, GREATEST(2, wait_minutes + FLOOR(RAND()*3) - 1)),
        pax_inside = GREATEST(0, pax_inside + FLOOR(RAND()*80) - 40),
        updated_at = " . sql_now() . "");
}

function sim_belts(PDO $pdo): void
{
    if (!(int)$pdo->query('SELECT COUNT(*) FROM bhs_belts')->fetchColumn()) {
        return;
    }
    $pdo->exec("UPDATE bhs_belts SET
        bags_on_belt = GREATEST(0, bags_on_belt + FLOOR(RAND()*10) - 5),
        status = " . sql_if("bags_on_belt > 0", "'running'", "'idle'") . "");
}


/**
 * Open / close gates based on flight clock:
 * Intl dep: open 40 min before (range 30–50). Domestic dep: 30 min. Arr: 10 min before.
 * Free after Arrived/Departed when min hold elapsed; then assign next or leave available.
 * is_reserve=1 emergency gates only if terminal has no free normal gate.
 */
function sim_gate_schedule_windows(PDO $pdo): void
{
    $sql = "SELECT f.id, f.type, f.is_international, f.scheduled_time, f.gate_id, f.status,
                   g.terminal, g.is_reserve, g.status AS gate_status
            FROM flights f
            INNER JOIN gates g ON g.id = f.gate_id
            WHERE f.is_tomorrow = 0
              AND f.status NOT IN ('Departed','Arrived','Cancelled')";
    try {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return;
    }
    $now = atl_now_ts();
    foreach ($rows as $f) {
        $sched = atl_parse_ts((string)$f['scheduled_time']);
        if (!$sched) continue;
        $mins = ($sched - $now) / 60.0;
        $isDep = ($f['type'] ?? '') === 'dep';
        $intl = (int)($f['is_international'] ?? 0) === 1;
        if ($isDep) {
            $openBefore = $intl ? 40 : 30; // intl ~30–50 mid; domestic 30
        } else {
            $openBefore = 10; // landing: gate open 10 min before on-block
        }
        // Open window: from openBefore down to after arrival processing
        $shouldOpen = ($mins <= $openBefore && $mins >= -180);
        if ($shouldOpen && ($f['gate_status'] ?? '') === 'available') {
            $pdo->prepare("UPDATE gates SET status='occupied', current_flight_id=?, occupied_since=COALESCE(occupied_since, " . sql_now() . ") WHERE id=?")
                ->execute([(int)$f['id'], (int)$f['gate_id']]);
        }
    }
    sim_release_stale_gates($pdo);
}


/**
 * Gate occupancy policy (per hall / terminal, normal gates only):
 * - Quiet hours (00–05, 22–23): target exactly 2 free
 * - All other hours: target exactly 1 free
 * - Never more than 2 free; never 0 free (always ≥1 free so assignments remain possible)
 * - Extra free gates are filled ORDERLY: max 2 new occupations per terminal per tick,
 *   always the next flights by scheduled_time (no sudden mass fill when density changes).
 * - Free gates stay available for new flight assignment
 */
function sim_gate_occupancy_cycle(PDO $pdo): void
{
    $hour = (int)atl_now()->format('G');
    $quiet = ($hour <= 5 || $hour >= 22);
    $targetFree = $quiet ? 2 : 1;
    // Orderly fill: never dump many flights onto gates in one tick
    $maxNewPerTick = 2;

    $terminals = ['T', 'A', 'B', 'C', 'D', 'E', 'F'];
    foreach ($terminals as $term) {
        $st = $pdo->prepare("SELECT id, status, current_flight_id FROM gates WHERE terminal=? AND is_reserve=0 ORDER BY gate_number");
        $st->execute([$term]);
        $list = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$list) {
            continue;
        }

        $free = [];
        $occ = [];
        foreach ($list as $g) {
            if (($g['status'] ?? '') === 'available') {
                $free[] = $g;
            } else {
                $occ[] = $g;
            }
        }
        $freeCount = count($free);

        // Too many free → occupy excess gradually with next chronological flights
        if ($freeCount > $targetFree) {
            $need = min($freeCount - $targetFree, $maxNewPerTick);
            // Strict chronological order by scheduled_time; prefer same-terminal planned gates
            $waiting = $pdo->prepare("SELECT f.id FROM flights f
                LEFT JOIN gates g ON g.id = f.gate_id
                WHERE f.is_tomorrow = 0
                  AND f.status IN ('Scheduled','On Time','Boarding','Final Call','Landing','Taxi to Gate','Pushback','Deboarding','Cleaning','Ready at Gate')
                  AND f.status NOT IN ('Departed','Arrived','Cancelled')
                  AND (f.gate_id IS NULL OR g.terminal = ? OR g.status = 'available' OR g.current_flight_id IS NULL OR g.current_flight_id != f.id)
                ORDER BY
                  f.scheduled_time ASC,
                  CASE WHEN g.terminal = ? THEN 0 ELSE 1 END,
                  " . sql_field_order("f.status", ["Taxi to Gate","Landing","Boarding","Final Call","Pushback","Deboarding","Cleaning","Ready at Gate","On Time","Scheduled"]) . "
                LIMIT 40");
            $waiting->execute([$term, $term]);
            $wids = $waiting->fetchAll(PDO::FETCH_COLUMN);
            $wi = 0;
            $assigned = 0;
            // Occupy from the end of free list so the first $targetFree stay free
            $toOccupy = array_slice($free, $targetFree);
            foreach ($toOccupy as $g) {
                if ($assigned >= $need || $wi >= count($wids)) {
                    break;
                }
                $fid = (int)$wids[$wi++];
                // Skip if this flight is already locked on another occupied gate
                $chk = $pdo->prepare("SELECT id FROM gates WHERE current_flight_id=? AND status='occupied' AND id!=?");
                $chk->execute([$fid, (int)$g['id']]);
                if ($chk->fetchColumn()) {
                    continue;
                }
                $pdo->prepare("UPDATE gates SET status='occupied', current_flight_id=?, occupied_since=COALESCE(occupied_since, " . sql_now() . ") WHERE id=?")
                    ->execute([$fid, (int)$g['id']]);
                $pdo->prepare('UPDATE flights SET gate_id=?, updated_at=' . sql_now() . ' WHERE id=?')
                    ->execute([(int)$g['id'], $fid]);
                $assigned++;
            }
        }

        // Recompute free after possible occupations
        $st2 = $pdo->prepare("SELECT id, status, current_flight_id FROM gates WHERE terminal=? AND is_reserve=0");
        $st2->execute([$term]);
        $list2 = $st2->fetchAll(PDO::FETCH_ASSOC);
        $free2 = [];
        $occ2 = [];
        foreach ($list2 as $g) {
            if (($g['status'] ?? '') === 'available') {
                $free2[] = $g;
            } else {
                $occ2[] = $g;
            }
        }
        $freeCount = count($free2);

        // Always keep at least 1 free (even in busiest). Free finished/stale first.
        if ($freeCount < 1) {
            $needFree = 1;
            foreach (array_reverse($occ2) as $g) {
                if ($needFree <= 0) {
                    break;
                }
                $fid = (int)($g['current_flight_id'] ?? 0);
                if ($fid > 0) {
                    $stF = $pdo->prepare("SELECT status FROM flights WHERE id=?");
                    $stF->execute([$fid]);
                    $fs = (string)$stF->fetchColumn();
                    if (!in_array($fs, ['Departed', 'Arrived', 'Cancelled', ''], true)) {
                        continue; // still live turnaround
                    }
                }
                $pdo->prepare("UPDATE gates SET status='available', current_flight_id=NULL, occupied_since=NULL WHERE id=?")
                    ->execute([(int)$g['id']]);
                $needFree--;
            }
        }
    }
}

function run_simulation_tick(PDO $pdo): array
{
    $pdo->beginTransaction();
    try {
        sim_advance_flights($pdo);
        sim_gate_schedule_windows($pdo);
        sim_gate_occupancy_cycle($pdo);
        sim_terminal_density($pdo);
        sim_belts($pdo);
        if (random_int(1, 100) <= 15) {
            sim_push_fuel_alerts($pdo);
        }
        $pdo->exec('UPDATE system_state SET sim_tick = sim_tick + 1, last_tick_at = ' . sql_now() . ' WHERE id = 1');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $pdo->query('SELECT * FROM system_state WHERE id = 1')->fetch() ?: [];
}
