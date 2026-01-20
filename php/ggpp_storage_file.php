<?php

/**
 * Storage implementation using the file system.
 * The real filename is hidden from the class calling this implementation.
 * The UDI is not the real filename, because it is stored in a nested directory structure.
 * The rate limitation is implementing by creating a "lock" file (.rate file) for
 * each client_id (and eventually the hash of their IP) containing the request count. The 
 * modification time of the file is the start time of the current period.
 */
class StorageFile extends Storage {
    private $storage_dir;
    private $config;

    public function __construct($config) {
        $this->config = $config;
        $this->storage_dir = $config['file']['path'];
        $this->initialize_storage($this->storage_dir);
    }

    private function initialize_storage($dir)
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
    }

    // Expose the PDO object for advanced usage if needed (command line interface)
    public function getStoragePHPObject() {
        return $this->storage_dir;
    }

    private function split_udi_into_fragments($udi) {
        // crash on invlid udi
        if (strpos($udi, '.') !== false || strpos($udi, '/') !== false || strpos($udi, '\\') !== false) {
            DIE_WITH_ERROR(500, 'Invalid UDI format'); // someone is trying to do path traversal ;)
        }
        // split udi with the - symbol
        $fragments = explode('-', $udi);
        if (count($fragments) != 4) {
            DIE_WITH_ERROR(500, 'Invalid UDI format');
        }
        return $fragments;
    }
    private function get_real_document_filename($udi) {
        $fragments = $this->split_udi_into_fragments($udi);
        $filename =  $this->storage_dir
            .DIRECTORY_SEPARATOR.($fragments[0])
            .DIRECTORY_SEPARATOR.($fragments[1])
            .DIRECTORY_SEPARATOR.$udi.'.data';
        return $filename;
    }

    
    public function document_exists($udi) {
        $filename = $this->get_real_document_filename($udi);
        return file_exists($filename);
    }

    public function delete_document($udi) {
        $filename = $this->get_real_document_filename($udi);
        if (file_exists($filename)) {
            unlink($filename);
            // __JFO TODO purge empty directories
            return true;
        }
        return false;
    }

    public function get_document($udi) {
        $filename = $this->get_real_document_filename($udi);
        if (!file_exists($filename)) {
            return false;
        }
        $data = file_get_contents($filename);
        return $data;
    }
    public function store_document($udi, $data) {
        $filename = $this->get_real_document_filename($udi);
        $dir = dirname($filename);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($filename, $data);
    }

    public function get_request_count($client_rate_key, int $rounded_time)
    {
        $rate_file = $this->storage_dir
            .DIRECTORY_SEPARATOR.'rate_limit_'.$client_rate_key.'.rate';
        if (!file_exists($rate_file)) {
            return 0;
        }
        $m = filemtime($rate_file); // the modification time of the file is the start time of the period
        if ($m < $rounded_time) {
            return 0;
        }
        // the file exist and is in the current period
        $count = (int)file_get_contents($rate_file);
        return $count;
    }

    public function set_request_count($client_rate_key, int $rounded_time, int $count)
    {
        $rate_file = $this->storage_dir
            .DIRECTORY_SEPARATOR.'rate_limit_'.$client_rate_key.'.rate';
        file_put_contents($rate_file, (string)$count);
        // set the modification time of the file to the start time of the period
        touch($rate_file, $rounded_time);
    }

    public function getStorageDir()
    {
        return $this->storage_dir;
    }
}
