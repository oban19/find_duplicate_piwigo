<?php
/*
Plugin Name: Find Duplicates
Version: 1.1.11
Description: Tool for finding duplicate and similar images (pHash/dHash/time).
Plugin URI: http://piwigo.org/ext/extension_view.php?eid=1123
Author: Oban
Has Settings: true
*/

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

define('FIND_DUPLICATES_ID', basename(dirname(__FILE__)));
define('FIND_DUPLICATES_PATH', PHPWG_PLUGINS_PATH . FIND_DUPLICATES_ID . '/');
define('FIND_DUPLICATES_ADMIN', get_root_url() . 'admin.php?page=plugin-' . FIND_DUPLICATES_ID);
define('FIND_DUPLICATES_VERSION', '1.1.11');

global $prefixeTable;
define('FIND_DUPLICATES_TABLE', $prefixeTable . 'find_duplicates_hashes');


// -----------------------------------------------------------------
// Difference Hash (dHash) generator
// -----------------------------------------------------------------
function find_duplicates_compute_dhash($filepath)
{
  if (!function_exists('imagecreatefromstring'))
  {
    return null;
  }

  $img_content = @file_get_contents($filepath);
  if (!$img_content) return null;

  $img = @imagecreatefromstring($img_content);
  if (!$img) return null;

  $width = 9;
  $height = 8;
  
  $thumb = imagecreatetruecolor($width, $height);
  if (!$thumb) {
    if (is_resource($img)) {
      imagedestroy($img);
    } else {
      unset($img);
    }
    return null;
  }

  // Convert to grayscale and crop center square before resizing to 9x8
  imagefilter($img, IMG_FILTER_GRAYSCALE);
  $src_w = imagesx($img);
  $src_h = imagesy($img);
  $side = min($src_w, $src_h);
  if ($side <= 0) return null;
  $src_x = (int)(($src_w - $side) / 2);
  $src_y = (int)(($src_h - $side) / 2);
  imagecopyresampled($thumb, $img, 0, 0, $src_x, $src_y, $width, $height, $side, $side);

  $hash = '';
  for ($y = 0; $y < $height; $y++) {
    for ($x = 0; $x < $width - 1; $x++) {
      $color1 = imagecolorat($thumb, $x, $y);
      $color2 = imagecolorat($thumb, $x + 1, $y);

      // We only care about luminosity since it's grayscale
      $lum1 = $color1 & 0xFF;
      $lum2 = $color2 & 0xFF;

      $hash .= ($lum1 > $lum2) ? '1' : '0';
    }
  }

  if (is_resource($thumb)) {
    imagedestroy($thumb);
  } else {
    unset($thumb);
  }
  if (is_resource($img)) {
    imagedestroy($img);
  } else {
    unset($img);
  }

  return $hash; // 64-bit binary string
}

// Resolve optimal source image path for dHash computation (prefers IMG_SQUARE derivative)
function find_duplicates_get_hash_source_path($row)
{
  if (!is_array($row) || !isset($row['path'])) {
    return '';
  }

  if (class_exists('SrcImage') && class_exists('DerivativeImage') && class_exists('ImageStdParams')) {
    try {
      $src = new SrcImage($row);
      $square_type = defined('IMG_SQUARE') ? IMG_SQUARE : 'square';
      $params = ImageStdParams::get_by_type($square_type);
      $thumb = new DerivativeImage($params, $src);
      $thumb_path_rel = $thumb->get_path();
      if (!empty($thumb_path_rel)) {
        if (file_exists($thumb_path_rel)) {
          return $thumb_path_rel;
        }
        $thumb_path_abs = PHPWG_ROOT_PATH . $thumb_path_rel;
        if (file_exists($thumb_path_abs)) {
          return $thumb_path_abs;
        }
      }
    } catch (Throwable $e) {
      // Ignore derivative lookup errors and fall back to original image path
    }
  }

  return PHPWG_ROOT_PATH . $row['path'];
}

