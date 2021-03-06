<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2013 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
namespace Think;
/**
 * ThinkPHP 数据库中间层实现类
 */
class Db {
    // 数据库类型
    protected $dbType     = null;
    // 是否自动释放查询结果
    protected $autoFree   = false;
    // 当前操作所属的模型名
    protected $model      = '_think_';
    // 是否使用永久连接
    protected $pconnect   = false;
    // 当前SQL指令
    protected $queryStr   = '';
    protected $modelSql   = array();
    // 最后插入ID
    protected $lastInsID  = null;
    // 返回或者影响记录数
    protected $numRows    = 0;
    // 返回字段数
    protected $numCols    = 0;
    // 事务指令数
    protected $transTimes = 0;
    // 错误信息
    protected $error      = '';
    // 数据库连接ID 支持多个连接
    protected $linkID     = array();
    // 当前连接ID
    protected $_linkID    = null;
    // 当前查询ID
    protected $queryID    = null;
    // 是否已经连接数据库
    protected $connected  = false;
    // 数据库连接参数配置
    protected $config     = '';
    // 数据库表达式
    protected $comparison = array('eq'=>'=','neq'=>'<>','gt'=>'>','egt'=>'>=','lt'=>'<','elt'=>'<=','notlike'=>'NOT LIKE','like'=>'LIKE','in'=>'IN','notin'=>'NOT IN');
    // 查询表达式
    protected $selectSql  = 'SELECT%DISTINCT% %FIELD% FROM %TABLE%%JOIN%%WHERE%%GROUP%%HAVING%%ORDER%%LIMIT% %UNION%%COMMENT%';
    // 参数绑定
    protected $bind       = array();

    /**
     * 取得数据库类实例
     * 从静态变量中获取或者从工厂类中创建数据库驱动类实例
     * @static
     * @access public
     * @return mixed 返回数据库驱动类
     */
    public static function getInstance($db_config='') {
		static $_instance	=	array();  //单例功能
		$guid	=	to_guid_string($db_config); //用的是md5加密
		if(!isset($_instance[$guid])){
			$obj	=	new Db();
			$_instance[$guid]	=	$obj->factory($db_config);
		}
		return $_instance[$guid];
    }

    /**
     * 加载数据库 支持配置文件或者 DSN
     * 返回一个数据库驱动类的实例
     * 1.读取数据库配置
     * 2.获取数据库类型对应的包含命名空间的类名
     * 3.检查驱动类是否存在，并实例化
     * 4.返回数据库驱动实例
     * @access public
     * @param mixed $db_config 数据库配置信息
     * @return string
     */
    public function factory($db_config='') {
    	//$db_config>>>array()
        // 读取数据库配置
        $db_config = $this->parseConfig($db_config);  //返回的是一个配置参数的关联数组
        /*
         * $db_config = array(
         * 		'dbms' => 'mysqli',
         * 		'username' => 'root',
         *      'password' => 'root',
         *      'hostname' => '127.0.0.1',
         *      'hostport' => '3306',
         *      'database' => 'ot',
         *      'dsn' => NULL,
         *      'params' => NULL
         * )
         */
        if(empty($db_config['dbms']))
        	//L('_NO_DB_CONFIG_')>>>'没有定义数据库配置'
            E(L('_NO_DB_CONFIG_'));
        // 数据库类型
        if(strpos($db_config['dbms'],'\\')){
            $class  =   $db_config['dbms'];
        }else{
        	//$dbType>>>'Mysqli'
            $dbType =   ucwords(strtolower($db_config['dbms']));
            //$class>>>'Think\Db\Driver\Mysqli'
            $class  =   'Think\\Db\\Driver\\'. $dbType;            
        }
        // 检查驱动类
        if(class_exists($class)) {
        	//实例化Mysqli驱动类
            $db = new $class($db_config);
        }else {
            // 类没有定义
            E(L('_NO_DB_DRIVER_').': ' . $class);
        }
        return $db;
    }

    /**
     * 根据DSN获取数据库类型 返回大写
     * @access protected
     * @param string $dsn  dsn字符串
     * @return string
     */
    protected function _getDsnType($dsn) {
        $match  =  explode(':',$dsn);
        $dbType = strtoupper(trim($match[0]));
        return $dbType;
    }

