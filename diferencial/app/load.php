<?php
header('Content-Type: application/json');
$avg = function_exists('sys_getloadavg') ? sys_getloadavg() : [0, 0, 0];
echo json_encode([
    'l1'  => round($avg[0], 2),
    'l5'  => round($avg[1], 2),
    'l15' => round($avg[2], 2),
]);
