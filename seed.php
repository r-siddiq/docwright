<?php
/** Create a fresh local database with synthetic demonstration records. */
require __DIR__ . '/lib/bootstrap.php';

$dbPath = getenv('DOCWRIGHT_DB_PATH') ?: __DIR__ . '/db.sqlite';
if ($dbPath !== ':memory:' && file_exists($dbPath)) {
    fwrite(STDERR, "Database already exists; choose an unused DOCWRIGHT_DB_PATH.\n");
    exit(1);
}
$hasAuthentication = is_file(__DIR__ . '/migrations/006_add_auth.php');
$demoPassword = getenv('DOCWRIGHT_DEMO_PASSWORD');
if ($hasAuthentication && (!is_string($demoPassword) || $demoPassword === '' || $demoPassword === 'CHANGE_ME')) {
    fwrite(STDERR, "Set DOCWRIGHT_DEMO_PASSWORD to a unique local value before seeding.\n");
    exit(1);
}
$pdo = db();
$pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));
require __DIR__ . '/lib/migrations_runner.php';
run_migrations();
if ($hasAuthentication) {
    $staff = $pdo->prepare('INSERT INTO staff (email, name, password_hash) VALUES (?, ?, ?)');
    $staff->execute(['editor@example.test', 'Example Editor', password_hash($demoPassword, PASSWORD_DEFAULT)]);
} else {
    $staff = $pdo->prepare('INSERT INTO staff (email, name) VALUES (?, ?)');
    $staff->execute(['editor@example.test', 'Example Editor']);
}
$doc = $pdo->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, ?)');
$doc->execute(['Welcome Packet', 'This is synthetic sample content.', 1]);
$share = $pdo->prepare('INSERT INTO shares (document_id, token, recipient_email) VALUES (?, ?, ?)');
$share->execute([1, random_token(), 'reader@example.test']);
echo "Created a fresh database with synthetic example records.\n";
