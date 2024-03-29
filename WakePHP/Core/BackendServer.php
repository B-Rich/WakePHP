<?php
namespace WakePHP\Core;

/**
 * Class BackendServer
 * @package WakePHP\Core
 */
class BackendServer extends \PHPDaemon\Network\Server {
	use \PHPDaemon\Traits\StaticObjectWatchdog;
	/**
	 * Setting default config options
	 * Overriden from NetworkServer::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return array(
			// @todo add description strings
			'listen'         => '0.0.0.0',
			'port'           => 9999,
			'defaultcharset' => 'utf-8',
			'expose'         => 1,
		);
	}

}

