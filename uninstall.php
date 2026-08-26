<?php

if (!defined('FREEPBX_IS_AUTH')) {
	die('No direct script access allowed');
}

\FreePBX::Concurrencycount()->uninstall();
