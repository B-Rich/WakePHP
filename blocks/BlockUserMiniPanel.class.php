<?php
class BlockUserMiniPanel extends Block {

	public function init() {
		
		$block = $this;
		$this->req->components->Account->onAuth(function($result) use ($block) {
			$block->runTemplate();
		});
	}
	
	public function execute() {
		$this->ready();
	}

}
