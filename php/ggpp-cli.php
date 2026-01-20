#!/usr/bin/env php
<?php
include_once('.config.dist.php');
include_once('ggpp_core.php');

/**
 * Print an error message to stderr
 */
function ERROR($message) {
    fwrite(STDERR, "[ERROR] $message\n");
}

/**
 * Print an info message to stdout
 */
function INFO($message) {
    echo "$message\n";
}

/**
 * Format bytes as human-readable string
 */
function format_bytes($bytes) {
    if ($bytes < 1) {
        return '0 B';
    }
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    
    return round($bytes, 2) . ' ' . $units[$pow];
}

function getDocumentsFromDatabase($pdo)
{
    $stmt = $pdo->query("SELECT udi, LENGTH(data) as size, date_update FROM documents");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $date_timestamp = strtotime($row['date_update']);
        $documents[$row['udi']] = [
            'udi' => $row['udi'],
            'size' => (int)$row['size'],
            'date_update' => $date_timestamp
        ];
    }
    return $documents;
}

function getDocumentsFromFilesystem($storage_dir)
{
    
    $documents = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storage_dir));
    foreach ($iterator as $file) {
        if ($file->isFile() && substr($file->getFilename(), -5) === '.data') {
            $udi = substr($file->getFilename(), 0, -5);
            $size = $file->getSize();
            $date_update = $file->getMTime();
            $documents[$udi] = [
                'udi' => $udi,
                'size' => (int)$size,
                'date_update' => $date_update
            ];
        }
    }

    $documents = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($storage_dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'data') {
            $udi = $file->getBasename('.data');
            $documents[$udi] = [
                'udi' => $udi,
                'size' => $file->getSize(),
                'date_update' => $file->getMTime()
            ];
        }
    }
    return $documents;
}

// For security reasy, this method is not implemented
// in the storage classes (to prevent data leaks from the web)
function getDocumentsList($storage)
{
    $documents = [];
    if ($storage instanceof StorageSQLite || $storage instanceof StorageMySQL) {
        $pdo = $storage->getStoragePHPObject();
        $documents = getDocumentsFromDatabase($pdo);
    }
    else if ($storage instanceof StorageFile) {        
        $storage_dir = $storage->getStoragePHPObject();
        $documents = getDocumentsFromFilesystem($storage_dir);
    }
    else {
        DIE_WITH_ERROR(500, 'Unsupported storage type for document listing');
    }
    return $documents;
}

function print_stats($ggpp) {
    global $CONFIG;
    $storage = $ggpp->getStorage();
    $docs = getDocumentsList($storage);
    // basic stats
    $total_docs = count($docs);
    $total_size = 0;
    $max_size = 0;
    $min_date = 0;
    $max_date = 0;
    $max_retention = $CONFIG['max_retention'];
    $nb_old_docs = 0;
    $size_old_docs = 0;
    $now = time();
    foreach ($docs as $doc) {
        $total_size += $doc['size'];
        $date = $doc['date_update'];
        if ($now - $date > $max_retention * 86400) {
            $nb_old_docs++;
            $size_old_docs += $doc['size'];
        }
        if ($doc['size'] > $max_size) {
            $max_size = $doc['size'];
        }
        if ($date > $max_date) {
            $max_date = $date;
        }
        if ($min_date == 0) {
            $min_date = $date;
        } else if ($date < $min_date) {
            $min_date = $date;
        }
    }
    if ($total_size > 0) {
        $avg = sprintf("%.2f", $total_size / $total_docs);   
    } else $avg = 0;
    INFO("Total documents    : $total_docs");
    INFO("  Documents older than $max_retention days : $nb_old_docs");
    INFO("  Size of old documents     : $size_old_docs (" . format_bytes($size_old_docs) . ")");
    INFO("Total size         : $total_size (" . format_bytes($total_size) . ")");
    INFO("Average size       : $avg (" . format_bytes($avg) . ")");
    INFO("Max size           : $max_size (" . format_bytes($max_size) . ")");
    INFO("Oldest document    : " . date('Y-m-d H:i:s', $min_date));
    INFO("Newest document    : " . date('Y-m-d H:i:s', $max_date));
    $sizes = array_column($docs, 'size');
    $distribution = calculate_size_distribution($sizes, $max_size);
    INFO("Document size distribution (from 0% to 100% of max size):");
    $max_distribution = max(array_values($distribution));
    foreach ($distribution as $range => $count_in_range) {
        if ($max_distribution == 0) {
            $bar_length = 0;
        } else {    
            $bar_length = (int)($count_in_range /  $max_distribution * 40);
        }
        $bar = str_repeat("█", $bar_length);
        INFO(sprintf(" %s : %s %d documents", $range, $bar, $count_in_range));
    }
}

/**
 * Calculate document distribution across size ranges
 */
