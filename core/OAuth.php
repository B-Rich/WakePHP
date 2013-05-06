<?php
namespace WakePHP\core;

class OAuth {
	/**
	 * It's not my decision to write static method.
	 * @param $method
	 * @param $url
	 * @param $oauth_params
	 * @param $app_secret
	 * @param string $user_token
	 * @param array $request_params will be merged into oauth params
	 * @return mixed
	 */
	public static function getSignature($method, $url, $oauth_params, $app_secret, $user_token = '', $request_params = array()) {
		$method = strtoupper($method);
		$params = array_merge($request_params, $oauth_params);
		ksort($params);
		$signature_base   = $method . '&' . rawurlencode($url) . '&';
		$signature_params = [];
		foreach ($params as $param => $value) {
			$signature_params[] = rawurlencode($param) . '=' . rawurlencode($value);
		}
		$signature_base .= rawurlencode(implode('&', $signature_params));
		$signing_key = rawurlencode($app_secret) . '&' . ($user_token ? rawurlencode($user_token) : '');
		$signature   = str_replace(['+', '%7E'], ['%20', '~'], base64_encode(
			hash_hmac('sha1', $signature_base, $signing_key, true)));
		return $signature;
	}
}
