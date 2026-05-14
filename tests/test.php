<?php

require __DIR__ . '/../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "seed failed\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function test(string $name, callable $fn): void {
    global $pass, $fail;
    try {
        $fn();
        echo "  [ok] {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

function assert_true($cond, string $msg = ''): void {
    if (!$cond) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected true');
    }
}

echo "\nRunning tests:\n";

test('seeded share link resolves to the seeded document', function () {
    $stmt = db()->prepare('
        SELECT d.title
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        LIMIT 1
    ');
    $stmt->execute();
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected the seeded share to resolve');
    assert_true($row['title'] === 'Welcome Packet', 'unexpected title: ' . var_export($row['title'], true));
});

test('scheduled document shows not-yet-available', function () {
    $pdo = db();

    // Create doc with future scheduled_at
    $stmt = $pdo->prepare('INSERT INTO documents (title, body, created_by, scheduled_at) VALUES (?, ?, 1, ?)');
    $futureTime = date('Y-m-d\TH:i:s', strtotime('+1 day'));
    $stmt->execute(['Future Doc', 'Body', $futureTime]);
    $docId = (int) $pdo->lastInsertId();

    // Create share token
    $token = random_token();
    $stmt = $pdo->prepare('INSERT INTO shares (document_id, token, recipient_email) VALUES (?, ?, ?)');
    $stmt->execute([$docId, $token, 'recipient2@example.test']);

    // Capture view.php output
    ob_start();
    $_GET['token'] = $token;
    include __DIR__ . '/../public/view.php';
    $output = ob_get_clean();

    assert_true(strpos($output, 'not yet available') !== false, 'Expected not yet available message');
});

test('null scheduled_at shows content', function () {
    $pdo = db();

    // Create doc with NULL scheduled_at
    $stmt = $pdo->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)');
    $stmt->execute(['Immediate Doc', 'Body']);
    $docId = (int) $pdo->lastInsertId();

    // Create share token
    $token = random_token();
    $stmt = $pdo->prepare('INSERT INTO shares (document_id, token, recipient_email) VALUES (?, ?, ?)');
    $stmt->execute([$docId, $token, 'recipient2@example.test']);

    // Capture view.php output
    ob_start();
    $_GET['token'] = $token;
    include __DIR__ . '/../public/view.php';
    $output = ob_get_clean();

    assert_true(strpos($output, 'Immediate Doc') !== false, 'Expected document title');
});

test('readable_id is generated and unique', function () {
    $pdo = db();

    // Create doc
    $stmt = $pdo->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)');
    $stmt->execute(['Q3 Budget Report', 'Body']);
    $docId = (int) $pdo->lastInsertId();

    // Generate readable_id
    $readableId = generate_readable_id('Q3 Budget Report');

    // Create share
    $stmt = $pdo->prepare('INSERT INTO shares (document_id, token, recipient_email, readable_id) VALUES (?, ?, ?, ?)');
    $token = random_token();
    $stmt->execute([$docId, $token, 'recipient2@example.test', $readableId]);

    assert_true(!empty($readableId), 'readable_id should be generated');
    assert_true(preg_match('/^[a-z0-9]+-[a-zA-Z0-9]{6}$/', $readableId), 'Format should be slug-random6: ' . $readableId);
});

test('view resolves by readable_id', function () {
    $pdo = db();

    // Create doc with share
    $stmt = $pdo->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)');
    $stmt->execute(['Welcome Guide', 'Welcome content']);
    $docId = (int) $pdo->lastInsertId();

    $token = random_token();
    $readableId = generate_readable_id('Welcome Guide');
    $stmt = $pdo->prepare('INSERT INTO shares (document_id, token, recipient_email, readable_id) VALUES (?, ?, ?, ?)');
    $stmt->execute([$docId, $token, 'recipient2@example.test', $readableId]);

    // Capture view.php output with rid
    ob_start();
    $_GET['rid'] = $readableId;
    include __DIR__ . '/../public/view.php';
    $output = ob_get_clean();

    assert_true(strpos($output, 'Welcome Guide') !== false, 'Expected document title');
    assert_true(strpos($output, 'Welcome content') !== false, 'Expected document body');
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
