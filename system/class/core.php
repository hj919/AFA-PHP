<?php if (!defined('AFA')) die();

class Request{
    //模型名称
    public $module = NULL;
    //控制器名称
    public $controller = '';
    //执行方法
    public $method = '';
    //参数
    public $params = array();
    //模型路径
    public $module_dir = '';
    //当前URL
    public $url = '';
    //URL目录,方便同一控制器中使用
    public $url_pre = '';
    
    public static function instance($url = ''){
        return new self($url);
    }
    
    public function __construct($url = ''){
        
        $url = trim($url);
        if ($url == ''){
            if(PHP_SAPI == 'cli') {
                $url = isset($_SERVER['argv'][1])?self::parse($_SERVER['argv'][1]):""; //得到格式一致的路径URL，并设置$_GET
            } else{
                $url = @$_SERVER['PATH_INFO'];//此路径已被系统默认处理过了
            }
        }else{
            $url = self::parse($url);//得到格式一致的路径URL，并设置$_GET
        }
        
        if(F::config('suffix')) {
            $url = str_replace(F::config('suffix'), '', $url);
        }
        $this->url = $url;
        
        global $modules, $cModule, $cController, $cMethod;
        $this->controller = $cController;
        $this->method = $cMethod;
        
        $path_vars = explode('/', $url);
        
        if(isset($path_vars[1]) && $path_vars[1])  {
            $this->module = $path_vars[1];//模型名
        }else{
            $this->module = $cModule? $cModule: NULL;
        }
        
        if(isset($path_vars[2]) && $path_vars[2])  {
            $this->controller = $path_vars[2];
        }

        if ($this->module && isset($modules[$this->module])) {
            $this->module_dir = MODULEPATH.$this->module.DIRECTORY_SEPARATOR;
            //控制器所在目录
            $conpath = $this->module_dir.'controller'.DIRECTORY_SEPARATOR;
            //查找module/模块名/controller/控制器名.php 文件
            $cFile = $conpath.$this->controller.EXT;
            
            if (file_exists($cFile)) {
                $this->url_pre = $this->module.'/'.$this->controller.'/';
                $this->method = @$path_vars[3]?@$path_vars[3]:$this->method;
                $this->params = @$path_vars[4]?array_slice($path_vars, 4):$this->params;
            } else {
                $confile = @$path_vars[3]?@$path_vars[3]:$cController;
                //查找module/模块名/controller/子文件夹/控制器名.php 文件
                $cFile = $conpath.$this->controller.DIRECTORY_SEPARATOR.$confile.EXT;
                if(file_exists($cFile)){
                    $this->url_pre = $this->module.'/'.$this->controller.'/'.$confile.'/';
                    $this->controller = $confile.'_'.ucfirst($this->controller);
                    $this->method = @$path_vars[4]?@$path_vars[4]:$this->method;
                    $this->params = @$path_vars[5]?array_slice($path_vars, 5):$this->params;
                }else {
                    //查找module/模块名/controller/模块名.php 文件
                    $cFile = $conpath.$this->module.EXT;
                    if (file_exists($cFile)){
                        $this->url_pre = $this->module.'/';
                        $this->controller = $this->module;
                        $this->method = @$path_vars[2]?@$path_vars[2]:$this->method;
                        $this->params = @$path_vars[3]?array_slice($path_vars, 3):$this->params;
                    }else 
                        trigger_error("Controller {$this->controller}:{$cFile} not exist! ", E_USER_ERROR);
                }
            }
            //把路径加入自动载入中
            Load::addModule($this->module, $this->module_dir);
            
        } else {
            $this->controller = $this->module?$this->module:$cController;
            $conpath = APPPATH.'controller'.DIRECTORY_SEPARATOR;
            //查找application/controller/控制器名.php 文件
            $cFile = $conpath.$this->controller.EXT;
            
            if (file_exists($cFile)) {
                $this->url_pre = $this->controller.'/';
                $this->method = @$path_vars[2]?@$path_vars[2]:$this->method;
                $this->params = @$path_vars[3]?array_slice($path_vars, 3):$this->params;
            } else {
                $confile = @$path_vars[2]?@$path_vars[2]:$cController;
                //查找application/controller/子文件夹/控制器名.php 文件
                $cFile = $conpath.$this->controller.DIRECTORY_SEPARATOR.$confile.EXT;
                if(file_exists($cFile)){
                    $this->url_pre = $this->controller.'/'.$confile.'/';
                    $this->controller = $confile.'_'.ucfirst($this->controller);
                    $this->method = @$path_vars[3]?@$path_vars[3]:$this->method;
                    $this->params = @$path_vars[4]?array_slice($path_vars, 4):$this->params;
                }else {
                    trigger_error("Controller {$this->controller}:{$cFile} not exist! ", E_USER_ERROR);
                }
            }
        }
        
        $this->url_pre = '/'.$this->url_pre;
        
        //载入控制器文件
        require_once $cFile;
        
        return $this;
    }
    