    /**
     * 分析数据库配置信息，支持数组和DSN
     * 1.如果传过来的$db_config是''，就读取部分数据库的配置参数。（暂时定为不传递参数的情况）
     * 2.返回数据库配置参数的关联数组
     * @access private
     * @param mixed $db_config 数据库配置信息
     * @return string
     */
    private function parseConfig($db_config='') {
    	//$db_config>>>''
        if ( !empty($db_config) && is_string($db_config)) {
            // 如果DSN字符串则进行解析
            $db_config = $this->parseDSN($db_config);
        }elseif(is_array($db_config)) { // 数组配置
             $db_config =   array_change_key_case($db_config);
             $db_config = array(
                  'dbms'      =>  $db_config['db_type'],
                  'username'  =>  $db_config['db_user'],
                  'password'  =>  $db_config['db_pwd'],
                  'hostname'  =>  $db_config['db_host'],
                  'hostport'  =>  $db_config['db_port'],
                  'database'  =>  $db_config['db_name'],
                  'dsn'       =>  $db_config['db_dsn'],
                  'params'    =>  $db_config['db_params'],
             );
        }elseif(empty($db_config)) {
            // 如果配置为空，读取配置文件设置
            //C('DB_DSN')>>>NULL
            if( C('DB_DSN') && 'pdo' != strtolower(C('DB_TYPE')) ) { // 如果设置了DB_DSN 则优先
                $db_config =  $this->parseDSN(C('DB_DSN'));
            }else{
            	/*
            	 * C('DB_TYPE')>>>'mysqli'
            	 * C('DB_USER')>>>'root'
            	 * C('DB_PWD')>>>'root'
            	 * C('DB_HOST')>>>'127.0.0.1'
            	 * C('DB_PORT')>>>'3306
            	 * C('DB_NAME')>>>'ot'
            	 * C('DB_DSN')>>>
            	 * C('DB_PARAMS')>>>
            	 */
                $db_config = array (
                    'dbms'      =>  C('DB_TYPE'),
                    'username'  =>  C('DB_USER'),
                    'password'  =>  C('DB_PWD'),
                    'hostname'  =>  C('DB_HOST'),
                    'hostport'  =>  C('DB_PORT'),
                    'database'  =>  C('DB_NAME'),
                    'dsn'       =>  C('DB_DSN'),
                    'params'    =>  C('DB_PARAMS'),
                );
            }
        }
        return $db_config;
    }

    /**
     * 初始化数据库连接
     * 进行连接操作，并赋值_linkID属性
     * @access protected
     * @param boolean $master 主服务器
     * @return void
     */
    protected function initConnect($master=true) {
    	//C('DB_DEPLOY_TYPE')>>>0。数据库部署模式：0单一服务器，1主从服务器
        if(1 == C('DB_DEPLOY_TYPE'))
            // 采用分布式数据库
            $this->_linkID = $this->multiConnect($master);
        else
            // 默认单数据库
            if ( !$this->connected ) $this->_linkID = $this->connect();
    }

    /**
     * 连接分布式服务器
     * @access protected
     * @param boolean $master 主服务器
     * @return void
     */
    protected function multiConnect($master=false) {
        static $_config = array();
        if(empty($_config)) {
            // 缓存分布式数据库配置解析
            foreach ($this->config as $key=>$val){
                $_config[$key]      =   explode(',',$val);
            }
        }
        // 数据库读写是否分离
        if(C('DB_RW_SEPARATE')){
            // 主从式采用读写分离
            if($master)
                // 主服务器写入
                $r  =   floor(mt_rand(0,C('DB_MASTER_NUM')-1));
            else{
                if(is_numeric(C('DB_SLAVE_NO'))) {// 指定服务器读
                    $r = C('DB_SLAVE_NO');
                }else{
                    // 读操作连接从服务器
                    $r = floor(mt_rand(C('DB_MASTER_NUM'),count($_config['hostname'])-1));   // 每次随机连接的数据库
                }
            }
        }else{
            // 读写操作不区分服务器
            $r = floor(mt_rand(0,count($_config['hostname'])-1));   // 每次随机连接的数据库
        }
        $db_config = array(
            'username'  =>  isset($_config['username'][$r])?$_config['username'][$r]:$_config['username'][0],
            'password'  =>  isset($_config['password'][$r])?$_config['password'][$r]:$_config['password'][0],
            'hostname'  =>  isset($_config['hostname'][$r])?$_config['hostname'][$r]:$_config['hostname'][0],
            'hostport'  =>  isset($_config['hostport'][$r])?$_config['hostport'][$r]:$_config['hostport'][0],
            'database'  =>  isset($_config['database'][$r])?$_config['database'][$r]:$_config['database'][0],
            'dsn'       =>  isset($_config['dsn'][$r])?$_config['dsn'][$r]:$_config['dsn'][0],
            'params'    =>  isset($_config['params'][$r])?$_config['params'][$r]:$_config['params'][0],
        );
        return $this->connect($db_config,$r);
    }

    /**
     * DSN解析
     * 格式： mysql://username:passwd@localhost:3306/DbName
     * @static
     * @access public
     * @param string $dsnStr
     * @return array
     */
    public function parseDSN($dsnStr) {
        if( empty($dsnStr) ){return false;}
        $info = parse_url($dsnStr);
        if($info['scheme']){
            $dsn = array(
            'dbms'      =>  $info['scheme'],
            'username'  =>  isset($info['user']) ? $info['user'] : '',
            'password'  =>  isset($info['pass']) ? $info['pass'] : '',
            'hostname'  =>  isset($info['host']) ? $info['host'] : '',
            'hostport'  =>  isset($info['port']) ? $info['port'] : '',
            'database'  =>  isset($info['path']) ? substr($info['path'],1) : ''
            );
        }else {
            preg_match('/^(.*?)\:\/\/(.*?)\:(.*?)\@(.*?)\:([0-9]{1, 6})\/(.*?)$/',trim($dsnStr),$matches);
            $dsn = array (
            'dbms'      =>  $matches[1],
            'username'  =>  $matches[2],
            'password'  =>  $matches[3],
            'hostname'  =>  $matches[4],
            'hostport'  =>  $matches[5],
            'database'  =>  $matches[6]
            );
        }
        $dsn['dsn'] =  ''; // 兼容配置信息数组
        return $dsn;
     }

