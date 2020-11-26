<?php
class Chat {
    /**
    * 单例对象
    * @var object
    */
    protected static $_instance = null;
     
    /**
    * 服务器配置
    */       
    private static $config = array(
        'reactor_num' => 1, //reactor thread num
        'worker_num' => 1,    //worker process num
        'max_conn' => 100,
        'backlog' => 128,   //listen backlog
        'max_request' => 50,
        'open_cpu_affinity' => 1,
//        'heartbeat_check_interval' => 60, //每隔多少秒检测一次，单位秒，Swoole会轮询所有TCP连接，将超过心跳时间的连接关闭掉
//        'heartbeat_idle_time' => 60, //TCP连接的最大闲置时间，单位s , 如果某fd最后一次发包距离现在的时间超过heartbeat_idle_time会把这个连接关闭。
    );
    
    private static $serv = null;
    
    /**
     * 共享内存对象,使用redis来做共享内存存数据
     */         
    private static $redis = null;
	
	/**
     * server port
     */
	const SERV_PORT = 9502;
	
	/**
     * redis ip
     */
	const REDIS_IP = '127.0.0.1';
	
	/**
     * redis port
     */
	const REDIS_PORT = 6381;
    
    /**
     * 用户列表
     */ 
    private static $userlist = array();
    
    /**
     * 聊天内容,redis的key
     */         
    const CHAT = 'chat';
                                                                                                            
    /**
    * 单例模式，防止对象被克隆
    */
    private function __clone() {}
    
    /**
    * 单例模式，防止对象被克隆
    */
    private function __construct() {}
    
    /**
    * 获取单例对象
    * @param int uid 用户UID
    * @param string token 用户Token
    * @return object
    */
    public static function getInstance() {
        if (self::$_instance == null) {
            self::$_instance = new Chat();
            self::initRedis();            
        }
        return self::$_instance;
    }
    
    /**
    * 设置配置文件
    */     
    public static function setConf($config = array()) {
        if(!empty($config)) {
            self::$config = $config;
        }       
        return self::$_instance;
    }
    
    /**
     * 初始化redis对象
     */         
    private static function initRedis() {
        self::$redis = new Redis();
        self::$redis->connect(self::REDIS_IP, self::REDIS_PORT);
        return self::$redis;    
    }
    
    /**
    * 运行服务器
    */     
    public static function run() {
        self::$serv = new swoole_websocket_server("0.0.0.0", self::SERV_PORT); 
        self::$serv->set(self::$config);  
        self::$serv->on('Open', function($server, $req) {
            self::onConncet($server, $req);
        });
		
		//设置头部跨域处理        
        self::$serv->on('Message', function($server, $frame) {
            $data = json_decode($frame->data, true);
            $cmd = isset($data['cmd']) ?  trim($data['cmd']) : '';
            $func_name = 'cmd'.$cmd;
            if(method_exists(self::$_instance, $func_name)) {
                self::$func_name($server, $frame);
            }
        });
        
        self::$serv->on('Close', function($server, $fd) {
            //离线操作
            self::onExit($server, $fd);
        });    
        self::$serv->start(); 
        return self::$_instance;  
    }
       
    /**
     * 登陆
     */                                                
    private static function cmdLogin($server, $frame) {
        $data = json_decode($frame->data, true);
        $name = $data['name'];       
        //判断用户名是否登录过， 如果登陆过， 关闭之前的连接
        if($key = array_search($name, self::$userlist)) {
            unset(self::$userlist[$key]);
            //$server->close($key);
        }
        self::$userlist[$frame->fd] = $name;                               
        $data = array(
            'cmd'=>'login',
            'msg'=> "&nbsp;".date("Y-m-d H:i:s") . " ". $name.'上线了'
        );
                
        if(!empty($data)) {
            self::Broadcast($server, json_encode($data));
        }
        
        //下发在线列表和内容
        self::cmdGetOnline($server, $frame);
        self::cmdGetHistory($server, $frame);  
            
    }
    
    /**
     * 获取在线列表
     */         
    private static function cmdGetOnline($server, $frame) {
        $userlist = array();       
        foreach($server->connections as $fd) {
            if(isset(self::$userlist[$fd])) {
                $userlist[$fd] = self::$userlist[$fd];
            }
        }      
        $data = array(
            'cmd'=>'getOnline',
            'msg'=> $userlist
        );
        if(!empty($data)) {
             self::Broadcast($server, json_encode($data));
        }    
    }
    
    /**
     * 发送消息
     */         
    private static function cmdMessage($server, $frame) {
        $name = self::$userlist[$frame->fd];
        $data = json_decode($frame->data, true);
        echo "message: ".$data['msg'].PHP_EOL;
        $datatime = date("Y-m-d H:i:s");
        $content = '【'.$datatime.' '.$name.'说】：'.$data['msg'];
        $chat = json_encode(array('fd'=>$frame->fd,'name'=>$name,'msg'=>$content,'datetime'=>$datatime));
        self::$redis->lpush(self::CHAT, $chat);      
        $data = array(
            'cmd'=>'message',
            'msg'=> $content
        );
        if(!empty($data)) {
            self::Broadcast($server, json_encode($data));
        }  
    }
        
    /**
     * 获取历史记录，登陆时候获取
     */             
    private static function cmdGetHistory($server, $frame) {
        $chat = self::$redis->lrange(self::CHAT, 0 , -1);
        $chat = array_reverse($chat, true);
        //把聊天内容处理成字符串形式下发
        $msg = array();        
        foreach($chat as $v) {
            $v = json_decode($v, true);
            $msg[] = $v['msg'];            
        }
        $data = array(
            'cmd'=>'getHistory',
            'msg'=> implode('<br />', $msg)
        );
        if(!empty($msg)) {
            $server->push($frame->fd, json_encode($data));
        }     
    }
        
    /**
     * 广播消息
     */         
    public static function Broadcast($server, $msg) {
        foreach($server->connections as $fd) {
        	$server->push($fd, $msg);
        }
    }
    
    /**
     * 下线通知所有人
     */         
    private static function onExit($server, $fd) {
		if(isset(self::$userlist[$fd])) {
            $data = array(
                'cmd'=>'offline',
                'msg'=> "&nbsp;".date("Y-m-d H:i:s") . " ". self::$userlist[$fd].'离开了'
            );       
            if(!empty($data)) {
                self::Broadcast($server, json_encode($data));
            } 
            unset(self::$userlist[$fd]);
        }   
    }
    
    /**
     * 上线通知所有人
     */         
    private static function onConncet($server, $frame) {
   
    }
}

//设置编码
header("Content-type: text/html; charset=utf-8");
//调用实例
Chat::getInstance()->setConf()->run();
