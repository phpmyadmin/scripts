<?php
require('/home/demo/scripts/demo/php/config-common.inc.php');
$cfg['ZeroConf'] = false;

$cfg['Servers'][1]['extension'] = 'mysqli';
$cfg['Servers'][1]['auth_type'] = 'config';
$cfg['Servers'][2]['extension'] = 'mysqli';
$cfg['Servers'][2]['auth_type'] = 'config';


unset($cfg['Servers'][1]['bookmarktable']);
unset($cfg['Servers'][1]['relation']);
unset($cfg['Servers'][1]['table_info']);
unset($cfg['Servers'][1]['table_coords']);
unset($cfg['Servers'][1]['pdf_pages']);
unset($cfg['Servers'][1]['column_info']);
unset($cfg['Servers'][1]['history']);
unset($cfg['Servers'][1]['designer_coords']);
unset($cfg['Servers'][1]['controluser']);
unset($cfg['Servers'][1]['controlpass']);
unset($cfg['Servers'][1]['pmadb']);

unset($cfg['Servers'][2]['bookmarktable']);
unset($cfg['Servers'][2]['relation']);
unset($cfg['Servers'][2]['table_info']);
unset($cfg['Servers'][2]['table_coords']);
unset($cfg['Servers'][2]['pdf_pages']);
unset($cfg['Servers'][2]['column_info']);
unset($cfg['Servers'][2]['history']);
unset($cfg['Servers'][2]['designer_coords']);
unset($cfg['Servers'][2]['controluser']);
unset($cfg['Servers'][2]['controlpass']);
unset($cfg['Servers'][2]['pmadb']);
?>
