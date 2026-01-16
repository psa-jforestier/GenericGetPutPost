<?php
/**
 * This file overload the default config from .config.dist.php
 */
$CONFIG['client_id'] = array(
    'ggppdemo' => [
        'max_size' => 1*1024, // 1kB of data for the demo
        'max_req_period' => 10, // for the demo, no more than 3 req in 10s
        'max_req_count' => 3, 
        'use_ip_lock' => true
        ],
);
$CONFIG['storage'] = 'file';
$CONFIG['max_retention'] = 1 * 365;
$CONFIG['salt'] = 'GGPP';

$CONFIG['storage'] = 'sqlite';
$CONFIG['sqlite'] = array(
    'dsn' => 'sqlite:'.__DIR__.'/../data/ggpp_data.sqlite'
);
    
