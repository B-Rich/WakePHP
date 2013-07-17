<?php
namespace WakePHP\Core;

use PHPDaemon\Clients\HTTP\Pool as HTTPClient;
use PHPDaemon\Core\ClassFinder;
use PHPDaemon\Core\ComplexJob;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Request\IRequestUpstream;
use PHPDaemon\Request\RequestHeadersAlreadySent;
use PHPDaemon\Structures\StackCallbacks;
use WakePHP\Blocks\Block;
use WakePHP\Utils\Array2XML;

/**
 * Request class.
 * @property array session
 * @dynamic_fields
 */
class Request extends \PHPDaemon\HTTPRequest\Generic {

	public $locale;
	public $path;
	public $pathArg = array();
	public $pathArgType = array();
	public $html;
	public $inner = array();
	public $startTime;
	public $req;
	public $jobTotal = 0;
	public $jobDone = 0;
	/** @var \Quicky */
	public $tpl;
	/** @var Components */
	public $components;
	public $dispatched = false;
	public $updatedSession = false;
	public $xmlRootName = 'response';
	/** @var  BackendClientConnection */
	public $backendClientConn;
	public $backendClientCbs;
	public $backendClientInited = false;
	/** @var  BackendServerConnection */
	public $backendServerConn;
	/** @var Block[] */
	public $queries = [];
	public $queriesCnt = 0;
	public $readyBlocks = 0;
	public $rid;
	public $account;
	private static $emulMode = false;
	/** @var  WakePHP */
	public $appInstance;
	public $cmpName;
	public $controller;
	public $dataType;
	/** @var ComplexJob */
	public $job;
	protected $theme;

	/**
	 * Constructor
	 * @param WakePHP $appInstance
	 * @param IRequestUpstream $upstream.
	 * @param $parent
	 * @return \WakePHP\Core\Request
	 */
	public function __construct($appInstance, $upstream, $parent = null) {
		if (self::$emulMode) {
			Daemon::log('emulMode');
			return;
		}
		parent::__construct($appInstance, $upstream, $parent);
	}
	
	public function handleException($e) {
		if ($this->cmpName !== null) {
			$this->setResult(['exception' => ['type' => ClassFinder::getClassBasename($e), 'code' => $e->getCode(), 'msg' => $e->getMessage()]]);
			return true;
		}
	}
	/**
	 * @return string
	 */
	public function getBaseUrl() {
		return ($this->attrs->server['HTTPS'] === 'off' ? 'http' : 'https') . '://' . $this->appInstance->config->domain->value;
	}

	/**
	 * @return string
	 */
	public function getBackUrl($backUrl) {
		if ($backUrl !== null) {
			$domain = parse_url($backUrl, PHP_URL_HOST);
			if (!$this->checkDomainMatch($domain)) {
				return $this->getBaseUrl();
			}
			return $backUrl;
		} else {
			return $this->getBaseUrl();
		}
	}

	public function init() {
		try {
			$this->header('Content-Type: text/html');
		} catch (RequestHeadersAlreadySent $e) {
		}

		$this->theme = $this->appInstance->config->defaulttheme->value;

		$this->components = new Components($this);

		$this->startTime = microtime(true);

		$this->tpl = $this->appInstance->getQuickyInstance();
		$this->tpl->assign('req', $this);
	}

	public function getIp() {
		$s = &$this->attrs->server;
		$ip = $s['REMOTE_ADDR'];
		$for = '';
		if (isset($s['HTTP_CLIENT_IP'])) {
			$for = $s['HTTP_CLIENT_IP'];
		} elseif (isset($s['HTTP_X_FORWARDED_FOR'])) {
			$for = $s['HTTP_X_FORWARDED_FOR'];
		} elseif (isset($s['HTTP_VIA'])) {
			$for = $s['HTTP_VIA'];
		}
		return $ip . ($for !== '' ? ' for ' . $for : '');
	}

