<?php
namespace WakePHP\ORM;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Utils\Crypt;
use WakePHP\ORM\Generic;

/**
 * Accounts
 */
class Accounts extends Generic {

	/** @var \PHPDaemon\Clients\Mongo\Collection */
	public $accounts;
	/** @var \PHPDaemon\Clients\Mongo\Collection */
	public $aclgroups;

	public function init() {
		$this->accounts  = $this->appInstance->db->{$this->appInstance->dbname . '.accounts'};
		$this->aclgroups = $this->appInstance->db->{$this->appInstance->dbname . '.aclgroups'};
	}

	/**
	 * @param string $username
	 * @param callable $cb
	 */
	public function getAccountByName($username, $cb) {
		$this->getObject('Account', ['username' => (string) $username], $cb);
	}

	/**
	 * @param callable $cb
	 * @param array $cond
	 */
	public function findAccounts($cb, $cond = array()) {
		$this->accounts->find($cb, $cond);
	}

	/**
	 * @param $ip
	 * @param callable $cb
	 */
	public function getRecentSignupsFromIP($ip, $cb) {

		$this->accounts->count($cb, array('where' => array('ip' => (string)$ip, 'regdate' => array('$gt' => time() - 3600))));

	}

	/**
	 * @param string $username
	 * @param callable $cb
	 */
	public function getAccountByUnifiedName($username, $cb) {
		$this->getObject('Account', ['unifiedusername' => $this->unifyUsername($username)], $cb);
	}

	/**
	 * @param string $email
	 * @param callable $cb
	 */
	public function getAccountByUnifiedEmail($email, $cb) {
		$this->getObject('Account', ['unifiedemail' => $this->unifyEmail($email)], $cb);
	}

	/**
	 * @param string $email
	 * @param callable $cb
	 */
	public function getAccountByEmail($email, $cb) {
		$this->getObject('Account', ['email' => $email], $cb);
	}

	/**
	 * @param $id
	 * @param callable $cb
	 */
	public function getAccountById($id, $cb) {
		$this->getObject('Account', $id, $cb, $id);
	}

	/**
	 * @param $name
	 * @param callable $cb
	 */
	public function getACLgroup($name, $cb) {
		$this->aclgroups->findOne($cb, array(
			'where' => array('name' => $name),
		));
	}

	/**
	 * @param array $account
	 * @param string $password
	 * @return bool
	 */
	public function checkPassword($account, $password) {
		if ($account && !isset($account['password'])) {
			return false;
		}
		return Crypt::compareStrings($account['password'], Crypt::hash($password, $account['salt'] . $this->appInstance->config->cryptsaltextra->value));
	}

	/**
	 * @param string $username
	 * @return string
	 */
	public function unifyUsername($username) {
		static $equals = array(
			'з3z', 'пn', 'оo0', 'еeё',
			'б6b', 'хx', 'уyu', 'ийiu!ия1',
			'мm', 'кk', 'аa', 'ьb',
			'сcs', 'tт', 'йиu', 'i!1',
			'рp', 'tт', 'нh'
		);
		$result = mb_strtolower(preg_replace_callback('~([' . implode('])|([', $equals) . '])~u', function ($m) use ($equals) {
			return '[' . $equals[max(array_keys($m)) - 1] . ']';
		}, $username), 'UTF-8');
		return $result;
	}

	/**
	 * @param string $email
	 * @return string
	 */
	public function unifyEmail($email) {
		static $hosts = array(
			'googlemail.com' => 'gmail.com'
		);
		$email = mb_strtolower($email, 'UTF-8');

		list ($name, $host) = explode('@', $email . '@');
		if (($p = strpos($name, '+')) !== false) {
			$name = substr($name, 0, $p);
		}

		$name = str_replace('.', '', $name);
		$host = rtrim(str_replace('..', '.', $host), '.');
		if (isset($hosts[$host])) {
			$host = $hosts[$host];
		}

		return $name . '@' . $host;
	}

	/**
	 * @param $account
	 * @param callable $cb
	 */
	public function confirmAccount($conf, $cb = null) {
		$this->getObject('Account', $account)->confirm()->save($cb);
	}

	/**
	 * @param array $account
	 * @param string $group
	 * @param callable $cb
	 */
	public function addACLgroupToAccount($account, $group, $cb = null) {
		if (isset($account['_id']) && is_string($account['_id'])) {
			$account['_id'] = new \MongoId($account['_id']);
		}
		if (!is_string($group)) {
			return;
		}
		$this->accounts->updateOne($account, array('$addToSet' => array('aclgroups' => $group)), $cb);
	}

	/**
	 * @param array $account
	 * @param array $credentials
	 * @param callable $cb
	 */
	public function addCredentialsToAccount($account, $credentials, $cb = null) {
		if (isset($account['_id'])) {
			$find = ['_id' => is_string($account['_id']) ? new \MongoId($account['_id']) : $account['_id']];
		}
		elseif (isset($account['email'])) {
			$find = ['email' => $account['email']];
		}
		else {
			$find = $account;
		}
		$this->accounts->updateOne($find, ['$push' => ['credentials' => $credentials]], $cb);
	}

	/**
	 * @param $req
	 * @return array
	 */
	public function getAccountBase($req) {
		return [
			'email'            => '',
			'username'         => '',
			'location'         => '',
			'password'         => '',
			'ukey'			   => Crypt::randomString(16),
			'confirmationcode' => substr(md5($req->attrs->server['REMOTE_ADDR'] . "\x00"
											 . Daemon::uniqid() . "\x00"
											 . $this->appInstance->config->cryptsalt->value . "\x00"
											 . microtime(true) . "\x00"
											 . mt_rand(0, mt_getrandmax()))
				, 0, 6),
			'regdate'          => time(),
			'etime'            => time(),
			'ttlSession'		=> 1200,
			'ip'               => $req->attrs->server['REMOTE_ADDR'],
			'subscription'     => 'daily',
			'aclgroups'        => array('Users'),
			'acl'              => array(),
		];
	}

	/**
	 * @param $group
	 */
	public function saveACLgroup($group) {
		$this->aclgroups->upsert(array('name' => $group['name']), array('$set' => $group));
	}

}
