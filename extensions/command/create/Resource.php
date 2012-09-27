<?php

namespace li3_resources\extensions\command\create;

class Resource extends \lithium\console\command\create\Controller {

	protected function _parent($request) {
		return '\li3_resources\action\Resource';
	}

	/**
	 * Indicates that resource classes should be stored in the `controllers` sub-namespace.
	 *
	 * @param object $request
	 * @param array $options
	 * @return string
	 */
	protected function _namespace($request, $options = array()) {
		return $this->_library['prefix'] . 'controllers';
	}

	/**
	 * Indicates that the name of the resource class should be an unprefixed and unsuffixed
	 * pluralized name
	 *
	 * @param object $request
	 * @param array $options
	 * @return string
	 */
	protected function _class($request) {
		return $this->_name($request);
	}
}

?>