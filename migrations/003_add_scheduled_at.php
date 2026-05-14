<?php
/**
 * Adds scheduled_at column for scheduled publishing feature.
 */
db()->exec("ALTER TABLE documents ADD COLUMN scheduled_at TEXT");
