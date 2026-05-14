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

if (!function_exists('slugify')) {
    function slugify(string $title): string {
        $first = strtok($title, " \t\n\r\0\x0B");
        if ($first === false) {
            return 'doc';
        }
        $slug = strtolower($first);
        $slug = preg_replace('/[^a-z0-9-]/', '', $slug);
        $slug = trim($slug, '-');
        return empty($slug) ? 'doc' : substr($slug, 0, 20);
    }
}

if (!function_exists('generate_readable_id')) {
    function generate_readable_id(string $title): string {
        $slug = slugify($title);
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $maxAttempts = 3;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $random = '';
            for ($i = 0; $i < 6; $i++) {
                $random .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $id = $slug . '-' . $random;

            $stmt = db()->prepare("SELECT 1 FROM shares WHERE readable_id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                return $id;
            }
        }

        throw new RuntimeException("Failed to generate unique readable_id after $maxAttempts attempts");
    }
}

!defined('AUDIT_ACTION_SCHEDULE') && define('AUDIT_ACTION_SCHEDULE', 'schedule');