    /**
     * 执行请求
     * @throws AfaException
     */
    public function run(){
        // controller 类的命名规则为：文件名(首字母大写)+'_Controller'
        $class = new ReflectionClass(ucfirst($this->controller) . '_Controller');
        
        // Create a new controller instance
        $controller = $class->newInstance($this);
        try {
            // Load the controller method
            $method = $class->getMethod($this->method.'_Action');
        } catch (ReflectionException $e) {
            // Use __call instead
            $method = $class->getMethod('__call');
            
            // Use arguments in __call format
            array_unshift($this->params, $this->method.'_Action');
        }
        
        $before = $class->getMethod('before');
        $before->invokeArgs($controller, $this->params);
        
        // Execute the controller method
        $method->invokeArgs($controller, $this->params);
        
        $after = $class->getMethod('after');
        $after->invokeArgs($controller, $this->params);

        return $this;
    }
    
    /**
     * 
     * @param string $url
     * @return Ambigous <string, mixed>
     */
    private static function parse($url){
        $url_arr = parse_url($url);
        
        if (isset($url_arr['query'])){//设置$_GET
            $afa_vars = explode('&', $url_arr['query']);
            foreach ($afa_vars as $afa_str){
                $arr = explode('=', $afa_str);
                //初始化get的值
                $_GET[$arr[0]] = @$arr[1];
            }
            unset($afa_vars);
        }
        
        $first_word = substr($url_arr['path'], 0, 1);
        
        return $first_word == '/'?$url_arr['path']:'/'.$url_arr['path'];
    }
    
    public function __get($key){
        return $this->$key;
    }

}

/**
 * 控制器类
 *
 */
class Controller {
	
    public $view;
    
    public $request;
    
	/**
	 * 默认模板地址
	 *
	 */
	public function __construct(Request $request){
		$this->view = View::instance($request->module_dir);
		$this->request = $request;
	}
	
	public function __call($method = '', $params = ''){
	    throw new Exception(get_class($this)."控制器类中 不存在 $method 方法。", E_ERROR);
	}
	
	public function before(){
	    
	}
	
	public function after(){
	     
	}
	
	public function echojson($data, $format = 'json'){

        header("Content-type:text/html;charset=utf-8");

        if('json' == $format){
            echo F::json_encode($data);
        }elseif('jsonp' == $format){
            $fun = input::get('callback');
            echo $fun.'('.F::json_encode($data).')';
        }else{
            echo $data;
        }

        return true;
	}
	
	public function echomsg($message, $url = false, $exit = false){
        F::tip($message, $url, 5, $exit);
	}
	
	public function echoerror(){
	    
	}
	
	public function echo404($message){
	    header("HTTP/1.1 404 Not Found");
	    header("Status: 404 Not Found");
	    header("Content-Type: text/html; charset=UTF-8");
	    if (preg_match('/MSIE/i',input::server('HTTP_USER_AGENT'))){
	        echo str_repeat(" ",512);
	    }
	    echo 'this 404 page <br />';
	    echo $message;
	    exit;
	}