	/**
	 * @param $prop
	 */
	public function propertyUpdated($prop) {
		if ($this->backendServerConn) {
			$this->backendServerConn->propertyUpdated($this, $prop, $this->{$prop});
		}
	}

	/**
	 * @return \stdClass
	 */
	public function exportObject() {
		$req        = new \stdClass;
		$req->attrs = $this->attrs;
		return $req;
	}

	/**
	 * @param Block $block
	 * @return bool
	 */
	public function getBlock($block) {
		if (!$this->appInstance->backendClient) {
			return false;
		}
		if ($this->upstream instanceof BackendServerConnection) {
			return false;
		}

		if (ClassFinder::getClassBasename($block) === 'Block') {
			return false;
		}

		/**
		 * @param BackendClientConnection $conn
		 */
		$fc = function ($conn) use ($block) {
			if (!$conn->connected) {
				// fail
				return;
			}
			if (!$this->backendClientConn) {
				$this->backendClientConn = $conn;
				$conn->beginRequest($this);
			}
			$conn->getBlock($this->rid, $block);
			if ($this->backendClientCbs !== null) {
				$this->backendClientCbs->executeAll($conn);
				$this->backendClientCbs = null;
			}
		};
		if ($this->backendClientConn) {
			$this->backendClientConn->onConnected($fc);
		}
		else {
			if ($this->backendClientInited) {
				if ($this->backendClientCbs === null) {
					$this->backendClientCbs = new StackCallbacks();
				}
				$this->backendClientCbs->push($fc);
			}
			else {
				$this->appInstance->backendClient->getConnection($fc);
				$this->backendClientInited = true;
			}
		}
		return true;
	}

	/**
	 * @param string $format
	 * @param integer $ts
	 * @return mixed
	 */
	public function date($format, $ts = null) { // @todo
		if ($ts === null) {
			$ts = time();
		}
		$t      = array();
		$format = preg_replace_callback('~%n2?~', function ($m) use (&$t) {
			$t[] = $m[0];
			return "\x01";
		}, $format);
		$r      = date($format, $ts);
		$req    = $this;
		$r      = preg_replace_callback('~\x01~s', function ($m) use ($t, $ts, $req) {
			static $i = 0;
			switch ($t[$i++]) {
				case "%n":
					return $req->monthes[date('n', $ts)];
				case "%n2":
					return $req->monthes2[date('n', $ts)];
			}
		}, $r);
		return $r;
	}

	/**
	 * @param $st
	 * @param $fin
	 * @return array
	 */
	public function date_period($st, $fin) {
		if ((is_int($st)) || (ctype_digit($st))) {
			$st = $this->date('d-m-Y-H-i-s', $st);
		}
		$st = explode('-', $st);
		if ((is_int($fin)) || (ctype_digit($fin))) {
			$fin = $this->date('d-m-Y-H-i-s', $fin);
		}
		$fin = explode('-', $fin);
		if (($seconds = $fin[5] - $st[5]) < 0) {
			$fin[4]--;
			$seconds += 60;
		}
		if (($minutes = $fin[4] - $st[4]) < 0) {
			$fin[3]--;
			$minutes += 60;
		}
		if (($hours = $fin[3] - $st[3]) < 0) {
			$fin[0]--;
			$hours += 24;
		}
		if (($days = $fin[0] - $st[0]) < 0) {
			$fin[1]--;
			$days += $this->date('t', mktime(1, 0, 0, $fin[1], $fin[0], $fin[2]));
		}
		if (($months = $fin[1] - $st[1]) < 0) {
			$fin[2]--;
			$months += 12;
		}
		$years = $fin[2] - $st[2];
		return array($seconds, $minutes, $hours, $days, $months, $years);
	}

	/**
	 * @param $str
	 * @return int
	 */
	public function strtotime($str) {
		return \WakePHP\Utils\Strtotime::parse($str);
	}

