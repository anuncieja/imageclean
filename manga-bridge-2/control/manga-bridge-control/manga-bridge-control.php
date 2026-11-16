<?php
/**
 * Plugin Name: Manga Bridge Control — Staging
 * Description: Transporte externo isolado. Não publica, altera ou remove conteúdo do Manga Bridge Importer.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.4
 */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/includes/Protocol.php';
require_once __DIR__ . '/includes/Job.php';
require_once __DIR__ . '/includes/Store.php';
require_once __DIR__ . '/includes/Plugin.php';
\MangaBridge2\Plugin::boot();
