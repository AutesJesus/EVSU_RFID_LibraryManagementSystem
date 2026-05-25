<?php

declare(strict_types=1);

/**
 * EVSU RFID Library — deployment & health check page.
 * Open after deploy: /site-test.php
 * Does not display passwords or secret values.
 */

$checks = [];
$run = static function (string $id, string $label, bool $ok, string $detail = ''): array {
    return ['id' => $id, 'label' => $label, 'ok' => $ok, 'detail' => $detail];
};

$checks[] = $run(
    'php',
    'PHP version (8.1+)',
    version_compare(PHP_VERSION, '8.1.0', '>='),
    'Running PHP ' . PHP_VERSION
);

$checks[] = $run(
    'index',
    'Main entry (index.php)',
    is_file(__DIR__ . '/index.php'),
    is_file(__DIR__ . '/index.php') ? 'index.php found' : 'index.php missing'
);

$checks[] = $run(
    'login',
    'Login page (login.php)',
    is_file(__DIR__ . '/login.php'),
    is_file(__DIR__ . '/login.php') ? 'login.php found' : 'login.php missing'
);

$checks[] = $run(
    'vendor',
    'Composer dependencies (vendor/)',
    is_file(__DIR__ . '/vendor/autoload.php'),
    is_file(__DIR__ . '/vendor/autoload.php') ? 'vendor/autoload.php found' : 'Run composer install before deploy'
);

$hasDbLocal = is_file(__DIR__ . '/config/db.local.php');
$checks[] = $run(
    'db_config',
    'Database config (config/db.local.php)',
    $hasDbLocal,
    $hasDbLocal ? 'db.local.php present' : 'Missing — copy db.local.php.example or redeploy with GitHub DB_* secrets'
);

$dbOk = false;
$dbDetail = 'Not tested';
$dbError = '';

if ($hasDbLocal) {
    require_once __DIR__ . '/config/database.php';
    $userSet = defined('DB_USER') && DB_USER !== '';
    $nameSet = defined('DB_NAME') && DB_NAME !== '';
    $checks[] = $run(
        'db_defs',
        'Database settings loaded',
        $userSet && $nameSet,
        $userSet && $nameSet
            ? 'Host: ' . (defined('DB_HOST') ? DB_HOST : '?') . ', database: ' . DB_NAME . ', user: ' . DB_USER
            : 'DB_USER or DB_NAME empty — use GitHub secrets DB_USER, DB_PASS, DB_NAME (not DB_USERNAME / DB_DATABASE)'
    );

    if ($userSet && $nameSet) {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME),
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $pdo->query('SELECT 1');
            $dbOk = true;
            $dbDetail = 'Connected to MySQL successfully';
        } catch (Throwable $e) {
            $dbError = $e->getMessage();
            $dbDetail = 'Connection failed — verify DB_PASS in GitHub secrets matches hPanel MySQL password';
        }
    } else {
        $dbDetail = 'Skipped — database name or user not configured';
    }
} else {
    $checks[] = $run('db_defs', 'Database settings loaded', false, 'Skipped — db.local.php missing');
}

$checks[] = $run('db_connect', 'MySQL connection', $dbOk, $dbDetail . ($dbError !== '' ? ' — ' . $dbError : ''));

$uploadsWritable = is_dir(__DIR__ . '/uploads') && is_writable(__DIR__ . '/uploads');
$checks[] = $run(
    'uploads',
    'Uploads folder writable',
    $uploadsWritable,
    $uploadsWritable ? 'uploads/ is writable' : 'Create uploads/ and set permissions to 755 or 775'
);

$hasMailLocal = is_file(__DIR__ . '/config/mail.local.php');
$mailOk = false;
$mailDetail = 'mail.local.php missing — add MAIL_SMTP_* GitHub secrets or copy mail.local.php.example on the server';
if ($hasMailLocal) {
    require_once __DIR__ . '/config/mail.php';
    require_once __DIR__ . '/includes/mail.php';
    $mailCfg = mail_is_configured();
    $mailOk = $mailCfg['ok'];
    $mailDetail = $mailOk
        ? 'SMTP user and password set (Brevo)'
        : ($mailCfg['error'] ?? 'Incomplete mail config');
}
$checks[] = $run('mail', 'Email (Brevo SMTP)', $mailOk, $mailDetail);

