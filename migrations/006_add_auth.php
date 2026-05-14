<?php
/**
 * Adds password_hash column to staff table for authentication.
 */
db()->exec("ALTER TABLE staff ADD COLUMN password_hash TEXT");