// -----------------------------------------------------------------
// Hooks
// -----------------------------------------------------------------
add_event_handler('init', 'find_duplicates_init');
function find_duplicates_init()
{
  load_language('plugin.lang', FIND_DUPLICATES_PATH);
}

add_event_handler('loc_end_add_uploaded_file', 'find_duplicates_on_upload');

function find_duplicates_on_upload($image_infos)
{
  if (!is_array($image_infos) || !isset($image_infos['id']))
  {
    return;
  }

  if (isset($image_infos['path']))
  {
    $filepath = find_duplicates_get_hash_source_path($image_infos);
    if ($filepath !== '' && file_exists($filepath))
    {
      $hash = find_duplicates_compute_dhash($filepath);
      if ($hash !== null)
      {
        pwg_query("INSERT INTO " . FIND_DUPLICATES_TABLE . " (image_id, phash) VALUES (" . (int)$image_infos['id'] . ", '" . $hash . "') ON DUPLICATE KEY UPDATE phash = VALUES(phash)");
      }
    }
  }

  find_duplicates_clear_cache();
}

// Clean up when an image is deleted
add_event_handler('delete_elements', 'find_duplicates_on_delete');

function find_duplicates_on_delete($ids)
{
  if (empty($ids)) return;

  $clean_ids = array();
  foreach ((array)$ids as $id) {
    $int_id = (int)$id;
    if ($int_id > 0) {
      $clean_ids[$int_id] = $int_id;
    }
  }

  if (empty($clean_ids)) return;

  $chunks = array_chunk($clean_ids, 500);
  foreach ($chunks as $chunk) {
    $query = '
      DELETE FROM ' . FIND_DUPLICATES_TABLE . '
      WHERE image_id IN (' . implode(',', $chunk) . ')';
    pwg_query($query);
  }

  find_duplicates_clear_cache();
}

// Batch set favorite status for a group of image IDs
function find_duplicates_set_group_favorite($raw_ids, $target_status = 1)
{
  $target_status = ((int)$target_status === 0) ? 0 : 1;
  $id_parts = is_array($raw_ids) ? $raw_ids : explode(',', (string)$raw_ids);
  $valid_ids = array();
  foreach ($id_parts as $part) {
    $id = (int)$part;
    if ($id > 0) {
      $valid_ids[$id] = $id;
    }
  }

  if (empty($valid_ids)) {
    return array();
  }

  $values = array();
  $batch_size = 500;
  foreach ($valid_ids as $id) {
    $values[] = '(' . (int)$id . ', ' . (int)$target_status . ')';
    if (count($values) >= $batch_size) {
      pwg_query('INSERT INTO ' . FIND_DUPLICATES_TABLE . ' (image_id, is_favorite) VALUES ' . implode(', ', $values) . ' ON DUPLICATE KEY UPDATE is_favorite = ' . (int)$target_status);
      $values = array();
    }
  }

  if (!empty($values)) {
    pwg_query('INSERT INTO ' . FIND_DUPLICATES_TABLE . ' (image_id, is_favorite) VALUES ' . implode(', ', $values) . ' ON DUPLICATE KEY UPDATE is_favorite = ' . (int)$target_status);
  }

  return array_values($valid_ids);
}

// Ensure _data/find_duplicates_cache exists and is protected from direct HTTP access
function find_duplicates_ensure_cache_dir()
{
  $cache_dir = PHPWG_ROOT_PATH . '_data/find_duplicates_cache';
  if (!is_dir($cache_dir)) {
    @mkdir($cache_dir, 0755, true);
  }
  if (!file_exists($cache_dir . '/index.htm')) {
    @file_put_contents($cache_dir . '/index.htm', '');
  }
  if (!file_exists($cache_dir . '/.htaccess')) {
    $htaccess_content = "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n";
    @file_put_contents($cache_dir . '/.htaccess', $htaccess_content);
  }

  return $cache_dir;
}

