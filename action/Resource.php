<?php
/**
 * li3_resources: Friendly resource definitions for Lithium.
 *
 * @copyright     Copyright 2012, Union of RAD, LLC (http://union-of-rad.com)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace li3_resources\action;

use Exception;
use Countable;
use lithium\util\Set;
use lithium\util\Inflector;
use lithium\core\Libraries;

/**
 * The `Resource` class allows you to define a REST-oriented resource 
 */
abstract class Resource extends \lithium\core\Object {

	protected $_binding;

	protected $_parameters = array();

	protected $_methods = array(
		'GET'    => array('view'   => 'id', 'index' => null),
		'POST'   => array('edit'   => 'id', 'add'   => null),
		'PUT'    => array('edit'   => 'id'),
		'PATCH'  => array('edit'   => 'id'),
		'DELETE' => array('delete' => 'id')
	);

	protected $_autoConfig = array(
		'binding', 'classes', 'methods', 'parameters', 'handleExceptions'
	);

	protected $_classes = array();

	/**
	 * Flag indicating whether exceptions should automatically be caught and converted to HTTP error
	 * responses. If set to `false`, errors will bubble up outside of the resource's internal
	 * dispatch routine. Defaults to `true`.
	 *
	 * @var boolean
	 */
	protected $_handleExceptions = true;

	protected function _init() {
		parent::_init();

		$this->_classes += array(
			'entity' => 'lithium\data\Entity',
			'response' => 'lithium\action\Response',
			'resources' => 'li3_resources\net\http\Resources',
			'responder' => 'li3_resources\action\resource\Responder'
		);
	}

	/**
	 * When a resource responds to a request from a browser to render an HTML page, other data
	 * besides the resource is often required. This method may be extended in `Resource` classes
	 * to return an array of variables 
	 *
	 * @param object $request The object containing the state inforamation for the current request.
	 * @param object $resource The entity or colletion operated on by the current action.
	 * @return array Returns an array of named variables to be used in the template.
	 */
	protected function _viewData($request, array $resources) {
		return array();
	}

	/**
	 * Gets the model class binding for this resource, using the `$_binding` property if it is
	 * configured manually, or by parsing this class' name and attempting to find a model that
	 * matches.
	 *
	 * @return string Returns the fully qualified class name of the model to which this `Resource`
	 *         class is bound. Unless otherwise specified, all queries performed by this resource
	 *         will be executed against the model given.
	 */
	protected function _binding() {
		if ($this->_binding) {
			return $this->_binding;
		}
		return ($this->_binding = Libraries::locate('models', $this->_name()));
	}

	/**
	 * Gets the base name of the resource class, i.e. "Posts".
	 *
	 * @return string Returns the class name of the resource, without the namespace name.
	 */
	protected function _name() {
		return basename(str_replace('\\', '/', get_class($this)));
	}

	protected function _method($request, $params) {
		$name = $this->_name();
		$badIndex = ($request->method != 'GET' && $params['action'] == 'index');

		if (($action = $params['action']) && !$badIndex) {
			$methods = array_diff(get_class_methods($this), get_class_methods(__CLASS__));

			if (!in_array($action, $methods) || strpos($action, '_') === 0) {
				$message = "The `{$name}` resource doesn't understand how to do `{$action}`.";
				throw new MethodNotAllowedException($message);
			}
			return $action;
		}

		if (!isset($this->_methods[$request->method])) {
			$message = "The `{$name}` resource does not handle `{$request->method}` requests.";
			throw new MethodNotAllowedException($message);
		}
		$requestParams = array_filter($request->params, function($val) { return $val !== null; });

		foreach ($this->_methods[$request->method] as $action => $params) {
			$params = (array) $params;

			if (array_intersect($params, array_keys($requestParams)) !== $params) {
				continue;
			}
			return $action;
		}
		$message = "The `{$name}` resource could not process the request because the ";
		$message .= "parameters are invalid";
		throw new BadRequestException($message);
	}

