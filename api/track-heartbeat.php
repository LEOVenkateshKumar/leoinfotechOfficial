<?php
require_once '../includes/functions.php';
// Just a heartbeat to keep session alive
echo json_encode(['status' => 'ok', 'time' => date('Y-m-d H:i:s')]);
