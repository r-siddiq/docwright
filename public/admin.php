<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

require_auth();
$staff = current_staff();

$error = null;
$csrf_error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        http_response_code(419);
        $csrf_error = 'Session expired, please try again.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$csrf_error) {
    $title = trim($_POST['title'] ?? '');
    $body = trim($_POST['body'] ?? '');

    if ($title === '' || $body === '') {
        $error = 'Title and body are required.';
    } else {
        $scheduledAt = !empty($_POST['scheduled_at']) ? $_POST['scheduled_at'] : null;
        $stmt = db()->prepare('
            INSERT INTO documents (title, body, created_by, scheduled_at)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$title, $body, $staff['id'], $scheduledAt]);
        $docId = (int) db()->lastInsertId();

        if ($scheduledAt) {
            audit_log(AUDIT_ACTION_SCHEDULE, 'document', $docId, ['scheduled_at' => $scheduledAt]);
        } else {
            audit_log('create', 'document', $docId, ['title' => $title]);
        }

        header('Location: /admin.php?created=' . $docId);
        exit;
    }
}

$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $stmt = db()->prepare('
        SELECT d.*, s.name AS creator_name
        FROM documents d
        JOIN staff s ON s.id = d.created_by
        WHERE d.title LIKE ?
        ORDER BY d.created_at DESC
    ');
    $stmt->execute([$search . '%']);
    $docs = $stmt->fetchAll();
} else {
    $docs = db()->query('
        SELECT d.*, s.name AS creator_name
        FROM documents d
        JOIN staff s ON s.id = d.created_by
        ORDER BY d.created_at DESC
    ')->fetchAll();
}

render_header('Admin', $staff);
?>

<h1 class="page-title">Admin</h1>
<p class="page-subtitle">Create documents and generate share links for recipients.</p>
<p class="page-subtitle"><a href="/logout.php">Log out</a></p>

<?php if (!empty($_GET['created'])): ?>
    <div class="banner banner-success">Document #<?= (int) $_GET['created'] ?> created.</div>
<?php endif ?>

<?php if ($error): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>
<?php if ($csrf_error): ?>
    <div class="banner banner-error"><?= h($csrf_error) ?></div>
<?php endif ?>

<section class="card">
    <h2 class="card-title">New document</h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <div class="form-field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" required>
        </div>
        <div class="form-field">
            <label for="body">Body</label>
            <textarea id="body" name="body" required></textarea>
        </div>
        <div class="form-field">
            <label for="scheduled_at">Schedule for (optional)</label>
            <input type="datetime-local" id="scheduled_at" name="scheduled_at">
            <span class="hint">Leave empty to publish immediately</span>
        </div>
        <button type="submit" class="btn">Create document</button>
    </form>
</section>

<form method="get" class="search-form">
    <div class="form-field-inline">
        <label for="search">Search documents</label>
        <input type="text" id="search" name="search" value="<?= h($search) ?>" placeholder="Type to filter...">
        <?php if ($search): ?>
            <a href="/admin.php" class="btn-link">Clear</a>
        <?php endif ?>
    </div>
</form>

<section class="card">
    <h2 class="card-title">Documents</h2>
    <?php if ($search !== '' && empty($docs)): ?>
        <p class="empty">No documents match "<?= h($search) ?>". <a href="/admin.php">Show all documents</a></p>
    <?php elseif (empty($docs)): ?>
        <p class="empty">No documents yet.</p>
    <?php else: ?>
        <p class="results-count"><?= count($docs) ?> document(s)<?= $search !== '' ? ' matching "'.$search.'"' : '' ?></p>
        <table class="data">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Creator</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($docs as $d): ?>
                    <tr>
                        <td class="id">#<?= (int) $d['id'] ?></td>
                        <td><?= h($d['title']) ?></td>
                        <td><?= h($d['creator_name']) ?></td>
                        <td><?= h($d['created_at']) ?></td>
                        <td><a href="/share.php?doc=<?= (int) $d['id'] ?>" class="btn-link">Create share →</a></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
</section>

<?php render_footer(); ?>
