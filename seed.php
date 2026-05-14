<?php
require __DIR__ . '/lib/bootstrap.php';

$demoPassword = getenv('DOCWRIGHT_DEMO_PASSWORD');
if (!is_string($demoPassword) || $demoPassword === '') {
    $demoPassword = bin2hex(random_bytes(24));
}
$dbPath = __DIR__ . '/db.sqlite';
if (file_exists($dbPath)) {
    unlink($dbPath);
}

$pdo = db();
$pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));

require __DIR__ . '/lib/migrations_runner.php';
run_migrations();

// Seed staff with passwords
$staffMembers = [
    ['email' => 'editor1@example.test', 'name' => 'Editor 1'],
    ['email' => 'editor2@example.test', 'name' => 'Editor 2'],
    ['email' => 'editor3@example.test', 'name' => 'Editor 3'],
    ['email' => 'editor4@example.test', 'name' => 'Editor 4'],
    ['email' => 'editor5@example.test', 'name' => 'Editor 5'],
];

$stmt = $pdo->prepare('INSERT INTO staff (email, name, password_hash) VALUES (?, ?, ?)');
foreach ($staffMembers as $s) {
    $stmt->execute([$s['email'], $s['name'], password_hash($demoPassword, PASSWORD_DEFAULT)]);
}

// Seed documents
$docs = [
    ['title' => 'Welcome Packet', 'body' => "Welcome to Docwright. Synthetic document content.", 'owner' => 1],
    ['title' => 'Sample Document 2', 'body' => "Synthetic budget document content.", 'owner' => 2],
];

$stmt = $pdo->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, ?)');
foreach ($docs as $d) {
    $stmt->execute([$d['title'], $d['body'], $d['owner']]);
}
$docId = (int) $pdo->lastInsertId();

// Create a share for the first doc
$token = random_token();
$stmt = $pdo->prepare('INSERT INTO shares (document_id, token, recipient_email) VALUES (?, ?, ?)');
$stmt->execute([1, $token, 'recipient1@example.test']);

echo "\n=== Seeded Credentials ===\n";
echo "Set DOCWRIGHT_DEMO_PASSWORD locally; the value is not printed.
";
foreach ($staffMembers as $i => $s) {
    echo ($i+1) . ". {$s['name']} <{$s['email']}>\n";
}
echo "\n";
echo "Admin:        http://localhost:8000/admin.php\n";
echo "Sample share: http://localhost:8000/view.php?token={$token}\n";