    /**
     * 查看接口手册
     * @param $api 接口名称
     * @return bool true
     */
    public function man_Action($api = ''){
        global $modules;
        if(!isset($modules['man'])){
            return $this->echo404('man 手册模块未开启.');
        }

        $path_url = '/man/api/'.$this->request->controller.($api?'/'.$api:'');
        Request::instance($path_url)->run();
    }

    public function __set($name, $value)
    {
        $this->$name = $value;
    }

    public function __get($name)
    {
        return @$this->$name;
    }

}

/**
 * 模型类
 *
 */
class Model {
	//数据库链接对象
    protected $db;
	//数据表名称
	protected $table;
	//模块名称
	protected $module;
	//数据库配置
	protected $config;
	
	//主键字段，通常为自增ID字段
	protected $primary = 'id';
	public function __construct($id = null){
		$this->db = db::instance($this->module?$this->module:'default');
		if ($id){
		    $sql = sql::select('*', $this->table, array("$this->primary"=>$id));
			$orm = $this->db->getOneResult($sql);
			if ($orm){
                foreach ($orm as $k => $v) {
                    $this->$k = $v;
                }
            }
			unset($orm);
		}
	}
	
	/**
	 * 更新与插入  ORM
	 * @return Ambigous <boolean, number>
	 */
	public function save(){
	    $fieldsArr = array();
	    $insert = false;
	    foreach ($this->fileds as $f=>$v){
	        if ($f == $this->primary) continue;
	        $fieldsArr[$f] = isset($this->$f)?$this->$f:$v;
	    }
	    if ($this->{$this->primary}){
	        $sql = sql::update($fieldsArr, $this->table, array("{$this->primary}"=>$this->{$this->primary}));
	    }else{
	        $insert = true;
	        $sql = sql::insert($fieldsArr, $this->table);
	    }

	    $flag = $this->db->exec($sql);
	    if ($flag && $insert){
	        //插入数据后更新当前数据的ID
	        $this->{$this->primary} = $this->db->getId(); 
	    }
	    return $flag;
	}

    /**
     * 插入  ORM, 主键值非自增时使用此方法插入数据
     * @return Ambigous <boolean, number>
     */
    public function insert(){
        $fieldsArr = array();
        foreach ($this->fileds as $f=>$v){
            $fieldsArr[$f] = isset($this->$f)?$this->$f:$v;
        }

        $sql = sql::insert($fieldsArr, $this->table);
        $flag = $this->db->exec($sql);

        return $flag;
    }
	
	/**
	 * 删除 ORM
	 */
	public function delete(){
	    if ($this->{$this->primary}){
	        $sql = sql::delete($this->table, array("{$this->primary}"=>"{$this->{$this->primary}}"));
	        return $this->db->exec($sql);
	    }
	    return false;
	}
	/**
	 * 基础查询
	 * @param string $limit
	 * @param array $where
	 * @param string $orderby
	 * @return multitype: 查询结果
	 */
	public function lists($limit = "0,10", $where = array(), $orderby = ''){
	    $sql = sql::select('*', $this->table)
	    ->where($where)
	    ->orderby($orderby?$orderby:"{$this->primary} DESC")
	    ->limit($limit)->render();
	    return $this->db->query($sql);
	}
	
	public function __get($name){
	    return isset($this->$name)?$this->$name:'';
	}
	
	public function __set($name, $value){
	    $this->$name = $value;
	}

}

/**
 * 视图类
 */
class View {
	/**
	 * 视图文件
	 */
	private $file = '';
	
	private $filename = '';
	
	private $module_dir = '';
	
	/**
	 * 视图变量存放
	 */
	private $params = array();
	
	/**
	 * 全局变量存放
	 */
	public static $global_params = array();
	
