<?php
/**
 * Plugin Name: Manga Bridge Control — Staging
 * Description: Coleta pelo GitHub Actions e publicação de obras novas autorizadas em staging. Requer Manga Bridge Importer 1.7.53.
 * Version: 0.2.0
 * Requires at least: 6.4
 * Requires PHP: 8.4
 */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/includes/Protocol.php';
require_once __DIR__ . '/includes/Job.php';
require_once __DIR__ . '/includes/Store.php';
require_once __DIR__ . '/includes/Publisher.php';
require_once __DIR__ . '/includes/Plugin.php';
\MangaBridge2\Plugin::boot();
