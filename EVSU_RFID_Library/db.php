<?php

declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_USER = 'root';
const DB_PASS = '';
const DB_NAME = 'evsu_rfid_library';

/** Default app admin (only inserted when admins table is empty). Change password after first login. */
const DEFAULT_ADMIN_USERNAME = 'admin';
const DEFAULT_ADMIN_PASSWORD = 'admin123';

/** Default admin RFID for kiosk login step (seeded on first admin row when RFID is unset). Change in DB or admin settings if needed. */
const DEFAULT_ADMIN_RFID_TAG = '2880654146';

/**
 * URL path to a file under the project web root (e.g. uploads/photo.jpg).
 * Avoids broken images when pages live in /admin/ or /faculty/ vs project root.
 */
function app_public_path(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/');
    $dir = str_replace('\\', '/', dirname($script));
    $leaf = basename(rtrim($dir, '/'));
    if ($leaf === 'admin' || $leaf === 'faculty' || $leaf === 'student') {
        $base = str_replace('\\', '/', dirname($dir));
    } else {
        $base = $dir;
    }
    $base = rtrim(str_replace('\\', '/', $base), '/');
    if ($base === '' || $base === '.') {
        return '/' . $relativePath;
    }
    return $base . '/' . $relativePath;
}

function get_pdo(): PDO
{
    static $pdo_singleton = null;
    if ($pdo_singleton instanceof PDO) {
        return $pdo_singleton;
    }

    $dsnNoDb = sprintf('mysql:host=%s;charset=utf8mb4', DB_HOST);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    $serverPdo = new PDO($dsnNoDb, DB_USER, DB_PASS, $options);
    $dbName = str_replace('`', '``', DB_NAME);
    $serverPdo->exec(
        "CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS admins (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(64) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            avatar_path VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NULL,
            rfid_tag VARCHAR(64) NOT NULL UNIQUE,
            role ENUM(\'student\', \'faculty\', \'librarian\') NOT NULL,
            department VARCHAR(255) NOT NULL,
            username VARCHAR(64) NULL UNIQUE,
            password VARCHAR(255) NULL,
            avatar_path VARCHAR(255) NULL,
            status ENUM(\'active\', \'inactive\') NOT NULL DEFAULT \'active\',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS entry_exit_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            rfid_tag VARCHAR(64) NOT NULL,
            mode ENUM(\'entry\', \'exit\') NOT NULL,
            scanned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            note VARCHAR(255) NULL,
            INDEX idx_scanned_at (scanned_at),
            INDEX idx_rfid_tag (rfid_tag),
            INDEX idx_user_id (user_id),
            CONSTRAINT fk_logs_user_id
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // Inventory: books (admin-managed)
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS books (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            author VARCHAR(255) NULL,
            isbn VARCHAR(32) NULL,
            description TEXT NULL,
            cover_path VARCHAR(512) NULL,
            genre VARCHAR(128) NULL,
            language VARCHAR(64) NULL,
            edition VARCHAR(64) NULL,
            copies_total INT UNSIGNED NOT NULL DEFAULT 1,
            copies_available INT UNSIGNED NOT NULL DEFAULT 1,
            status ENUM(\'active\', \'archived\') NOT NULL DEFAULT \'active\',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_title (title),
            INDEX idx_author (author),
            INDEX idx_isbn (isbn),
            INDEX idx_genre (genre),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // Borrowing transactions
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS borrowings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            book_id INT UNSIGNED NOT NULL,
            borrowed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            due_at DATETIME NULL,
            returned_at DATETIME NULL,
            lost_at DATETIME NULL,
            status ENUM(\'borrowed\', \'returned\', \'lost\') NOT NULL DEFAULT \'borrowed\',
            note TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_book_id (book_id),
            INDEX idx_status (status),
            INDEX idx_borrowed_at (borrowed_at),
            INDEX idx_due_at (due_at),
            CONSTRAINT fk_borrowings_user_id
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE RESTRICT,
            CONSTRAINT fk_borrowings_book_id
                FOREIGN KEY (book_id) REFERENCES books(id)
                ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // Lightweight migration: ensure new columns exist for older installs.
    $colStmt = $pdo->prepare(
        'SELECT COUNT(*) AS c
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = :db
           AND TABLE_NAME = :table
           AND COLUMN_NAME = :col'
    );
    $colStmt->execute(['db' => DB_NAME, 'table' => 'users', 'col' => 'email']);
    $hasEmail = (int) $colStmt->fetch()['c'] > 0;
    if (!$hasEmail) {
        $pdo->exec('ALTER TABLE users ADD COLUMN email VARCHAR(255) NULL AFTER full_name');
    }

    $colStmt->execute(['db' => DB_NAME, 'table' => 'users', 'col' => 'avatar_path']);
    $hasUserAvatar = (int) $colStmt->fetch()['c'] > 0;
    if (!$hasUserAvatar) {
        try {
            $pdo->exec('ALTER TABLE users ADD COLUMN avatar_path VARCHAR(255) NULL AFTER password');
        } catch (Throwable $e) {
        }
    }

    $colStmt->execute(['db' => DB_NAME, 'table' => 'admins', 'col' => 'avatar_path']);
    $hasAdminAvatar = (int) $colStmt->fetch()['c'] > 0;
    if (!$hasAdminAvatar) {
        try {
            $pdo->exec('ALTER TABLE admins ADD COLUMN avatar_path VARCHAR(255) NULL AFTER password_hash');
        } catch (Throwable $e) {
        }
    }

    $colStmt->execute(['db' => DB_NAME, 'table' => 'admins', 'col' => 'library_user_id']);
    $hasAdminLibraryUser = (int) $colStmt->fetch()['c'] > 0;
    if (!$hasAdminLibraryUser) {
        try {
            $pdo->exec(
                'ALTER TABLE admins ADD COLUMN library_user_id INT UNSIGNED NULL AFTER avatar_path'
            );
        } catch (Throwable $e) {
        }
        try {
            $pdo->exec(
                'ALTER TABLE admins ADD CONSTRAINT fk_admins_library_user_id FOREIGN KEY (library_user_id) REFERENCES users(id) ON DELETE SET NULL'
            );
        } catch (Throwable $e) {
        }
    }

    $colStmt->execute(['db' => DB_NAME, 'table' => 'admins', 'col' => 'rfid_tag']);
    $hasAdminRfid = (int) $colStmt->fetch()['c'] > 0;
    if (!$hasAdminRfid) {
        try {
            $pdo->exec(
                'ALTER TABLE admins ADD COLUMN rfid_tag VARCHAR(64) NULL AFTER password_hash'
            );
        } catch (Throwable $e) {
        }
        try {
            $pdo->exec(
                'CREATE UNIQUE INDEX uq_admins_rfid_tag ON admins (rfid_tag)'
            );
        } catch (Throwable $e) {
        }
        $pick = $pdo->query(
            'SELECT MIN(id) AS id FROM admins WHERE rfid_tag IS NULL OR rfid_tag = \'\''
        )->fetch();
        if ($pick && (int) ($pick['id'] ?? 0) > 0) {
            try {
                $u = $pdo->prepare('UPDATE admins SET rfid_tag = :tag WHERE id = :id');
                $u->execute(['tag' => DEFAULT_ADMIN_RFID_TAG, 'id' => (int) $pick['id']]);
            } catch (Throwable $e) {
            }
        }
    }

    // Ensure books.status exists for older installs (if books table existed before)
    $colStmt->execute(['db' => DB_NAME, 'table' => 'books', 'col' => 'status']);
    $hasBookStatus = (int) $colStmt->fetch()['c'] > 0;
    if (!$hasBookStatus) {
        // Best-effort: if books didn't exist, CREATE TABLE above already handled it.
        try {
            $pdo->exec("ALTER TABLE books ADD COLUMN status ENUM('active','archived') NOT NULL DEFAULT 'active' AFTER copies_available");
        } catch (Throwable $e) {
            // Ignore if table doesn't exist or column already exists.
        }
    }

    // Books: metadata columns + remove legacy call number / per-book RFID
    $bookMetaCols = [
        ['description', 'ALTER TABLE books ADD COLUMN description TEXT NULL AFTER isbn'],
        ['cover_path', 'ALTER TABLE books ADD COLUMN cover_path VARCHAR(512) NULL AFTER description'],
        ['genre', 'ALTER TABLE books ADD COLUMN genre VARCHAR(128) NULL AFTER cover_path'],
        ['language', 'ALTER TABLE books ADD COLUMN language VARCHAR(64) NULL AFTER genre'],
        ['edition', 'ALTER TABLE books ADD COLUMN edition VARCHAR(64) NULL AFTER language'],
    ];
    foreach ($bookMetaCols as [$colName, $alterSql]) {
        $colStmt->execute(['db' => DB_NAME, 'table' => 'books', 'col' => $colName]);
        $hasCol = (int) $colStmt->fetch()['c'] > 0;
        if (!$hasCol) {
            try {
                $pdo->exec($alterSql);
            } catch (Throwable $e) {
            }
        }
    }
    foreach (['call_number', 'book_rfid_tag'] as $legacyBookCol) {
        $colStmt->execute(['db' => DB_NAME, 'table' => 'books', 'col' => $legacyBookCol]);
        $hasLegacy = (int) $colStmt->fetch()['c'] > 0;
        if ($hasLegacy) {
            try {
                $pdo->exec('ALTER TABLE books DROP COLUMN `' . str_replace('`', '``', $legacyBookCol) . '`');
            } catch (Throwable $e) {
            }
        }
    }

    // Borrowings: lost timestamp + longer notes for resolution history
    $colStmt->execute(['db' => DB_NAME, 'table' => 'borrowings', 'col' => 'lost_at']);
    $hasBorrowLostAt = (int) $colStmt->fetch()['c'] > 0;
    if (!$hasBorrowLostAt) {
        try {
            $pdo->exec('ALTER TABLE borrowings ADD COLUMN lost_at DATETIME NULL AFTER returned_at');
        } catch (Throwable $e) {
        }
    }
    try {
        $pdo->exec('ALTER TABLE borrowings MODIFY COLUMN note TEXT NULL');
    } catch (Throwable $e) {
    }

    $count = (int) $pdo->query('SELECT COUNT(*) AS c FROM admins')->fetch()['c'];
    if ($count === 0) {
        $stmt = $pdo->prepare(
            'INSERT INTO admins (username, password_hash, rfid_tag) VALUES (:username, :password_hash, :rfid_tag)'
        );
        $stmt->execute([
            'username' => DEFAULT_ADMIN_USERNAME,
            'password_hash' => password_hash(DEFAULT_ADMIN_PASSWORD, PASSWORD_DEFAULT),
            'rfid_tag' => DEFAULT_ADMIN_RFID_TAG,
        ]);
    }

    $pdo_singleton = $pdo;
    return $pdo_singleton;
}

// Bootstrap on include
get_pdo();
