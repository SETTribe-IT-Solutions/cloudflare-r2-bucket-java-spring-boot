<?php
/**
 * =========================================================================
 *  CLOUDFLARE R2 FILE MIGRATION SCRIPT (Reusable)
 * =========================================================================
 *
 *  A reusable script to migrate locally-stored files to Cloudflare R2 bucket.
 *  Works with ANY PHP/MySQL project — just configure `migrate_config.php`.
 *
 *  WORKFLOW:
 *  Step 1 - Scan database tables for file references (local paths)
 *  Step 2 - For each file:
 *     a) Check if file already exists in R2 → SKIP if yes
 *     b) If not in R2 → UPLOAD the file
 *        i)  Validate the upload was successful (HeadObject + size check)
 *        ii) Update the database path for that record
 *
 *  SETUP:
 *  1. Copy `migrate_to_r2.php` and `migrate_config.php` into your project root
 *  2. Edit `migrate_config.php` with your DB, R2, and table mapping details
 *  3. Ensure `composer require aws/aws-sdk-php` is installed
 *
 *  USAGE:
 *    php migrate_to_r2.php                           # Live migration
 *    php migrate_to_r2.php --dry-run                 # Preview only (no changes)
 *    php migrate_to_r2.php --config=my_config.php    # Use custom config file
 *    php migrate_to_r2.php --table=accident          # Migrate only specific table
 *    php migrate_to_r2.php --dry-run --table=gallery_master  # Combine flags
 *
 *  ⚠️ IMPORTANT: Run on the SERVER where uploaded files exist locally.
 *  ⚠️ BACKUP your database before running this script.
 * =========================================================================
 */

// ─────────────────────────────────────────────
// PHP ENVIRONMENT SETUP
// ─────────────────────────────────────────────

set_time_limit(0);
ini_set('memory_limit', '512M');
ini_set('display_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kolkata');


// ─────────────────────────────────────────────
// PARSE CLI ARGUMENTS
// ─────────────────────────────────────────────

$isDryRun = in_array('--dry-run', $argv ?? []);
$configFile = 'migrate_config.php';  // Default config file
$filterTable = null;                   // Optional: migrate only one table
$minSize = null;
$maxSize = null;
$scanOrphans = in_array('--scan-orphans', $argv ?? []);

foreach ($argv ?? [] as $arg) {
    if (strpos($arg, '--config=') === 0) {
        $configFile = substr($arg, 9);
    }
    if (strpos($arg, '--table=') === 0) {
        $filterTable = substr($arg, 8);
    }
    if (strpos($arg, '--min-size=') === 0) {
        $minSize = (int)substr($arg, 11);
    }
    if (strpos($arg, '--max-size=') === 0) {
        $maxSize = (int)substr($arg, 11);
    }
}


// ─────────────────────────────────────────────
// LOAD CONFIG
// ─────────────────────────────────────────────

$configPath = __DIR__ . DIRECTORY_SEPARATOR . $configFile;

if (!file_exists($configPath)) {
    die("❌ Config file not found: $configPath\n\n"
        . "Create a 'migrate_config.php' file in the same directory.\n"
        . "See the included template for the required format.\n");
}

$config = require $configPath;

// Validate required config sections
$requiredSections = ['database', 'r2', 'uploads_path', 'table_mappings'];
foreach ($requiredSections as $section) {
    if (!isset($config[$section]) || empty($config[$section])) {
        die("❌ Missing required config section: '$section' in $configFile\n");
    }
}

$dbConfig = $config['database'];
$r2Config = $config['r2'];
$uploadsBasePath = rtrim($config['uploads_path'], '/\\') . DIRECTORY_SEPARATOR;
$tableMappings = $config['table_mappings'];
$autoloadPath = $config['autoload_path'] ?? __DIR__ . '/vendor/autoload.php';

// Validate required DB fields
foreach (['host', 'username', 'password', 'name'] as $field) {
    if (empty($dbConfig[$field])) {
        die("❌ Missing required database config field: '$field'\n");
    }
}

// Validate required R2 fields
foreach (['account_id', 'access_key', 'secret_key', 'bucket', 'public_base_url'] as $field) {
    if (empty($r2Config[$field])) {
        die("❌ Missing required R2 config field: '$field'\n");
    }
}


// ─────────────────────────────────────────────
// CONNECT TO DATABASE
// ─────────────────────────────────────────────