	/**
	 * 构造函数
	 *
	 * @param $file 视图文件名
	 */
	public function __construct($file = ''){
		$this->filename = $file;
	}
	
	/**
	 * @param 静态方法
	 */
	public static function instance($module_dir = '', $file = ''){
		$view = new View($file);
		$view->module_dir = $module_dir;
		return $view;
	}

	/**
	 * Magically sets a view variable.
	 *
	 * @param   string   variable key
	 * @param   string   variable value
	 * @return  void
	 */
	public function __set($key, $value)
	{
		$this->params[$key] = $value;
	}
	
	public function set_view($filename){
	    $this->filename = $filename;
	}
	
	/**
	 * @param $file 视图文件名
	 */
	private function set_file($file)
	{
		$c = substr($file, 0, 1);
	    if ($c == DIRECTORY_SEPARATOR && file_exists($file . EXT))
            $this->file = $file . EXT;
        else {
            $file = ($this->module_dir ? $this->module_dir : APPPATH) . 'view' . DIRECTORY_SEPARATOR . $file . EXT;
            if (file_exists($file)) {
                return $this->file = $file;
            }else throw new Exception($file. 'is not exit', E_ERROR);
        }
    }

	/**
	 * Magically gets a view variable.
	 *
	 * @param  string  variable key
	 * @return mixed   variable value if the key is found
	 * @return void    if the key is not found
	 */
	public function &__get($key)
	{
		if (isset($this->params[$key]))
			return $this->params[$key];

		if (isset(View::$global_params[$key]))
			return View::$global_params[$key];

		if (isset($this->$key))
			return $this->$key;
	}
	
	/**
	 * 自动设置变量
	 */
	public function __call($func, $args = NULL){
		return $this->__get($func);
	}
    
	/**
	 * 自动设置变量
	 */
	public function render($render = true){
		$this->set_file($this->filename);

		$data = array_merge(View::$global_params, $this->params);
		// Buffering on
		ob_start();
		// Import the view variables to local namespace
		extract($data, EXTR_SKIP);
		
		include $this->file;

		// Fetch the output and close the buffer
		$str = ob_get_clean();
		if ($render) echo $str;
		return $str;
	}
	
	public function __toString(){
	    return $this->render(false);
	}
}

/**
 * 自定义错误处理类,
 * 可自由修改错误显示页面 view/error.php
 * @see        https://github.com/fafa211/AFA-PHP/blob/master/system/class/core.php
 * @author     郑书发 <22575353@qq.com>
 * @version    1.0
 * @category   core
 * @package    Classes
 * @copyright  Copyright (c) 2015-2020 afaphp.com
 * @license    http://www.afaphp.com/license.html
 */
class AfaException {

    /**
     * exception错误处理
     * @param object $exception
     * @return boolean
     */
    public static function exception_handle($exception)
    {
        $code = $exception->getCode();
        self::log($code, $exception->getMessage(), $exception->getFile(), $exception->getLine());
        
        switch ($code){
            case E_ERROR:
            case 1045:
                $view = new View(APPPATH.'view'.DIRECTORY_SEPARATOR.'error');
                $view->type = '错误';
                $view->message = $exception->getMessage();
                $view->file = $exception->getFile();
                $view->line = $exception->getLine();
                $view->trace = preg_replace("/\n/", "</p><p>", '<p>'.$exception->getTraceAsString());
                
                $view->render();
                exit(1);
                break;
            case E_WARNING:
                echo "<b>WARNING</b> [$code] {$exception->getMessage()} {$exception->getFile()} {$exception->getLine()}<br />\n";
                break;
            case E_NOTICE:
                echo "<b>NOTICE</b>: [$code] {$exception->getMessage()} {$exception->getFile()} {$exception->getLine()}<br />\n";
                break;
            default:
                echo "<b>Unknown error type</b>: [$code] {$exception->getMessage()} {$exception->getFile()} {$exception->getLine()}<br />\n";
                break;
                
        }
        return true;
    }

