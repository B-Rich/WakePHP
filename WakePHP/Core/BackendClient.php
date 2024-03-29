<?php
namespace WakePHP\Core;

/**
 * Class BackendClient
 * @package WakePHP\Core
 */
class BackendClient extends \PHPDaemon\Network\Client {
	use \PHPDaemon\Traits\StaticObjectWatchdog;
	/**
	 * Setting default config options
	 * Overriden from NetworkClient::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return [
			// @todo add description strings
			'servers'        => '127.0.0.1',
			'port'           => 9999,
			'maxconnperserv' => 32
		];
	}
}

