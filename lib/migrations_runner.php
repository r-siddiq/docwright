<?php
/**
 * Runs pending migrations from the migrations/ directory.
 *
 * Each migration is a PHP file named XXX_description.php where XXX is a
 * zero-padded sequence number. Migrations are run in alphanumeric order
 * and tracked in the migrations table to prevent re-running.
 *
 * Each migration runs in its own BEGIN IMMEDIATE transaction for atomicity.
 * If a migration fails, changes are rolled back and execution stops.
 */
function run_migrations(): void {
    $migrationsDir = __DIR__ . '/../migrations';
    $db = db();

    // Ensure migrations table exists (idempotent for fresh databases)
    $db->exec('
        CREATE TABLE IF NOT EXISTS migrations (
            name TEXT PRIMARY KEY,
            applied_at TEXT DEFAULT CURRENT_TIMESTAMP
        )
    ');

    // Get already-applied migrations
    $applied = $db->query("SELECT name FROM migrations")->fetchAll(PDO::FETCH_COLUMN);

    // Get all migration files sorted by name (ensures correct order)
    $files = glob("$migrationsDir/*.php");
    sort($files);

    // Run pending migrations
    foreach ($files as $file) {
        $name = basename($file, '.php');
        if (in_array($name, $applied)) {
            continue;
        }

        $db->beginTransaction();
        try {
            // Execute migration
            include $file;

            // Record migration as applied
            $stmt = $db->prepare("INSERT INTO migrations (name) VALUES (?)");
            $stmt->execute([$name]);

            $db->commit();
            echo "Applied migration: $name\n";
        } catch (Throwable $e) {
            $db->rollBack();
            throw new RuntimeException("Migration failed: $name", 0, $e);
        }
    }
}