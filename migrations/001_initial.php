<?php
/**
 * Creates the migrations tracking table.
 * This is the first migration and must run before any other migrations.
 */
db()->exec('
    CREATE TABLE IF NOT EXISTS migrations (
        name TEXT PRIMARY KEY,
        applied_at TEXT DEFAULT CURRENT_TIMESTAMP
    )
');