    /**
     * 数据库调试 记录当前SQL
     * 将sql语句与所用时间拼接成字符串，使用trace()
     * @access protected
     */
    protected function debug() {
        $this->modelSql[$this->model]   =  $this->queryStr;	//通过模型来记录查询语句的
        $this->model  =   '_think_';
        // 记录操作结束时间
        //C('DB_SQL_LOG')>>>false，SQL执行日志记录
        if (C('DB_SQL_LOG')) {
            G('queryEndTime');
            //debug>>>这里是否要在使用$this->queryStr后，释放其内存
            trace($this->queryStr.' [ RunTime:'.G('queryStartTime','queryEndTime',6).'s ]','','SQL');
        }
    }

    /**
     * 设置锁机制
     * mysql只支持 'FOR UPDATE'的锁表
     * 返回一个字符串 ' FOR UPDATE '
     * @access protected
     * @return string
     */
    protected function parseLock($lock=false) {
        if(!$lock) return '';
        if('ORACLE' == $this->dbType) {
            return ' FOR UPDATE NOWAIT ';
        }
        return ' FOR UPDATE ';
    }

    /**
     * set分析
     * $data转换成'SET a=b,c=d'的形式
     * @access protected
     * @param array $data
     * @return string
     */
    protected function parseSet($data) {
        foreach ($data as $key=>$val){
            if(is_array($val) && 'exp' == $val[0]){
                $set[]  =   $this->parseKey($key).'='.$val[1];
            }elseif(is_scalar($val) || is_null($val)) { // 过滤非标量数据
              if(C('DB_BIND_PARAM') && 0 !== strpos($val,':')){
                $name   =   md5($key);
                $set[]  =   $this->parseKey($key).'=:'.$name;
                $this->bindParam($name,$val);
              }else{
                $set[]  =   $this->parseKey($key).'='.$this->parseValue($val);
              }
            }
        }
        return ' SET '.implode(',',$set);
    }

     /**
     * 参数绑定
     * @access protected
     * @param string $name 绑定参数名
     * @param mixed $value 绑定值
     * @return void
     */
    protected function bindParam($name,$value){
        $this->bind[':'.$name]  =   $value;
    }

     /**
     * 参数绑定分析
     * @access protected
     * @param array $bind
     * @return array
     */
    protected function parseBind($bind){
        $bind           =   array_merge($this->bind,$bind);
        $this->bind     =   array();
        return $bind;
    }

    /**
     * 字段名分析
     * 并没有做任何处理，直接使用return返回了。可以在自定义模型中重载
     * @access protected
     * @param string $key
     * @return string
     */
    protected function parseKey(&$key) {
        return $key;
    }
    
    /**
     * value分析
     * 字符串就用单引号加反斜线处理
     * 布尔转换成'1'或'0'
     * null转换成'null'
     * @access protected
     * @param mixed $value
     * @return string
     */
    protected function parseValue($value) {
        if(is_string($value)) {
        	//单引号加反斜线
            $value =  '\''.$this->escapeString($value).'\'';
        }elseif(isset($value[0]) && is_string($value[0]) && strtolower($value[0]) == 'exp'){
            $value =  $this->escapeString($value[1]);
        }elseif(is_array($value)) {
            $value =  array_map(array($this, 'parseValue'),$value);
        }elseif(is_bool($value)){
        	//bool>>>'1'或'0'
            $value =  $value ? '1' : '0';
        }elseif(is_null($value)){
        	//NULL>>>'null'
            $value =  'null';
        }
        return $value;
    }

    /**
     * field分析
     * 生成字段名、字段别名连接成的字符串，支持数组和字符串形式的参数。
     * 数组形式参数会省去字符串的处理，方便别名的定义
     * @access protected
     * @param mixed $fields
     * @return string
     */
    protected function parseField($fields) {
    	//$fields>>>'type,name,value'
        if(is_string($fields) && strpos($fields,',')) {
            $fields    = explode(',',$fields);
        }
        if(is_array($fields)) {	//这个数组可以是上一步生成的。$fields如果是数组，那么久不用上一步的字符串解析，还方便定义别名
            // 完善数组方式传字段名的支持
            // 支持 'field1'=>'field2' 这样的字段别名定义
            $array   =  array();
            foreach ($fields as $key=>$field){
                if(!is_numeric($key))
                    $array[] =  $this->parseKey($key).' AS '.$this->parseKey($field);
                else
                    $array[] =  $this->parseKey($field);
            }
            $fieldsStr = implode(',', $array);
        }elseif(is_string($fields) && !empty($fields)) { //只有一个字段，因为第一对字符串形式参数进行过处理，转换为数组
            $fieldsStr = $this->parseKey($fields);
        }else{
            $fieldsStr = '*';
        }
        //TODO 如果是查询全部字段，并且是join的方式，那么就把要查的表加个别名，以免字段被覆盖
        return $fieldsStr;
    }

    /**
     * table分析
     * 分析表名，返回'`onethink_document`'形式的字符串，对参数$tables基本没做处理，具体处理需要在自定义模型中做
     * @access protected
     * @param mixed $table
     * @return string
     */
    protected function parseTable($tables) {
        if(is_array($tables)) {// 支持别名定义
            $array   =  array();
            foreach ($tables as $table=>$alias){
                if(!is_numeric($table))
                    $array[] =  $this->parseKey($table).' '.$this->parseKey($alias);
                else
                    $array[] =  $this->parseKey($table);
            }
            $tables  =  $array;
        }elseif(is_string($tables)){
        	//$tables>>>array('onethink_document')
            $tables  =  explode(',',$tables);
            /*array_walk——使用用户自定义函数对数组中的每个元素做回调处理
             * array_walk()不会受到array内部数组指针的影响。array_walk()会遍历真个数组而不管指针的位置
             */
	        array_walk($tables, array(&$this, 'parseKey'));
	        //$tables>>>array('`onethink_document`')
        }
        //$tables>>>'`onethink_document`'
	    $tables = implode(',',$tables);	//用implode()和explode来进行字符串、数组间的转换
        return $tables;
    }

