<?php

include_once('.config.dist.php');

$storage = $CONFIG['storage'];
include_once('ggpp_storage.php');
include_once('ggpp_storage_'.$storage.'.php');

define('UDI_ALPHABET', 'ABCDEFGHJKLMNPRSTUVWXYZ123456789');
define('UDI_LENGTH', 12); // length of the udi, without dashes
define('UDI_LENGTH_DASHED', 15); // length of the udi, with dashes
define('UDI_MAX_TRIES', 5);

function DIE_WITH_ERROR($http_status_code, $message) {
    header('HTTP/1.1 '.$http_status_code);
    echo $message;
    exit;
}

class GGPP {
    private $config;
    private $storage;

    public function __construct($config) {
        $this->config = $config;
        $storage_type = $config['storage'];
        if (!in_array($storage_type, ['file', 'mysql', 'sqlite'])) {
            DIE_WITH_ERROR(500, 'Invalid storage type');
        }
        if ($storage_type == 'file') {
            $this->storage = new StorageFile($config);
        } else if ($storage_type == 'mysql') {
            $this->storage = new StorageMySQL($config);
        } else if ($storage_type == 'sqlite') {
            $this->storage = new StorageSQLite($config);
        }
    }

    public function getStorage() {
        return $this->storage;
    }

    public function create_new_document($data) {
        $udi = ggpp_create_new_udi($this->getStorage());
        // store the document using the storage backend
        $this->storage->store_document($udi, $data);
        return $udi;
    }

    public function get_document($udi) {
        if (ggpp_is_valid_udi($udi)) {
            return $this->storage->get_document($udi);
        } else {
            DIE_WITH_ERROR(400, 'Invalid UDI format');
        }        
    }

    public function update_document($udi, $data) {
        if (ggpp_is_valid_udi($udi)) {
            if (!$this->storage->document_exists($udi)) {
                DIE_WITH_ERROR(404, 'Document not found');
            }
            $this->storage->store_document($udi, $data);
        } else {
            DIE_WITH_ERROR(400, 'Invalid UDI format');
        }
    }

    public function check_rate_limit($client_id, $md5_ip_address, $max_req_period, $max_req_count, $use_ip_lock) {
        if ($max_req_period === -1 || $max_req_count === -1) {
            return true; // no limit
        }
        $client_rate_key = $client_id . ($use_ip_lock ? '_'.$md5_ip_address : '');
        $now = time();
        //echo "Checkrate of $client_rate_key : Time = $now (".date('Y-m-d H:i:s', $now).")\n";
        $rounded_time = floor($now / $max_req_period) * $max_req_period;
        //echo "Rounded time = $rounded_time (".date('Y-m-d H:i:s', $rounded_time).")\n";
        $nb_req_for_period = $this->storage->get_request_count($client_rate_key, $rounded_time);
        //echo "Nb req for period = $nb_req_for_period\n";
        if ($nb_req_for_period >= $max_req_count) {
            return false; // rate limit exceeded
        }
        $this->storage->set_request_count($client_rate_key, $rounded_time, $nb_req_for_period + 1);
        return true;
    }

    public function get_rate_usage($client_id, $md5_ip_address, $max_req_period, $max_req_count, $use_ip_lock) {
        $client_rate_key = $client_id . ($use_ip_lock ? '_'.$md5_ip_address : '');
        $now = time();
        $rounded_time = floor($now / $max_req_period) * $max_req_period;
        $nb_req_for_period = $this->storage->get_request_count($client_rate_key, $rounded_time);
        return $nb_req_for_period;
    }
}

/**
 * return true if the udi format is valid
 */
function ggpp_is_valid_udi($udi)
{
    if (strlen($udi) != UDI_LENGTH_DASHED) {
        return false;
    }
    $fragments = explode('-', $udi);
    if (count($fragments) != 4) {
        return false;
    }
    foreach ($fragments as $fragment) {
        if (strlen($fragment) != 3) {
            return false;
        }
        for ($i = 0; $i < 3; $i++) {
            if (strpos(UDI_ALPHABET, $fragment[$i]) === false) {
                return false;
            }
        }
    }
    return true;
}
/**
 * Generate a new unique document identifier for a future document.
 * Verify if the udi is not already used.
 * Work with all storage backends.
 */
function ggpp_create_new_udi($storageClass = null)
{
    global $CONFIG;
    $tries = 0;
    $now = time();
    $prefix = date('y', $now);
    $m = date('m', $now) + 0;
    if ($m == 10 || $m == 11 || $m == 12) {
        $m = chr(ord('A') + ($m - 10));
    }
    $m = strval($m);
    $prefix = $prefix . $m;
    do {
        $udi = $prefix;
        // Generate a 9 char random string from the UDI_ALPHABET
        for ($i = 3; $i < UDI_LENGTH; $i++) {
                if ($i % 3 == 0) {
                    $udi .= '-';
                }
            $udi .= UDI_ALPHABET[random_int(0, strlen(UDI_ALPHABET) - 1)];
            //$udi .= UDI_ALPHABET[random_int(0, 0)]; // reduce entropy for test & debug
        }
        // check if udi already exists
        if ($storageClass != null && !$storageClass->document_exists($udi)) {
            return $udi;
        }
        $tries++;
        // ouch, one collision, try again
    } while ($tries < 5);
    if ($tries >= UDI_MAX_TRIES)    
        DIE_WITH_ERROR(500, 'Unable to generate a unique identifier');
    return $udi;
}

