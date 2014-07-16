<?php

/**
 * Configurations for ExtDirect integration
 * Default values for all boolean configurations is "false" (this is easy to remember)
 * 
 * @author jbruni
 */
class ExtDirect
{
	/**
	 * @var array   Name of the classes to be published to the Ext.Direct API
	 */
	static public $api_classes = array();
	
	/**
	 * @var string   Ext.Direct API attribute "url"
	 */
	static public $url;
	
	/**
	 * @var string   Ext.Direct API attribute "namespace"
	 */
	static public $namespace = 'Ext.php';
	
	/**
	 * @var string   Ext.Direct API attribute "descriptor"
	 */
	static public $descriptor = 'Ext.php.REMOTING_API';
	
	/**
	 * @var boolean   Set this to true to count only the required parameters of a method for the API "len" attribute
	 */
	static public $count_only_required_params = false;
	
	/**
	 * @var boolean   Set this to true to include static methods in the API declaration
	 */
	static public $include_static_methods = false; 
	
	
	/**
	 * @var boolean   Set this to true to include inherited methods in the API declaration
	 */
	static public $include_inherited_methods = false;
	
	/**
	 * @var boolean   Set this to true to create an object instance of a class even if the method being called is static
	 */
	static public $instantiate_static = false;
	
	/**
	 * @var boolean   Set this to true to call the action class constructor sending the action parameters to it
	 */
	static public $constructor_send_params = false;
	
	/**
	 * @var boolean   Set this to true to allow exception detailed information in the output
	 */
	static public $debug  = false;
	
	/**
	 * @var boolean   Set this to true to pass all action method call results through utf8_encode function
	 */
	static public $utf8_encode = false;
	
	/**
	 * @var string   Available options are "json" (good for Ext Designer) and "javascript"
	 */
	static public $default_api_output = 'json';
	
	/**
	 * @return string   JSON encoded array containing the full API declaration
	 */
	static public function get_api_json()
	{
		$api_array = array(
			'url'        => ( empty( self::$url ) ? $_SERVER['PHP_SELF'] : self::$url ),
			'type'       => 'remoting',
			'namespace'  => self::$namespace,
			'descriptor' => self::$descriptor
		);
		
		$actions = array();
		
		foreach( self::$api_classes as $class )
		{
			$methods = array(); 
			$reflection = new ReflectionClass( $class );
			
			foreach( $reflection->getMethods() as $method )
			{
				// Only public methods will be declared
				if ( !$method->isPublic() )
					continue;
				
				// Don't declare constructor, destructor or abstract methods
				if ( $method->isConstructor() || $method->isDestructor() || $method->isAbstract() )
					continue;
				
				// Only declare static methods according to "include_static_methods" configuration
				if ( !self::$include_static_methods && $method->isStatic() )
					continue;
				
				// Do not declare inherited methods, according to "include_inherited_methods" configuration
				if ( !self::$include_inherited_methods && ( $method->getDeclaringClass()->name != $class ) )
					continue;
				
				// Count only required parameters or count them all, according to "count_only_required_params" configuration 
				if ( self::$count_only_required_params )
					$methods[] = array( 'name' => $method->getName(), 'len' => $method->getNumberOfRequiredParameters() );
				else
					$methods[] = array( 'name' => $method->getName(), 'len' => $method->getNumberOfParameters() );
			}
			$actions[$class] = $methods;
		}
		
		$api_array['actions'] = $actions;
		
		return json_encode( $api_array );
	}
	
	/**
	 * @return string   JSON encoded array containing the full API declaration
	 */
	static public function get_api_javascript()
	{
		$template = <<<JAVASCRIPT

Ext.namespace( '[%namespace%]' );
[%descriptor%] = [%actions%];
Ext.Direct.addProvider( [%descriptor%] );

JAVASCRIPT;
		
		$elements = array(
			'[%actions%]'    => self::get_api_json(),
			'[%namespace%]'  => ExtDirect::$namespace,
			'[%descriptor%]' => ExtDirect::$descriptor
		);
		
		return strtr( $template, $elements );
	}
	
	static public function provide( $api_classes = null )
	{
		new ExtDirectController( $api_classes );
	}
}

/**
 * Process Ext.Direct HTTP requests
 * 
 * @author jbruni
 */
class ExtDirectRequest
{
	/**
	 * @var array   Actions to be executed in this request
	 */
	public $actions = array();
	
