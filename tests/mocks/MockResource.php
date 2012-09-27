<?php

namespace li3_resources\tests\mocks;

class MockResource extends \li3_resources\action\Resource {

	public function __invoke($request, array $params = array()) {
		if (isset($this->_config['mockMethod'])) {
			$params = ($params ?: $request->params) + array('action' => null);
			return $this->_method($request, $params);
		}
		return parent::__invoke($request, $params);
	}

	public function index($request, $resources) {
		return $resources;
	}

	public function add($request, $resource) {
		return $resource->save();
	}

	public function edit($request, $resource) {
		return $resource->save();
	}

	public function delete($request, $resource) {
		return $resource->delete();
	}

	public function arbitrary() {}
}

?>