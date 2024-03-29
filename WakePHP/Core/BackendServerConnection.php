<?php
namespace WakePHP\Core;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Network\Connection;
use WakePHP\Blocks\Block;

/**
 * Class BackendServerConnection
 * @package WakePHP\Core
 */
class BackendServerConnection extends Connection {
	use \PHPDaemon\Traits\StaticObjectWatchdog;
	/**
	 * @var Request[]
	 */
	public $requests = [];
	protected $buf;
	protected $config;

	/**
	 *
	 */
	public function init() {
		$this->config = $this->pool->config;
	}

	/**
	 * @param array $p
	 */
	public function onPacket($p) {
		if (!is_array($p)) {
			return;
		}
		if ($p['type'] === 'startReq') {
			$rid = $p['rid'];
			//Daemon::log(get_class($this) . '('.spl_object_hash($this).')=>startRequest: '.Debug::dump($rid));
			$req                    = new Request($this->pool->appInstance, $this, $p['req']);
			$req->backendServerConn = $this;
			$req->rid               = $rid;
			$this->requests[$rid]   = $req;
		}
		elseif ($p['type'] === 'getBlock') {
			$rid = $p['rid'];
			if (!isset($this->requests[$rid])) {
				Daemon::log(get_class($this) . '(' . spl_object_hash($this) . ')=>Unknown request: ' . Debug::dump($rid));
				return;
			}
			$req = $this->requests[$rid];
			if ((!isset($p['block']['type'])) || (!class_exists($class = '\\WakePHP\\Blocks\\Block' . $p['block']['type']))) {
				$class = '\\WakePHP\\Blocks\\Block';
			}
			$block       = new $class($p['block'], $req, true);
			$block->_nid = $p['bid'];
			$block->bid  = $p['bid'];
			//Daemon::log('[srv] adding bid '.json_encode($p['block']));
			$req->queries[$p['bid']] = $block;
			$block->init();
		}
		elseif ($p['type'] == 'endRequest') {
			$rid = $p['rid'];
			if (!isset($this->requests[$rid])) {
				Daemon::log(get_class($this) . '(' . spl_object_hash($this) . ')=>Unknown request: ' . Debug::dump($rid));
				return;
			}
			$req = $this->requests[$rid];
			$req->finish();
			unset($this->requests[$rid]);
		}
	}

	/**
	 * @param Request $req
	 * @param string $prop
	 * @param $val
	 */
	public function propertyUpdated($req, $prop, $val) {
		$this->sendPacket([
							  'type' => 'propertyUpdated',
							  'rid'  => $req->rid,
							  'prop' => $prop,
							  'val'  => $val,
						  ]);
	}

	/**
	 * @param Block $block
	 */
	public function onReadyBlock($block) {
		//Daemon::log('[srv] onReadyBlock bid '.json_encode([$block->name, $block->bid]));
		$this->sendPacket([
							  'type'  => 'readyBlock',
							  'rid'   => $block->req->rid,
							  'bid'   => $block->_nid,
							  'block' => $block->exportObject(),
						  ]);
		unset($block->req->queries[$block->_nid]);
	}

	/**
	 * @param Request $req
	 * @param string $s
	 */
	public function requestOut($req, $s) {
	}

	/**
	 * @param Request $req
	 */
	public function freeRequest($req) {
	}

	/**
	 * Handles the output from downstream requests.
	 * @param Request $req
	 * @param $appStatus
	 * @param $protoStatus
	 * @return boolean Succcess.
	 */
	public function endRequest($req, $appStatus, $protoStatus) {
	}

	public function onFinish() {
		$this->requests = null;
	}

	/**
	 * @param $p
	 */
	public function sendPacket($p) {
		$data = igbinary_serialize($p);
		$this->write(pack('N', strlen($data)) . $data);
	}

	/**
	 * Called when new data received.
	 * @param string $buf New data.
	 * @return void
	 */
	public function stdin($buf) {
		$this->buf .= $buf;
		start:
		if (strlen($this->buf) < 4) {
			return; // not ready yet
		}
		$u    = unpack('N', $this->buf);
		$size = $u[1];
		if (strlen($this->buf) < 4 + $size) {
			return; // no ready yet;
		}
		$packet    = binarySubstr($this->buf, 4, $size);
		$this->buf = binarySubstr($this->buf, 4 + $size);
		$this->onPacket(igbinary_unserialize($packet));
		goto start;
	}
}
