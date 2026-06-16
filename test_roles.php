<?php
define('CLI_SCRIPT', true);
require(__DIR__.'/../../../config.php');
global $DB;
$rs = $DB->get_records('role_context_levels');
print_r($rs);