    /**
     * where分析
     * 1.声明一个$whereStr用于存放解析后的where字符串
     * 2.字符串形式的$where直接拼接
     * 3.数组形式，通过嵌套遍历和parseWhereItem()进行解析，拼接，并自动在$whereStr末尾添加一个逻辑运算符，去掉运算符
     * 4.返回sql标准的where字符串
     * @access protected
     * @param mixed $where
     * @return string
     */
    protected function parseWhere($where) {
    	/*
    	 * $where>>>array(
    	 * 	'id|name' => array(
    	 * 		1, 'pax', '_multi' => true
    	 * 	)	
    	 * )
    	 */
        $whereStr = '';
        if(is_string($where)) {	//字符串是直接使用的，那么解析效率会高些，但是如果条件复杂就不方便书写
            // 直接使用字符串条件
            $whereStr = $where;
        }else{ // 使用数组表达式。这是使用比较多的
            $operate  = isset($where['_logic'])?strtoupper($where['_logic']):'';	//'_logic'是指逻辑运算，与、或亦或等。转换成大写，是因为sql关键一般大写
            if(in_array($operate,array('AND','OR','XOR'))){
                // 定义逻辑运算规则 例如 OR XOR AND NOT
                $operate    =   ' '.$operate.' ';	
                unset($where['_logic']);	//销毁变量
            }else{
                // 默认进行 AND 运算
                $operate    =   ' AND ';
            }
            foreach ($where as $key=>$val){
                $whereStr .= '( ';
                //is_numeric——检查变量是否为数字或数字字符串
                if(is_numeric($key)){
                    $key  = '_complex';	//复合查询
                }                    
                if(0===strpos($key,'_')) {
                    $whereStr   .= $this->parseThinkWhere($key,$val);
                }else{
                    // 查询字段的安全过滤，根据mysql的字段命名规则来检查字段名
                    if(!preg_match('/^[A-Z_\|\&\-.a-z0-9\(\)\,]+$/',trim($key))){	//检查字段名的命名规则
                        E(L('_EXPRESS_ERROR_').':'.$key);
                    }
                    // 多条件支持
                    //$multi>>>false
                    $multi  = is_array($val) &&  isset($val['_multi']);	//判断条件比较长时，可以用这种方式
                    $key    = trim($key);
                    //debug>>>为了避免'|'和'&'同时出线，是否先检测一下，通过再继续下去？
                    if(strpos($key,'|')) { // 支持 name|title|nickname 方式定义查询字段
                    	/*
                    	 * $where>>>array(
                    	 * 		'id|name' => array(
                    	 * 			1, 'pax', '_multi' => true
                    	 * 	)
                    	 * )
                    	 */
                        $array =  explode('|',$key);	//因为这，所以'|'和'&'不能同时使用，否则会出现字段错误
                        $str   =  array();
                        foreach ($array as $m=>$k){	//$m是数组索引，$k是字段名
                            $v =  $multi?$val[$m]:$val;	//$v是字段值
                            $str[]   = '('.$this->parseWhereItem($this->parseKey($k),$v).')';
                        }
                        $whereStr .= implode(' OR ',$str);	//将键名中的'|'用sql的 ' OR '代替
                    }elseif(strpos($key,'&')){	//'&'的判断。如果同时有'|'和'&'，会导致条件解析两次，并且字段名出线错误，条件的字符串连接两次
                        $array =  explode('&',$key);
                        $str   =  array();
                        foreach ($array as $m=>$k){
                            $v =  $multi?$val[$m]:$val;
                            $str[]   = '('.$this->parseWhereItem($this->parseKey($k),$v).')';
                        }
                        $whereStr .= implode(' AND ',$str);
                    }else{
                        $whereStr .= $this->parseWhereItem($this->parseKey($key),$val);
                    }
                }
                $whereStr .= ' )'.$operate;	//自动在where末尾连接了一个'AND'、'OR'、'XOR'
            }
            $whereStr = substr($whereStr,0,-strlen($operate));	//去where字符串后面多余逻辑运算符
        }
        return empty($whereStr)?'':' WHERE '.$whereStr;
    }

