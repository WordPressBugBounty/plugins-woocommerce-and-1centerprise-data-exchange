<?php
if (!defined('ABSPATH')) exit(esc_html(__("The exchange using direct URL is not supported anymore. Please change your exchange URL to http://example.com/?wc1c=exchange.", 'woocommerce-and-1centerprise-data-exchange')));

if (!defined('WC1C_SUPPRESS_NOTICES')) define('WC1C_SUPPRESS_NOTICES', false);
if (!defined('WC1C_FILE_LIMIT')) define('WC1C_FILE_LIMIT', null);
if (!defined('WC1C_XML_CHARSET')) define('WC1C_XML_CHARSET', 'UTF-8');
if (!defined('WC1C_DISABLE_VARIATIONS')) define('WC1C_DISABLE_VARIATIONS', false);
if (!defined('WC1C_OUTOFSTOCK_STATUS')) define('WC1C_OUTOFSTOCK_STATUS', 'outofstock');
if (!defined('WC1C_MANAGE_STOCK')) define('WC1C_MANAGE_STOCK', 'yes');
if (!defined('WC1C_CLEANUP_GARBAGE')) define('WC1C_CLEANUP_GARBAGE', true);
define('WC1C_TIMESTAMP', time());

function wc1c_query_vars($query_vars) {
  $query_vars[] = 'wc1c';

  return $query_vars;
}
add_filter('query_vars', 'wc1c_query_vars');

add_action('init', 'wc1c_add_rewrite_rules', 1000);

function wc1c_is_debug() {
  return defined('WP_DEBUG') && WP_DEBUG || defined('WC1C_DEBUG') && WC1C_DEBUG;
}

