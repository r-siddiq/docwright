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

if (!function_exists('require_auth')) {
    function require_auth(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['staff_id'])) {
            header('Location: /login.php');
            exit;
        }
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('validate_csrf')) {
    function validate_csrf(string $token): bool {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('current_staff')) {
    function current_staff(): array {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['staff_id'])) {
            throw new RuntimeException('Not authenticated. Did you mean to call require_auth() first?');
        }
        $stmt = db()->prepare('SELECT * FROM staff WHERE id = ?');
        $stmt->execute([$_SESSION['staff_id']]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Staff not found for session staff_id.');
        }
        return $row;
    }
}