$conn = mysqli_connect(
    $dbConfig['host'],
    $dbConfig['username'],
    $dbConfig['password'],
    $dbConfig['name'],
    $dbConfig['port'] ?? 3306
) or die("❌ Database connection failed: " . mysqli_connect_error() . "\n");

mysqli_set_charset($conn, $dbConfig['charset'] ?? 'utf8mb4');


// ─────────────────────────────────────────────
// INITIALIZE R2 CLIENT
// ─────────────────────────────────────────────

if (!file_exists($autoloadPath)) {
    die("❌ Composer autoload not found at: $autoloadPath\n"
        . "Run: composer require aws/aws-sdk-php\n");
}

require $autoloadPath;

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

$bucket = $r2Config['bucket'];
$publicBaseURL = rtrim($r2Config['public_base_url'], '/') . '/';

$s3 = new S3Client([
    'version' => 'latest',
    'region' => 'auto',
    'endpoint' => "https://{$r2Config['account_id']}.r2.cloudflarestorage.com",
    'credentials' => [
        'key' => $r2Config['access_key'],
        'secret' => $r2Config['secret_key'],
    ]
]);


// ─────────────────────────────────────────────
// FILTER TABLE MAPPINGS (if --table flag used)
// ─────────────────────────────────────────────

if ($filterTable) {
    $tableMappings = array_filter($tableMappings, function ($m) use ($filterTable) {
        return $m['table'] === $filterTable;
    });
    if (empty($tableMappings)) {
        die("❌ No mapping found for table: '$filterTable'\n");
    }
}


// ─────────────────────────────────────────────
// COUNTERS & LOGGING
// ─────────────────────────────────────────────

$stats = [
    'total_records' => 0,
    'already_on_r2' => 0,
    'skipped_empty' => 0,
    'skipped_size'  => 0,
    'uploaded' => 0,
    'upload_failed' => 0,
    'file_not_found' => 0,
    'db_updated' => 0,
    'db_update_fail' => 0,
    'verify_failed' => 0,
];

$logFile = __DIR__ . '/migration_log_' . date('Y-m-d_H-i-s') . '.txt';
$logHandle = fopen($logFile, 'w');

function logMsg($msg, $type = 'INFO')
{
    global $logHandle;
    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] [$type] $msg\n";
    echo $line;
    fwrite($logHandle, $line);
}


// ─────────────────────────────────────────────
// HELPER: Check if file exists in R2
// ─────────────────────────────────────────────

function fileExistsInR2($s3, $bucket, $key)
{
    try {
        $s3->headObject([
            'Bucket' => $bucket,
            'Key' => $key,
        ]);
        return true;
    } catch (S3Exception $e) {
        if ($e->getStatusCode() === 404) {
            return false;
        }
        logMsg("R2 HeadObject error for key '$key': " . $e->getMessage(), 'WARN');
        return false;
    }
}


// ─────────────────────────────────────────────
// HELPER: Verify upload (exists + size match)
// ─────────────────────────────────────────────

function verifyUpload($s3, $bucket, $key, $localFileSize)
{
    try {
        $result = $s3->headObject([
            'Bucket' => $bucket,
            'Key' => $key,
        ]);
        $remoteSize = $result['ContentLength'] ?? 0;

        if ($remoteSize === $localFileSize) {
            return true;
        }
        logMsg("Size mismatch for '$key': local=$localFileSize, remote=$remoteSize", 'WARN');
        return false;
    } catch (S3Exception $e) {
        logMsg("Verify failed for '$key': " . $e->getMessage(), 'ERROR');
        return false;
    }
}


// ─────────────────────────────────────────────
// HELPER: Check if DB value is already an R2 URL
// ─────────────────────────────────────────────

function isAlreadyOnR2($value, $publicBaseURL)
{
    return (
        strpos($value, '.r2.dev') !== false ||
        strpos($value, 'r2.cloudflarestorage.com') !== false ||
        strpos($value, $publicBaseURL) === 0
    );
}


// ─────────────────────────────────────────────
// HELPER: Resolve local file path from DB value
// ─────────────────────────────────────────────

