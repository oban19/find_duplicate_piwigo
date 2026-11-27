<?php
// Mocking global environment for Piwigo in an isolated temp directory
// so tests never create _data/ or temporary files inside the repository workspace.
$fd_test_root = rtrim(sys_get_temp_dir(), '/\\') . '/piwigo_fd_test_' . getmypid() . '_' . uniqid('', true) . '/';
if (!is_dir($fd_test_root)) {
    @mkdir($fd_test_root, 0755, true);
}

register_shutdown_function(function () use ($fd_test_root) {
    if (!is_dir($fd_test_root)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fd_test_root, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($fd_test_root);
});

define('PHPWG_ROOT_PATH', $fd_test_root);
define('PHPWG_PLUGINS_PATH', PHPWG_ROOT_PATH . 'plugins/');
if (!defined('ACCESS_ADMINISTRATOR')) {
    define('ACCESS_ADMINISTRATOR', 'admin');
}

// Dummy function to replace core function
if (!function_exists('l10n')) { function l10n($str) { return $str; } }
function get_root_url() {
    return '/';
}

if (!function_exists('check_status')) {
    function check_status($status) {
        return true;
    }
}

global $prefixeTable;
$prefixeTable = 'piwigo_';

// Mock add_event_handler so it doesn't fail when the plugin includes main.inc.php
if (!function_exists('add_event_handler')) {
    function add_event_handler($event, $function, $priority = 50) {
        // Do nothing for tests
    }
}

// Global variables to capture SQL queries and mock fetch results for assertions
global $captured_queries, $mock_fetch_assoc_result, $mock_fetch_assoc_queue;
$captured_queries = [];
$mock_fetch_assoc_result = false;
$mock_fetch_assoc_queue = null;

if (!function_exists('pwg_query')) {
    function pwg_query($query) {
        global $captured_queries;
        $captured_queries[] = trim($query);
        return true;
    }
}

if (!function_exists('pwg_db_fetch_assoc')) {
    function pwg_db_fetch_assoc($result) {
        global $mock_fetch_assoc_result, $mock_fetch_assoc_queue;
        if (is_array($mock_fetch_assoc_queue)) {
            return !empty($mock_fetch_assoc_queue) ? array_shift($mock_fetch_assoc_queue) : false;
        }
        return $mock_fetch_assoc_result;
    }
}

// Include the main plugin file to make functions available
require_once __DIR__ . '/../main.inc.php';

// Helper function to reset test state
function reset_captured_queries() {
    global $captured_queries, $mock_fetch_assoc_result, $mock_fetch_assoc_queue;
    $captured_queries = [];
    $mock_fetch_assoc_result = false;
    $mock_fetch_assoc_queue = null;
}

// Helper function to get captured queries
function get_captured_queries() {
    global $captured_queries;
    return $captured_queries;
}
