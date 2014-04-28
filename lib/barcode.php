<?php
require_once 'Barcode2.php';
$num = isset($_REQUEST['s']) ? $_REQUEST['s'] : '0';
Image_Barcode2::draw($num, 'code128', 'png', true, 30, 2);
