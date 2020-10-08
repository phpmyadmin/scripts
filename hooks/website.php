<?php
/**
 * GitHub webhook to trigger website rebuild
 */

error_reporting(E_ALL);

define('PMAHOOKS', true);

require_once __DIR__ . '/lib/github.php';

github_verify_post();

trigger_website_render();