    // where子单元分析
    /**
     * 解析where中的每一个字段
     * $key>>>带反引号的字段名
     * $val>>>是字段名对应的值 
     * 1.判断数组还是字符串
     * 2.若是数组，就用preg_match()来验证各种查询表达式的关键字，通过了才能进一步处理，按sql书写规则拼接条件字符串
     * 3.若是字符串，直接拼接成条件字符串
     * 注意：在拼写的时候全部框架自己加了空格
     */
    protected function parseWhereItem($key,$val) {
        $whereStr = '';
        if(is_array($val)) { //限制条件是数组
            if(is_string($val[0])) {
                if(preg_match('/^(EQ|NEQ|GT|EGT|LT|ELT)$/i',$val[0])) { // 比较运算，没有区分大小写。array('eq', 2)
                	//$this->comparsion>>>一个数据库表达式转换数组，array('eq'=>'=','neq'=>'<>'...)
                    $whereStr .= $key.' '.$this->comparison[strtolower($val[0])].' '.$this->parseValue($val[1]);
                }elseif(preg_match('/^(NOTLIKE|LIKE)$/i',$val[0])){// 模糊查找。array('like', '%ad%')
                    if(is_array($val[1])) {	//array('like',array('%think%', '%tp),'OR')
                        $likeLogic  =   isset($val[2])?strtoupper($val[2]):'OR';	//两个模糊查询，默认是'OR'
                        if(in_array($likeLogic,array('AND','OR','XOR'))){	//过滤两个模糊查询的逻辑运算符
                            $likeStr    =   $this->comparison[strtolower($val[0])];
                            $like       =   array();
                            foreach ($val[1] as $item){
                                $like[] = $key.' '.$likeStr.' '.$this->parseValue($item);
                            }
                            $whereStr .= '('.implode(' '.$likeLogic.' ',$like).')';	//拼接条件字符串                          
                        }
                    }else{
                        $whereStr .= $key.' '.$this->comparison[strtolower($val[0])].' '.$this->parseValue($val[1]);	//拼接条件字符串
                    }
                }elseif('exp'==strtolower($val[0])){ // 使用表达式。array('exp', '  IN (1,3,8)  ')
                    $whereStr .= ' ('.$key.' '.$val[1].') ';	//因为拼接字符串的表达式中已经含有空格，所以在实际用时，不用特意加空格
                }elseif(preg_match('/IN/i',$val[0])){ // IN 运算。array('in', '1,3,8')
                    if(isset($val[2]) && 'exp'==$val[2]) {	//debug
                        $whereStr .= $key.' '.strtoupper($val[0]).' '.$val[1];
                    }else{
                        if(is_string($val[1])) {	//字符串也转换成了数组形式，因为要做值的分析。所以，直接用数组好点
                             $val[1] =  explode(',',$val[1]);
                        }	
                        $zone      =   implode(',',$this->parseValue($val[1])); //对值进行过滤
                        $whereStr .= $key.' '.strtoupper($val[0]).' ('.$zone.')';
                    }
                }elseif(preg_match('/BETWEEN/i',$val[0])){ // BETWEEN运算。array('between', array('1', '8'))
                    $data = is_string($val[1])? explode(',',$val[1]):$val[1];
                    $whereStr .=  ' ('.$key.' '.strtoupper($val[0]).' '.$this->parseValue($data[0]).' AND '.$this->parseValue($data[1]).' )';
                }else{
                	//L('_EXPRESS_ERROR_')>>>'表达式错误'。就是'eq'、'exp'等字符串写错了，没有用正则表达式匹配到
                    E(L('_EXPRESS_ERROR_').':'.$val[0]);
                }
            }else {	//用的少
            	//array(array('like', '%a'), array('gt', 1)
                $count = count($val);
                $rule  = isset($val[$count-1]) ? (is_array($val[$count-1]) ? strtoupper($val[$count-1][0]) : strtoupper($val[$count-1]) ) : '' ; 
                if(in_array($rule,array('AND','OR','XOR'))) {
                    $count  = $count -1;
                }else{
                    $rule   = 'AND';
                }
                for($i=0;$i<$count;$i++) {
                    $data = is_array($val[$i])?$val[$i][1]:$val[$i];
                    if('exp'==strtolower($val[$i][0])) {
                        $whereStr .= '('.$key.' '.$data.') '.$rule.' ';
                    }else{
                        $whereStr .= '('.$this->parseWhereItem($key,$val[$i]).') '.$rule.' ';
                    }
                }
                $whereStr = substr($whereStr,0,-4);
            }
        }else {
            //对字符串类型字段采用模糊匹配
            //C('DB_LIKE_FIELDS')在配置文件中并没有这个，是动态添加的
            if(C('DB_LIKE_FIELDS') && preg_match('/('.C('DB_LIKE_FIELDS').')/i',$key)) {
                $val  =  '%'.$val.'%';
                $whereStr .= $key.' LIKE '.$this->parseValue($val);
            }else {
                $whereStr .= $key.' = '.$this->parseValue($val);	//字符串是直接拼接的，不用做各种处理，比较快。但是增加了sql书写难度，让代码变的复杂
            }
        }
        return $whereStr;
    }

    /**
     * 特殊条件分析
     * 只支持3个：'_string','_complex','_query'
     * '_string'：直接返回字符串
     * '_complex':递归调用parseWhere()，知道条件是转换成字符串
     * '_query':使用parse_str()解析，用逻辑运算符作为implode()的分隔符，获得一个字符串
     * @access protected
     * @param string $key
     * @param mixed $val
     * @return string
     */
    protected function parseThinkWhere($key,$val) {
        $whereStr   = '';
        switch($key) {
            case '_string':
                // 字符串模式查询条件
                $whereStr = $val;	//字符串形式直接使用
                break;
            case '_complex':
                // 复合查询条件。递归调用parseWhere(),知道值是变成了字符串
                $whereStr   =   is_string($val)? $val : substr($this->parseWhere($val),6);
                break;
            case '_query':
                // 字符串模式查询条件
                //parse_str()——用类似解析url中参数的形式解析字符串，并赋值给变量
                parse_str($val,$where);
                if(isset($where['_logic'])) {
                    $op   =  ' '.strtoupper($where['_logic']).' ';
                    unset($where['_logic']);
                }else{
                    $op   =  ' AND ';
                }
                $array   =  array();
                foreach ($where as $field=>$data)
                    $array[] = $this->parseKey($field).' = '.$this->parseValue($data);
                $whereStr   = implode($op,$array);	//用' AND '当作了分隔符
                break;
        }
        return $whereStr;
    }

