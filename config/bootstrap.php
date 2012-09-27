<?php

use lithium\core\Libraries;

Libraries::paths(array('resources' => array(
	'{:library}\controllers\{:namespace}\{:class}\{:name}'
)));

Libraries::add("Mockery", array(
	'path' => dirname(__DIR__) . '/libraries/mockery/library',
	'prefix' => ''
));

?>