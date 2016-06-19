<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

namespace think\mongo;

use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Cursor;
use MongoDB\Driver\Exception as MongoException;
use MongoDB\Driver\Exception\ConnectionException;
use MongoDB\Driver\Exception\ConnectionTimeoutException;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query as MongoQuery;
use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\WriteConcern;
use think\Db;
use think\mongo\Query as Query;
use think\Debug;
use think\Exception;
use think\Log;

/**
 * Mongo数据库驱动
 */
class Connection
{
    protected $dbName = ''; // dbName
    /** @var string 当前SQL指令 */
    protected $queryStr = '';
    // 查询数据集类型
    protected $resultSetType = 'array';
    // 查询数据类型
    protected $typeMap = 'array';
    protected $mongo; // MongoDb Object
    protected $cursor; // MongoCursor Object

    // 监听回调
    protected static $event = [];
    /** @var PDO[] 数据库连接ID 支持多个连接 */
    protected $links = [];
    /** @var PDO 当前连接ID */
    protected $linkID;
    protected $linkRead;
    protected $linkWrite;

    // 返回或者影响记录数
    protected $numRows = 0;
    // 错误信息
    protected $error = '';
    // 查询对象
    protected $query = [];
    // 数据库连接参数配置
    protected $config = [
        // 数据库类型
        'type'           => '',
        // 服务器地址
        'hostname'       => '',
        // 数据库名
        'database'       => '',
        // 用户名
        'username'       => '',
        // 密码
        'password'       => '',
        // 端口
        'hostport'       => '',
        // 连接dsn
        'dsn'            => '',
        // 数据库连接参数
        'params'         => [],
        // 数据库编码默认采用utf8
        'charset'        => 'utf8',
        // 主键名
        'pk'             => '_id',
        // 数据库表前缀
        'prefix'         => '',
        // 数据库调试模式
        'debug'          => false,
        // 数据库部署方式:0 集中式(单一服务器),1 分布式(主从服务器)
        'deploy'         => 0,
        // 数据库读写是否分离 主从式有效
        'rw_separate'    => false,
        // 读写分离后 主服务器数量
        'master_num'     => 1,
        // 指定从服务器序号
        'slave_no'       => '',
        // 是否严格检查字段是否存在
        'fields_strict'  => true,
        // 数据集返回类型
        'resultset_type' => 'array',
        // 自动写入时间戳字段
        'auto_timestamp' => false,
        // 是否需要进行SQL性能分析
        'sql_explain'    => false,
        // typeMap
        'type_map'       => [ 'root' => 'array', 'document' => 'array'],
    ];

    /**
     * 架构函数 读取数据库配置信息
     * @access public
     * @param array $config 数据库配置数组
     */
    public function __construct(array $config = [])
    {
        if (!class_exists('\MongoDB\Driver\Manager')) {
            throw new Exception('require mongodb > 1.0');
        }
        if (!empty($config)) {
            $this->config = array_merge($this->config, $config);
        }
    }

    /**
     * 连接数据库方法
     * @access public
     */
    public function connect(array $config = [], $linkNum = 0, $autoConnection = false)
    {
        if (!isset($this->links[$linkNum])) {
            if (empty($config)) {
                $config = $this->config;
            }
            $this->dbName   = $config['database'];
            $this->typeMap  = $config['type_map'];
            $host           = 'mongodb://' . ($config['username'] ? "{$config['username']}" : '') . ($config['password'] ? ":{$config['password']}@" : '') . $config['hostname'] . ($config['hostport'] ? ":{$config['hostport']}" : '') . '/' . ($config['database'] ? "{$config['database']}" : '');
            try {
                $this->links[$linkNum] = new Manager($host, $this->config['params']);
            } catch (ConnectionException $e) {
                throw new Exception($e->getMessage());
            } catch (ConnectionTimeoutException $e) {
                if ($autoConnection) {
                    Log::record($e->getMessage(), 'error');
                    return $this->connect($autoConnection, $linkNum);
                } else {
                    throw new Exception($e->getMessage());
                }
            }
        }
        return $this->links[$linkNum];
    }