function resolveLocalFilePath($dbValue, $uploadsBasePath, $r2Folder = null)
{
    // Extract the relative filename from the DB value
    $relativePath = $dbValue;

    if (strpos($dbValue, 'uploads/') === 0) {
        $relativePath = substr($dbValue, 8);
    } elseif (strpos($dbValue, 'uploads/') !== false) {
        $relativePath = substr($dbValue, strpos($dbValue, 'uploads/') + 8);
    }

    $basename = basename($dbValue);
    $sanitizedBase = str_replace(':', '_', $basename);
    $hyphenatedBase = str_replace(':', '-', $basename);

    // ── Attempt 1: Check in the specific feature folder first (User's recommendation) ──
    if ($r2Folder) {
        $checkFolders = [$r2Folder];
        
        // Some mappings use slightly different folder names than the physical ones
        // e.g. r2_folder 'latestInitiative' vs physical 'kayInitiatives'
        // We will try both if they differ.
        
        foreach ($checkFolders as $folder) {
            $folderPath = rtrim($uploadsBasePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR;
            
            $attempts = [
                $folderPath . $basename,
                $folderPath . $sanitizedBase,
                $folderPath . $hyphenatedBase
            ];
            
            foreach ($attempts as $path) {
                if (file_exists($path) && is_file($path)) {
                    return $path;
                }
            }
        }
    }

    // ── Attempt 2: Exact match at relative path ──
    $path = $uploadsBasePath . $relativePath;
    if (file_exists($path) && is_file($path)) {
        return $path;
    }

    // ── Attempt 3: Replace colons in relative path ──
    $path = $uploadsBasePath . str_replace(':', '_', $relativePath);
    if (file_exists($path) && is_file($path)) {
        return $path;
    }

    // ── Attempt 4: Try just the basename in uploads root ──
    $path = $uploadsBasePath . $basename;
    if (file_exists($path) && is_file($path)) {
        return $path;
    }
    $path = $uploadsBasePath . $sanitizedBase;
    if (file_exists($path) && is_file($path)) {
        return $path;
    }

    // ── Attempt 5: Search in ANY module subfolders ──
    $globPattern = rtrim($uploadsBasePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR;
    
    $subfolderGlob = glob($globPattern . $basename);
    if (!empty($subfolderGlob)) return $subfolderGlob[0];
    
    $subfolderGlob = glob($globPattern . $sanitizedBase);
    if (!empty($subfolderGlob)) return $subfolderGlob[0];

    // ── Attempt 6: Glob search by the original filename portion ──
    if (preg_match('/^\d{4}-\d{2}-\d{2}[_\s]\d{2}[:\-_]\d{2}[:\-_]\d{2}(?:am|pm)?(.+)$/i', $basename, $matches)) {
        $docName = $matches[1];
        $globPattern = $uploadsBasePath . '*' . $docName;
        $found = glob($globPattern);
        if (!empty($found)) return $found[0];
    }

    // Nothing found — return the best guess so the caller can log the "not found" error
    return $uploadsBasePath . $relativePath;
}


// ─────────────────────────────────────────────
// HELPER: Build the R2 object key
// ─────────────────────────────────────────────

function buildR2Key($dbValue, $r2Folder)
{
    $filename = basename($dbValue);

    // If DB value already has subfolder structure under "uploads/"
    if (strpos($dbValue, 'uploads/') === 0) {
        $afterUploads = substr($dbValue, 8);
        if (strpos($afterUploads, '/') !== false) {
            // Sanitize colons → underscores (DB may store colons in timestamps)
            return str_replace(':', '_', $afterUploads);
        }
    }

    // Sanitize colons from filename for a clean R2 key
    $filename = str_replace(':', '_', $filename);

    return $r2Folder . '/' . $filename;
}


// ═════════════════════════════════════════════
//  MAIN MIGRATION LOOP
// ═════════════════════════════════════════════

logMsg("═══════════════════════════════════════════════════════════════");
logMsg("  CLOUDFLARE R2 MIGRATION SCRIPT");
logMsg("═══════════════════════════════════════════════════════════════");
logMsg("  Mode:         " . ($isDryRun ? "🔍 DRY RUN (no changes)" : "🚀 LIVE RUN"));
logMsg("  Config:       $configFile");
logMsg("  Database:     {$dbConfig['name']}@{$dbConfig['host']}");
logMsg("  Bucket:       $bucket");
logMsg("  Public URL:   $publicBaseURL");
logMsg("  Uploads Path: $uploadsBasePath");
if ($filterTable) {
    logMsg("  Table Filter: $filterTable");
}
if ($minSize !== null) {
    logMsg("  Min Size:     $minSize bytes");
}
if ($maxSize !== null) {
    logMsg("  Max Size:     $maxSize bytes");
}
logMsg("═══════════════════════════════════════════════════════════════");
echo "\n";

foreach ($tableMappings as $mapping) {
    $table = $mapping['table'];
    $column = $mapping['column'];
    $r2Folder = $mapping['r2_folder'];
    $idColumn = $mapping['id_column'] ?? 'id'; // Support custom primary key

    logMsg("──────────────────────────────────────────────");
    logMsg("📋 Table: `$table` → Column: `$column` → R2: $r2Folder/");
    logMsg("──────────────────────────────────────────────");

    // Fetch all records with non-empty file values
    $escapedTable = mysqli_real_escape_string($conn, $table);
    $escapedColumn = mysqli_real_escape_string($conn, $column);
    $escapedIdCol = mysqli_real_escape_string($conn, $idColumn);

    $query = "SELECT `$escapedIdCol`, `$escapedColumn` FROM `$escapedTable` ";
    $query .= "WHERE `$escapedColumn` IS NOT NULL AND TRIM(`$escapedColumn`) != ''";

    $result = mysqli_query($conn, $query);

    if (!$result) {
        logMsg("⚠️ Query failed for `$table`: " . mysqli_error($conn), 'ERROR');
        continue;
    }

    $count = mysqli_num_rows($result);
    logMsg("Found $count records with file references.");

    $tableUploaded = 0;
    $tableSkipped = 0;

    while ($row = mysqli_fetch_assoc($result)) {
        $stats['total_records']++;
        $id = $row[$idColumn];
        $dbValue = trim($row[$column]);

        if (empty($dbValue)) {
            $stats['skipped_empty']++;
            continue;
        }

        // ─── STEP 1a: Already on R2? → SKIP ───
        if (isAlreadyOnR2($dbValue, $publicBaseURL)) {
            $stats['already_on_r2']++;
            $tableSkipped++;
            continue;
        }

        // ─── STEP 1b: Build R2 key & check if object exists ───
        $r2Key = buildR2Key($dbValue, $r2Folder);

        if (fileExistsInR2($s3, $bucket, $r2Key)) {
            // File is on R2 but DB path wasn't updated — fix it
            $newURL = $publicBaseURL . $r2Key;
            logMsg("  [$idColumn:$id] Object exists in R2, updating DB path → $newURL");

            if (!$isDryRun) {
                $updateSQL = "UPDATE `$escapedTable` SET `$escapedColumn` = '"
                    . mysqli_real_escape_string($conn, $newURL)
                    . "' WHERE `$escapedIdCol` = '"
                    . mysqli_real_escape_string($conn, $id) . "'";

                if (!mysqli_ping($conn)) {
                    logMsg("  [$idColumn:$id] 🔄 Reconnecting to database...");
                    mysqli_close($conn);
                    $conn = mysqli_connect($dbConfig['host'], $dbConfig['username'], $dbConfig['password'], $dbConfig['name'], $dbConfig['port'] ?? 3306);
                    mysqli_set_charset($conn, $dbConfig['charset'] ?? 'utf8mb4');
                }

                if (mysqli_query($conn, $updateSQL)) {
                    $stats['db_updated']++;
                    logMsg("  [$idColumn:$id] ✅ DB path updated.", 'SUCCESS');
                } else {
                    $stats['db_update_fail']++;
                    logMsg("  [$idColumn:$id] ❌ DB update failed: " . mysqli_error($conn), 'ERROR');
                }
            }
            $stats['already_on_r2']++;
            $tableSkipped++;
            continue;
        }

        // ─── STEP 2b: File NOT in R2 → UPLOAD ───
        $localFilePath = resolveLocalFilePath($dbValue, $uploadsBasePath, $r2Folder);

        if (!file_exists($localFilePath) || !is_file($localFilePath)) {
            $stats['file_not_found']++;
            logMsg("  [$idColumn:$id] ⚠️ Local file not found: $localFilePath", 'WARN');
            continue;
        }

        $localFileSize = filesize($localFilePath);

        // ─── Size filtering ───
        if ($minSize !== null && $localFileSize < $minSize) {
            $stats['skipped_size']++;
            continue;
        }
        if ($maxSize !== null && $localFileSize > $maxSize) {
            $stats['skipped_size']++;
            continue;
        }

        $sizeMB = round($localFileSize / 1024 / 1024, 2);
        logMsg("  [$idColumn:$id] 📤 Uploading: " . basename($localFilePath) . " → $r2Key ($sizeMB MB)");

        if ($isDryRun) {
            $stats['uploaded']++;
            $stats['db_updated']++;
            $tableUploaded++;
            logMsg("  [$idColumn:$id] 🔍 DRY RUN: Would upload + update DB.", 'DRY');
            continue;
        }

        // Upload to R2
        try {
            $s3->putObject([
                'Bucket' => $bucket,
                'Key' => $r2Key,
                'SourceFile' => $localFilePath,
                'ACL' => 'public-read',
            ]);
        } catch (S3Exception $e) {
            $stats['upload_failed']++;
            logMsg("  [$idColumn:$id] ❌ Upload FAILED: " . $e->getMessage(), 'ERROR');
            continue;
        }

        // ─── STEP 2b(i): Verify upload ───
        if (!verifyUpload($s3, $bucket, $r2Key, $localFileSize)) {
            $stats['verify_failed']++;
            logMsg("  [$idColumn:$id] ❌ Verification FAILED. Skipping DB update.", 'ERROR');

            // Clean up corrupt upload
            try {
                $s3->deleteObject(['Bucket' => $bucket, 'Key' => $r2Key]);
                logMsg("  [$idColumn:$id] 🗑️ Cleaned up failed upload.", 'WARN');
            } catch (S3Exception $e) {
                logMsg("  [$idColumn:$id] ⚠️ Cleanup error: " . $e->getMessage(), 'WARN');
            }
            continue;
        }

        $stats['uploaded']++;
        logMsg("  [$idColumn:$id] ✅ Upload verified.");

        // ─── STEP 2b(ii): Update DB path ───
        $newURL = $publicBaseURL . $r2Key;
        $updateSQL = "UPDATE `$escapedTable` SET `$escapedColumn` = '"
            . mysqli_real_escape_string($conn, $newURL)
            . "' WHERE `$escapedIdCol` = '"
            . mysqli_real_escape_string($conn, $id) . "'";

        if (!mysqli_ping($conn)) {
            logMsg("  [$idColumn:$id] 🔄 Reconnecting to database...");
            mysqli_close($conn);
            $conn = mysqli_connect($dbConfig['host'], $dbConfig['username'], $dbConfig['password'], $dbConfig['name'], $dbConfig['port'] ?? 3306);
            mysqli_set_charset($conn, $dbConfig['charset'] ?? 'utf8mb4');
        }

        if (mysqli_query($conn, $updateSQL)) {
            $stats['db_updated']++;
            $tableUploaded++;
            logMsg("  [$idColumn:$id] ✅ DB updated → $newURL", 'SUCCESS');
        } else {
            $stats['db_update_fail']++;
            logMsg("  [$idColumn:$id] ❌ DB update FAILED: " . mysqli_error($conn), 'ERROR');
            logMsg("  [$idColumn:$id] ⚠️ File is on R2 but DB still has local path!", 'WARN');
        }
    }

    logMsg("📊 `$table`.`$column`: Uploaded=$tableUploaded | Skipped=$tableSkipped");
    echo "\n";
}


// ═════════════════════════════════════════════
//  FINAL SUMMARY
// ═════════════════════════════════════════════

echo "\n";
logMsg("═══════════════════════════════════════════════════════════════");
logMsg("  MIGRATION COMPLETE" . ($isDryRun ? " (DRY RUN)" : ""));
logMsg("═══════════════════════════════════════════════════════════════");
logMsg("  Total records scanned:    {$stats['total_records']}");
logMsg("  Already on R2 (skipped):  {$stats['already_on_r2']}");
logMsg("  Empty values (skipped):   {$stats['skipped_empty']}");
logMsg("  Size filter (skipped):    {$stats['skipped_size']}");
logMsg("  Files not found locally:  {$stats['file_not_found']}");
logMsg("  Successfully uploaded:    {$stats['uploaded']}");
logMsg("  Upload failures:          {$stats['upload_failed']}");
logMsg("  Verification failures:    {$stats['verify_failed']}");
logMsg("  DB paths updated:         {$stats['db_updated']}");
logMsg("  DB update failures:       {$stats['db_update_fail']}");
logMsg("═══════════════════════════════════════════════════════════════");
logMsg("  Log file: $logFile");
logMsg("═══════════════════════════════════════════════════════════════");

fclose($logHandle);
mysqli_close($conn);

?>