<?php

date_default_timezone_set('America/Chicago');

if (!function_exists('db')) {
    function db(): PDO {
        static $pdo = null;
        if ($pdo === null) {
            $path = __DIR__ . '/../db.sqlite';
            $pdo = new PDO('sqlite:' . $path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
        return $pdo;
    }
}

if (!function_exists('current_staff')) {
    function current_staff(): array {
        $stmt = db()->prepare('SELECT * FROM staff WHERE id = 1');
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('No staff row #1 found. Did you run `php seed.php`?');
        }
        return $row;
    }
}

if (!function_exists('audit_log')) {
    function audit_log(string $action, string $entity_type, int $entity_id, array $details = []): void {
        $staff = current_staff();
        $stmt = db()->prepare('
            INSERT INTO audit_log (staff_id, action, entity_type, entity_id, details)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $staff['id'],
            $action,
            $entity_type,
            $entity_id,
            json_encode($details),
        ]);
    }
}

if (!function_exists('random_token')) {
    function random_token(int $bytes = 16): string {
        return bin2hex(random_bytes($bytes));
    }
}

if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

!defined('AUDIT_ACTION_SCHEDULE') && define('AUDIT_ACTION_SCHEDULE', 'schedule');