<?php
/**
 * This file overload the default config from .config.dist.php
 */
$CONFIG['client_id'] = array(
    'ggppdemo' => ['max_req_period' => 60, 'max_req_count' => 100, 'use_ip_lock' => true],
);
$CONFIG['storage'] = 'file';
$CONFIG['max_retention'] = 1 * 365;
$CONFIG['salt'] = 'GGPP';
    