function calculate_size_distribution($sizes, $max_size) {
    $distribution = [];
    
    // Create 10 buckets (10% each)
    for ($i = 0; $i < 10; $i++) {
        $lower = ($i / 10) * $max_size;
        $upper = (($i + 1) / 10) * $max_size;
        
        // For the last bucket, include max_size
        if ($i === 9) {
            $upper = $max_size + 1;
        }
        
        $count = 0;
        foreach ($sizes as $size) {
            if ($size >= $lower && $size < $upper) {
                $count++;
            }
        }
        
        $lower_formatted = format_bytes($lower);
        $upper_formatted = format_bytes($upper - 1);
        $key = sprintf("%3d%% - %3d%%", $i * 10, ($i + 1) * 10);
        
        $distribution[$key] = $count;
    }
    return $distribution;
}

function delete_old($ggpp, $max_retention, $confirm) {
    global $CONFIG;
    $storage = $ggpp->getStorage();
    $max_retention = 0;
    INFO("Scanning for documents older than $max_retention days...");
    $docs = getDocumentsList($storage);
    INFO("Evaluating " . count($docs) . " documents...");
    $now = time();
    $deleted_count = 0;
    $deleted_size = 0;
    foreach ($docs as $doc) {
        $date = $doc['date_update'];
        if ($now - $date > $max_retention * 86400) {
            INFO("Document " . $doc['udi'] . " (size: " . format_bytes($doc['size']) . ", date: " . date('Y-m-d H:i:s', $date) . ")");
            $deleted_count++;
            $deleted_size += $doc['size'];
            if ($confirm) {
                $storage->delete_document($doc['udi']);
            }
        }
    }
    if ($confirm) {
        INFO("Deleted $deleted_count documents, freeing " . format_bytes($deleted_size) . " of storage.");
    } else {
        INFO("Found $deleted_count documents to delete, totaling " . format_bytes($deleted_size) . " of storage.");
        INFO("Run the command again with --yes to actually delete the old documents.");
    }
}
function print_info($ggpp) {
    global $CONFIG;
    INFO("Version: " . GGPP::$version);
    INFO("Current configuration:");
    INFO("  Storage : " . $CONFIG['storage']);
    INFO("    File storage   : " . ($CONFIG['storage'] == 'file' ? '* in use *' : ''));
    INFO("       Path        : " . $CONFIG['file']['path']);
    INFO("    SQLite storage : " . ($CONFIG['storage'] == 'sqlite' ? '* in use *' : ''));
    INFO("       DSN         : " . $CONFIG['sqlite']['dsn']);
    INFO("    MySQL storage  : " . ($CONFIG['storage'] == 'mysql' ? '* in use *' : ''));
    INFO("       DSN         : " . $CONFIG['mysql']['dsn']);
    INFO("       Username    : " . $CONFIG['mysql']['username']);
    INFO(" Maximum retention  : " . $CONFIG['max_retention'] . " days");
    INFO(" Default allowed origins: " . implode(', ', $CONFIG['allowed-origins']));
    INFO("Configured client IDs:");
    foreach ($CONFIG['client_id'] as $client_id => $client_config) {    
        INFO("  Client ID: $client_id");
        INFO("    Max size         : " . $client_config['max_size'] . " bytes (" . format_bytes($client_config['max_size']) . ")");
        INFO("    Max req period   : " . $client_config['max_req_period'] . " seconds");
        INFO("    Max req count    : " . $client_config['max_req_count']);
        INFO("    Use IP lock      : " . ($client_config['use_ip_lock'] ? 'yes' : 'no'));
        if (isset($client_config['allowed-origins'])) {
            INFO("    Allowed origins  : " . implode(', ', $client_config['allowed-origins']));
        } else {
            INFO("    Allowed origins  : (default)");
        }
    }   
}   

$command = $argv[1] ?? null;
echo "GGPP CLI Management Script, version " . GGPP::$version . "\n";

if (!$command) {
    ?>

Usage: php ggpp-cli.php [command] [options]
Commands:
    --info    Display information about the GGPP configuration
    --stats   Display statistics about stored documents
    --delete-old [--yes]  Delete documents older than the maximum retention period (configured in ggpp_config.php)
Options:
    --yes     Confirm deletion of old documents (required to actually perform deletion)
<?php
    exit(0);
}

try {
    $ggpp = new GGPP($CONFIG);
    
    switch ($command) {
        case '--stats':
            print_stats($ggpp);
            break;
        case '--info':
            print_info($ggpp);
            break;
        case '--delete-old':
            $confirm = in_array('--yes', $argv);
            delete_old($ggpp, $CONFIG['max_retention'], $confirm);
            break;
        default:
            error("Unknown command: $command");
            exit(1);
    }
} catch (Exception $e) {
    error("An error occurred: " . $e->getMessage());
    exit(1);
}

exit(0);
