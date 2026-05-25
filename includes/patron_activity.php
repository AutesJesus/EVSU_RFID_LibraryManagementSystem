<?php
declare(strict_types=1);

/**
 * Load patron profile, stats, chart series, and recent history for dashboards.
 *
 * @return array{
 *   profile: array<string,mixed>|null,
 *   my_borrow_rows: list<array<string,mixed>>,
 *   my_log_rows: list<array<string,mixed>>,
 *   stat_my_borrowed: int,
 *   stat_my_overdue: int,
 *   stat_my_returned: int,
 *   stat_my_lost: int,
 *   stat_my_logs_today: int,
 *   stat_my_logs_total: int,
 *   labels_short: list<string>,
 *   labels_iso: list<string>,
 *   chart_logs_entries: list<int>,
 *   chart_logs_exits: list<int>,
 *   chart_borrow_issued: list<int>,
 *   chart_borrow_returned: list<int>,
 *   chart_status_borrowed: int,
 *   chart_status_returned: int,
 *   chart_status_lost: int,
 *   chart_days: int,
 *   has_charts: bool
 * }
 */
function patron_load_dashboard(PDO $pdo, int $userId, int $chartDays = 14): array
{
    $empty = [
        'profile' => null,
        'my_borrow_rows' => [],
        'my_log_rows' => [],
        'stat_my_borrowed' => 0,
        'stat_my_overdue' => 0,
        'stat_my_returned' => 0,
        'stat_my_lost' => 0,
        'stat_my_logs_today' => 0,
        'stat_my_logs_total' => 0,
        'labels_short' => [],
        'labels_iso' => [],
        'chart_logs_entries' => [],
        'chart_logs_exits' => [],
        'chart_borrow_issued' => [],
        'chart_borrow_returned' => [],
        'chart_status_borrowed' => 0,
        'chart_status_returned' => 0,
        'chart_status_lost' => 0,
        'chart_days' => $chartDays,
        'has_charts' => false,
    ];

    if ($userId <= 0) {
        return $empty;
    }

    $chartDays = max(7, min(60, $chartDays));

    $stmtPat = $pdo->prepare(
        'SELECT id, full_name, email, rfid_tag, role, department, status, username, avatar_path, created_at
         FROM users WHERE id = :id LIMIT 1'
    );
    $stmtPat->execute(['id' => $userId]);
    $profile = $stmtPat->fetch();
    if ($profile === false) {
        return $empty;
    }

    $uid = $userId;
    $fetchCount = static function (string $sql, array $params = []) use ($pdo): int {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if ($row === false) {
            return 0;
        }
        return (int) reset($row);
    };

    $stat_my_borrowed = $fetchCount(
        "SELECT COUNT(*) FROM borrowings WHERE user_id = :u AND status = 'borrowed'",
        ['u' => $uid]
    );
    $stat_my_overdue = $fetchCount(
        "SELECT COUNT(*) FROM borrowings
         WHERE user_id = :u AND status = 'borrowed'
           AND due_at IS NOT NULL AND due_at < NOW()",
        ['u' => $uid]
    );
    $stat_my_returned = $fetchCount(
        "SELECT COUNT(*) FROM borrowings WHERE user_id = :u AND status = 'returned'",
        ['u' => $uid]
    );
    $stat_my_lost = $fetchCount(
        "SELECT COUNT(*) FROM borrowings WHERE user_id = :u AND status = 'lost'",
        ['u' => $uid]
    );
    $stat_my_logs_today = $fetchCount(
        "SELECT COUNT(*) FROM entry_exit_logs WHERE user_id = :u AND DATE(scanned_at) = CURDATE()",
        ['u' => $uid]
    );
    $stat_my_logs_total = $fetchCount(
        'SELECT COUNT(*) FROM entry_exit_logs WHERE user_id = :u',
        ['u' => $uid]
    );

    $stmtBr = $pdo->prepare(
        "SELECT br.id, br.borrowed_at, br.due_at, br.returned_at, br.status, br.note,
                b.title AS book_title, b.author AS book_author
         FROM borrowings br
         JOIN books b ON b.id = br.book_id
         WHERE br.user_id = :u
         ORDER BY (br.status = 'borrowed') DESC, br.borrowed_at DESC, br.id DESC
         LIMIT 25"
    );
    $stmtBr->execute(['u' => $uid]);
    $my_borrow_rows = $stmtBr->fetchAll();

    $stmtLg = $pdo->prepare(
        "SELECT scanned_at, mode, rfid_tag, note
         FROM entry_exit_logs
         WHERE user_id = :u
         ORDER BY scanned_at DESC, id DESC
         LIMIT 25"
    );
    $stmtLg->execute(['u' => $uid]);
    $my_log_rows = $stmtLg->fetchAll();

    $labels_short = [];
    $labels_iso = [];
    $chartStart = (new DateTimeImmutable('today'))->modify('-' . ($chartDays - 1) . ' days');
    for ($i = 0; $i < $chartDays; $i++) {
        $d = $chartStart->modify('+' . $i . ' days');
        $labels_iso[] = $d->format('Y-m-d');
        $labels_short[] = $d->format('M j');
    }

    $intervalDays = (int) ($chartDays - 1);

    $stmtLogsDay = $pdo->prepare(
        "SELECT DATE(scanned_at) AS d,
                SUM(CASE WHEN mode = 'entry' THEN 1 ELSE 0 END) AS entries,
                SUM(CASE WHEN mode = 'exit' THEN 1 ELSE 0 END) AS exits
         FROM entry_exit_logs
         WHERE user_id = :u
           AND scanned_at >= DATE_SUB(CURDATE(), INTERVAL {$intervalDays} DAY)
         GROUP BY DATE(scanned_at)
         ORDER BY d ASC"
    );
    $stmtLogsDay->execute(['u' => $uid]);
    $logsMap = [];
    foreach ($stmtLogsDay->fetchAll() as $row) {
        $logsMap[(string) $row['d']] = [
            'entries' => (int) $row['entries'],
            'exits' => (int) $row['exits'],
        ];
    }
    $chart_logs_entries = [];
    $chart_logs_exits = [];
    foreach ($labels_iso as $iso) {
        $day = $logsMap[$iso] ?? [];
        $chart_logs_entries[] = (int) ($day['entries'] ?? 0);
        $chart_logs_exits[] = (int) ($day['exits'] ?? 0);
    }

    $stmtBorrowDay = $pdo->prepare(
        "SELECT DATE(borrowed_at) AS d, COUNT(*) AS c
         FROM borrowings
         WHERE user_id = :u
           AND borrowed_at >= DATE_SUB(CURDATE(), INTERVAL {$intervalDays} DAY)
         GROUP BY DATE(borrowed_at)
         ORDER BY d ASC"
    );
    $stmtBorrowDay->execute(['u' => $uid]);
    $borrowMap = [];
    foreach ($stmtBorrowDay->fetchAll() as $row) {
        $borrowMap[(string) $row['d']] = (int) $row['c'];
    }
    $chart_borrow_issued = [];
    foreach ($labels_iso as $iso) {
        $chart_borrow_issued[] = $borrowMap[$iso] ?? 0;
    }

    $stmtReturnDay = $pdo->prepare(
        "SELECT DATE(returned_at) AS d, COUNT(*) AS c
         FROM borrowings
         WHERE user_id = :u
           AND status = 'returned'
           AND returned_at IS NOT NULL
           AND DATE(returned_at) >= DATE_SUB(CURDATE(), INTERVAL {$intervalDays} DAY)
         GROUP BY DATE(returned_at)
         ORDER BY d ASC"
    );
    $stmtReturnDay->execute(['u' => $uid]);
    $returnsMap = [];
    foreach ($stmtReturnDay->fetchAll() as $row) {
        $returnsMap[(string) $row['d']] = (int) $row['c'];
    }
    $chart_borrow_returned = [];
    foreach ($labels_iso as $iso) {
        $chart_borrow_returned[] = $returnsMap[$iso] ?? 0;
    }

    return [
        'profile' => $profile,
        'my_borrow_rows' => $my_borrow_rows,
        'my_log_rows' => $my_log_rows,
        'stat_my_borrowed' => $stat_my_borrowed,
        'stat_my_overdue' => $stat_my_overdue,
        'stat_my_returned' => $stat_my_returned,
        'stat_my_lost' => $stat_my_lost,
        'stat_my_logs_today' => $stat_my_logs_today,
        'stat_my_logs_total' => $stat_my_logs_total,
        'labels_short' => $labels_short,
        'labels_iso' => $labels_iso,
        'chart_logs_entries' => $chart_logs_entries,
        'chart_logs_exits' => $chart_logs_exits,
        'chart_borrow_issued' => $chart_borrow_issued,
        'chart_borrow_returned' => $chart_borrow_returned,
        'chart_status_borrowed' => $stat_my_borrowed,
        'chart_status_returned' => $stat_my_returned,
        'chart_status_lost' => $stat_my_lost,
        'chart_days' => $chartDays,
        'has_charts' => true,
    ];
}