	/**
	 * @param $obj
	 */
	public function onReadyBlock($obj) {
		$this->html = str_replace($obj->tag, $obj->html, $this->html);
		unset($this->inner[$obj->_nid]);
		$this->wakeup();
	}

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {

		if ($this->dispatched) {
			goto waiting;
		}

		init:

		$this->dispatch();

		waiting:

		if (($this->jobDone >= $this->jobTotal) && (sizeof($this->inner) == 0)) {
			goto ready;
		}
		$this->sleep(5);

		ready:

		unset($this->tpl);

		echo $this->html;
	}

	/**
	 * @param string $domain
	 * @param string $pattern
	 * @return bool
	 */
	public function checkDomainMatch($domain = null, $pattern = null) {
		if ($domain === null) {
			$domain = parse_url(Request::getString($this->attrs->server['HTTP_REFERER']), PHP_URL_HOST);
		}
		if ($pattern === null) {
			$pattern = $this->appInstance->config->cookiedomain->value;
		}
		foreach (explode(', ', $pattern) as $part) {
			if (substr($part, 0, 1) === '.') {
				if ('.' . ltrim(substr($domain, -strlen($part)), '.') === $part) {
					return true;
				}
			}
			else {
				if ($domain === $part) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * URI parser.
	 * @return void.
	 */
	public function dispatch() {
		$this->dispatched = true;
		$e                = explode('/', substr($_SERVER['DOCUMENT_URI'], 1), 2);
		if (($e[0] === 'component') && isset($e[1])) {

			$this->locale = Request::getString($this->attrs->request['LC']);
			if (!in_array($this->locale, $this->appInstance->locales, true)) {
				$this->locale = $this->appInstance->config->defaultlocale->value;
			}

			$e = explode('/', substr($_SERVER['DOCUMENT_URI'], 1), 4);
			++$this->jobTotal;
			$this->cmpName    = $e[1];
			$this->controller = isset($e[2]) ? $e[2] : '';
			$this->dataType   = isset($e[3]) ? $e[3] : 'json';
			if ($this->components->{$this->cmpName}) {
				$method = $this->controller . 'Controller';
				if (!$this->components->{$this->cmpName}->checkReferer()) {
					$this->setResult(array('errmsg' => 'Unacceptable referer.'));
					return;
				}
				if (method_exists($this->components->{$this->cmpName}, $method)) {
					$this->components->{$this->cmpName}->$method();
				}
				else {
					$this->setResult(array('errmsg' => 'Unknown controller.'));
				}
			}
			else {
				$this->setResult(array('errmsg' => 'Unknown component.'));
			}
			return;
		}

		if (!isset($e[1])) {
			$this->locale = $this->appInstance->config->defaultlocale->value;
			$this->path   = '/' . $e[0];
		}
		else {
			$this->locale = $e[0];
			$this->path   = '/' . $e[1];
			if (!in_array($this->locale, $this->appInstance->locales, true)) {
				try {
					$this->header('Location: /' . $this->appInstance->config->defaultlocale->value . $this->path);
				} catch (RequestHeadersAlreadySent $e) {
				}
				$this->finish();
				return;
			}
			$req        = $this;
			$this->path = preg_replace_callback('~/([a-z\d]{24})(?=/|$)~', function ($m) use ($req) {
				$type  = '';
				$value = null;
				if (isset($m[1]) && $m[1] !== '') {
					$type  = 'id';
					$value = $m[1];
				}
				$req->pathArgType[] = $type;
				$req->pathArg[]     = $value;
				return '/%' . $type;
			}, $this->path);

		}

		if ($this->backendServerConn) {
			return;
		}

		++$this->jobTotal;
		$this->appInstance->blocks->getBlock(array(
												 'theme' => $this->theme,
												 'path'  => $this->path,
											 ), array($this, 'loadPage'));
	}

	public function setResult($result = NULL) {
		if ($this->dataType === 'json') {
			try {
				$this->header('Content-Type: text/json');
			} catch (RequestHeadersAlreadySent $e) {
			}
			$this->html = json_encode($result);
		}
		elseif ($this->dataType === 'xml') {
			$converter = new Array2XML();
			$converter->setRootName($this->xmlRootName);
			try {
				$this->header('Content-Type: text/xml');
			} catch (RequestHeadersAlreadySent $e) {
			}
			$this->html = $converter->convert($result);
		}
		else {
			$this->html = json_encode(['errmsg' => 'Unsupported data-type.']);
		}
		++$this->jobDone;
		$this->wakeup();
	}

	/**
	 * @param array $block
	 */
	public function addBlock($block) {
		if ((!isset($block['type'])) || (!class_exists($class = '\\WakePHP\\Blocks\\Block' . $block['type']))) {
			$class = '\\WakePHP\\Blocks\\Block';
		}
		$block['tag']    = (string)new \MongoId;
		$block['nowrap'] = true;
		$this->html .= $block['tag'];
		new $class($block, $this);
	}

	/**
	 * @param $page
	 */
	public function loadPage($page) {

		++$this->jobDone;

		if (!$page) {
			++$this->jobTotal;
			try {
				$this->header('404 Not Found');
			} catch (RequestHeadersAlreadySent $e) {
			}
			$this->appInstance->blocks->getBlock(array(
													 'theme' => $this->theme,
													 'path'  => '/404',
												 ), array($this, 'loadErrorPage'));
			return;
		}
		$this->addBlock($page);
	}

	/**
	 * @param $page
	 */
	public function loadErrorPage($page) {

		++$this->jobDone;

		if (!$page) {
			$this->html = 'Unable to load error-page.';
			$this->wakeup();
			return;
		}

		$this->addBlock($page);

	}

	public function onFinish() {
		if ($this->backendClientConn) {
			$this->backendClientConn->endRequest($this);
			unset($this->backendClientConn);
		}
		$this->components->cleanup();
		Daemon::log('onFinish - ' . $this->attrs->server['REQUEST_URI']);
	}

	protected function sessionDecode($str) {
		$this->setSessionState($str);
		return $str !== false;
	}
	public function sessionRead($sid, $cb = null) {
		$this->appInstance->sessions->getSessionById($sid, function ($session) use ($cb) {
			call_user_func($cb, $session);
		});
	}

	protected function sessionStartNew($cb = null) {
		$session = $this->appInstance->sessions->startSession(
			['ip' => $this->getIp(), 'useragent' => Request::getString($this->attrs->server['HTTP_USER_AGENT'])],
			function ($lastError) use (&$session, $cb) {
				if (!$session) {
					if ($cb !== null) {
						call_user_func($cb, false);
					}
					return;
				}
				$this->sessionId = (string) $session['id'];
				$this->attrs->session = $session;
				$this->setcookie(
			  		ini_get('session.name')
					, $this->sessionId
					, ini_get('session.cookie_lifetime')
					, ini_get('session.cookie_path')
					, $this->appInstance->config->cookiedomain->value ?: ini_get('session.cookie_domain')
					, ini_get('session.cookie_secure')
					, ini_get('session.cookie_httponly')
				);
				call_user_func($cb, true);
			});
	}

	public function sessionCommit($cb = null) {
		if ($this->updatedSession) {
			$this->appInstance->sessions->saveSession($this->attrs->session, $cb);
		} else {
			if ($cb !== null) {
				call_user_func($cb);
			}
		}
	}

	public function __destruct() {
		Daemon::log('destruct - ' . $this->attrs->server['REQUEST_URI']);
	}

	/**
	 * @param $url
	 */
	public function redirectTo($url) {
		$this->status(302);
		$this->header('Cache-Control: no-cache, no-store, must-revalidate');
		$this->header('Pragma: no-cache');
		$this->header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
		$this->header('Location: ' . HTTPClient::buildUrl($url));
		$this->setResult([]);
	}
}
