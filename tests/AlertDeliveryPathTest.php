<?php
if (!interface_exists('BMO')) { interface BMO {} }
require_once dirname(__DIR__) . '/Concurrencycount.class.php';

class AlertDeliveryPathConcurrencycount extends \FreePBX\modules\Concurrencycount {
	public $settingsReads = 0;
	public $recipients = [];
	public function __construct() {}
	public function getLiveSettings(): array { $this->settingsReads++; return ['alert_email'=>'production@example.test']; }
	protected function sendPreparedThresholdEvent(array $event, string $to): array { $this->recipients[] = $to; return ['ok'=>true, 'message'=>'']; }
	public function deliver(array $event, ?string $override = null): array { return $this->deliverThresholdEvent($event, $override); }
}

function alert_path_assert($condition, string $message): void { if (!$condition) throw new Exception($message); }
$module = new AlertDeliveryPathConcurrencycount();
$event = ['type'=>'alert','scope'=>'overall','timestamp'=>1];
$module->deliver($event);
$module->deliver($event, 'test@example.test');
alert_path_assert($module->settingsReads === 2, 'Production and Test email must both load and validate live settings');
alert_path_assert($module->recipients === ['production@example.test', 'test@example.test'], 'Test recipient override must occur only after shared production preparation');
$reflection = new ReflectionClass($module);
alert_path_assert($reflection->getConstant('ALERT_DELIVERY_STATUS_KEY') !== $reflection->getConstant('ALERT_TEST_STATUS_KEY'), 'Production and Test delivery diagnostics must use separate records');
echo "Alert delivery path tests passed\n";
