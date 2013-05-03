<?php
namespace WakePHP\blocks;

use WakePHP\core\Block;

class BlockAccountInfo extends Block {

	public function init() {
		
		$block = $this;
		$this->req->appInstance->accounts->getAccountByName(\Request::getString($this->req->attrs->get['username']), function($account) use ($block) {
			$block->assign('account', $account);
			$block->runTemplate();
		});
	}

}
