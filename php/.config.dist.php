<?php
/**
 * This is the default configuration file. Do not change or delete it.
 * Copy this file to "php/.config.php" and overload configuration in it as needed.
 */
global $CONFIG;
$CONFIG = [
    'maintenance'=>false, // in maintenance mode, all actions will fails
    'storage' => 'file', // or mysql or sqlite
    'max_retention' => 2 * 365, // Maximum retention time in days
    'salt' => 'please_change_this_salt_value', // Change this value to a random string for better security
    'allowed-origins' => ['*'], // List of allowed origins for CORS. Use ['*'] to allow all origins. Can be overlodaded per client in .config.php (use "https://example.com:8080" format)
    // config for storage == file :
    'file' => [
        'path' => __DIR__ . '/../data/', // Path to the directory where files will be stored (must be rw accessible)
        'sqlite' => 'sqlite:'.__DIR__.'/../data/ggpp_data.sqlite'        
    ],
    // config for storage == mysql. Be sure to create the database (dbname) and user before using it.
    'mysql' => [
        'dsn' => 'mysql:host=localhost;dbname=ggpp_data;charset=utf8mb4',
        'username' => 'root', // Set to null if using socket authentication or .my.cnf
        'password' => ''      // Set to null if using socket authentication or .my.cnf
    ],
    // config for storage == sqlite :
    'sqlite' => [
        'dsn' => 'sqlite:'.__DIR__.'/../data/ggpp_data.sqlite'
    ],
    // Other configurations :
    'client_id' => [
        // each IP using client1 can do 100 req per minute (set max_req_count to -1 for no limit)
        'client1' => [
            'max_size' => 1 * 1024 * 1024, // Maximum size in bytes, must be aligned with the maximum post data size allowed by php (post_max_size )
            'max_req_period' => 60, 
            'max_req_count' => 100, 
            'use_ip_lock' => true],
        // each client2 can do 1 req per second without IP lock
        'client2' => [
            'max_size' => 1 * 1024 * 1024, // Maximum size in bytes, must be aligned with the maximum post data size allowed by php (post_max_size )
            'max_req_period' =>  1, 
            'max_req_count' =>   1, 
            'use_ip_lock' => false],
    ]
];

// v
@include_once('.config.php');