    /**
     * 创建指定模型的查询对象
     * @access public
     * @param string $model 模型类名称
     * @return Query
     */
    public function model($model)
    {
        if (!isset($this->query[$model])) {
            $this->query[$model] = new Query($this, $model);
        }
        return $this->query[$model];
    }

    /**
     * 调用Query类的查询方法
     * @access public
     * @param string $method 方法名称
     * @param array $args 调用参数
     * @return mixed
     */
    public function __call($method, $args)
    {
        if (!isset($this->query['database'])) {
            $this->query['database'] = new Query($this);
        }
        return call_user_func_array([$this->query['database'], $method], $args);
    }

    /**
     * 获取数据库的配置参数
     * @access public
     * @param string $config 配置名称
     * @return mixed
     */
    public function getConfig($config = '')
    {
        return $config ? $this->config[$config] : $this->config;
    }

    /**
     * 设置数据库的配置参数
     * @access public
     * @param string $config 配置名称
     * @param mixed $value 配置值
     * @return void
     */
    public function setConfig($config, $value)
    {
        $this->config[$config] = $value;
    }

    /**
     * 获取Mongo Manager对象
     * @access public
     * @return Manager|false
     */
    public function getMongo()
    {
        if (!$this->mongo) {
            return false;
        } else {
            return $this->mongo;
        }
    }

    /**
     * 设置/获取当前操作的database
     * @access public
     * @param string  $db db
     * @throws Exception
     */
    public function db($db = null)
    {
        if (is_null($db)) {
            return $this->dbName;
        } else {
            $this->dbName = $db;
        }
    }

