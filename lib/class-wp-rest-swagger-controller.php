<?php

/**
 * Swagger base class.
 */
class WP_REST_Swagger_Controller extends WP_REST_Controller
{


	/**
	 * Construct the API handler object.
	 */
	public function __construct()
	{
		$this->namespace = 'apigenerate';
	}

	/**
	 * Register the meta-related routes.
	 */
	public function register_routes()
	{
		register_rest_route($this->namespace, '/swagger', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array($this, 'get_swagger'),
				'permission_callback' => array($this, 'get_swagger_permissions_check'),
				'args'                => $this->get_swagger_params(),
			),

			'schema' => '',
		));
	}

	public function get_swagger_params()
	{
		$new_params = array();
		return $new_params;
	}

	public function get_swagger_permissions_check($request)
	{
		return true;
	}

	function getSiteRoot($path = '')
	{
		global $wp_rewrite;
		$rootURL = site_url();

		if ($wp_rewrite->using_index_permalinks()) $rootURL .= '/' . $wp_rewrite->index;

		$rootURL .= '/' . $path;

		return $rootURL;
	}

	private function compose_operation_name($carry, $part)
	{
		$carry .= ucfirst(strtolower($part));
		return $carry;
	}

	/**
	 * Retrieve custom swagger object.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Request|WP_Error Meta object data on success, WP_Error otherwise
	 */
	public function get_swagger($request)
	{

		global $wp_rewrite;
		//
		//		if($wp_rewrite->root!='/'){
		//			$basePath = '/'.$wp_rewrite->root;//'/'.$matches[1].'/';
		//		}

		$title = wp_title('', 0);
		$host = parse_url(site_url('/'), PHP_URL_HOST) . ':' . parse_url(site_url('/'), PHP_URL_PORT);
		if (empty($title)) {
			$title = $host;
		}

		$basePath = parse_url(get_rest_url(), PHP_URL_PATH);
		$basePath = str_replace('index.php/', '', $basePath);
		$basePath = rtrim($basePath, '/');

		$swagger = array(
			'openapi' => '3.0.3',
			'info' => array(
				'version' => '1.0',
				'title' => $title
			),
			'tags' => [],
			'servers' => array(
				array(
					'url' => get_rest_url()
				)
			),
			'paths' => array(),
			'components' => array(
				'schemas' => array(
					'wp_error' => array(
						'properties' => array(
							'code' => array(
								'type' => 'string'
							), 'message' => array(
								'type' => 'string'
							), 'data' => array(
								'type' => 'object', 'properties' => array(
									'status' => array(
										'type' => 'integer'
									)
								)
							)
						)
					)
				),
				'securitySchemes' => array(
					"cookieAuth" => array(
						"type" => "apiKey",
						"name" => "X-WP-Nonce",
						"in" => "header",
						"description" => "Please see http://v2.wp-api.org/guide/authentication/"
					)
				)
			),
		);

		$security = array(
			array('cookieAuth' => array())
		);


		if (function_exists('rest_oauth1_init')) {
			$swagger['components']['securitySchemes']['oauth'] = array(
				'type' => 'oauth2', 'x-oauth1' => true, 'flow' => 'accessCode', 'authorizationUrl' => $this->getSiteRoot('oauth1/authorize'), 'tokenUrl' =>  $this->getSiteRoot('oauth1/request'), 'x-accessUrl' =>  $this->getSiteRoot('oauth1/access'), 'scopes' => array(
					'basic' => 'OAuth authentication uses the OAuth 1.0a specification (published as RFC5849)'
				)
			);
			$security[] = 	array('oauth' => array('basic'));
		}


		if (class_exists('WO_Server')) {
			$rootURL = site_url();

			if ($wp_rewrite->using_index_permalinks()) $rootURL .= '/' . $wp_rewrite->index;

			$swagger['components']['securitySchemes']['oauth'] = array(
				'type' => 'oauth2', 'flow' => 'accessCode', 'authorizationUrl' => $rootURL . '/oauth/authorize', 'tokenUrl' => $rootURL . '/oauth/token', 'scopes' => array(
					'openid' => 'openid'
				)
			);
			$security[] = 	array('oauth' => array('openid'));
		}

		if (class_exists('Application_Passwords') || function_exists('json_basic_auth_handler')) {
			$swagger['components']['securitySchemes']['basicAuth'] = array(
				'scheme' => 'basic',
				'type' => (is_ssl() | force_ssl_admin()) ? 'https' : 'http'
			);
			$security[] = 	array('basicAuth' => array(''));
		}

		$restServer = rest_get_server();
		$routes = $restServer->get_namespaces();
		$tags = [];
		foreach ($routes as $key => $value) {
			if (defined('COBEIA_API_SCHEMA')) {
				if (!in_array($value, COBEIA_API_SCHEMA)) {
					continue;
				}
			}
			if ($value == "apigenerate") {
				continue;
			}
			$tags[] = array('name' => explode('/', $value)[0]);
		}

		$swagger['tags'] = $tags;

		foreach ($restServer->get_routes() as $endpointName => $endpoint) {

			// don't include self - that's a bit meta
			if ($endpointName == '/' . $this->namespace . '/swagger') {
				continue;
			}
			if (defined('COBEIA_API_SCHEMA')) {
				if (!array_filter(COBEIA_API_SCHEMA, function ($filter_endpoint) use ($endpointName) {
					if (strpos($endpointName, $filter_endpoint) !== false) {
						return $endpointName;
					}
				})) {
					continue;
				}
			}


			$routeopt = $restServer->get_route_options($endpointName);
			if (!empty($routeopt['schema'][1])) {

				$schema = call_user_func(array(
					$routeopt['schema'][0], $routeopt['schema'][1]
				));
				if (isset($schema['title']) && $schema['title']) {
					$schema['title'] = str_replace("/", "_", $routeopt['namespace']) . '_' . str_replace(" ", "_", $schema['title']);
					$swagger['components']['schemas'][$schema['title']] = $this->schemaIntoDefinition($schema);
					$outputSchema = array('$ref' => '#/components/schemas/' . $schema['title']);
				}
			} else {
				//if there is no schema then it's a safe bet that this API call
				//will not work - move to the next one.
				continue;
			}

			$defaultidParams = array();
			//Replace endpoints var and add to the parameters required
			$endpointName = preg_replace_callback(
				'#\(\?P<(\w+?)>.*?\)(\?\))?#',
				function ($matches) use (&$defaultidParams) {
					$defaultidParams[] = array(
						'name' => $matches[1],
						'in' => 'path',
						'required' => true,
						'schema' => array(
							'type' => 'integer'
						)
					);
					return '{' . $matches[1] . '}';
				},
				$endpointName
			);
			// $endpointName = str_replace(site_url(), '',rest_url($endpointName));
			$endpointName = str_replace($basePath, '', $endpointName);

			if (empty($swagger['paths'][$endpointName])) {
				$swagger['paths'][$endpointName] = array();
			}

			foreach ($endpoint as $endpointPart) {

				foreach ($endpointPart['methods'] as $methodName => $method) {
					if (in_array($methodName, array('PUT', 'PATCH'))) continue; //duplicated by post

					$pathParamName = array_map(function ($param) {
						return $param['name'];
					}, $defaultidParams);

					$parameters = $defaultidParams;
					$schema = array();

					//Clean up parameters
					foreach ($endpointPart['args'] as $pname => $pdetails) {
						if (isset($parameters[$key]) && $parameters[$key]['in'] == 'path') {
							if (strpos($pname, 'id') !== false) {
								$parameters[$key]["schema"]["type"] = "integer";
							}
						}
						$parameter = array(
							'name' => $pname, 'in' => $methodName == 'POST' ? 'formData' : 'query', 'style' => 'form', 'explode' => 'false'
						);
						$key = array_search($pname, array_column($parameters, 'name'));
						if (!empty($pdetails['description'])) $parameter['description'] = $pdetails['description'];
						if (!empty($pdetails['format'])) $parameter['schema']['format'] = $pdetails['format'];
						if (!empty($pdetails['default'])) $parameter['schema']['default'] = $pdetails['default'];
						if (!empty($pdetails['enum'])) $parameter['schema']['enum'] = array_values($pdetails['enum']);
						if (!empty($pdetails['required'])) $parameter['required'] = $pdetails['required'];
						if (!empty($pdetails['minimum'])) {
							$parameter['schema']['minimum'] = $pdetails['minimum'];
							$parameter['schema']['format'] = 'number';
						}
						if (!empty($pdetails['maximum'])) {
							$parameter['schema']['maximum'] = $pdetails['maximum'];
							$parameter['schema']['format'] = 'number';
						}
						if (is_array($pdetails['type'])) {
							if (in_array('integer', $pdetails['type'])) {
								$pdetails['type'] = 'integer';
							} elseif (in_array('array', $pdetails['type'])) {
								$pdetails['type'] = 'array';
							}
						}
						if (!empty($pdetails['type'])) {
							if ($pdetails['type'] == 'array') {
								$parameter['schema']['type'] = $pdetails['type'];
								$parameter['schema']['items'] = array('type' => 'string');
								if (isset($pdetails['items']['enum'])) {
									$parameter['schema']['items']['enum'] = $pdetails['items']['enum'];
								}
								if ($pdetails['items']['type'] == 'object' && isset($pdetails['items']['properties'])) {
									$parameter['schema']['items'] = array(
										'type' => 'object',
										'properties' => $pdetails['items']['properties']
									);
									$parameter['schema']['items']['properties'] = $this->cleanParameter($parameter['schema']['items']['properties']);
								}
								if (isset($parameter['schema']['default']) && !is_array($parameter['schema']['default']) && $parameter['schema']['default'] != null) {
									$parameter['schema']['default'] = array($parameter['default']);
								}
							} elseif ($pdetails['type'] == 'object') {
								if (isset($pdetails['properties']) || !empty($pdetails['properties'])) {
									$parameter['schema']['type'] = 'object';
									$parameter['schema']['properties'] = $pdetails['properties'];
									$parameter['schema']['properties'] = $this->cleanParameter($parameter['schema']['properties']);
								} else {
									$parameter['schema']['type'] = 'string';
								}
								if (!isset($pdetails['properties']) || empty($pdetails['properties'])) {
									$parameter['schema']['type'] = 'string';
								}
							} elseif ($pdetails['type'] == 'date-time') {
								$parameter['schema']['type'] = 'string';
								$parameter['schema']['format'] = 'date-time';
							} elseif (is_array($pdetails['type']) && in_array('string', $pdetails['type'])) {
								$parameter['schema']['type'] = 'string';
								$parameter['schema']['format'] = 'date-time';
							} else if ($pdetails['type'] == 'null') {
								$parameter['schema']['type'] = 'string';
								$parameter['schema']['nullable'] = true;
							} else {
								$parameter['schema']['type'] = $pdetails['type'];
							}
							if (isset($parameter['default']) && is_array($parameter['default']) && $parameter['type'] == 'string') {
								$parameter['schema']['default'] = "";
							}
						}

						if (!in_array($parameter['name'], $pathParamName)) {
							if ($methodName === 'POST') {
								unset($parameter['in']);
								unset($parameter['explode']);
								unset($parameter['style']);
								unset($parameter['required']);
								array_push($schema, $parameter);
							} else {
								unset($parameter['type']);
								$parameters[] = $parameter;
							}
						}
					}

					if ($methodName === 'POST' && !empty($schema)) {
						$this->removeDuplicates($schema);
						$properties = array();
						foreach ($schema as $index => $t) {
							$properties[$t['name']] = $t;
							if (empty($properties[$t['name']]['type'])) {
								if (!empty($properties[$t['name']]['schema']['type'])) {
									$properties[$t['name']]['type'] = $properties[$t['name']]['schema']['type'];
								} else {
									$properties[$t['name']]['type'] = 'string';
								}
							}
							if (!empty($properties[$t['name']]['schema']['items'])) $properties[$t['name']]['items'] = $properties[$t['name']]['schema']['items'];
							if (!empty($properties[$t['name']]['schema']['enum'])) $properties[$t['name']]['enum'] = $properties[$t['name']]['schema']['enum'];
							if (!empty($properties[$t['name']]['schema']['properties'])) $properties[$t['name']]['properties'] = $properties[$t['name']]['schema']['properties'];
							unset($properties[$t['name']]['schema']['type']);
							unset($properties[$t['name']]['name']);
							unset($properties[$t['name']]['schema']);
						}
					} else {
						$this->removeDuplicates($parameters);
					}

					//If the endpoint is not grabbing a specific object then
					//assume it's returning a list
					$outputSchemaForMethod = $outputSchema;
					if ($methodName == 'GET' && !preg_match('/}$/', $endpointName)) {
						if (
							!preg_match('/activity\/{id}\/comment/', $endpointName) &&
							!preg_match('/members\/me/', $endpointName) &&
							!preg_match('/users\/me/', $endpointName) &&
							!preg_match('/messages\/search-thread/', $endpointName)
						) {
							$outputSchemaForMethod = array(
								'type' => 'array', 'items' => $outputSchemaForMethod
							);
						}
					}

					$responses = array(
						200 => array(
							'description' => "successful operation",
							'content' => array(
								'application/json' => array(
									'schema' => $outputSchemaForMethod
								)
							)
						),
						'default' => array(
							'description' => "error",
							'content' => array(
								'application/json' => array(
									'schema' => array('$ref' => '#/components/schemas/wp_error')
								)
							)
						)
					);

					if (in_array($methodName, array('POST', 'PATCH', 'PUT')) && !preg_match('/}$/', $endpointName)) {
						//This are actually 201's in the default API - but joy of joys this is unreliable
						$responses[201] = array(
							'description' => "successful operation",
							'content' => array(
								'application/json' => array(
									'schema' => $outputSchemaForMethod
								)
							)
						);
					}

					$operationId = ucfirst(strtolower($methodName)) . array_reduce(explode('/', preg_replace("/{(\w+)}/", 'by/${1}', $endpointName)), array($this, "compose_operation_name"));

					$tags = explode('/', $endpointName);

					$swagger['paths'][$endpointName][strtolower($methodName)] = array(
						'tags' => array($tags[1]), 'parameters' => $parameters, 'security' => $security, 'responses' => $responses, 'operationId' => $operationId
					);
					if ($methodName === 'POST' && !empty($schema)) {
						$swagger['paths'][$endpointName][strtolower($methodName)]['x-codegen-request-body-name'] = 'body';
						$swagger['paths'][$endpointName][strtolower($methodName)]['requestBody'] = array(
							'content' => array(
								'application/json' => array(
									'schema' => array(
										'type' => 'object',
										'title' => ucfirst(strtolower($methodName)) . array_reduce(explode('/', preg_replace("/{(\w+)}/", 'by/${1}', $endpointName)), array($this, "compose_operation_name")) . 'Input',
										'properties' => $properties
									)
								)
							)
						);
					}
				}
			}
		}

		$response = rest_ensure_response($swagger);

		return apply_filters('rest_prepare_meta_value', $response, $request);
	}

	private function cleanParameter($properties)
	{
		foreach ((array) $properties as $key => $t) {
			if ($properties[$key]['type'] == 'array') {
				if ($properties[$key]['items']['type'] == 'object') {
					$properties[$key]['items']['properties'] = $this->cleanParameter($properties[$key]['items']['properties']);
				}
			}
			if ($properties[$key]['type'] == 'object') {
				if (isset($properties[$key]['context'])) unset($properties[$key]['context']);
				if (isset($properties[$key]['properties']) || !empty($properties[$key]['properties'])) {
					$properties[$key]['properties'] = $this->cleanParameter($properties[$key]['properties']);
				} else {
					$properties[$key]['type'] = 'string';
				}
			} else {
				if (is_array($t['type'])) $properties[$key]['type'] = 'string';
				if ($t['type'] == 'mixed') $properties[$key]['type'] = 'string';
				if ($properties[$key]['type'] == 'null') {
					$properties[$key]['type'] = 'string';
					$properties[$key]['nullable'] = true;
				}
				if (isset($t['context'])) unset($properties[$key]['context']);
				if (isset($t['sanitize_callback'])) unset($properties[$key]['sanitize_callback']);
				if (isset($t['validate_callback'])) unset($properties[$key]['validate_callback']);
				if (isset($t['required'])) unset($properties[$key]['required']);
				if (isset($t['readonly'])) unset($properties[$key]);
			}
		}
		return $properties;
	}

	private function removeDuplicates($params)
	{
		$isdupblicate = array();
		foreach ($params as $index => $t) {
			if (isset($isdupblicate[$t["name"]])) {
				array_splice($params, $index, 1);
				continue;
			}
			$isdupblicate[$t["name"]] = true;
		}

		$isdupblicate2 = array();
		foreach ($params as $index => $t) {
			if (isset($isdupblicate2[$t["name"]])) {
				array_splice($params, $index, 1);
				continue;
			}
			$isdupblicate2[$t["name"]] = true;
		}
	}

	/**
	 * Turns the schema set up by the endpoint into a swagger definition.
	 *
	 * @param array $schema
	 * @return array Definition
	 */
	private function schemaIntoDefinition($schema)
	{
		if (!empty($schema['$schema'])) unset($schema['$schema']);
		if (!empty($schema['links'])) unset($schema['links']);
		if (!empty($schema['readonly'])) unset($schema['readonly']);
		if (!empty($schema['context'])) unset($schema['context']);
		// if(!empty($schema['title']))unset($schema['title']);

		if (empty($schema['properties'])) {
			$schema['properties'] = new stdClass();
		}
		if (isset($schema['items'])) {
			unset($schema['items']);
		}

		foreach ($schema['properties'] as $name => &$prop) {
			if (!empty($prop['arg_options'])) unset($prop['arg_options']);
			if (!empty($prop['$schema'])) unset($prop['$schema']);
			if (!empty($prop['in'])) unset($prop['in']);
			if (!empty($prop['validate_callback'])) unset($prop['validate_callback']);
			if (!empty($prop['context'])) unset($prop['context']);
			if (!empty($prop['readonly'])) unset($prop['readonly']);
			if (!empty($prop['items']['context'])) unset($prop['items']['context']);
			if (is_array($prop['default'])) unset($prop['default']);
			if (is_array($prop['type'])) $prop['type'] = $prop['type'][0];
			if (isset($prop['default']) && empty($prop['default'])) unset($prop['default']);
			if ($prop['type'] == 'mixed') $prop['type'] = 'string';
			if ($prop['type'] == 'null') {
				$prop['type'] = 'string';
				$prop['nullable'] = true;
			}

			if (!empty($prop['properties'])) {
				$prop['type'] = 'object';
				unset($prop['default']);
				$prop = $this->schemaIntoDefinition($prop);
			} else if (isset($prop['properties'])) {
				$prop['properties'] = array('id' => array('type' => 'integer'));
				// $prop['properties'] = new stdClass();
			}

			//-- Changes by Richi
			if (!empty($prop['enum'])) {
				if (isset($prop['enum'][0]) && $prop['enum'][0] == "") {
					if (count($prop['enum']) > 1) {
						array_shift($prop['enum']);
					} else {
						$prop['enum'][0] = "NONE";
					}
				};
			}

			if (!empty($prop['default']) && $prop['default'] == null) {
				unset($prop['default']);
			}
			//--
			if ($prop['type'] == 'object' && (!isset($prop['properties']) || empty($prop['properties']))) {
				if (!empty($prop['items'])) unset($prop['items']);
				$prop['properties'] = array('id' => array('type' => 'integer'));
			}
			if ($prop['type'] == 'array') {
				if (isset($prop['items']['type']) && $prop['items']['type'] === 'object') {
					$prop['items'] = $this->schemaIntoDefinition($prop['items']);
				} else if (isset($prop['items']['type'])) {
					if (is_array($prop['items']['type'])) {
						$prop['items'] = array('type' => $prop['items']['type'][1]);
					} else {
						$prop['items'] = array('type' => $prop['items']['type']);
					}
				} else {
					$prop['items'] = array('type' => 'string');
				}
			} elseif ($prop['type'] == 'date-time') {
				$prop['type'] = 'string';
				$prop['format'] = 'date-time';
			} elseif (is_array($prop['type']) && in_array('string', $prop['type'])) {
				$prop['type'] = 'string';
				$prop['format'] = 'date-time';
			}
			if ($prop['type'] == 'bool') {
				$prop['type'] = 'boolean';
			}
			if (isset($prop['enum'])) {
				$prop['enum'] = array_values($prop['enum']);
			}
			if (isset($prop['required'])) unset($prop['required']);
			if (isset($prop['readonly'])) unset($prop['readonly']);
			if (isset($prop['context'])) unset($prop['context']);
		}

		return $schema;
	}

	/**
	 * Get the meta schema, conforming to JSON Schema
	 *
	 * @return array
	 */
	public function get_swagger_schema()
	{
		$schema = json_decode(file_get_contents(dirname(__FILE__) . '/schema.json'), 1);
		return $schema;
	}
}
