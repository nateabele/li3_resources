# li3_resources

#### _A friendly resource definition framework for Lithium_

## Design Goals

 * Provide a consistent, conventional way across the application to query objects, and a standard interface to manipulate queries
 * Abstract away the differences between browsers and API clients, allowing controller code to focus on business logic
 * Conform to the principles of REST and hypermedia

## Operational Philosophy

The basic unit of organization for business logic is a `Resource` class. These act like traditional MVC controllers, but make more assumptions about handling requests and responses.

### Basic Anatomy

Assuming a `Posts` model (and templates, where appropriate), this class fully implements the `Resource` API, and is capable of serving as a browser-based application and a functioning REST API.

```php
<?php

namespace blog\controllers;

class Posts extends \li3_resources\action\Resource {

	public function index($request, $posts) {
		return $posts;
	}

	public function view($request, $post) {
		return $post;
	}

	public function add($request, $post) {
		return ($request->data) ? $post->save() : $post;
	}

	public function edit($request, $post) {
		return ($request->data) ? $post->save($request->data) : $post;
	}

	public function delete($request, $post) {
		return $discountCode->delete();
	}
}

?>
```

### Setup

In order to integrate `li3_resources` into your application to serve resource classes, it must be enabled at two points. First, the `Resources` class must be bound to the `Dispatcher`:

```php
<?php
// config/bootstrap/action.php:

use li3_resources\net\http\Resources;

// ...

Resources::bind('lithium\action\Dispatcher');

?>
```

Then, you must expose the resources you wish to route to in your application's routing:

```php
<?php
// config/routes.php:

use lithium\net\http\Router;
use li3_resources\net\http\Resources;

// ...

Router::connect(Resources::export(['Posts', 'Comments', 'Users', 'Session']));

?>
```

### Naming & Binding

An important thing to note in the above is the lack of direct access to a `Posts` model. Also, unlike controllers, resource class names have no suffix. A resource's name is used (by default) to identify how it will appear in a URL, but resource classes look up their _bindings_ by attempting to find a models that match their own names (i.e. `blog\models\Posts` in the above case).

Bindings are used to query objects that will be passed to resource methods. Typically, binding objects are models, but custom bindings can be used by overriding the `binding()` method, like so:

```php
<?php

namespace blog\controllers;

class Session extends \li3_resources\action\Resource {

	protected $_methods = array(
		'GET'    => array('view' => null),
		'POST'   => array('add' => null),
		'DELETE' => array('delete' => null)
	);

	protected $_parameters = array(
		'add' => array('session' => array('call' => 'create', 'required' => false)),
		'delete' => array('session' => array('call' => 'delete'))
	);

	public static function binding() {
		return 'lithium\security\Auth';
	}

	public function add($request, $session) {
		return $session ? array(true, $session) : 401;
	}

	public function view($request, $session) {
		return $session;
	}

	public function delete($request) {
		return 204;
	}
}

?>
```

This resource binds to the `Auth` class to create an API for managing sessions. Here, we also overrode the `$_parameters` property to tell the class to use a custom resource type called `session`, which can be defined as follows:

```php
<?php
// config/bootstrap/action.php

use li3_resources\net\http\Resources;

Resources::handlers(array(
	'session' => array(
		function($request, array $resource) {
			return $resource['binding']::check();
		},
		'create' => function($request, array $resource) {
			return $resource['binding']::check($request);
		},
		'delete' => function($request, array $resource) {
			return $resource['binding']::clear();
		}
	)
));

Resources::bind('lithium\action\Dispatcher');
?>
```

This creates the `session` resource type and defines 3 handlers: the first is the default handler used for querying session data, the second passes a `Request` object into `Auth::check()` in order to create the session, and the third clears the session data.

Now, sending `POST` request to `/session` with correct login credentials will produce a `201 Created` HTTP response, and return the session data (as a JSON structure, assuming the request was sent with `Accept: application/json`).

