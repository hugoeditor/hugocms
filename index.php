<?php
/**
 * HugoCMS – Einstiegspunkt im Wurzelverzeichnis.
 *
 * Bindet ausschließlich den festen Backend-Einstiegspunkt ein. Die gesamte
 * Logik liegt in backend/core/hugocms.php; dort werden auch custom.php
 * (backend/custom/), hugocms.ini und mounts.ini (backend/) gesucht.
 */

declare(strict_types=1);

require __DIR__ . '/backend/core/hugocms.php';