// Generate an instance-specific 16-char secret suffix for cache filenames
function find_duplicates_get_cache_secret()
{
  global $conf;
  $secret = isset($conf['secret_key'])
    ? (string)$conf['secret_key']
    : (function_exists('conf_get_param') ? (string)conf_get_param('secret_key', '') : '');

  return substr(hash('sha256', $secret . '|' . FIND_DUPLICATES_TABLE . '|' . PHPWG_ROOT_PATH), 0, 16);
}

// Clear JSON cache files in _data/find_duplicates_cache
function find_duplicates_clear_cache($remove_dir = false)
{
  $cache_dir = PHPWG_ROOT_PATH . '_data/find_duplicates_cache';
  if (!is_dir($cache_dir)) {
    return;
  }

  $files = glob($cache_dir . '/*.json');
  if (is_array($files)) {
    foreach ($files as $file) {
      @unlink($file);
    }
  }

  if ($remove_dir) {
    if (file_exists($cache_dir . '/.htaccess')) {
      @unlink($cache_dir . '/.htaccess');
    }
    if (file_exists($cache_dir . '/index.htm')) {
      @unlink($cache_dir . '/index.htm');
    }
    @rmdir($cache_dir);
  }
}

// Group images taken within a rolling time interval (in seconds), optionally per-album
function find_duplicates_group_by_time($images_data, $fd_time_interval, $same_album = 0)
{
  if (!is_array($images_data) || empty($images_data)) {
    return array();
  }

  $fd_time_interval = (int)$fd_time_interval;
  $valid_items = array();

  foreach ($images_data as $img_id => $img_data) {
    if (empty($img_data['DATE_CREATION'])) {
      continue;
    }
    $date_str = (string)$img_data['DATE_CREATION'];
    if (strpos($date_str, '0000-00-00') === 0) {
      continue;
    }
    $ts = strtotime($date_str);
    if ($ts === false || $ts <= 0) {
      continue;
    }
    $valid_items[] = array(
      'id' => $img_id,
      'ts' => $ts,
      'albums' => (!empty($img_data['ALBUMS']) && is_array($img_data['ALBUMS'])) ? $img_data['ALBUMS'] : array(),
    );
  }

  if (count($valid_items) < 2) {
    return array();
  }

  usort($valid_items, function ($a, $b) {
    if ($a['ts'] !== $b['ts']) {
      return $a['ts'] <=> $b['ts'];
    }
    if ((int)$a['id'] !== (int)$b['id']) {
      return (int)$a['id'] <=> (int)$b['id'];
    }
    return strcmp((string)$a['id'], (string)$b['id']);
  });

  if (!$same_album) {
    $groups = array();
    $current_group = array();
    $last_time = null;

    foreach ($valid_items as $item) {
      if ($last_time !== null && ($item['ts'] - $last_time) <= $fd_time_interval) {
        $current_group[] = $item['id'];
      } else {
        if (count($current_group) > 1) {
          $groups[] = $current_group;
        }
        $current_group = array($item['id']);
      }
      $last_time = $item['ts'];
    }

    if (count($current_group) > 1) {
      $groups[] = $current_group;
    }

    return $groups;
  }

  // Partition already-sorted items into per-album buckets so interleaved photos from other albums never split a group
  $album_buckets = array();
  foreach ($valid_items as $item) {
    foreach ($item['albums'] as $album_id => $_name) {
      $album_buckets[$album_id][] = $item;
    }
  }

  $seen_keys = array();
  $group_entries = array();

  foreach ($album_buckets as $bucket) {
    if (count($bucket) < 2) {
      continue;
    }

    $current_group = array();
    $current_first_ts = null;
    $last_time = null;

    foreach ($bucket as $item) {
      if ($last_time !== null && ($item['ts'] - $last_time) <= $fd_time_interval) {
        $current_group[] = $item['id'];
      } else {
        if (count($current_group) > 1) {
          $sorted_ids = $current_group;
          sort($sorted_ids, SORT_REGULAR);
          $key = implode(',', $sorted_ids);
          if (!isset($seen_keys[$key])) {
            $seen_keys[$key] = true;
            $group_entries[] = array(
              'ids' => $current_group,
              'first_ts' => $current_first_ts,
              'first_id' => $current_group[0],
              'key' => $key,
            );
          }
        }
        $current_group = array($item['id']);
        $current_first_ts = $item['ts'];
      }
      $last_time = $item['ts'];
    }

    if (count($current_group) > 1) {
      $sorted_ids = $current_group;
      sort($sorted_ids, SORT_REGULAR);
      $key = implode(',', $sorted_ids);
      if (!isset($seen_keys[$key])) {
        $seen_keys[$key] = true;
        $group_entries[] = array(
          'ids' => $current_group,
          'first_ts' => $current_first_ts,
          'first_id' => $current_group[0],
          'key' => $key,
        );
      }
    }
  }

  usort($group_entries, function ($a, $b) {
    if ($a['first_ts'] !== $b['first_ts']) {
      return $a['first_ts'] <=> $b['first_ts'];
    }
    if ((int)$a['first_id'] !== (int)$b['first_id']) {
      return (int)$a['first_id'] <=> (int)$b['first_id'];
    }
    return strcmp($a['key'], $b['key']);
  });

  $groups = array();
  foreach ($group_entries as $entry) {
    $groups[] = $entry['ids'];
  }

  return $groups;
}

