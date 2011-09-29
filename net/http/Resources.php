<?php
/**
 * //
 * // Example implementation
 * //
 * 
 * // controllers/PostsController.php:
 * 
 * namespace blog\controllers;
 * 
 * class PostsController extends Base {
 * 
 * 	public $resources = array();
 * 
 * 	protected function _init() {
 * 		parent::_init();
 * 
 * 		$this->resources = array(
 * 			'index' => array(
 * 				'posts' => array('required' => false, 'find' => array(
 * 					'all', 'order' => array('updated' => 'asc')
 * 				))
 * 			),
 * 			'view' => array('post' => 'id')
 * 		);
 * 	}
 * 
 * 	public function index($posts) {
 * 		if (!count($posts)) {
 * 			return $this->redirect('Posts::add');
 * 		}
 * 		return compact('posts');
 * 	}
 * 
 * 	public function view($post) {
 * 		return compact('post');
 * 	}
 * }
 * 
 * // config/bootstrap/action.php:
 * 
 * use lithium\action\Dispatcher;
 * use li3_resources\net\http\Resources;
 * 
 * Dispatcher::applyFilter('_call', function($self, $params, $chain) {
 * 	if (!isset($params['callable']->resources)) {
 * 		return $chain->next($self, $params, $chain);
 * 	}
 * 	$params['params'] += array('args' => array());
 * 	$new = Resources::map($params['callable']->resources, $params['params']);
 * 	$params['params']['args'] = array_merge($new, $params['params']['args']);
 * 	return $chain->next($self, $params, $chain);
 * });
*/

namespace li3_resources\net\http;

use lithium\util\Set;
use lithium\core\Libraries;
use lithium\util\Inflector;
use lithium\action\DispatchException; // @todo ResourceNotFoundException?
use lithium\core\ClassNotFoundException;
// @todo When implemented for real, it should probably be an UnmappedResourceException
// (code 400: Bad Request) or something.

class Resources extends \lithium\core\StaticObject {

	public static function map(array $config, array $params) {
		return static::_filter(__FUNCTION__, compact('config', 'params'), function($self, $params) {
			$config = $params['config'];
			$request = $params['params'];
			$defaults = array(
				null,
				'find' => array('first'),
				'required' => true,
				'params' => array(),
				'in' => null
			);

			if (!isset($config[$request['action']])) {
				return array();
			}
			$config = (array) $config[$request['action']];
			$result = array();

			foreach ($config as $name => $resource) {
				$args = array($name, $request, $resource, $defaults);
				list($model, $resource, $conditions) = $self::invokeMethod('_defaults', $args);

				if (!$conditions && $resource['params']) {
					$result[$name] = null;
					continue;
				}
				if ($resource['in']) {
					$result[$name] = $self::invokeMethod('_queryCollection', array(
						$result, $name, $resource, $conditions
					));
					continue;
				}
				$result[$name] = $self::get(compact('name', 'model') + array(
					'required' => $resource['required'],
					'find' => Set::merge($resource['find'], compact('conditions'))
				));
			}
			return array_values($result);
		});
	}

	protected static function _queryCollection($data, $name, array $resource, array $conditions) {
		$defaults = array(
			'model' => null,
			'find' => array('first'),
			'required' => false
		);
		$resource += $defaults;
		$resource['find'] += $defaults['find'];
		list($parent, $field) = explode('.', $resource['in'], 2);

		if (!isset($data[$parent]) || !isset($data[$parent]->{$field})) {
			if ($resource['required']) {
				// Maybe Bad Request?
				$message = "Resource `{$name}` not found in parent `{$parent}`.";
				throw new DispatchException($message);
			}
			return;
		}
		$result = $data[$parent]->{$field};

		if ($conditions) {
			$method = ($resource['find'][0] == 'first') ? 'first' : 'find';
			$result = $result->{$method}($conditions);
		}
		if ($result && isset($resource['find']['order'])) {
			$result->sort($resource['find']['order']);
		}
		return $result;
	}

	public static function get(array $resource) {
		$defaults = array(
			'name' => null,
			'model' => null,
			'find' => array('first'),
			'required' => false
		);
		$resource += $defaults;
		$resource['find'] += $defaults['find'];

		return static::_filter(__FUNCTION__, compact('resource'), function($self, $params) {
			$resource = $params['resource'];

			if (!$resource['model'] || !class_exists($model = $resource['model'])) {
				throw new ClassNotFoundException("Could not find resource-mapped model class.");
			}
			$query  = $resource['find'];
			$method = $query[0];
			unset($query[0]);
			$result = $model::$method($query);

			if (!$result && $resource['required']) {
				$message = "Resource not found for `{$resource['name']}`.";
				throw new DispatchException($message);
			}
			return $result;
		});
	}

	protected static function _defaults($name, array $request, $resource, array $defaults) {
		$resource = is_string($resource) ? array('params' => $resource) : $resource;
		$resource += $defaults;

		$required = array();
		$resource[0] = $resource[0] ?: Inflector::camelize(Inflector::pluralize($name));

		if (!$resource['params']) {
			$resource['params'] = array_diff_key($resource, $defaults);
		}
		if ($resource['params'] = $resource['params']) {
			$required = array_combine((array) $resource['params'], (array) $resource['params']);
			$resource['params'] = is_string($resource['params']) ? $required : $resource['params'];
		}
		if (!$model = Libraries::locate('models', $resource[0])) {
			$message = "Could not find resource-mapped model class `{$resource[0]}`";
			throw new ClassNotFoundException($message);
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
			if ($resource['required']) {
				// Maybe Bad Request?
				$diff = join(', ', array_diff_key($required, $conditions));
				$message = "Mapped resource parameter `{$diff}` not found for resource `{$name}`.";
				throw new DispatchException($message);
			}
			$conditions = array();
		}
		return array($model, $resource, $conditions);
	}
}

?>