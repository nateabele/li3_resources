<?php
/**
 * li3_resources: Friendly resource definitions for Lithium.
 *
 * @copyright     Copyright 2012, Union of RAD, LLC (http://union-of-rad.com)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace li3_resources\net\http;

use Countable;
use lithium\util\Set;
use lithium\core\Libraries;
use lithium\util\Inflector;
use lithium\core\ConfigException;
use lithium\net\http\RoutingException;
use li3_resources\action\BadRequestException;
use li3_resources\action\ResourceNotFoundException;
use li3_resources\action\UnmappedResourceException;

class Resources extends \lithium\core\StaticObject {

	protected static $_classes = array(
		'router' => 'lithium\net\http\Router',
		'route' => 'lithium\net\http\Route',
		'model' => 'lithium\data\Model'
	);

	protected static $_handlers = array();

	protected static $_exports = array();

	protected static $_bindings = array();

	public static function config(array $config = array()) {
		if ($config) {
			if (isset($config['classes'])) {
				static::$_classes = $config['classes'] + static::$_classes;
				unset($config['classes']);
			}
		}
		return array('classes' => static::$_classes);
	}

	public static function handlers($handlers = null) {
		if (!static::$_handlers) {
			static::$_handlers = array(
				'binding' => function($request, $options) {
					$model = $options['binding'];
					$name = $options['name'];
					$model = Libraries::locate('models', $model ?: Inflector::pluralize($name));

					if (!$model || is_string($model) && !class_exists($model)) {
						$msg = "Could not find resource-mapped model class for resource `{$name}`.";
						throw new UnmappedResourceException($msg);
					}
					return $model;
				},
				'isRequired' => function($request, $options, $result = null) {
					$name = $options['name'];
					$req = $options['required'];
					$model = $options['binding'];
					$isCountable = $result && $result instanceof Countable;

					if (($result && !$isCountable) || ($isCountable && count($result)) || !$req) {
						return $result;
					}
					$model = is_object($model) ? get_class($model) : $model;
					$message = "Resource `{$name}` not found in model `{$model}`.";
					throw new ResourceNotFoundException($message);
				},
				'default' => array(
					function($request, $options) {
						$isRequired = Resources::handlers('isRequired');
						$query = ((array) $options['call']) + array('all');
						$call = $query[0];
						unset($query[0]);
						return $isRequired($request, $options, $options['binding']::$call($query));
					},
					'create' => function($request, $options) {
						return $options['binding']::create($request->data);
					}
				)
			);
		}

		if (is_array($handlers)) {
			static::$_handlers = $handlers + static::$_handlers;
		}

		if ($handlers && is_string($handlers)) {
			return isset(static::$_handlers[$handlers]) ? static::$_handlers[$handlers] : null;
		}
		return static::$_handlers;
	}

	/**
	 * Maps an array of resource parameter definitions to an array of resource parameters
	 * (typically entities and collections queried from model objects).
	 *
	 * @param array $resources An array of resource parameter definitions, keyed by name. For
	 *              information on the options available for individual resource parameter
	 *              definitions, see the `$options` parameter of `Resources::get()`.
	 * @param array $config The configuration array for the associated `Resource` object, which is
	 *              returned from the object's `_config()` method.
	 * @param object $request The `Request` instance representing the current HTTP request. The
	 *               routing parameters contained in this object are used to generate model queries
	 *               that produce resource parameters.
	 * @return array
	 * @filter
	 */
	public static function all($resources, array $config, $request) {
		$params = compact('config', 'resources', 'request');

		return static::_filter(__FUNCTION__, $params, function($self, $params) {
			$config    = $params['config'] + array('binding' => null);
			$resources = $params['resources'];
			$request   = $params['request'];
			$data      = array();
			$defaults  = array(
				$config['binding'], 'params' => null, 'in' => null, 'required' => true
			);

			$map = function($name, $resource) use (
				&$data, &$map, $self, $request, $config, $resources, $defaults
			) {
				if (is_int($name)) {
					$name = $resource;
					$resource = array();
				}
				if (isset($data[$name])) {
					return;
				}
				$resource = is_string($resource) ? array('params' => $resource) : $resource;
				$resource += compact('name') + $defaults;
				$resource['binding'] = $resource[0];
				unset($resource[0]);

				if ($resource['in'] && !array_key_exists($resource['in'], $data)) {
					list($key) = explode('.', $resource['in']);

					if (!isset($resources[$key])) {
						$msg = "Parent resource `{$key}` of child `{$name}` must be defined.";
						throw new ConfigException($msg);
					}
					$map($key, $resources[$key]);
				}
				$data[$name] = $self::get($request, compact('name', 'data') + $resource, $config);
			};

			foreach ((array) $resources as $name => $resource) {
				$map($name, $resource);
			}
			return $data;
		});
	}

	/**
	 * Executes a query against a model based on a resource mapping configuration.
	 *
	 * @param array $options The query configuration for a single resource parameter. Available
	 *              options are:
	 *              - `'name'` _string_: The name of the resource parameter being queried.
	 *              - `'binding'` _string_: The fully-namespaced class name of the model to query
	 *                for the resource parameter.
	 *              - `'call'` _mixed_: A string indicating the name of the method to call (i.e.
	 *                `'find'` or `'first'`), or an array where the first item is the name of the
	 *                method to call, and other available keys are `'conditions'` and `'order'`.
	 *                See the `find()`/`first()` and `sort()` methods of `data\Collection` for
	 *                information on valid values. Defaults to `'first'`.
	 *              - `'required'` _boolean_: Boolean indicating whether this resource parameter is
	 *                required. If `true`, and returning a single object, that object must
	 *                exist. If `true` and returning a collection, that collection must contain
	 *                one or more items. Defaults to `true`.
	 *              - `'data'` _array_: The keyed array of other resources that have already been
	 *                queried and are to be returned as the final result of `all()`. Primarily used
	 *                in conjunction with the `'in'` option.
	 *              - `'in'` _string_: A key representing a field in an existing resource in
	 *                `'data'` that has already been queried. The typical use case is a 
	 * @return object Returns the result of a model query, usually either an `Entity` or
	 *         `Collection`.
	 * @filter
	 */
	public static function get($request, array $options = array()) {
		$defaults = array(
			'name' => null,
			'binding' => null,
			'call' => 'first',
			'required' => true,
			'data' => array(),
			'params' => null,
			'in' => null,
		);
		$options += $defaults;
		$func = __FUNCTION__;

		return static::_filter($func, compact('request', 'options'), function($self, $params) {
			$options = $params['options'];
			$request = $params['request'];

			if ($options['in']) {
				return $self::queryCollection($options, $query);
			}
			$query = $self::mapQuery($options, $request);
			$options['call'] = Set::merge($query, (array) $options['call']);

			if (!$options['call']['conditions'] && $options['params']) {
				return;
			}

			$options['binding'] = call_user_func($self::handlers('binding'), $request, $options);
			$func = $self::handlers($options['name']) ?: $self::handlers('default');

			if (is_array($func)) {
				$key = isset($options['call'][0]) ? $options['call'][0] : 'all';
				$func = is_string($key) && isset($func[$key]) ? $func[$key] : $func[0];
			}
			return $func($request, $options);
		});
	}

	/**
	 * Uses helper methods from `lithium\data\Collection` to query inside an already-fetched result,
	 * based on a set of parameters. Works with the `'in'` option of resource parameter definitions.
	 *
	 * For example, if you have defined a 
	 *
	 * @see lithium\data\Collection::find()
	 * @see lithium\data\Collection::first()
	 * @see lithium\data\Collection::sort()
	 * @param array $data An array of resource parameters that have already been queried, keyed by
	 *              name.
	 * @param array $options The query options for the resource parameter being queried. Available
	 *              options are:
	 *              - `'name'` _string_: The name of the resource parameter being queried.
	 *              - `'call'` _mixed_: A string indicating the name of the method to call (i.e.
	 *                `'find'` or `'first'`), or an array where the first item is the name of the
	 *                method to call, and other available keys are `'conditions'` and `'order'`.
	 *                See the `find()`/`first()` and `sort()` methods of `data\Collection` for
	 *                information on valid values. Defaults to `'first'`.
	 *              - `'required'` _boolean_: Boolean indicating whether this resource parameter is
	 *                required. If `true`, and returning a single object, that object must
	 *                exist. If `true` and returning a collection, that collection must contain
	 *                one or more items. Defaults to `true`.
	 * @param array $query 
	 * @return object
	 */
	public static function queryCollection($data, array $options, array $query) {
		$defaults = array('call' => 'first', 'required' => true, 'name' => null);
		$options += $defaults;
		$name = $options['name'];

		$options['call'] = (array) $options['call'];
		$options['call'] += array('conditions' => array(), 'order' => null);
		list($parent, $field) = explode('.', $options['in'], 2);

		if (!isset($data[$parent]) || !isset($data[$parent]->{$field})) {
			if (!$options['required']) {
				return;
			}
			$message = "Resource `{$name}` not found in parent `{$parent}`.";
			throw new ResourceNotFoundException($message);
		}
		$result = $data[$parent]->{$field};
		$call = $options['call'];
		$method = $call[0];

		$result = ($call['conditions']) ? $result->{$method}($call['conditions']) : $result;
		$isCountable = $result instanceof Countable;
		return ($isCountable && $call['order']) ? $result->sort($call['order']) : $result;
	}

	/**
	 * Takes a resource parameter definition and a `Request` object, and maps the route parameters
	 * to a model query.
	 *
	 * @param array $resource An array containing a resource parameter definition.
	 * @param array $config An array containing the configuration of the associated `Resource`
	 *              object.
	 * @param object $request The `Request` object instance containing the routing parameters that
	 *               will be mapped into the resulting query.
	 * @return array Returns an array containing query parameters suitable for passing to a model
	 *         finder.
	 */
	public static function mapQuery(array $resource, $request) {
		$map = static::handlers();
		$classes = static::$_classes;

		$name = $resource['name'];
		$model = $map['binding']($request, $resource);
		unset($resource['binding']);
		$params = $resource['params'];
		$query = array('conditions' => array());

		if (!$params) {
			$params = $request->id ? 'id' : array();
		}
		if (is_string($params)) {
			$isModel = (is_object($model) || in_array($classes['model'], class_parents($model)));
			$params = $isModel ? array($params => $model::key()) : array($params => $params);
		}

		foreach ($params as $param => $field) {
			if ($resource['required'] && !isset($request->{$param})) {
				$message = "Mapped resource parameter `{$param}` not found for resource `{$name}`.";
				throw new BadRequestException($message);
			}
			$query['conditions'][$field] = $request->{$param};
		}
		return $query;
	}

	public static function defaults($name, array $request, $options, array $defaults) {
		if ($options['params'] = $options['params']) {
			$required = (array) $options['params'];
			$options['params'] = is_string($options['params']) ? $required : $options['params'];
		}
		$conditions = array_intersect_key($request, $required);

		if ($conditions && $resource['params']) {
			ksort($resource['params']);
			ksort($conditions);
			$conditions = array_combine(array_keys($resource['params']), $conditions);
		}

		if (isset($conditions['id'])) {
			$id = $conditions['id'];
			unset($conditions['id']);
			$conditions += $model::key($id);
		}
		if (count($required) !== count($conditions) || array_filter($conditions) != $conditions) {
			if ($options['required']) {
				$diff = join(', ', array_diff_key($required, $conditions));
				$message = "Mapped resource parameter `{$diff}` not found for resource `{$name}`.";
				throw new BadRequestException($message);
			}
			$conditions = array();
		}
		return array($model, $options, $conditions);
	}

	public static function export(array $resources, array $options = array()) {
		$defaults = array('prefix' => null);
		$options += $defaults;

		$classes = static::$_classes;
		$remap = array();
		$names = array();

		foreach ($resources as $resource => $config) {
			if (is_int($resource)) {
				$resource = $config;
				$config = array();
			}
			$config += array(
				'class' => Libraries::locate('resources', $resource),
				'path' => str_replace('_', '-', Inflector::underscore($resource))
			);
			$config += array('binding' => $config['class']::binding());
			$first = substr($config['path'], 0, 1);

			$remap[$resource] = $config;
			$names[] = "[{$first}" . ucfirst($first) . "]" . substr($config['path'], 1);

			static::$_exports[$resource] = $config;
			static::$_bindings += array($config['binding'] => $resource);
		}
		$template  = $options['prefix'] . '/{:controller:' . join('|', $names) . '}';
		$template .= '/{:action:[^0-9]+}';
		$template .= '/{:id:(?:[0-9a-f]{24})|(?:\d+)}'; //'.{:type}';

		return static::_instance('route', compact('template') + array(
			'formatters' => $classes['router']::formatters(),
			'params' => array('action' => null, /*'type' => null,*/ 'id' => null)
		));
	}

	public static function bindingFor($class) {
		if (!isset(static::$_bindings[$class])) {
			return null;
		}
		$name = static::$_bindings[$class];
		return compact('name') + static::$_exports[$name];
	}

	/**
	 * @todo Resource configurations should include route parameter extraction.
	 */
	public static function link($request, $object, array $options = array()) {
		$classes = static::$_classes;
		$options += array('binding' => null, 'resource' => null);
		$options['binding'] = $options['binding'] ?: $object->model();

		foreach (static::$_exports as $resource => $config) {
			if ($options['binding'] !== $config['binding']) {
				continue;
			}
			$params = $options['binding']::key($object) + array(
				'controller' => $config['path'], 'action' => null
			);
			// @hack
			$params['id'] = $params['_id'];
			unset($params['_id']);
			return $classes['router']::match($params, $request, $options);
		}
		throw new RoutingException();
	}

	public static function bind($class) {
		$class::applyFilter('_callable', function($self, $params, $chain) {
			$options = $params['options'];
			$name = $params['params']['controller'];
	
			if ($class = Libraries::locate('resources', $name, $options)) {
				if (strpos($class, 'Controller') === false) {
					return Libraries::instance(null, $class, $options);
				}
			}
			return $chain->next($self, $params, $chain);
		});
	}
}

?>