<?php
/**
 * Readthedocs webhook to trigger push content to CDN
 */

error_reporting(E_ALL);

define('PMAHOOKS', true);

require_once __DIR__ . '/lib/github.php';

trigger_docs_render();
