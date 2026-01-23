# GGPP: AI Coding Agent Guide

## Project Overview

**GGPP** (Generic GET PUT POST) is a document storage API inspired by pastebin/jsfiddle. It provides a REST interface for creating, retrieving, and updating arbitrary binary data with pluggable storage backends (file, SQLite, MySQL).

### Core Concept
- **PUT**: Create new document → returns `OK:UDI` with unique identifier, requires client_id
- **POST**: Update existing document → requires UDI + client_id, returns `OK:UDI`
- **GET**: Retrieve document → requires UDI + client_id, returns binary data
- **OPTIONS**: CORS preflight + rate limit info

UDI format: `YYM-ABC-DEF-GHI` where YY=year, M=month (1-C in base-12), ABC-DEF-GHI=9-char random code using `ABCDEFGHJKLMNPRSTUVWXYZ123456789` (no O/Q/0/I/L to avoid confusion).

## Architecture

### Request Flow
1. **Entry**: [php/ggpp.php](php/ggpp.php) - validates `client_id` via `X-Client-ID` header (preferred) or query param
2. **CORS handling**: Origin validation from `$CONFIG['allowed-origins']` (global or per-client override)
3. **IP hashing**: `$md5_ip_address = md5($ip_address.$CONFIG['salt'])` - **never use raw IP** (line 54 debug mode disabled)
4. **Rate limiting**: `check_rate_limit()` before processing (returns 429 if exceeded)
5. **Core logic**: `GGPP` class in [php/ggpp_core.php](php/ggpp_core.php) orchestrates document operations
6. **Storage**: Pluggable backend via abstract base class in [php/ggpp_storage.php](php/ggpp_storage.php)

### Storage Backend Interface
All backends (`StorageFile`, `StorageSQLite`, `StorageMySQL`) implement:
- `document_exists($udi)` - check if UDI exists
- `get_document($udi)` - retrieve binary data (returns `false` if not found)
- `store_document($udi, $data)` - create/update document
- `get_request_count($client_rate_key, $rounded_time)` - get rate limit counter
- `set_request_count($client_rate_key, $rounded_time, $count)` - update rate limit counter
- `delete_document($udi)` - remove document (CLI only)
- `getStoragePHPObject()` - expose underlying storage for CLI (PDO or directory path)

**File backend** ([php/ggpp_storage_file.php](php/ggpp_storage_file.php)): Nested structure `data/YYM/ABC/YYM-ABC-DEF-GHI.data` + rate limits via `.rate` files (modification time = period start).

**MySQL backend** marked "TO BE MORE TESTED" - use file or SQLite for production.

## Configuration

**Critical**: Copy [php/.config.dist.php](php/.config.dist.php) to `php/.config.php` before running. Config load chain: `.config.dist.php` sets defaults → `@include_once('.config.php')` overrides.

```php
$CONFIG = [
    'maintenance' => false,        // 503 mode (TODO: add GET-only mode per line 8)
    'storage' => 'file',           // 'file'|'mysql'|'sqlite'
    'salt' => 'CHANGE_THIS',       // CRITICAL: changing invalidates all UDIs
    'max_retention' => 730,        // days (default 2 years)
    'allowed-origins' => ['*'],    // CORS whitelist (or per-client override)
    'client_id' => [
        'client1' => [
            'max_size' => 1048576,       // bytes (must align with php.ini post_max_size)
            'max_req_period' => 60,      // seconds for rate window
            'max_req_count' => 100,      // requests per period (-1 = unlimited)
            'use_ip_lock' => true        // rate limit per hashed-IP vs global
        ]
    ],
    'file' => ['path' => __DIR__.'/../data/'],
    'mysql' => ['dsn' => '...', 'username' => '...', 'password' => '...'],
    'sqlite' => ['dsn' => 'sqlite:../data/ggpp_data.sqlite']
];
```

## Critical Workflows

### Testing & Debugging
1. **Demo client**: Open [index.html](index.html) in browser (standalone HTML5 + JS)
2. **Manual testing**: 
   ```bash
   curl -H "X-Client-ID: client1" -d "test data" -X PUT http://localhost/php/ggpp.php
   # Returns: OK:26B-ABC-DEF-GHI
   
   curl -H "X-Client-ID: client1" "http://localhost/php/ggpp.php?udi=26B-ABC-DEF-GHI"
   # Returns: test data
   ```
