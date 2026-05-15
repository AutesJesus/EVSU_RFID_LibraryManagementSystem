<?php
declare(strict_types=1);

require_once __DIR__ . '/app_session.php';

function student_require_login(): void
{
    app_session_start();
    if (empty($_SESSION['user_id']) || (string) ($_SESSION['user_role'] ?? '') !== 'student') {
        header('Location: ../login.php');
        exit;
    }
}

function student_current_user_id(): int
{
    return (int) ($_SESSION['user_id'] ?? 0);
}