Subsequent `GET` requests to `/session` will return the session data, and `DELETE /session` will clear the session, logging the current user out.

### Actions

Instead of having directly-accessible actions (i.e. where `/posts/add` maps to `PostsController::add()`), resource objects use REST-compliant resource-oriented routing, and route to actions internally, based on a combination of HTTP verbs and URL parameters (including actions in URLs is allowed but strongly discouraged).

The default mapping appears thusly:

```php
<?php
...
protected $_methods = array(
	'GET'    => array('view'   => 'id', 'index' => null),
	'POST'   => array('edit'   => 'id', 'add'   => null),
	'PUT'    => array('edit'   => 'id'),
	'PATCH'  => array('edit'   => 'id'),
	'DELETE' => array('delete' => 'id')
);
...
?>
```

This translates to the following:

**Create**
* `POST /posts => controllers\Posts::add(models\Posts::create($request->data))`


**List / View**
* `GET /posts => controllers\Posts::index(models\Posts::all())`
* `GET /posts/1 => controllers\Posts::view(models\Posts::first(1))`


**Edit**
* `PUT /posts/1 => controllers\Posts::edit(models\Posts::first(1))`
* `POST /posts/1 => controllers\Posts::edit(models\Posts::first(1))`
* `PATCH /posts/1 => controllers\Posts::edit(models\Posts::first(1))`

**Delete**
* `DELETE /posts/1 => controllers\Posts::delete(models\Posts::first(1))`

This mapping is configurable by overriding the `$_methods` property in `Resource` subclasses, making it possible to map requests differently based on different parameters, or add support for other HTTP verbs, such as HEAD, OPTIONS, [and others](http://en.wikipedia.org/wiki/Hypertext_Transfer_Protocol#Request_methods).

### Return Values

The `Resource` class intelligently converts objects and buinsess-rule-oriented response values to HTTP responses using a series of heuristics. `Resource` action return values can be any of the following:

* **A boolean**:
	Most often, all that's necessary is to return a boolean value from an action, indicating success or failure. Depending on the context, this value will be converted to one of several HTTP status codes and body responses. The `Resource` class also tracks the objects you operate on, so it can be intelligent about _what_ your response code is in regards to.

	For example, see the following:
	```php
	<?php
		...
		public function add($request, $post) {
			return $post->save();
		}
		...
	?>
	```

	Here, the boolean value of the result of `save()` is returned. In order to know exactly what operation succeeded, however, `Resource` compares the state of `$post` before and after the operation. If the operation was successful, it will generate a `201 Created` response. If the operation failed, it will generate a `422 Unprocessable Entity` response, and will encode the result of `$post->errors()` as the response body.

* **An object**:
	Returning an object is most often useful if you wish to return an object other than the one accepted as the main operating parameter to the resource action, or if responding to a request from a browser. In the skeleton example above, we see `add()` implemented like so: `return ($request->data) ? $post->save() : $post;`. In the case of this resource being queried from an API, data will accompany the request, in which case the operation can be processed and a boolean value returned. However, if the resource is being queried via `GET` from a browser, the browser simply wants a page with a form, therefore no operation is performed, and the object is simply returned.

* **An HTTP status code**:
	Returning an HTTP status code can be used almost interchangeably with returning a boolean value: it is generally used to indicate either success or failure of an operation. Using an HTTP code explicitly is most useful when the default status code returned by the framework would not be sufficient to describe an operation. For example, if the `add()` action of a resource simply put an object into a queue for later processing, it would be most appropriate to return a `202 Accepted`, indicating that the request was accepted, but has not been operated on yet.

* **An array**:
	Arrays are used to combine one or more of the above, along with other options that can be used to generate the response. Arrays are generally returned in the following format: `[$status[, $object][, options...]]`, where `status` is a boolean value _or_ HTTP status code, `$object` is the object you wish to respond with (optional), and `options` represents any/value pairs present in the array, which are used to generate the response (also optional).