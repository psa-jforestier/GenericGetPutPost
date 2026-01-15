<?php

/**
 * Storage implementation using the file system.
 * The real filename is hidden from the class colling this implementation.
 * The UDI is not the real filename, because it is stored in a nested directory structure.
 */
class StorageFile extends Storage {
    private $storage_dir;
    private $config;

    public function __construct($config) {
        $this->config = $config;
        $this->storage_dir = $config['file']['path'];
        if (!is_dir($this->storage_dir)) {
            mkdir($this->storage_dir, 0700, true);
        }
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

    public function get_document($udi, $client_id) {
        $filename = $this->get_real_document_filename($udi);
        if (!file_exists($filename)) {
            return false;
        }
        $data = file_get_contents($filename);
        return $data;
    }
    public function store_document($udi, $data, $client_id) {
        $filename = $this->get_real_document_filename($udi);
        $dir = dirname($filename);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($filename, $data);
    }
}