	/**
	 * Generates a valid `Response` object, based on the results returned by a resource action.
	 *
	 * @param object $request The `Request` object.
	 * @param mixed $result The result returned from the resource method.
	 *              Either a boolean value indicating success or failure, or an HTTP
	 *              status code.
	 * @return object
	 */
	protected function _response($request, $result, array $resources, array $options) {
		$classes = $this->_classes;
		$object = $result;
		$status = null;

		if (is_array($result)) {
			list($status, $object) = $result;
		}
		$data = $object;
		$identity = spl_object_hash($data);
		$response = $this->_instance('response', compact('request'));
		$options = compact('data', 'status') + $options + array('controller' => $this->_name());

		foreach (array('before', 'after') as $key) {
			if (isset($options[$key][$identity])) {
				$options[$key] = $options[$key][$identity];
			}
		}

		if (!$classes['responder']::requiresView($request)) {
			return $classes['responder']::handle($request, $response, $options);
		}
		$key = lcfirst($options['controller']);

		foreach ($resources as $name => $value) {
			if (is_object($value) && spl_object_hash($value) == $identity) {
				$key = $name;
				break;
			}
		}
		$options += array('template' => $options['method']);
		$options['controller'] = Inflector::underscore($options['controller']);
		$options['data'] = array($key => $options['data']) + $this->_viewData($request, $resources);

		return $classes['responder']::handle($request, $response, $options);
	}

	/**
	 * The main invokation point of the `Resource` class. It receives an instance of the `Request`
	 * object representing the HTTP call (and optionally a separate array of route parameters), and
	 * uses it to dispatch a request to the correct `Resource` instance method.
	 *
	 * In conjunction with the `Resources` class, the instance's configuration is then used to map
	 * query parameters to entities loaded from external classes, usually models, which are passed
	 * into the instance method to be executed.
	 *
	 * The method then executes, usually performing some operation on the loaded entity, then
	 * returning either the entity, a status code, or both, in the form of
	 * `array($status, $entity)`; where `$status` is either a boolean value indicating success or
	 * failure, or an HTTP status code.
	 *
	 * @see lithium\action\Request
	 * @see lithium\action\Dispatcher::applyRules()
	 * @see lithium\net\http\Resources
	 *
	 * @param string $request 
	 * @param array $params Optional. If the route parameters have been rewritten (for example
	 *              by `Dispatcher`).
	 * @return object Returns a `Response` object.
	 */
	public function __invoke($request, array $params = array()) {
		$classes = $this->_classes;
		$params = ($params ?: $request->params) + array('action' => null);
		$before = $after = $resources = array();
		$method = $params['action'];

		$stateMap = array($classes['responder'], 'state');
		$keyMap = function($obj) { return is_object($obj) ? spl_object_hash($obj) : null; };

		try {
			$method = $this->_method($request, $params);
			$invoke = array(&$this, $method);

			$resources = $this->_get($method, $request);
			$keys = array_map($keyMap, $resources);

			$before = array_combine($keys, array_map($stateMap, $resources));
			$result = call_user_func_array($invoke, array_merge(array($request), $resources));
			$after = array_combine($keys, array_map($stateMap, $resources));
		} catch (Exception $e) {
			if (!$this->_handleExceptions) {
				throw $e;
			}
			$result = $e;
		}
		$result = is_scalar($result) ? array($result, reset($resources)) : $result;

		return $this->_response($request, $result, $resources, compact(
			'before', 'after', 'params', 'method'
		));
	}

	/**
	 * Gets the bound parameters for the given resource method.
	 *
	 * @param string $method The name of the method to be invoked on this resource.
	 * @return array
	 */
	protected function _get($method, $request) {
		$resources = $this->_classes['resources'];
		$defs = $this->_parameters + array($method => null);
		$list = $defs[$method] ?: $this->_default($method);
		return $resources::all($list, $this->_config(), $request);
	}

	/**
	 * If no resource parameter definition exists for a method, generate a default mapping.
	 *
	 * @param string $method The class method name to be called, i.e. `'index'`, `'add'`, etc.
	 * @return array Returns an array where the key is the singular or plural name of the `Resource`
	 *         class, i.e. `'post'` or `'posts'`, and the value is a sub-array with an
	 *         optionally-non-namespaced model name or fully-qualified class name as the first
	 *         value, and a `'call'` key, indicating the name of the class method to call.
	 */
	protected function _default($method) {
		$name = lcfirst($this->_name());
		$isPlural = ($method == 'index');
		$call = array(true => 'first', $isPlural => 'all', ($method == 'add') => 'create');
		$key = $isPlural ? Inflector::pluralize($name) : Inflector::singularize($name);

		return array($key => array(
			$this->_binding(), 'call' => $call[true], 'required' => !$isPlural
		));
	}

	protected function _config() {
		return array(
			'binding'   => $this->_binding(),
			'classes'   => $this->_classes,
			'methods'   => $this->_methods
		);
	}
}

?>