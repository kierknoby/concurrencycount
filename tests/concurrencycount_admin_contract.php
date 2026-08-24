<?php
/**
 * Static administrative/security contract.
 * Run directly: php tests/concurrencycount_admin_contract.php
 */

$root = dirname(__DIR__);
$class = file_get_contents($root . '/Concurrencycount.class.php');
$view = file_get_contents($root . '/views/main.php');
$javascript = file_get_contents($root . '/assets/js/concurrencycount.js');
$css = file_get_contents($root . '/assets/css/concurrencycount.css');
$module = simplexml_load_file($root . '/module.xml');

function admin_contract_assert($condition, $message) {
	if (!$condition) throw new Exception($message);
}

admin_contract_assert(strpos($class, 'const AJAX_COMMANDS') !== false, 'Central AJAX command list missing');
foreach (['wizardstep', 'run', 'download', 'previewfixture', 'email', 'gettrunks'] as $command) {
	admin_contract_assert(strpos($class, "'" . $command . "'") !== false, 'AJAX command missing: ' . $command);
}
admin_contract_assert(strpos($class, "'authenticate'] = true") !== false, 'AJAX authentication setting missing');
admin_contract_assert(strpos($class, "'allowremote'] = false") !== false, 'AJAX remote-access setting missing');
admin_contract_assert(substr_count($class, 'requireValidCsrfToken();') >= 2, 'JSON and custom AJAX handlers must require CSRF');
admin_contract_assert(strpos($class, 'hash_equals(') !== false, 'hash_equals CSRF validation missing');
admin_contract_assert(strpos($class, 'FREEPBX_SYSTEM_IDENT') !== false, 'System identifier missing from email implementation');
admin_contract_assert(strpos($class, 'unknown system') !== false, 'System identifier fallback missing');
admin_contract_assert(strpos($view, 'data-csrf-token=') !== false && strpos($view, 'name="token"') !== false, 'View must expose the CSRF token');
admin_contract_assert(substr_count($javascript, 'token:') >= 3, 'AJAX, download, and fixture preview must send CSRF tokens');
admin_contract_assert(strpos($javascript, 'Sweep is experimental') !== false, 'Sweep experimental wording missing');
admin_contract_assert(strpos($view, 'Demo writes to CDR.') !== false, 'Demo warning missing');
admin_contract_assert(strpos($view, 'cc-download') !== false && strpos($view, 'cc-email-send') !== false, 'Download/email controls missing');
admin_contract_assert(strpos($javascript, "command: 'download'") !== false && strpos($javascript, "command: 'email'") !== false, 'Download/email command wiring missing');
admin_contract_assert(strpos($css, '#page_body') !== false && strpos($css, 'cc-table-scroll') !== false, 'Responsive containment/table scrolling missing');
admin_contract_assert((string)$module->version === '2.1.0', 'Admin contract version mismatch');
echo "Administrative contract passed\n";
