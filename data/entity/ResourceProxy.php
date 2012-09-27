<?php

namespace li3_resources\data\entity;

class ResourceProxy extends \lithium\core\Object {

	protected $_binding;

	protected $_success = true;

	protected $_fields = array();

	protected $_classes = array();

	protected $_operations = array('save', 'delete');

	protected $_autoConfig = array('binding', 'fields', 'classes');

	protected function _init() {
		parent::_init();

		$this->_classes += array(
			'router' => 'lithium\net\http\Router',
			'resources' => 'li3_resources\action\Resources'
		);
		$classes = $this->_classes;

		$this->_fields += array(
			'_url' => function($entity) use ($classes) {
				
			}
		);
	}

	public function __call($method, array $params) {
		
	}

	public function success() {
		
	}
}

?>