$allOk = true;
foreach ($checks as $c) {
    if (!$c['ok']) {
        $allOk = false;
        break;
    }
}

$statusLabel = $allOk ? 'All checks passed' : 'Some checks failed';
$statusClass = $allOk ? 'ok' : 'fail';
$testedAt = gmdate('Y-m-d H:i:s') . ' UTC';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EVSU Library — Site Test</title>
    <style>
        :root {
            --ok: #0d7a3e;
            --ok-bg: #e8f5ec;
            --fail: #b42318;
            --fail-bg: #fdecea;
            --text: #1a1a1a;
            --muted: #5c5c5c;
            --border: #e2e2e2;
            --card: #fff;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", system-ui, sans-serif;
            background: #f0f2f5;
            color: var(--text);
            line-height: 1.5;
        }
        .wrap {
            max-width: 42rem;
            margin: 0 auto;
            padding: 1.5rem 1rem 2.5rem;
        }
        header {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        header h1 {
            margin: 0 0 .25rem;
            font-size: 1.5rem;
            font-weight: 700;
        }
        header p { margin: 0; color: var(--muted); font-size: .95rem; }
        .banner {
            border-radius: 10px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.25rem;
            font-weight: 600;
            text-align: center;
        }
        .banner.ok { background: var(--ok-bg); color: var(--ok); border: 1px solid #b8dfc8; }
        .banner.fail { background: var(--fail-bg); color: var(--fail); border: 1px solid #f5c4c0; }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 1.25rem;
        }
        .check {
            display: flex;
            gap: .75rem;
            padding: .85rem 1rem;
            border-bottom: 1px solid var(--border);
            align-items: flex-start;
        }
        .check:last-child { border-bottom: none; }
        .icon {
            flex-shrink: 0;
            width: 1.5rem;
            height: 1.5rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .85rem;
            font-weight: 700;
            color: #fff;
        }
        .icon.ok { background: var(--ok); }
        .icon.fail { background: var(--fail); }
        .check h2 {
            margin: 0;
            font-size: 1rem;
            font-weight: 600;
        }
        .check p {
            margin: .2rem 0 0;
            font-size: .875rem;
            color: var(--muted);
            word-break: break-word;
        }
        .links {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem;
            justify-content: center;
        }
        .links a {
            display: inline-block;
            padding: .5rem 1rem;
            border-radius: 8px;
            background: #1e3a5f;
            color: #fff;
            text-decoration: none;
            font-size: .9rem;
            font-weight: 500;
        }
        .links a.secondary {
            background: #fff;
            color: #1e3a5f;
            border: 1px solid #1e3a5f;
        }
        footer {
            text-align: center;
            font-size: .8rem;
            color: var(--muted);
            margin-top: 1rem;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <header>
            <h1>EVSU RFID Library</h1>
            <p>Deployment &amp; health check</p>
        </header>

        <div class="banner <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?>
        </div>

        <div class="card">
            <?php foreach ($checks as $c) : ?>
                <div class="check">
                    <span class="icon <?= $c['ok'] ? 'ok' : 'fail' ?>" aria-hidden="true"><?= $c['ok'] ? '✓' : '✗' ?></span>
                    <div>
                        <h2><?= htmlspecialchars($c['label'], ENT_QUOTES, 'UTF-8') ?></h2>
                        <?php if ($c['detail'] !== '') : ?>
                            <p><?= htmlspecialchars($c['detail'], ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="links">
            <a href="login.php">Open login page</a>
            <a href="index.php" class="secondary">Home (index)</a>
            <a href="health.php" class="secondary">JSON report</a>
            <a href="ping.php" class="secondary">PHP ping</a>
        </div>

        <footer>Tested at <?= htmlspecialchars($testedAt, ENT_QUOTES, 'UTF-8') ?> · Remove or restrict this page after go-live if desired.</footer>
    </div>
</body>
</html>
