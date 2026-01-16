<?php

include_once('.config.dist.php');

include_once('ggpp_core.php');

$http_method = @$_SERVER['REQUEST_METHOD'];
$posted_data = trim(@file_get_contents('php://input'));
$client_id = (@$_SERVER['HTTP_X_CLIENT_ID'] != '' ? $_SERVER['HTTP_X_CLIENT_ID'] : @$_REQUEST['client_id']);
$client_id = trim($client_id);

// client_id is required (and must be authorized)
if ($client_id == '') {
    DIE_WITH_ERROR(400, 'Missing client_id');
}
if (!isset($CONFIG['client_id'][$client_id])) {
    DIE_WITH_ERROR(403, 'The client_id is not authorized');
}

$client_config = $CONFIG['client_id'][$client_id];
// get the client ip adresse from the http remote addr or the via http header X-Forwarded-For
$ip_address = '';
if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && $_SERVER['HTTP_X FORWARDED_FOR'] != '') {
    $ip_address = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
} else {
    $ip_address = $_SERVER['REMOTE_ADDR'];
}
// for debugging purpose only, use $md5_ip_address = $ip_address;
$md5_ip_address = md5($ip_address.$CONFIG['salt']); // Never work on real ip address, only on its hash with the salt
$md5_ip_address = $ip_address; // debug only
$ip_address = ''; unset($ip_address); // remove the real ip address from memory for better privacy

$ggpp = new GGPP($CONFIG);
$allowed = $ggpp->check_rate_limit($client_id, $md5_ip_address, $client_config['max_req_period'], $client_config['max_req_count'], $client_config['use_ip_lock']);
if (!$allowed) {
    DIE_WITH_ERROR(429, 'Too Many Requests');
}

if ($http_method == 'PUT') {
    // create a new document
    $udi = strtoupper(@$_REQUEST['udi']);
    if ($udi != '') {
        DIE_WITH_ERROR(400, 'Udi must not be set when creating a new document');
    }
    if (strlen($posted_data) == 0) {
        DIE_WITH_ERROR(400, 'Missing posted data');
        // we could allow empty data, but it does not make much sense 
        // (except to consume inode, test rate limiter etc).
    }
    if (strlen($posted_data) > $client_config['max_size']) {
        DIE_WITH_ERROR(413, 'Posted data too large');
    }
    $udi = $ggpp->create_new_document($posted_data);
    echo "OK:$udi\n";
}
else if ($http_method == 'POST') {
    // update an existing document
    $udi = strtoupper(@$_REQUEST['udi']);
    if ($udi == '') {
        DIE_WITH_ERROR(400, 'Missing udi');
    }
    if (strlen($posted_data) == 0) {
        DIE_WITH_ERROR(400, 'Missing posted data');
    }
    if (strlen($posted_data) > $client_config['max_size']) {
        DIE_WITH_ERROR(413, 'Posted data too large');
    }
    $ggpp->update_document($udi, $posted_data);
    echo "OK:$udi\n";
}
else if ($http_method == 'GET') {
    // get an existing document
    $udi = strtoupper(@$_REQUEST['udi']);
    if ($udi == '') {
        DIE_WITH_ERROR(400, 'Missing udi');
    }
    $data = $ggpp->get_document($udi);
    if ($data === false) {
        DIE_WITH_ERROR(404, 'Document not found');
    }
    echo $data;
}
else if ($http_method == 'OPTIONS') {
    // for CORS preflight requests
    header('Access-Control-Allow-Methods: GET, PUT, POST, OPTIONS');
    header('Access-Control-Allow-Headers: X-Client-Id, Content-Type');
    header('Access-Control-Max-Age: 86400'); // cache for 1 day
    echo "client_id: $client_id\n";
    echo "rate limit: ".$client_config['max_req_count']." requests per ".$client_config['max_req_period']." seconds\n";
    echo "rate usage: ".$ggpp->get_rate_usage($client_id, $md5_ip_address, $client_config['max_req_period'], $client_config['max_req_count'], $client_config['use_ip_lock'])." requests used in the current period\n";
}
else {
    DIE_WITH_ERROR(405, 'Method Not Allowed');
}