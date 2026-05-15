<?php

declare(strict_types=1);

/**
 * Process one kiosk entry/exit scan; updates session keys kiosk_scan_mode / kiosk_manual_mode.
 *
 * @param array<string, mixed> $input Keys: scan_mode?, mode?, rfid_tag?
 * @return array<string, mixed> Payload suitable for JSON (flash, modals, etc.)
 */
function kiosk_process_entry_exit_scan(PDO $pdo, array $input, array &$session): array
{
    $out = [
        'flash' => '',
        'flash_type' => 'info',
        'show_error_modal' => false,
        'error_modal_message' => '',
        'show_cooldown_modal' => false,
        'cooldown_remaining' => 0,
        'show_success_modal' => false,
        'success_modal_mode' => '',
        'success_modal_name' => '',
        'success_modal_role' => '',
        'success_modal_department' => '',
        'success_modal_username' => '',
        'scan_mode' => isset($session['kiosk_scan_mode']) && $session['kiosk_scan_mode'] === 'manual' ? 'manual' : 'auto',
        'manual_mode' => isset($session['kiosk_manual_mode']) && $session['kiosk_manual_mode'] === 'exit' ? 'exit' : 'entry',
    ];

    if (isset($input['scan_mode']) && $input['scan_mode'] === 'manual') {
        $out['scan_mode'] = 'manual';
    } else {
        $out['scan_mode'] = 'auto';
    }
    $session['kiosk_scan_mode'] = $out['scan_mode'];

    if (isset($input['mode'])) {
        $m = (string) $input['mode'];
        if ($m === 'exit' || $m === 'entry') {
            $out['manual_mode'] = $m;
            $session['kiosk_manual_mode'] = $out['manual_mode'];
        }
    }

    $rfid = isset($input['rfid_tag']) ? trim((string) $input['rfid_tag']) : '';
    $rfid = preg_replace('/[\x00\r\n]/', '', $rfid) ?? '';

    if ($rfid === '') {
        $out['flash'] = 'Please scan/enter an RFID tag.';
        $out['flash_type'] = 'warn';

        return $out;
    }

    $stmtLast = $pdo->prepare(
        'SELECT mode, TIMESTAMPDIFF(SECOND, scanned_at, NOW()) AS age_sec
         FROM entry_exit_logs
         WHERE rfid_tag = :rfid
         ORDER BY scanned_at DESC, id DESC
         LIMIT 1'
    );
    $stmtLast->execute(['rfid' => $rfid]);
    $lastLog = $stmtLast->fetch() ?: null;

    if ($lastLog !== null) {
        $ageSec = max(0, (int) ($lastLog['age_sec'] ?? 0));
        if ($ageSec < 5) {
            $out['show_cooldown_modal'] = true;
            $out['cooldown_remaining'] = max(1, 5 - $ageSec);

            return $out;
        }
    }

    $scan_mode = $out['scan_mode'];
    $manual_mode = $out['manual_mode'];
    if ($scan_mode === 'manual') {
        $mode = $manual_mode;
    } else {
        $mode = ($lastLog !== null && ($lastLog['mode'] ?? '') === 'entry') ? 'exit' : 'entry';
    }

    $stmt = $pdo->prepare(
        'SELECT id, full_name, role, department, username, status
         FROM users
         WHERE rfid_tag = :rfid
         LIMIT 1'
    );
    $stmt->execute(['rfid' => $rfid]);
    $matched_user = $stmt->fetch() ?: null;

    $user_id = null;
    $note = null;

    if ($matched_user) {
        $user_id = (int) $matched_user['id'];
        if ($matched_user['status'] !== 'active') {
            $note = 'inactive_user';
            $out['flash'] = 'RFID found but user is INACTIVE: ' . $matched_user['full_name'];
            $out['flash_type'] = 'warn';
        } else {
            $out['show_success_modal'] = true;
            $out['success_modal_mode'] = $mode;
            $out['success_modal_name'] = (string) $matched_user['full_name'];
            $out['success_modal_role'] = (string) $matched_user['role'];
            $out['success_modal_department'] = (string) $matched_user['department'];
            $un = trim((string) ($matched_user['username'] ?? ''));
            $out['success_modal_username'] = $un;
        }
    } else {
        $note = 'unknown_rfid';
        $out['flash'] = 'USER NOT FOUND. This RFID is not registered. Please contact the library/admin to register.';
        $out['flash_type'] = 'error';
        $out['show_error_modal'] = true;
        $out['error_modal_message'] = $out['flash'];
    }

    $ins = $pdo->prepare(
        'INSERT INTO entry_exit_logs (user_id, rfid_tag, mode, note)
         VALUES (:user_id, :rfid_tag, :mode, :note)'
    );
    $ins->execute([
        'user_id' => $user_id,
        'rfid_tag' => $rfid,
        'mode' => $mode,
        'note' => $note,
    ]);

    return $out;
}
