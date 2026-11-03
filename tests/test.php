<?php
/** In-memory checks; no existing database or network required. */
ob_start();
putenv('DOCWRIGHT_DB_PATH=:memory:');
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/migrations_runner.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$database = db();
$database->exec(file_get_contents(__DIR__ . '/../schema.sql'));
run_migrations();
$checks = 0;
function verify(bool $condition, string $description): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($description); }
    $checks++;
}
$staffColumns = array_column($database->query('PRAGMA table_info(staff)')->fetchAll(), 'name');
$documentColumns = array_column($database->query('PRAGMA table_info(documents)')->fetchAll(), 'name');
$shareColumns = array_column($database->query('PRAGMA table_info(shares)')->fetchAll(), 'name');
$localPassword = bin2hex(random_bytes(24));
if (in_array('password_hash', $staffColumns, true)) {
    $stmt = $database->prepare('INSERT INTO staff (email, name, password_hash) VALUES (?, ?, ?)');
    $stmt->execute(['editor@example.test', 'Example Editor', password_hash($localPassword, PASSWORD_DEFAULT)]);
    $hash = $database->query('SELECT password_hash FROM staff WHERE id = 1')->fetchColumn();
    verify(password_verify($localPassword, $hash), 'Valid authentication check');
    verify(!password_verify(bin2hex(random_bytes(24)), $hash), 'Invalid authentication check');
} else {
    $database->exec("INSERT INTO staff (email, name) VALUES ('editor@example.test', 'Example Editor')");
}
$stmt = $database->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)');
$stmt->execute(['Welcome Guide', 'Synthetic example']);
$stmt->execute(['Other Document', 'Synthetic example']);
$stmt = $database->prepare('SELECT COUNT(*) FROM documents WHERE title LIKE ?');
$stmt->execute(['Welcome%']);
verify((int) $stmt->fetchColumn() === 1, 'Title prefix search');
if (in_array('scheduled_at', $documentColumns, true)) {
    $future = date('Y-m-d\TH:i:s', time() + 86400);
    $stmt = $database->prepare('UPDATE documents SET scheduled_at = ? WHERE id = 1');
    $stmt->execute([$future]);
    verify(strtotime($database->query('SELECT scheduled_at FROM documents WHERE id = 1')->fetchColumn()) > time(), 'Future schedule storage');
}
$shareToken = random_token();
if (in_array('readable_id', $shareColumns, true) && function_exists('generate_readable_id')) {
    $readable = generate_readable_id('Welcome Guide');
    verify((bool) preg_match('/^[a-z0-9-]+-[A-Za-z0-9_-]{22}$/', $readable), 'Readable identifier includes 128 random bits');
    $stmt = $database->prepare('INSERT INTO shares (document_id, token, recipient_email, readable_id) VALUES (1, ?, ?, ?)');
    $stmt->execute([$shareToken, 'reader@example.test', $readable]);
    $stmt = $database->prepare('SELECT document_id FROM shares WHERE readable_id = ?');
    $stmt->execute([$readable]);
    verify((int) $stmt->fetchColumn() === 1, 'Readable share identifier');
    verify(generate_readable_id('Welcome Guide') !== $readable, 'Readable identifier uniqueness');
} else {
    $stmt = $database->prepare('INSERT INTO shares (document_id, token, recipient_email) VALUES (1, ?, ?)');
    $stmt->execute([$shareToken, 'reader@example.test']);
}
$stmt = $database->prepare('SELECT document_id FROM shares WHERE token = ?');
$stmt->execute([$shareToken]);
verify((int) $stmt->fetchColumn() === 1, 'Token share lookup');
if (function_exists('csrf_token')) {
    $csrf = csrf_token();
    verify(validate_csrf($csrf), 'Valid CSRF value');
    verify(!validate_csrf(bin2hex(random_bytes(24))), 'Invalid CSRF value');
}
$before = (int) $database->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
run_migrations();
$after = (int) $database->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
verify($before === $after, 'Migration runner is idempotent');
ob_end_clean();
echo "$checks checks passed.\n";
