<?php
/**
 * Adds readable_id column to shares table for human-readable share IDs.
 */
db()->exec("ALTER TABLE shares ADD COLUMN readable_id TEXT");
db()->exec("CREATE UNIQUE INDEX idx_shares_readable_id ON shares(readable_id) WHERE readable_id IS NOT NULL");