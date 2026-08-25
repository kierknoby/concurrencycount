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
foreach (['wizardstep', 'run', 'peakdetails', 'download', 'previewfixture', 'email', 'gettrunks'] as $command) {
	admin_contract_assert(strpos($class, "'" . $command . "'") !== false, 'AJAX command missing: ' . $command);
}
admin_contract_assert(strpos($class, "'authenticate'] = true") !== false, 'AJAX authentication setting missing');
admin_contract_assert(strpos($class, "'allowremote'] = false") !== false, 'AJAX remote-access setting missing');
admin_contract_assert(substr_count($class, 'requireValidCsrfToken();') >= 2, 'JSON and custom AJAX handlers must require CSRF');
admin_contract_assert(strpos($class, 'hash_equals(') !== false, 'hash_equals CSRF validation missing');
admin_contract_assert(strpos($class, 'FREEPBX_SYSTEM_IDENT') !== false, 'System identifier missing from email implementation');
admin_contract_assert(strpos($class, 'unknown system') !== false, 'System identifier fallback missing');
admin_contract_assert(strpos($class, 'new \\CI_Email()') !== false, 'CI_Email transport missing');
admin_contract_assert(strpos($class, '$this->FreePBX->Mail()') === false, 'Obsolete FreePBX Mail transport remains');
admin_contract_assert(strpos($class, '@mail(') === false && strpos($class, 'mail($') === false, 'Raw PHP mail transport remains');
foreach (['getNotificationFromAddress', 'normaliseEmailAddress', 'getNotificationSenderName', 'emailFromSupportsReturnPath'] as $helper) {
	admin_contract_assert(strpos($class, 'function ' . $helper) !== false, 'Email helper missing: ' . $helper);
}
foreach (['->to($to)', '->subject($subject)', "->set_mailtype('text')", '->message($body)', '->attach(', '->send()'] as $call) {
	admin_contract_assert(strpos($class, $call) !== false, 'Email call missing: ' . $call);
}
admin_contract_assert(strpos($class, "'Return-Path'") !== false, 'Return-Path handling missing');
admin_contract_assert(strpos($class, 'reply_to(') !== false, 'Reply-To handling missing');
admin_contract_assert(strpos($class, 'print_debugger') !== false, 'CI_Email diagnostics missing');
admin_contract_assert(strpos($class, 'accepted by the local mailer') !== false, 'Local-mailer acceptance wording missing');
admin_contract_assert(strpos($view, 'data-csrf-token=') !== false && strpos($view, 'name="token"') !== false, 'View must expose the CSRF token');
admin_contract_assert(substr_count($javascript, 'token:') >= 3, 'AJAX, download, and fixture preview must send CSRF tokens');
admin_contract_assert(strpos($javascript, 'Sweep is experimental') !== false, 'Sweep experimental wording missing');
admin_contract_assert(strpos($view, 'Demo writes to CDR.') !== false, 'Demo warning missing');
admin_contract_assert(strpos($view, 'cc-download') !== false && strpos($view, 'cc-email-send') !== false, 'Download/email controls missing');
foreach (['today', 'yesterday', 'last7', 'last30', 'month', 'custom'] as $preset) {
	admin_contract_assert(strpos($view, 'data-preset="' . $preset . '"') !== false, 'Date preset missing: ' . $preset);
}
admin_contract_assert(strpos($view, 'type="date"') !== false && strpos($view, 'cc-include-time') !== false, 'Native custom date/time controls missing');
admin_contract_assert(strpos($javascript, "command: 'peakdetails'") !== false, 'Peak detail AJAX wiring missing');
admin_contract_assert(strpos($javascript, 'config.php?display=') === false, 'Frontend must not construct FreePBX administrative URLs');
admin_contract_assert(strpos($class, "'need_html' => 'true'") !== false, 'Native CDR report action must submit an HTML search');
admin_contract_assert(strpos($javascript, "command: 'download'") !== false && strpos($javascript, "command: 'email'") !== false, 'Download/email command wiring missing');
admin_contract_assert(strpos($css, '#page_body') !== false && strpos($css, 'cc-table-scroll') !== false, 'Responsive containment/table scrolling missing');
admin_contract_assert((string)$module->version === '2.1.0', 'Admin contract version mismatch');
echo "Administrative contract passed\n";
