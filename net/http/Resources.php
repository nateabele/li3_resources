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
			$params = $params['params'];
			$defaults = array(
				null, 'find' => array(), 'required' => true, 'params' => array(), 'in' => null
			);

			if (!isset($config[$params['action']])) {
				return array();
			}
			$config = (array) $config[$params['action']];
			$result = array();

			foreach ($config as $name => $param) {
				$args = array($params, $param, $defaults);
				list($model, $param, $required, $values) = $self::invokeMethod('_defaults', $args);

				if (!$values && $param['params']) {
					$result[$name] = null;
					continue;
				}
				if ($param['in']) {
					$result[$name] = $self::invokeMethod('_queryCollection', array(
						isset($result[$param['in']]) ? $result[$param['in']] : null, $param
					));
					continue;
				}
				$result[$name] = $self::get(compact('name', 'model') + array(
					'required' => $param['required'],
					'find' => Set::merge($param['find'], array('conditions' => $values))
				));
			}
			return array_values($result);
		});
	}

	protected static function _queryCollection($data, array $options) {
		if (!$data && $options['required']) {
			throw new DispatchException("Resource not found."); // Maybe Bad Request?
		}
		if (isset($options['query']['conditions'])) {
			$method = ($options['method'] == 'first') ? 'first' : 'find';
			$data = $data->{$method}($options['query']['conditions']);
		}
		if (isset($options['query']['order'])) {
			$data->sort($options['query']['order']);
		}
		return $data;
	}

	public static function get(array $options) {
		$defaults = array(
			'name' => null,
			'model' => null,
			'find' => array('first'),
			'required' => false
		);
		$options += $defaults;
		$options['find'] += $defaults['find'];

		return static::_filter(__FUNCTION__, compact('options'), function($self, $params) {
			$options = $params['options'];

			if (!$options['model'] || !class_exists($model = $options['model'])) {
				throw new ClassNotFoundException("Could not find resource-mapped model class.");
			}
			$query  = $options['find'];
			$method = array_shift($query);
			$result = $model::$method($query);

			if (!$result && $options['required']) {
				throw new DispatchException("Resource not found.");
			}
			return $result;
		});
	}

	protected static function _defaults(array $config, $param, array $defaults) {
		$param = is_string($param) ? array('params' => $param) : $param;
		$param += $defaults;

		$required = array();
		$param[0] = $param[0] ?: Inflector::camelize($config['controller']);

		if ($param['params'] = (array) $param['params']) {
			$required = array_combine($param['params'], $param['params']);
		}
		$values = array_intersect_key($config, $required);
		$model = Libraries::locate('models', $param[0]);

		if (isset($values['id'])) {
			$id = $values['id'];
			unset($values['id']);
			$values += $model::key($id);
		}
		if (count($required) !== count($values) || array_filter($values) != $values) {
			if ($param['required']) {
				throw new DispatchException("Resource not found."); // Maybe Bad Request?
			}
			$values = array();
		}
		return array($model, $param, $required, $values);
	}
}

?>