	/**
	 * @var boolean   True if there is a file upload; false otherwise
	 */
	public $upload = false;
	
	/**
	 * Call the correct action processing method according to $_POST['extAction'] availability
	 */
	public function __construct()
	{
		if ( isset( $_POST['extAction'] ) )
			$this->get_form_action();
		else
			$this->get_request_actions();
	}
	
	/**
	 * Instantiate actions to be executed in this request using "extAction" (form)
	 */
	protected function get_form_action()
	{
		$extParameters = $_POST;
		
		foreach( array( 'extAction', 'extMethod', 'extTID', 'extUpload' ) as $variable )
		{
			if ( !isset( $extParameters[$variable] ) )
				$$variable = '';
			else
			{
				$$variable = $extParameters[$variable];
				unset( $extParameters[$variable] );
			}
		}
		
		$this->actions[] = new ExtDirectAction( $extAction, $extMethod, $extParameters, $extTID, $extUpload );
		
		$this->upload = (boolean) $extUpload;
	}
	
	/**
	 * Instantiate actions to be executed in this request (without "extAction")
	 */
	protected function get_request_actions()
	{
		$input = file_get_contents( 'php://input' );
		
		$request = json_decode( $input );
		
		if ( !is_array( $request ) )
			$request = array( $request );
		
		foreach( $request as $rpc )
		{
			foreach( array( 'type', 'action', 'method', 'data', 'tid' ) as $variable )
				$$variable = ( isset( $rpc->$variable ) ? $rpc->$variable : '' );
			
			if ( $type == 'rpc' )
				$this->actions[] = new ExtDirectAction( $action, $method, $data, $tid, false );
		}
	}
}

/**
 * Store HTTP response contents for output
 * 
 * @author jbruni
 */
class ExtDirectResponse
{
	/**
	 * @var array   HTTP headers to be sent in the response
	 */
	public $headers = array();
	
	/**
	 * @var contents   HTTP body to be sent in the response
	 */
	public $contents = '';
}

/**
 * Call a Ext.Direct API class method and format the results
 * 
 * @author jbruni
 */
class ExtDirectAction
{
	/**
	 * @var string   API class name
	 */	
	public $action;
	
	/**
	 * @var string   Method name
	 */
	public $method;
	
	/**
	 * @var mixed   Method parameters
	 */
	public $parameters;
	
	/**
	 * @var integer   Unique identifier for the transaction
	 */
	public $transaction_id;
	
	/**
	 * @var boolean   True if there is a file upload; false otherwise
	 */
	public $upload = false;
	
	/**
	 * @param string $action   API class name
	 * @param string $method   Method name
	 * @param mixed $parameters   Method parameters
	 * @param integer $transaction_id   Unique identifier for the transaction
	 * @param boolean $upload   True if there is a file upload; false otherwise
	 */
	public function __construct( $action, $method, $parameters, $transaction_id, $upload = false )
	{
		foreach( array( 'action', 'method', 'parameters', 'transaction_id', 'upload' ) as $parameter )
			$this->$parameter = $$parameter;
	}
	
	/**
	 * @return array   Result of the action execution
	 */
	public function run()
	{
		$response = array(
			'type'    => 'rpc',
			'tid'     => $this->transaction_id,
			'action'  => $this->action,
			'method'  => $this->method
		);
		
		try
		{
			$result = $this->call_action();
			$response['result'] = $result;
		}
		
		catch ( Exception $e )
		{
			$response['result'] = 'Exception';
			if ( ExtDirect::$debug )
				$response = array(
					'type'    => 'exception',
					'tid'     => $this->transaction_id,
					'message' => $e->getMessage(),
					'where'   => $e->getTraceAsString()
				);
		}
		
		if ( ExtDirect::$utf8_encode )
			array_walk_recursive( $response, array( $this, 'utf8_encode' ) );
		
		return $response;
	}
	
	/**
	 * @param mixed $value   If it is a string, it will be UTF8 encoded
	 * @param mixed $key   Not used (passed by "array_walk_recursive" function)
	 * @return mixed   UTF8 encoded string, or unchanged value if not a string
	 */
	protected function &utf8_encode( &$value, $key )
	{
		if ( is_string( $value ) )
			$value = utf8_encode( $value );
		return $value;
	}
	