// Ensure the is_favorite column exists on the plugin hashes table
function find_duplicates_ensure_favorite_column($table_name = null)
{
  if ($table_name === null) {
    $table_name = FIND_DUPLICATES_TABLE;
  }
  $result = pwg_query("SHOW COLUMNS FROM `" . $table_name . "` LIKE 'is_favorite'");
  if (!pwg_db_fetch_assoc($result)) {
    pwg_query("ALTER TABLE `" . $table_name . "` ADD COLUMN `is_favorite` tinyint(1) NOT NULL DEFAULT 0");
  }

}

// Helper: detect if image is a Motion Picture based on standard vendor naming conventions (Pixel, Samsung, etc.)
function find_duplicates_is_motion_photo($filename, $title = '')
{
  $pattern = '/^((MVIMG_|MotionPhoto_).*\.(jpe?g|heic|heif|png|webp|avif)$|.*[\._-](MP|MotionPhoto)(\.(jpe?g|heic|heif|png|webp|avif))?$)/i';
  return (preg_match($pattern, (string)$filename) || (!empty($title) && preg_match($pattern, (string)$title))) ? 1 : 0;
}


// Add link to the main left menu in admin panel (under Photos)
add_event_handler('loc_begin_admin_page', 'find_duplicates_admin_main_menu');

function find_duplicates_admin_main_menu()
{
  global $template, $page;

  if (isset($page['page'])) {
    $template->assign('U_FIND_DUPLICATES', FIND_DUPLICATES_ADMIN);
    $template->set_prefilter('admin', 'find_duplicates_admin_menu_prefilter');
  }
}

function find_duplicates_admin_menu_prefilter($content)
{
  $search = '<li><a href="{$U_BATCH}"><i class="icon-th"></i>{\'Batch Manager\'|@translate}</a></li>';
  $replace = $search . "\n" . '				<li><a href="{$U_FIND_DUPLICATES}"><i class="icon-docs"></i>{\'Find Duplicates\'|@translate}</a></li>';

  return str_replace($search, $replace, $content);
}

// Admin menu link
add_event_handler('get_admin_plugin_menu_links', 'find_duplicates_admin_menu');
function find_duplicates_admin_menu($menu)
{
  $menu[] = array(
    'NAME' => l10n('Find Duplicates'),
    'URL'  => FIND_DUPLICATES_ADMIN,
  );
  return $menu;
}