    /**
     * 执行查询
     * @access public
     * @param string $namespace
     * @param MongoQuery        $query 查询对象
     * @param ReadPreference    $readPreference readPreference
     * @param string|bool       $class 返回的数据集类型
     * @param string|array      $typeMap 指定返回的typeMap
     * @return mixed
     * @throws Exception
     */
    public function query($namespace, MongoQuery $query, ReadPreference $readPreference = null, $class = false, $typeMap = null)
    {
        $this->initConnect(false);
        Db::$queryTimes++;
        try {
            $this->debug(true);
            if (false === strpos($namespace, '.')) {
                $namespace = $this->dbName . '.' . $namespace;
            }
            $this->cursor = $this->mongo->executeQuery($namespace, $query, $readPreference);
            $this->debug(false);
            return $this->getResult($class, $typeMap);
        } catch (MongoException $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * 执行指令
     * @access public
     * @param Command  $command 指令
     * @param string $dbName
     * @param ReadPreference    $readPreference readPreference
     * @param string|bool       $class 返回的数据集类型
     * @param string|array      $typeMap 指定返回的typeMap
     * @return mixed
     * @throws Exception
     */
    public function command(Command $command, $dbName = '', ReadPreference $readPreference = null, $class = false, $typeMap)
    {
        $this->initConnect(false);
        Db::$queryTimes++;
        try {
            $this->debug(true);
            $this->cursor = $this->mongo->executeCommand($dbName ?: $this->dbName, $command, $readPreference);
            $this->debug(false);
            return $this->getResult($class, $typeMap);
        } catch (MongoException $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * 获得数据集
     * @access protected
     * @param bool|string       $class true 返回Mongo cursor 字符串用于指定返回的类名
     * @param string|array      $typeMap 指定返回的typeMap
     * @return mixed
     */
    protected function getResult($class = '', $typeMap = null)
    {
        if (true === $class) {
            return $this->cursor;
        }
        // 设置结果数据类型
        if(is_null($typeMap)){
            $typeMap = $this->typeMap;
        }
        $typeMap = is_string($typeMap) ? ['root' => $typeMap] : $typeMap;
        $this->cursor->setTypeMap($typeMap);

        // 获取数据集
        $result         = $this->cursor->toArray();
        $this->numRows  = count($result);
        if (!empty($class)) {
            // 返回指定数据集对象类
            $result = new $class($result);
        } elseif ('collection' == $this->resultSetType){
            // 返回数据集Collection对象
            $result = new Collection($result);            
        }

        return $result;
    }

    /**
     * 执行写操作
     * @access public
     * @param string $namespace
     * @param BulkWrite $bulk
     * @param WriteConcern $writeConcern
     *
     * @return WriteResult
     * @throws Exception
     */
    public function execute($namespace, BulkWrite $bulk, WriteConcern $writeConcern = null)
    {
        $this->initConnect(true);
        Db::$executeTimes++;
        try {
            if (false === strpos($namespace, '.')) {
                $namespace = $this->dbName . '.' . $namespace;
            }
            $this->debug(true);
            $writeResult = $this->mongo->executeBulkWrite($namespace, $bulk, $writeConcern);
            $this->debug(false);
            $this->numRows = $writeResult->getMatchedCount();
            return $writeResult;
        } catch (MongoException $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * 数据库调试 记录当前SQL及分析性能
     * @access protected
     * @param boolean $start 调试开始标记 true 开始 false 结束
     * @return void
     */
    protected function debug($start)
    {
        if (!empty($this->config['debug'])) {
            // 开启数据库调试模式
            if ($start) {
                Debug::remark('queryStartTime', 'time');
            } else {
                // 记录操作结束时间
                Debug::remark('queryEndTime', 'time');
                $runtime = Debug::getRangeTime('queryStartTime', 'queryEndTime');
            }
        }
    }

    /**
     * 释放查询结果
     * @access public
     */
    public function free()
    {
        $this->cursor = null;
    }

    /**
     * 关闭数据库
     * @access public
     */
    public function close()
    {
        if ($this->mongo) {
            $this->mongo  = null;
            $this->cursor = null;
        }
    }

    /**
     * 初始化数据库连接
     * @access protected
     * @param boolean $master 是否主服务器
     * @return void
     */
    protected function initConnect($master = true)
    {
        if (!empty($this->config['deploy'])) {
            // 采用分布式数据库
            if ($master) {
                if (!$this->linkWrite) {
                    $this->linkWrite = $this->multiConnect(true);
                }
                $this->mongo = $this->linkWrite;
            } else {
                if (!$this->linkRead) {
                    $this->linkRead = $this->multiConnect(false);
                }
                $this->mongo = $this->linkRead;
            }
        } elseif (!$this->mongo) {
            // 默认单数据库
            $this->mongo = $this->connect();
        }
    }

    /**
     * 连接分布式服务器
     * @access protected
     * @param boolean $master 主服务器
     * @return PDO
     */
    protected function multiConnect($master = false)
    {
        $_config = [];
        // 分布式数据库配置解析
        foreach (['username', 'password', 'hostname', 'hostport', 'database', 'dsn', 'charset'] as $name) {
            $_config[$name] = explode(',', $this->config[$name]);
        }

        // 主服务器序号
        $m = floor(mt_rand(0, $this->config['master_num'] - 1));

        if ($this->config['rw_separate']) {
            // 主从式采用读写分离
            if ($master) // 主服务器写入
            {
                $r = $m;
            } elseif (is_numeric($this->config['slave_no'])) {
                // 指定服务器读
                $r = $this->config['slave_no'];
            } else {
                // 读操作连接从服务器 每次随机连接的数据库
                $r = floor(mt_rand($this->config['master_num'], count($_config['hostname']) - 1));
            }
        } else {
            // 读写操作不区分服务器 每次随机连接的数据库
            $r = floor(mt_rand(0, count($_config['hostname']) - 1));
        }
        $dbMaster = false;
        if ($m != $r) {
            $dbMaster = [];
            foreach (['username', 'password', 'hostname', 'hostport', 'database', 'dsn', 'charset'] as $name) {
                $dbMaster[$name] = isset($_config[$name][$m]) ? $_config[$name][$m] : $_config[$name][0];
            }
        }
        $dbConfig = [];
        foreach (['username', 'password', 'hostname', 'hostport', 'database', 'dsn', 'charset'] as $name) {
            $dbConfig[$name] = isset($_config[$name][$r]) ? $_config[$name][$r] : $_config[$name][0];
        }
        return $this->connect($dbConfig, $r, $r == $m ? false : $dbMaster);
    }

    /**
     * 析构方法
     * @access public
     */
    public function __destruct()
    {
        // 释放查询
        $this->free();

        // 关闭连接
        $this->close();
    }
}
