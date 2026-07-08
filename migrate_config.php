<?php
/**
 * =========================================================================
 *  MIGRATION CONFIG — Project-Specific Settings
 * =========================================================================
 *
 *  Configure this file for YOUR project before running migrate_to_r2.php
 *
 *  SECTIONS TO CONFIGURE:
 *    1. Database credentials
 *    2. Cloudflare R2 credentials
 *    3. Local uploads path
 *    4. Table-to-column mappings (which tables/columns contain file paths)
 *
 * =========================================================================
 */

return [

    // ─────────────────────────────────────────────
    // 1. DATABASE CONNECTION
    // ─────────────────────────────────────────────
    'database' => [
        'host' => '103.102.234.161',
        'username' => 'swcqxuac_SP_Nanded',
        'password' => 'B{UUSMgY)P1zfl_?',
        'name' => 'swcqxuac_SP_Nanded',
        'port' => 3306,
        'charset' => 'utf8mb4',
    ],

    // ─────────────────────────────────────────────
    // 2. CLOUDFLARE R2 CREDENTIALS
    // ─────────────────────────────────────────────
    'r2' => [
        'account_id' => 'c945dad0e76eeb69446035b1aa0c7e11',
        'access_key' => '2f9d96d8d5098a9bcb943878790f4d0f',
        'secret_key' => '3f45fed08e96f1d886932a7daf9d372bc6036b2cc871bcacd570542cc6ca9cbb',
        'bucket' => 'nandedpolice',
        'public_base_url' => 'https://pub-812f459ba59a47eb8019d2386ae020d0.r2.dev/',
    ],

    // ─────────────────────────────────────────────
    // 3. LOCAL FILE STORAGE
    // ─────────────────────────────────────────────
    // Absolute or relative path to the folder where uploaded files are stored.
    // Use __DIR__ to make it relative to THIS config file's location.
    'uploads_path' => __DIR__ . '/uploads/',

    // Path to Composer autoload (relative to the migration script or absolute)
    'autoload_path' => __DIR__ . '/vendor/autoload.php',

    // ─────────────────────────────────────────────
    // 4. TABLE → FILE COLUMN MAPPINGS
    // ─────────────────────────────────────────────
    // Define which database tables and columns contain file references.
    //
    // Format for each entry:
    //   'table'     => Database table name
    //   'column'    => Column that stores the file path/URL
    //   'r2_folder' => Subfolder prefix in R2 bucket (e.g., "gallery" → R2 key = "gallery/filename.jpg")
    //   'id_column' => (Optional) Primary key column name. Defaults to 'id' if not specified.
    //
    // 💡 TIP: If a table has multiple file columns (e.g., 'image' AND 'video'),
    //         add separate entries for each column.
    //
    // EXAMPLE for a different project:
    //   ['table' => 'products',  'column' => 'thumbnail',   'r2_folder' => 'products/thumbs'],
    //   ['table' => 'products',  'column' => 'gallery_img',  'r2_folder' => 'products/gallery'],
    //   ['table' => 'users',     'column' => 'avatar',       'r2_folder' => 'avatars'],
    //   ['table' => 'documents', 'column' => 'pdf_path',     'r2_folder' => 'docs', 'id_column' => 'doc_id'],

    'table_mappings' => [
        ['table' => 'accident',             'column' => 'file', 'r2_folder' => 'accident'],
        ['table' => 'arrestAccused',        'column' => 'file',  'r2_folder' => 'arrestAccused'],
        ['table' => 'complaints',           'column' => 'photo', 'r2_folder' => 'complaints', 'id_column' => 'ticket_id'],
        ['table' => 'complainform',         'column' => 'file',  'r2_folder' => 'complainform'],
        ['table' => 'crimeMonthly',         'column' => 'file',  'r2_folder' => 'crimeMonthly'],
        ['table' => 'dcrPublish',           'column' => 'file',  'r2_folder' => 'dcrPublish'],
        ['table' => 'downloadForms',        'column' => 'form',  'r2_folder' => 'downloadForm'],
        ['table' => 'gallery_master',       'column' => 'image', 'r2_folder' => 'gallery'],
        ['table' => 'gallery_master',       'column' => 'video', 'r2_folder' => 'gallery'],
        ['table' => 'gazzette',             'column' => 'file',  'r2_folder' => 'gazzet'],
        ['table' => 'kayInitiatives',       'column' => 'image', 'r2_folder' => 'latestInitiative'],
        ['table' => 'leaveApprovalOrder',   'column' => 'file',  'r2_folder' => 'leaveApprovalOrder'],
        ['table' => 'loksevaAct',           'column' => 'file',  'r2_folder' => 'loksevaAct'],
        ['table' => 'missing',              'column' => 'file',  'r2_folder' => 'missing'],
        ['table' => 'news',                 'column' => 'file',  'r2_folder' => 'lastNew'],
        ['table' => 'officers',             'column' => 'image', 'r2_folder' => 'officers'],
        ['table' => 'police_station',       'column' => 'image', 'r2_folder' => 'policestation'],
        ['table' => 'positiveStory',        'column' => 'file',  'r2_folder' => 'positiveStory'],
        ['table' => 'recruitment',          'column' => 'file',  'r2_folder' => 'recruitment'],
        ['table' => 'RTI',                  'column' => 'file',  'r2_folder' => 'RTI'],
        ['table' => 'SC-ST-Act',            'column' => 'file',  'r2_folder' => 'SC-ST-Act'],
        ['table' => 'unclaimedDeadBodies',  'column' => 'file',  'r2_folder' => 'unclaimedDeadBodies'],
        ['table' => 'latestUpdates',        'column' => 'file',  'r2_folder' => 'latestUpdates'],
    ],

];
