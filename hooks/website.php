<?php
/**
 * GitHub webhook to trigger website rebuild
 */

error_reporting(E_ALL);

define('PMAHOOKS', True);

require_once('./lib/github.php');

trigger_website_render();