    /**
     * Error错误处理
     * @param int $errno
     * @param string $errstr
     * @param string $errfile
     * @param int $errline
     * @param string $errcontext
     * @return void|boolean
     */
    public static function error_handle($errno, $errstr, $errfile, $errline, $errcontext)
    {
        self::log($errno, $errstr, $errfile, $errline);
        
        if (! (error_reporting() & $errno)) {
            // This error code is not included in error_reporting
            return;
        }
        
        switch ($errno) {
            case E_USER_ERROR:
            case E_PARSE:
            case E_ERROR:
                $view = new View(APPPATH.'view'.DIRECTORY_SEPARATOR.'error');
                $view->type = '错误';
                $view->message = $errstr;
                $view->file = $errfile;
                $view->line = $errline;
                $view->trace = preg_replace("/\n/", "</p><p>", '<p>'.$errcontext."</p><p>PHP " . PHP_VERSION . " (" . PHP_OS . ")</p>");
                
                $view->render();
                exit(1);
                break;
            
            case E_USER_WARNING:
                echo "<b>My WARNING</b> [$errno] $errstr $errfile $errline<br />\n";
                break;
            
            case E_USER_NOTICE:
                echo "<b>My NOTICE</b> [$errno] $errstr $errfile $errline<br />\n";
                break;
            
            default:
                echo "Unknown error type: [$errno] $errstr $errfile $errline <br />\n";
                break;
        }
        
        /* Don't execute PHP internal error handler */
        return true;
    }
    
    public static function shutdown_handle()
    {
        
        $error = error_get_last();
        if (!$error || !in_array($error['type'], array(E_PARSE, E_ERROR, E_USER_ERROR))) return ;
        //调用error_handle
        self::error_handle($error['type'], $error['message'], $error['file'], $error['line'], '');
    }

    public static function log($code, $message, $file, $line)
    {
        //if(DEBUG) return true;
        $logfile = PROROOT.DIRECTORY_SEPARATOR.'runtime'.DIRECTORY_SEPARATOR.'log'.DIRECTORY_SEPARATOR.date('Y-m-d').'.php';
        $logstring = join("\t", array(date('Y-m-d H:i:s'), F::getIp(), $code, $message, $file, $line))."\n";
        error_log($logstring, 3, $logfile);
        return true;
    }

          
}

/**
 * 自动载入类
 */
class Load {
    
    public static $modules = array();
	
	static function loadClass($class_name)
	{
	    if (strpos($class_name, '_') === false){
			if (($c = substr($class_name,0,1)) === strtolower($c)){
			    return self::loadModule($class_name, 'helper');
			}else {
				return include(CLASSPATH.$class_name.EXT);
			}
		}else{
			$lastpos = strrpos($class_name, '_');
			$suffix = substr($class_name, $lastpos+1);

			$class_name = substr($class_name, 0, $lastpos);
			
			if ($suffix == 'Model' || $suffix = 'Controller'){
			    return self::loadModule($class_name, strtolower($suffix));
			}
		}
	}
	
	public static function addModule($module, $dir){
        if (! isset(self::$modules[$module])) {
            self::$modules[$module] = $dir;
        }
	}
	
	/**
	 * 自动载入model,helper,controller 或 class
	 * @param unknown $class_name
	 * @param string $type
	 */
	public static function loadModule($class_name, $type = 'model'){

	    if(!empty(self::$modules)){
	        foreach (self::$modules as $module => $dir) {
	            $file = $dir . $type . DIRECTORY_SEPARATOR . $class_name . EXT;
	            if (file_exists($file)) return include ($file);
	        }
	    }
        $file = APPPATH . $type . DIRECTORY_SEPARATOR . lcfirst($class_name) . EXT;
        if (file_exists($file)) return include ($file);

        if($type == 'controller' && strpos($class_name, '_') !== false){
            list($classfile, $subdir) = explode('_', $class_name);
            return include APPPATH . $type . DIRECTORY_SEPARATOR. $subdir. DIRECTORY_SEPARATOR. lcfirst($classfile). EXT;
        }
        return include (CLASSPATH . $class_name . EXT);
	}
}