    /**
     * limit分析
     * 要解析的sql形式: 'LIMIT 1,2'
     * 参数的形式：'1,2'
     * @access protected
     * @param mixed $lmit
     * @return string
     */
    protected function parseLimit($limit) {
        return !empty($limit)?   ' LIMIT '.$limit.' ':'';	//如果limit()中的参数是空的，那么会返回空字符串，那么计算写了->limit()也不会报错
    }

    /**
     * join分析
     * 参数是一个数组，单元是join语句，直接用implode()进行处理，赋值给字符串返回
     * @access protected
     * @param array $join
     * @return string
     */
    protected function parseJoin($join) {
    	/*
    	 * $join>>>array(
    	 * 		'INNER JOIN ref_members m ON m.id=c.fid"
    	 * )
    	 */
        $joinStr = '';
        if(!empty($join)) {
            $joinStr    =   ' '.implode(' ',$join).' ';
        }
        return $joinStr;
    }

    /**
     * order分析
     * 将参数转换成字符串形式，拼接'ORDER BY'，返回
     * 支持一维数组、二维数组、字符串的参数形式
     * 数组使用foreach()后用implode()拼接成字符串
     * @access protected
     * @param mixed $order
     * @return string
     */
    protected function parseOrder($order) {
        if(is_array($order)) {
            $array   =  array();
            foreach ($order as $key=>$val){
                if(is_numeric($key)) {	//一维数组。一维数组并没有使用的必要额，不如直接使用字符串，少了遍历的操作
                    $array[] =  $this->parseKey($val);
                }else{	//二维数组
                    $array[] =  $this->parseKey($key).' '.$val;
                }
            }
            $order   =  implode(',',$array);
        }
        return !empty($order)?  ' ORDER BY '.$order:'';
    }

    /**
     * group分析
     * 将' GROUP BY '与 参数连接，并返回
     * @access protected
     * @param mixed $group
     * @return string
     */
    protected function parseGroup($group) {
        return !empty($group)? ' GROUP BY '.$group:'';
    }

    /**
     * having分析
     * 将' HAVING '和参数连接，返回字符串
     * @access protected
     * @param string $having
     * @return string
     */
    protected function parseHaving($having) {
        return  !empty($having)?   ' HAVING '.$having:'';
    }

    /**
     * comment分析
     * 将注释内容转放到注释标记中，并返回该字符串
     * @access protected
     * @param string $comment
     * @return string
     */
    protected function parseComment($comment) {
        return  !empty($comment)?   ' /* '.$comment.' */':'';
    }

    /**
     * distinct分析
     * 通过$distinct是否为空，返回' DISTINCT '或者 ''
     * @access protected
     * @param mixed $distinct
     * @return string
     */
    protected function parseDistinct($distinct) {
        return !empty($distinct)?   ' DISTINCT ' :'';
    }

    /**
     * union分析
     * 用的少，基本不用
     * @access protected
     * @param mixed $union
     * @return string
     */
    protected function parseUnion($union) {
        if(empty($union)) return '';
        if(isset($union['_all'])) {
            $str  =   'UNION ALL ';
            unset($union['_all']);
        }else{
            $str  =   'UNION ';
        }
        foreach ($union as $u){
            $sql[] = $str.(is_array($u)?$this->buildSelectSql($u):$u);
        }
        return implode(' ',$sql);
    }

    /**
     * 插入记录
     * @access public
     * @param mixed $data 数据
     * @param array $options 参数表达式
     * @param boolean $replace 是否replace
     * @return false | integer
     */
    public function insert($data,$options=array(),$replace=false) {
    	header("content-type:text/html;charset=utf-8");
    	//添加数据
        $values  =  $fields    = array();	//$data是一个关联数组，$values用来存放值，$fields用来存放字段名
        //$options['model']>>>'Document'
        $this->model  =   $options['model'];
        /*
         * $data => array(
         * 		["uid"]=>
				  int(1)
				  ["name"]=>
				  string(3) "pax"
				  ["title"]=>
				  string(12) "添加文档"
				  ["category_id"]=>
				  int(2)
				  ["description"]=>
				  string(23) "给ot进行注释条件"
				  ["root"]=>
				  int(0)
				  ["pid"]=>
				  int(0)
				  ["type"]=>
				  int(2)
				  ["position"]=>
				  int(0)
				  ["link_id"]=>
				  int(0)
				  ["cover_id"]=>
				  int(0)
				  ["display"]=>
				  int(1)
         * ) 
         */
        //对$values和$fields进行赋值。没有用array_keys()和array_values()来简化操作，是因为存在不同的 情况，要附加不同的操作
        foreach ($data as $key=>$val){
            if(is_array($val) && 'exp' == $val[0]){
                $fields[]   =  $this->parseKey($key);
                $values[]   =  $val[1];
            }elseif(is_scalar($val) || is_null($val)) { // 过滤非标量数据。对非标量数据进行了过滤，所以在填充数据的时候，要先对非标量数据进行序列化处理
              $fields[]   =  $this->parseKey($key);
              //C('DB_BIND_PARAM')>>>数据库写入数据自动参数绑定，默认false
              if(C('DB_BIND_PARAM') && 0 !== strpos($val,':')){
                $name       =   md5($key);
                $values[]   =   ':'.$name;
                $this->bindParam($name,$val);
              }else{
                $values[]   =  $this->parseValue($val);
              }                
            }
        }
        //用表名、字段名、字段值拼接一个insert的sql语句
        $sql   =  ($replace?'REPLACE':'INSERT').' INTO '.$this->parseTable($options['table']).' ('.implode(',', $fields).') VALUES ('.implode(',', $values).')';
        $sql   .= $this->parseLock(isset($options['lock'])?$options['lock']:false);
        $sql   .= $this->parseComment(!empty($options['comment'])?$options['comment']:'');
        //TODO。debug>>>调用的是驱动的execute()，但是不知道为什么可以
        return $this->execute($sql,$this->parseBind(!empty($options['bind'])?$options['bind']:array()));
    }