function wc1c_wpdb_end($is_commit = false, $no_check = false) {
  global $wpdb, $wc1c_is_transaction;

  if (empty($wc1c_is_transaction)) return;

  $wc1c_is_transaction = false;

  if ($is_commit) {
    $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
  } else {
    $wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
  }
  if (!$no_check) wc1c_check_wpdb_error();

  if (wc1c_is_debug()) {
    if ($is_commit) {
      echo "\ncommit"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    } else {
      echo "\nrollback"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
  }
}

function wc1c_full_request_uri() {
  $uri = 'http';
  if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') $uri .= 's';
  $server_name = isset($_SERVER['SERVER_NAME']) ? wp_unslash($_SERVER['SERVER_NAME']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
  $uri .= "://$server_name";
  if (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] != 80) {
    $uri .= ':' . intval($_SERVER['SERVER_PORT']);
  }
  if (isset($_SERVER['REQUEST_URI'])) $uri .= wp_unslash($_SERVER['REQUEST_URI']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

  return $uri;
}

function wc1c_error($message, $type = "Error", $no_exit = false) {
  global $wc1c_is_error;

  $wc1c_is_error = true;

  $message = "$type: $message";
  $last_char = substr($message, -1);
  if (!in_array($last_char, array('.', '!', '?'))) $message .= '.';

  error_log($message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
  echo esc_html($message) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

  if (wc1c_is_debug()) {
    echo "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    debug_print_backtrace(); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_print_backtrace

    $info = array(
      "Request URI" => wc1c_full_request_uri(),
      "Server API" => PHP_SAPI,
      "Memory limit" => ini_get('memory_limit'),
      "Maximum POST size" => ini_get('post_max_size'),
      "PHP version" => PHP_VERSION,
      "WordPress version" => get_bloginfo('version'),
      "Plugin version" => WC1C_VERSION,
    );
    echo "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    foreach ($info as $info_name => $info_value) {
      echo esc_html($info_name) . ': ' . esc_html($info_value) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
  }

  if (!$no_exit) {
    wc1c_wpdb_end();

    exit;
  }
}

function wc1c_set_strict_mode() {
  // $error_reporting_level = !WC1C_SUPPRESS_NOTICES ? -1 : E_ALL & ~E_NOTICE;
  // error_reporting($error_reporting_level);
  set_error_handler('wc1c_strict_error_handler'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
  set_exception_handler('wc1c_strict_exception_handler');
}

function wc1c_output_callback($buffer) {
  global $wc1c_is_error;

  if (!headers_sent()) {
    $is_xml = isset($_GET['mode']) && $_GET['mode'] == 'query'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $content_type = !$is_xml || $wc1c_is_error ? 'text/plain' : 'text/xml';
    header("Content-Type: $content_type; charset=" . WC1C_XML_CHARSET);
  }

  if (WC1C_XML_CHARSET == 'UTF-8') {
    $buffer = "\xEF\xBB\xBF$buffer";
  }
  else {
    $buffer = mb_convert_encoding($buffer, WC1C_XML_CHARSET, 'UTF-8');
  }

  return $buffer;
}

function wc1c_set_output_callback() {
  ob_start('wc1c_output_callback');
}

function wc1c_strict_error_handler($errno, $errstr, $errfile, $errline, $errcontext) {
  if (error_reporting() === 0) return false; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting

  switch ($errno) {
    // case E_NOTICE:
    // case E_USER_NOTICE:
    //   $type = "Notice";
    //   break;
    // case E_WARNING:
    // case E_USER_WARNING:
    //   $type = "Warning";
    //   break;
    case E_ERROR:
    case E_USER_ERROR:
      $type = "Fatal Error";
      break;
    default:
      $type = "Unknown Error";
  }
  if (!isset($type)) return false;

  $message = sprintf("%s in %s on line %d", $errstr, $errfile, $errline);
  wc1c_error($message, "PHP $type");
}

function wc1c_strict_exception_handler($exception) {
  $message = sprintf("%s in %s on line %d", $exception->getMessage(), $exception->getFile(), $exception->getLine());
  wc1c_error($message, "Exception");
}

function wc1c_fix_fastcgi_get() {
  if (!$_GET && isset($_SERVER['REQUEST_URI'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $query = wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_QUERY); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    parse_str($query, $_GET); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
  }
}

function wc1c_cleanup_dir($path_dir) {
  $files = array_diff(scandir($path_dir), array('.', '..'));
  foreach ($files as $file) {
    $path = "$path_dir/$file";
    (is_dir($path) ? wc1c_cleanup_dir($path) : wp_delete_file($path));
  }
}

function wc1c_check_permissions($user) {
  if (!user_can($user, 'shop_manager') && !user_can($user, 'administrator')) wc1c_error("No permissions");
}

function wc1c_wp_error($wp_error, $only_error_code = null) {
  $messages = array();
  foreach ($wp_error->get_error_codes() as $error_code) {
    if ($only_error_code && $error_code != $only_error_code) continue;
    
    $wp_error_messages = implode(", ", $wp_error->get_error_messages($error_code));
    $wp_error_messages = wp_strip_all_tags($wp_error_messages);
    $messages[] = sprintf("%s: %s", $error_code, $wp_error_messages);
  }

  wc1c_error(implode("; ", $messages), "WP Error");
}

function wc1c_check_wp_error($wp_error) {
  if (is_wp_error($wp_error)) wc1c_wp_error($wp_error);
}

function wc1c_mode_checkauth() {
  foreach (array('HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION') as $server_key) {
    if (!isset($_SERVER[$server_key])) continue;

    list(, $auth_value) = explode(' ', wp_unslash($_SERVER[$server_key]), 2); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $auth_value = base64_decode($auth_value);
    list($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) = explode(':', $auth_value); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

    break;
  }

  if (!isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) wc1c_error("No authentication credentials"); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

  $wc1c_auth_user = sanitize_text_field(wp_unslash($_SERVER['PHP_AUTH_USER']));
  $wc1c_auth_pw   = wp_unslash($_SERVER['PHP_AUTH_PW']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
  $user = wp_authenticate($wc1c_auth_user, $wc1c_auth_pw);
  wc1c_check_wp_error($user);
  wc1c_check_permissions($user);

  $expiration = time() + apply_filters('auth_cookie_expiration', DAY_IN_SECONDS, $user->ID, false); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
  $auth_cookie = wp_generate_auth_cookie($user->ID, $expiration);

  exit("success\nwc1c-auth\n$auth_cookie"); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

function wc1c_check_auth() {
  $server_software = isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : '';
  if (preg_match("/ Development Server$/", $server_software)) return;

  if (!empty($_COOKIE['wc1c-auth'])) {
    $wc1c_auth_cookie = sanitize_text_field(wp_unslash($_COOKIE['wc1c-auth']));
    $user = wp_validate_auth_cookie($wc1c_auth_cookie, 'auth');
    if (!$user) wc1c_error("Invalid cookie");
  }
  else {
    $user = wp_get_current_user();
    if (!$user->ID) wc1c_error("Not logged in");
  }

  wc1c_check_permissions($user);
}

function wc1c_filesize_to_bytes($filesize) {
  switch (substr($filesize, -1)) {
    case 'G':
    case 'g':
      return (int) $filesize * 1000000000;
    case 'M':
    case 'm':
      return (int) $filesize * 1000000;
    case 'K':
    case 'k':
      return (int) $filesize * 1000;
    default:
      return $filesize;
  }
}

function wc1c_mode_init($type) {
  if (WC1C_CLEANUP_GARBAGE) wc1c_cleanup_dir(WC1C_DATA_DIR . $type);
  @exec("which unzip", $_, $status);
  $is_zip = @$status === 0 || class_exists('ZipArchive');
  if (!$is_zip) wc1c_error("The PHP extension zip is required.");

  $file_limits = array(
    wc1c_filesize_to_bytes('10M'),
    wc1c_filesize_to_bytes(ini_get('post_max_size')),
    wc1c_filesize_to_bytes(ini_get('memory_limit')),
  );
  @exec("grep ^MemFree: /proc/meminfo", $output, $status);
  if (@$status === 0 && $output) {
    $output = preg_split("/\s+/", $output[0]);
    $file_limits[] = intval($output[1] * 1000 * 0.7);
  }
  if (WC1C_FILE_LIMIT) $file_limits[] = wc1c_filesize_to_bytes(WC1C_FILE_LIMIT);
  $file_limit = min($file_limits);

  exit("zip=yes\nfile_limit=$file_limit"); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

function wc1c_mode_file($type, $filename) {
  if ($filename) {
    // Reject executable extensions — defense-in-depth against RCE.
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $blocked_ext = array('php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar', 'shtml', 'shtm', 'cgi', 'pl', 'py', 'rb', 'asp', 'aspx', 'jsp');
    if (in_array($ext, $blocked_ext, true)) {
      wc1c_error(sprintf("File extension not allowed: %s", $ext));
    }

    // Ensure the base directory exists before resolving its real path.
    $base_dir_raw = WC1C_DATA_DIR . sanitize_key($type);
    if (!is_dir($base_dir_raw)) wp_mkdir_p($base_dir_raw);
    $base_dir = realpath($base_dir_raw);
    if (!$base_dir) wc1c_error(sprintf("Failed to resolve base directory for type %s", $type));

    // Build path – ltrim strips leading separators only; realpath below catches
    // any remaining directory traversal (e.g. "subdir/../../../shell.php").
    $path = $base_dir . '/' . ltrim($filename, "./\\");
    $path_dir = dirname($path);
    if (!is_dir($path_dir) && !wp_mkdir_p($path_dir)) wc1c_error(sprintf("Failed to create directories for file %s", $filename));

    // Canonicalize and enforce containment: the resolved directory must be
    // inside (or equal to) the type-specific base directory.
    $real_path_dir = realpath($path_dir);
    if (!$real_path_dir || strpos($real_path_dir . '/', $base_dir . '/') !== 0) {
      wc1c_error(sprintf("Invalid file path for file %s", $filename));
    }

    // Reconstruct the final path from the verified directory + bare filename.
    $path = $real_path_dir . '/' . basename($filename);

    $input_file = fopen("php://input", 'r'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
    $temp_path = "$path~";
    $temp_file = fopen($temp_path, 'w'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
    stream_copy_to_stream($input_file, $temp_file);

    if (is_file($path)) {
      $temp_header = file_get_contents($temp_path, false, null, 0, 32);
      if (strpos($temp_header, "<?xml ") !== false) wp_delete_file($path);
    }

    $temp_file = fopen($temp_path, 'r'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
    $file = fopen($path, 'a'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
    stream_copy_to_stream($temp_file, $file);
    fclose($temp_file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
    fclose($file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
    wp_delete_file($temp_path);
  }

  if ($type == 'catalog') {
    exit("success");
  }
  elseif ($type == 'sale') {
    wc1c_unpack_files($type);

    $data_dir = WC1C_DATA_DIR . $type;
    foreach (glob("$data_dir/*.xml") as $path) {
      $filename = basename($path);
      wc1c_mode_import($type, $filename, 'orders');
    }
  }
}

function wc1c_check_wpdb_error() {
  global $wpdb;

  if (!$wpdb->last_error) return;

  wc1c_error(sprintf("%s for query \"%s\"", $wpdb->last_error, $wpdb->last_query), "DB Error", true);

  wc1c_wpdb_end(false, true);

  exit;
}

function wc1c_disable_time_limit() {
  $disabled_functions = explode(',', ini_get('disable_functions'));
  if (!in_array('set_time_limit', $disabled_functions)) @set_time_limit(0); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
}

function wc1c_set_transaction_mode() {
  global $wpdb, $wc1c_is_transaction;

  wc1c_disable_time_limit();

  register_shutdown_function('wc1c_transaction_shutdown_function');

  $wpdb->show_errors(false); 

  $wc1c_is_transaction = true;
  $wpdb->query("START TRANSACTION"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
  wc1c_check_wpdb_error();
}

function wc1c_transaction_shutdown_function() {
  $error = error_get_last();
  $is_commit = $error['type'] > E_PARSE;

  wc1c_wpdb_end($is_commit);
}

function wc1c_unpack_files($type) {
  $data_dir = WC1C_DATA_DIR . $type;
  $zip_paths = glob("$data_dir/*.zip");
  if (!$zip_paths) return;
  ob_end_clean();

  $command = sprintf("unzip -qqo -x %s -d %s", implode(' ', array_map('escapeshellarg', $zip_paths)), escapeshellarg($data_dir));
  @exec($command, $_, $status);

  if (@$status !== 0) {
    foreach ($zip_paths as $zip_path) {
      $zip = new ZipArchive();
      $result = $zip->open($zip_path);
      if ($result !== true) wc1c_error(sprintf("Failed open archive %s with error code %d", $zip_path, $result));

      $zip->extractTo($data_dir) or wc1c_error(sprintf("Failed to extract from archive %s", $zip_path));
      $zip->close() or wc1c_error(sprintf("Failed to close archive %s", $zip_path));
    }
  }

  foreach ($zip_paths as $zip_path) {
    wp_delete_file($zip_path);
    if (file_exists($zip_path)) wc1c_error(sprintf("Failed to unlink file %s", $zip_path));
  }

  if ($type == 'catalog') exit("progress");
}

function wc1c_xml_start_element_handler($parser, $name, $attrs) {
  global $wc1c_namespace, $wc1c_is_full, $wc1c_names, $wc1c_depth;

  $wc1c_names[] = $name;
  $wc1c_depth++;

  call_user_func("wc1c_{$wc1c_namespace}_start_element_handler", $wc1c_is_full, $wc1c_names, $wc1c_depth, $name, $attrs);

  static $element_number = 0;
  $element_number++;
  if ($element_number > 1000) {
    $element_number = 0;
    wp_cache_flush();
  }
}

function wc1c_xml_character_data_handler($parser, $data) {
  global $wc1c_namespace, $wc1c_is_full, $wc1c_names, $wc1c_depth;

  $name = $wc1c_names[$wc1c_depth];

  call_user_func("wc1c_{$wc1c_namespace}_character_data_handler", $wc1c_is_full, $wc1c_names, $wc1c_depth, $name, $data);
}

function wc1c_xml_end_element_handler($parser, $name) {
  global $wc1c_namespace, $wc1c_is_full, $wc1c_names, $wc1c_depth;
  
  call_user_func("wc1c_{$wc1c_namespace}_end_element_handler", $wc1c_is_full, $wc1c_names, $wc1c_depth, $name);

  array_pop($wc1c_names);
  $wc1c_depth--;
}

function wc1c_xml_parse($fp) {
  $parser = xml_parser_create();

  xml_set_element_handler($parser, 'wc1c_xml_start_element_handler', 'wc1c_xml_end_element_handler');
  xml_set_character_data_handler($parser, 'wc1c_xml_character_data_handler'); 

  $meta_data = stream_get_meta_data($fp);
  $filename = basename($meta_data['uri']);

  while (!($is_final = feof($fp))) {
    if (($data = fread($fp, 4096)) === false) wc1c_error(sprintf("Failed to read from file %s", $filename)); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
    if (!xml_parse($parser, $data, $is_final)) {
      $message = sprintf("%s in %s on line %d", xml_error_string(xml_get_error_code($parser)), $filename, xml_get_current_line_number($parser));
      wc1c_error($message, "XML Error");
    }
  }

  xml_parser_free($parser);
}

function wc1c_xml_parse_head($fp) {
  $is_full = null;
  $is_moysklad = null;
  while (($buffer = fgets($fp)) !== false) {
    if (strpos($buffer, " СинхронизацияТоваров=") !== false) $is_moysklad = true;

    if (strpos($buffer, " СодержитТолькоИзменения=") === false && strpos($buffer, "<СодержитТолькоИзменения>") === false) continue;

    $is_full = strpos($buffer, " СодержитТолькоИзменения=\"false\"") !== false || strpos($buffer, "<СодержитТолькоИзменения>false<") !== false;
    break;
  }

  $meta_data = stream_get_meta_data($fp);
  $filename = basename($meta_data['uri']);

  rewind($fp) or wc1c_error(sprintf("Failed to rewind on file %s", $filename));

  return array($is_full, $is_moysklad);
}

function wc1c_mode_import($type, $filename, $namespace = null) {
  global $wc1c_namespace, $wc1c_is_full, $wc1c_is_moysklad, $wc1c_names, $wc1c_depth;

  if ($type == 'catalog') wc1c_unpack_files($type);

  $path = WC1C_DATA_DIR . "$type/$filename";
  $fp = fopen($path, 'r') or wc1c_error(sprintf("Failed to open file %s", $filename)); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
  flock($fp, LOCK_EX) or wc1c_error(sprintf("Failed to lock file %s", $filename));

  wc1c_set_transaction_mode();

  if (!$namespace) $namespace = preg_replace("/^([a-zA-Z]+).+/", '$1', $filename);
  if (!in_array($namespace, array('import', 'offers', 'orders'))) wc1c_error(sprintf("Unknown import file type: %s", $namespace));

  $wc1c_namespace = $namespace;
  list($wc1c_is_full, $wc1c_is_moysklad) = wc1c_xml_parse_head($fp);
  $wc1c_names = array();
  $wc1c_depth = -1;

  require_once sprintf(WC1C_PLUGIN_DIR . "exchange/%s.php", $namespace);

  wc1c_xml_parse($fp);

  flock($fp, LOCK_UN) or wc1c_error(sprintf("Failed to unlock file %s", $filename));
  fclose($fp) or wc1c_error(sprintf("Failed to close file %s", $filename)); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

  exit("success");
}

function wc1c_post_id_by_meta($key, $value) {
  global $wpdb;

  if ($value === null) return;

  $cache_key = "wc1c_post_id_by_meta-$key-$value";
  $post_id = wp_cache_get($cache_key);
  if ($post_id === false) {
    $post_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta JOIN $wpdb->posts ON post_id = ID WHERE meta_key = %s AND meta_value = %s", $key, $value)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    wc1c_check_wpdb_error();

    if ($post_id) wp_cache_set($cache_key, $post_id);
  }

  return $post_id;
}

function wc1c_mode_query($type) {
  include WC1C_PLUGIN_DIR . "exchange/query.php";

  exit;
}

function wc1c_mode_success($type) {
  include WC1C_PLUGIN_DIR . "exchange/success.php";

  exit("success");
}

function wc1c_exchange() {
  wc1c_set_strict_mode();
  wc1c_set_output_callback();
  wc1c_fix_fastcgi_get();

  // phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
  if (empty($_GET['type'])) wc1c_error("No type");
  if (empty($_GET['mode'])) wc1c_error("No mode");

  if ($_GET['mode'] == 'checkauth') {
    wc1c_mode_checkauth();
  }

  wc1c_check_auth();

  define('WC1C_DEBUG', true);

  if ($_GET['mode'] == 'init') {
    wc1c_mode_init(sanitize_key($_GET['type']));
  }
  elseif ($_GET['mode'] == 'file') {
    wc1c_mode_file(sanitize_key($_GET['type']), isset($_GET['filename']) ? wp_unslash($_GET['filename']) : '');
  }
  elseif ($_GET['mode'] == 'import') {
    wc1c_mode_import(sanitize_key($_GET['type']), isset($_GET['filename']) ? wp_unslash($_GET['filename']) : '');
  }
  elseif ($_GET['mode'] == 'query') {
    wc1c_mode_query(sanitize_key($_GET['type']));
  }
  elseif ($_GET['mode'] == 'success') {
    wc1c_mode_success(sanitize_key($_GET['type']));
  }
  else {
    wc1c_error("Unknown mode");
  }
  // phpcs:enable
}

function wc1c_template_redirect() {
  $value = get_query_var('wc1c');
  if (empty($value)) return;
    
  if (strpos($value, '?') !== false) {
    list($value, $query) = explode('?', $value, 2);
    parse_str($query, $query);
    $_GET = array_merge($_GET, $query); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
  }
  $_GET['wc1c'] = $value;

  if ($value == 'exchange') {
    wc1c_exchange();
  }
  elseif ($value == 'clean') {
    require_once WC1C_PLUGIN_DIR . "clean.php";
    exit;
  }
}
add_action('template_redirect', 'wc1c_template_redirect', -10);