3. **Rate limit debugging**: Uncomment line 54 in [php/ggpp.php](php/ggpp.php) to disable IP hashing (shows real IP in logs)
4. **UDI collision testing**: Uncomment line 150 in [php/ggpp_core.php](php/ggpp_core.php) to reduce entropy: `//$udi .= UDI_ALPHABET[random_int(0, 0)]`

### CLI Maintenance ([php/ggpp-cli.php](php/ggpp-cli.php))
```bash
php ggpp-cli.php --info         # Show config summary
php ggpp-cli.php --stats        # Document count + size distribution chart
php ggpp-cli.php --delete-old --yes  # Purge docs older than max_retention
```

**Important**: Stop app during backup to prevent corruption. Backup file storage = `tar -czf data.tgz data/`. SQLite = copy `.sqlite` file while stopped.

### Adding Storage Backend
1. Create `php/ggpp_storage_TYPENAME.php`:
   ```php
   class StorageTYPENAME extends Storage {
       public function document_exists($udi) { /* ... */ }
       public function get_document($udi) { /* ... */ }
       public function store_document($udi, $data) { /* ... */ }
       public function get_request_count($key, $time) { /* ... */ }
       public function set_request_count($key, $time, $count) { /* ... */ }
       public function delete_document($udi) { /* ... */ }
       public function getStoragePHPObject() { /* ... */ }
   }
   ```
2. Update [php/ggpp_core.php](php/ggpp_core.php) constructor (line ~30): add case for `$storage_type == 'TYPENAME'`
3. Add config section to [php/.config.dist.php](php/.config.dist.php)

### HTTP Status Code Usage
Use `DIE_WITH_ERROR($code, $msg)` from [php/ggpp_core.php](php/ggpp_core.php) (line 14):
- **400**: Missing client_id/udi/data, invalid UDI format
- **403**: Unauthorized client_id, origin not in allowed-origins
- **404**: Document not found (GET/POST on non-existent UDI)
- **405**: Invalid HTTP method (not GET/POST/PUT/OPTIONS)
- **413**: Data exceeds `max_size` for client
- **429**: Rate limit exceeded
- **500**: Storage error, collision retries exhausted, invalid storage type
- **503**: Maintenance mode enabled

## Conventions & Patterns

### UDI Security
- **Format validation**: `ggpp_is_valid_udi()` checks 15 chars (12 + 3 dashes), 4 fragments of 3 chars each from alphabet
- **Collision detection**: `ggpp_create_new_udi()` tries 5 times (exits 500 if all fail)
- **Path traversal protection**: [php/ggpp_storage_file.php](php/ggpp_storage_file.php) line 35 blocks `.`, `/`, `\` in UDI
- **Month encoding**: Months 10-12 become 'A'-'C' (base-12 digit for single char)

### Rate Limiting Logic
- **Rounded time windows**: `floor($now / $max_req_period) * $max_req_period` groups requests into buckets
- **File backend quirk**: Uses `.rate` file mtime as period start (filesystem-dependent behavior)
- **IP privacy**: Hash IP with salt before storing (`$md5_ip_address`). Never log/store raw IPs except debug mode.
- **No GET/write differentiation**: All methods (GET/POST/PUT/OPTIONS) count toward same rate limit

### Data Privacy Model
- **Public by design**: Client_id is NOT a credential - anyone with it can read/write all documents
- **No encryption**: Data stored plaintext (recommend host-level encryption at rest)
- **Maintenance mode TODO**: Line 8 in [php/ggpp.php](php/ggpp.php) suggests future GET-only mode during backups

## Common Pitfalls

1. **Salt changes break ip hashing**: Changing `$CONFIG['salt']` will invalidate all IP hashing used for rate limitation. No impact on new or created UDI.
2. **Forgot to create `.config.php`**: App loads only dist config with placeholder client_ids
3. **POST to new UDI**: Returns 404 (must PUT first to create, then POST to update)
4. **MySQL instability**: Line comment "TO BE MORE TESTED" in [php/ggpp_storage_mysql.php](php/ggpp_storage_mysql.php) - prefer file/SQLite
5. **IP hashing enabled in prod**: Line 54 debug mode must stay commented (`//$md5_ip_address = $ip_address`) in production to protect user privacy
6. **PUT with UDI parameter**: Returns 400 (UDI auto-generated on PUT, only POST needs it)
7. **Empty directory cleanup**: TODO comment line 64 in [php/ggpp_storage_file.php](php/ggpp_storage_file.php) - orphaned dirs not purged
8. **CORS misconfig**: Check `allowed-origins` per-client override vs global setting (line 26-28 in [php/ggpp.php](php/ggpp.php))