    /**
     * 通过Select方式插入记录
     * @access public
     * @param string $fields 要插入的数据表字段名
     * @param string $table 要插入的数据表名
     * @param array $option  查询数据参数
     * @return false | integer
     */
    public function selectInsert($fields,$table,$options=array()) {
        $this->model  =   $options['model'];
        if(is_string($fields))   $fields    = explode(',',$fields);
        array_walk($fields, array($this, 'parseKey'));
        $sql   =    'INSERT INTO '.$this->parseTable($table).' ('.implode(',', $fields).') ';
        $sql   .= $this->buildSelectSql($options);
        return $this->execute($sql,$this->parseBind(!empty($options['bind'])?$options['bind']:array()));
    }

    /**
     * 更新记录
     * 参考的sql形式:'UPDATE `tablename` SET a=b,c=d WHERE ... ORDER BY ... LIMIT ... FOR UPDATE COMMENT...'
     * 逐个关键字进行解析，拼接成sql语句，调用mysqli的execute()，并返回其结果
     * @access public
     * @param mixed $data 数据
     * @param array $options 表达式
     * @return false | integer
     */
    public function update($data,$options) {
        $this->model  =   $options['model'];
        $sql   = 'UPDATE '
            .$this->parseTable($options['table'])
            .$this->parseSet($data)	//$data是一个关联数组，转传承'SET a=b,c=d'形式的字符串
            .$this->parseWhere(!empty($options['where'])?$options['where']:'')
            .$this->parseOrder(!empty($options['order'])?$options['order']:'')
            .$this->parseLimit(!empty($options['limit'])?$options['limit']:'')
            .$this->parseLock(isset($options['lock'])?$options['lock']:false)
            .$this->parseComment(!empty($options['comment'])?$options['comment']:'');
        return $this->execute($sql,$this->parseBind(!empty($options['bind'])?$options['bind']:array()));
    }

    /**
     * 删除记录
     * 参考sql格式：'DELETE FROM `tableName` WHERE ... ORDER ... LIMIT ... FOR UPDATE COMMENT ...'
     * 比update()方法要简单，一次解析关键字、拼接sql，调用mysqli的excute(),返回执行后的结果
     * @access public
     * @param array $options 表达式
     * @return false | integer
     */
    public function delete($options=array()) {
        $this->model  =   $options['model'];
        $sql   = 'DELETE FROM '
            .$this->parseTable($options['table'])
            .$this->parseWhere(!empty($options['where'])?$options['where']:'')
            .$this->parseOrder(!empty($options['order'])?$options['order']:'')
            .$this->parseLimit(!empty($options['limit'])?$options['limit']:'')
            .$this->parseLock(isset($options['lock'])?$options['lock']:false)
            .$this->parseComment(!empty($options['comment'])?$options['comment']:'');
        return $this->execute($sql,$this->parseBind(!empty($options['bind'])?$options['bind']:array()));
    }

    /**
     * 查找记录
     * 1.成成查询SQL
     * 2.调用query()
     * 3.返回查询结果
     * @access public
     * @param array $options 表达式
     * @return mixed
     */
    public function select($options=array()) {
        $this->model  =   $options['model'];
        $sql        =   $this->buildSelectSql($options);
        //debug>>>query()这个方法是怎么调用的？
        $result     =   $this->query($sql,$this->parseBind(!empty($options['bind'])?$options['bind']:array()));
        return $result;
    }

