<?php
namespace WakePHP\Jobs;

class JobNotFound extends Generic {
	public function run() {
		$this->sendResult(false);
	}
}