/**
 * $_GET, $_POST, $_SERVER 控制 HTTP vars
 *
 */
class input{

	/**
     * Returns an array with all the variables in the GET header, fetching them
     * @static
     */
	public static function get($key = '', $value = '')
	{
		if ($value !== '') $_GET[$key] = $value;
		if ($key) return @$_GET[$key];
		return $_GET;
	}

	/**
     * Returns an array with all the variables in the POST header, fetching them
     */
	public static function post($key = '', $value = '')
	{
		if ($value !== '') $_POST[$key] = $value;
		if ($key) return @$_POST[$key];
		return $_POST;
	}


	/**
     * Returns an array with all the variables in the session, fetching them
     */
	public static function session($key = '', $value = '')
	{
		if ($value !== '') $_SESSION[$key] = $value;
		if ($key) return @$_SESSION[$key];
		return $_SESSION;
	}

	/**
     * Returns an array with the contents of the $_COOKIE global variable
     */
	public static function cookie($key = '', $value = '', $time = 3600)
	{
		if ($value) {
			$_COOKIE[$key] = $value;
			setcookie($key, $value, time() + $time, '/');
		}elseif ($value === null){
		    setcookie($key, $value, time() - 24*60*60, '/');
		    return ;
		}
		if ($key) return @$_COOKIE[$key];
		return $_COOKIE;
	}

	/**
     * Returns the value of the $_REQUEST array. In PHP >= 4.1.0 it is defined as a mix
     * of the $_POST, $_GET and $_COOKIE arrays, but it didn't exist in earlier versions.
     */
	public static function request($key = '', $value = '')
	{
		if ($value !== '') $_REQUEST[$key] = $value;
		if ($key) return @$_REQUEST[$key];
		return $_REQUEST;
	}

	/**
     * Returns the $_SERVER array, otherwise known as $HTTP_SERVER_VARS in versions older
     * than PHP 4.1.0
     */
	public static function server($key = '', $value = '')
	{
		if ($value !== '') $_SERVER[$key] = $value;
		if ($key) return @$_SERVER[$key];
		return $_SERVER;
	}

	/**
     * Returns the $_SERVER array, otherwise known as $HTTP_SERVER_VARS in versions older
     * than PHP 4.1.0
     */
	public static function file($key = '')
	{
		if ($key) return @$_FILES[$key];
		return $_FILES;
	}
	

	/**
     * Returns the base URLs of the script
     * base/path/request/query/self
     */
	public static function uri($key = '', $value = false, $param = false)
	{
		if ($value){
		    $c = substr($value, 0, 1);
		    if ('/' === $c) return 'http://'.self::server('HTTP_HOST').$value;
		    $pos = strrpos(self::server('PATH_INFO'), '/');
		    $url = substr(self::server('PATH_INFO'), 0, $pos+1).$value;
		    
		    if (strpos($url, '?')) return $url;
		    
		    $houzui = F::config('suffix');
		    $url .= $houzui;
		    
		    if($param) {
		        $url .= '?'.self::server('QUERY_STRING');
		    }
		    return $url;
		}
	    switch ($key){
			case 'base': {
				return 'http://'.self::server('HTTP_HOST').'/';
			}
			case 'path': return self::server('PATH_INFO');
			case 'request': return self::server('REQUEST_URI');
			case 'query': return self::server('QUERY_STRING');
			case 'self': return self::server('PHP_SELF');
			default:	return '';
		}
	}

}

//自定义错误处理
set_exception_handler(array('AfaException', 'exception_handle'));
set_error_handler(array('AfaException', 'error_handle'));
register_shutdown_function(array('AfaException', 'shutdown_handle'));

/**
 * 设置对象的自动载入
 */
spl_autoload_register(array('Load', 'loadClass'));