    /**
     * 生成查询SQL
     * 1.检测参数中是否设置了page，有，则解析到$options['limit']
     * 2.检测是否开启了sql缓存，如果开启了，serialize()和md5()对$options进行处理，作为缓存键，尝试用S()去值，取到就直接返回，否则下步
     * 3.调用parseSql()生成sql语句
     * 4.如果开启了sql缓存，将生成的sql语句缓存起来，并设置缓存周期、缓存队列长度、缓存方式
     * 注意：由于一个项目的查询量非常大，所以必须设置缓存的数量，否则缓存占用的空间会变很大，间接影响效率。默认是20的长度
     * @access public
     * @param array $options 表达式
     * @return string
     */
    public function buildSelectSql($options=array()) {
        if(isset($options['page'])) {
            // 根据页数计算limit
            if(strpos($options['page'],',')) {
                list($page,$listRows) =  explode(',',$options['page']);
            }else{
                $page = $options['page'];
            }
            $page    =  $page?$page:1;	//如果page()参数为空，默认是从第一页开始查
            $listRows=  isset($listRows)?$listRows:(is_numeric($options['limit'])?$options['limit']:20);//如果page()没有说明每页记录数，默认给20
            $offset  =  $listRows*((int)$page-1);	//这个(int)没有必要，字符串-1也会转换成整形
            $options['limit'] =  $offset.','.$listRows;	//'2, 13'
        }
        if(C('DB_SQL_BUILD_CACHE')) { // SQL创建缓存。默认是false
            $key    =  md5(serialize($options));	//根据$options，使用serialize()和md5()进行加密
            $value  =  S($key);	//使用S()进行取值
            if(false !== $value) {
                return $value;
            }
        }
        $sql  =     $this->parseSql($this->selectSql,$options);
        $sql .=     $this->parseLock(isset($options['lock'])?$options['lock']:false);
        if(isset($key)) { // 写入SQL创建缓存。只有查询方法才支持sql解析缓存
        	//sql缓存并不是缓存了结果，而是缓存了解析后的sql语句，避免重复解析sql。
        	//C('DB_SQL_BUILD_LENGTH')>>>sql缓存队列的长度，默认是20。用来限制sql缓存的数量，因为一个项目的查询sql的量是非常大的，有必要设下缓存队列的长度。只会缓存最近20条数据
        	//C('DB_SQL_BUILD_QUEUE')>>>sql缓存队列的缓存方式，默认是file
            S($key,$sql,array('expire'=>0,'length'=>C('DB_SQL_BUILD_LENGTH'),'queue'=>C('DB_SQL_BUILD_QUEUE')));//永久生效
        }
        return $sql;
    }

    /**
     * 替换SQL语句中表达式
     * 对sql中的'%TABLE%','%DISTINCT%','%FIELD%'等关键字进行替换，调用parseTable()、parseDistinct()、parseField()等
     * @access public
     * @param array $options 表达式
     * @return string
     */
    public function parseSql($sql,$options=array()){
    	/*
    	 * $options = array(
    	 * 	'where' => array('status'=>1),
    	 *  'field' => 'type,name,value',
    	 *  'table' => 'onethink_config',
    	 *  'model' => 'Config'
    	 * )
    	 */
        $sql   = str_replace(
            array('%TABLE%','%DISTINCT%','%FIELD%','%JOIN%','%WHERE%','%GROUP%','%HAVING%','%ORDER%','%LIMIT%','%UNION%','%COMMENT%'),
            array(
                $this->parseTable($options['table']),
                $this->parseDistinct(isset($options['distinct'])?$options['distinct']:false),
                $this->parseField(!empty($options['field'])?$options['field']:'*'),
                $this->parseJoin(!empty($options['join'])?$options['join']:''),
                $this->parseWhere(!empty($options['where'])?$options['where']:''),
                $this->parseGroup(!empty($options['group'])?$options['group']:''),
                $this->parseHaving(!empty($options['having'])?$options['having']:''),
                $this->parseOrder(!empty($options['order'])?$options['order']:''),
                $this->parseLimit(!empty($options['limit'])?$options['limit']:''),
                $this->parseUnion(!empty($options['union'])?$options['union']:''),
                $this->parseComment(!empty($options['comment'])?$options['comment']:'')
            ),$sql);
        return $sql;
    }

    /**
     * 获取最近一次查询的sql语句 
     * 是在mysqli类中进行赋值的，在执行某个sql之前进行赋值的，并没有此属性的操作
     * @param string $model  模型名
     * @access public
     * @return string
     */
    public function getLastSql($model='') {
    	//queryStr是在mysqli中进行赋值的，是在执行某个sql之前进行赋值的，但是并没有进行主动销毁的操作
        return $model?$this->modelSql[$model]:$this->queryStr;
    }

    /**
     * 获取最近插入的ID
     * mysqli类中的excute()进行了赋值
     * @access public
     * @return string
     */
    public function getLastInsID() {
        return $this->lastInsID;
    }

    /**
     * 获取最近的错误信息
     * 在mysqli类的query()和excute()中调用error()，对error属性进行赋值。
     * 注意：只有在sql执行出现错误时，才会对这个属性进行赋值，会导致，打印的是其他执行sql的错误，
     * 		而不是当前执行sql的错误。
     * @access public
     * @return string
     */
    public function getError() {
        return $this->error;
    }

    /**
     * SQL指令安全过滤
     * 调用addslashes()对字符串进行处理
     * @access public
     * @param string $str  SQL字符串
     * @return string
     */
    public function escapeString($str) {
    	//addslashes——使用反斜线引用字符串，是为了数据库查询语句等的需要
        return addslashes($str);
    }

    /**
     * 设置当前操作模型
     * @access public
     * @param string $model  模型名
     * @return void
     */
    public function setModel($model){
        $this->model =  $model;
    }

   /**
     * 析构方法
     * @access public
     */
    public function __destruct() {
        // 释放查询
        if ($this->queryID){
            $this->free();
        }
        // 关闭连接
        $this->close();
    }

    // 关闭数据库 由驱动类定义
    public function close(){}
}