	/**
	 * @return mixed   Result of the action
	 */
	protected function call_action()
	{
		$class = $this->action;
		
		// Accept only calls to classes defined at "api_classes" configuration
		if ( !in_array( $class, ExtDirect::$api_classes ) )
			throw new Exception( 'Call to undefined or not allowed class ' . $class, E_USER_ERROR );
		
		// Do not allow calls to magic methods; only allow calls to methods returned by "get_class_methods" function
		if ( ( substr( $this->method, 0, 2 ) == '__' ) || !in_array( $this->method, get_class_methods( $class ) ) )
			throw new Exception( 'Call to undefined or not allowed method ' . $class . '::' . $this->method, E_USER_ERROR );
		
		$ref_method = new ReflectionMethod( $class, $this->method );
		
		// Get number of parameters for the method
		if ( ExtDirect::$count_only_required_params )
			$params = $ref_method->getNumberOfRequiredParameters();
		else
			$params = $ref_method->getNumberOfParameters();
		
		if ( count( $this->parameters ) < $params )
			throw new Exception( 'Call to ' . $class . ' method ' . $this->method . ' needs at least ' . $params . ' parameters', E_USER_ERROR );
		
		if ( $ref_method->isStatic() )
		{
			if ( !ExtDirect::$include_static_methods )
				throw new Exception( 'Call to static method ' . $class . '::' . $this->method . ' not allowed', E_USER_ERROR );
			
			// If the method is static, we usually don't need to create an instance
			if ( !ExtDirect::$instantiate_static )
				return call_user_func_array( array( $class, $this->method ), $this->parameters );
		}
		
		// By default, we don't send parameters to constructor, but "constructor_send_params" configuration allows this
		if ( !ExtDirect::$constructor_send_params )
			$this->instance = new $class;
		else
		{
			$ref_class = new ReflectionClass( $class );
			$this->instance = $ref_class->newInstanceArgs( $this->parameters );
		}
		
		return call_user_func_array( array( $this->instance, $this->method ), $this->parameters );
	}
}

/**
 * Ext.Direct API controller
 * 
 * @author jbruni
 */
class ExtDirectController
{
	/**
	 * @var ExtDirectRequest   Object to process HTTP request
	 */
	public $request;
	
	/**
	 * @var ExtDirectResponse   Object to store HTTP response
	 */
	public $response;
	
	/**
	 * @param string $actions_class   Name of the API class (ExtDirectActions descendant)
	 * @param boolean $autorun   If true, automatically run the controller
	 */
	public function __construct( $api_classes = null, $autorun = true )
	{
		if ( is_array( $api_classes ) )
			ExtDirect::$api_classes = $api_classes;
		
		elseif ( is_string( $api_classes ) )
			ExtDirect::$api_classes = array( $api_classes );
		
		$this->request   = new ExtDirectRequest();
		$this->response  = new ExtDirectResponse();
		
		if ( $autorun )
		{
			$this->run();
			$this->output();
			exit();
		}
	}
	
	
	/**
	 * @return string   JSON or JavaScript API declaration for the classes on "api_classes" configuration array
	 */
	public function get_api()
	{
		if ( isset( $_GET['javascript'] ) || ( ExtDirect::$default_api_output == 'javascript' ) )
			return ExtDirect::get_api_javascript();
		else
			return ExtDirect::get_api_json();
	}
	
	/**
	 * Process the request, execute the actions, and generate the response
	 */
	public function run()
	{
		if ( empty( $this->request->actions ) )
			$this->response->contents = $this->get_api();
		
		else
		{
			$response = array();
			foreach( $this->request->actions as $action )
				$response[] = $action->run();
			
			if ( count( $response ) > 1 )
				$this->response->contents = utf8_encode( json_encode( $response ) );
			else
				$this->response->contents = utf8_encode( json_encode( $response[0] ) );
			
			if ( $this->request->upload )
				$this->response->contents = '<html><body><textarea>' . preg_replace( '/&quot;/', '\\&quot;', $this->response->contents ) . '</textarea></body></html>';
		}
		
		if ( $this->request->upload )
			$this->response->headers[] = 'Content-Type: text/html';
		else
			$this->response->headers[] = 'Content-Type: text/javascript';
	}
	
	/**
	 * Output response contents
	 */
	public function output()
	{
		foreach( $this->response->headers as $header )
			header( $header );
		
		echo $this->response->contents;
	}
}

?>
