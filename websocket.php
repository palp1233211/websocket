<?php

include_once(__DIR__.'/RedisSingle.php');
class WebSocket{
	public $ws = null;
	//状态码
	public static $code = [
			'name'=>0,		//发送用户名和接收用户名
			'others'=>1,	//其他用户连接
			'allMembers'=>2,//向所有用户发送当前所有用户连接
			'myMsg'=>3,		//服务器向当前连接发送消息，未使用
			'othersMsg'=>4,	//服务器向其他用户发送的消息，，未使用
			'close'=>5,		//用户断开连接
			'clientMsg'=>6,	//接收客户端发送的消息
			'serverMsg'=>7,	//向客户端发送的消息
			'heartbeat'=>20,//接收客户端心跳检测发送的消息
		];
	//redes链接标识
	public $redis = null;
	/**
	 * 建立WebSocket服务器
	 * [__construct description]
	 */
	public function __construct(){
		$this->ws = new Swoole\WebSocket\Server('0.0.0.0',9510);
		$this->ws->set([
			'worker_num'=>2,
			'task_worker_num'=>2,
			'max_request'=>10000,
			'heartbeat_idle_time'      => 300, // 表示一个连接如果300秒内未向服务器发送任何数据，此连接将被强制关闭
			'heartbeat_check_interval' => 60,  // 表示每60秒遍历一次
		]);
		$this->ws->on('WorkerStart',[$this,'OnWorkerStart']);
		$this->ws->on('open',[$this,'OnOpen']);
		$this->ws->on('message',[$this,'OnMessage']);
		$this->ws->on('task',[$this,'OnTask']);
		$this->ws->on('close',[$this,'OnClose']);
		$this->ws->start();
	}
	/**
	 * 当进程启动事触发，删除redis的脏数据。
	 * @param Swoole\Server $server   [description]
	 * @param int           $workerId [description]
	 */
	public function OnWorkerStart(Swoole\Server $server, int $workerId){
		//当进程启动时，先检查当前有没有客户端连接，没有去删除一下redis中的客户信息，防止服务器意外终止，导致的redis有用户信息的脏数据。
		$connections = json_decode(json_encode($this->ws->connections),true);
		$this->redis = RedisSingle::getRedis();
		if( !count($connections)){
			$this->redis->del('allMembers');
		}
	}
	/**
	 * 用户建立连接
	 * @param Swoole\WebSocket\Server $server  [description]
	 * @param [type]                  $request [description]
	 */
	public function OnOpen(Swoole\WebSocket\Server $server , $request){
#		echo '用户的链接标识：'.$request->fd.PHP_EOL;
	}
	/**
	 * 用户发送消息
	 * @param Swoole\WebSocket\Server $server [description]
	 * @param [type]                  $frame  [description]
	 */
	public function OnMessage(Swoole\WebSocket\Server $server , $frame){
#		echo "receive from {$frame->fd}:{$frame->data},opcode:{$frame->opcode},fin:{$frame->finish}".PHP_EOL;

		$data = json_decode($frame->data,true);
		if($data['type'] == self::$code['clientMsg']){
			//有用户发布消息，广播到其他客户端用户
			$data['type'] = self::$code['serverMsg'];
			$data['fd'] = $this->redis->zscore('allMembers',$data['name']);
			$data['text'] = $this->filter($data['text']);		
			if(empty($data['text']))
				return;
			$server->task($data);
		}else if($data['type'] == self::$code['name']){
			if(empty($data['name'])){
                	        include_once(__DIR__.'/foundName.php');
        	                $foundName  = foundName::init();
	                        $data['name'] = $foundName->getName($this->redis->zrange('allMembers',0,-1));
                	        $message = json_encode(['type'=>self::$code['name'],'content'=>$data['name']]);
	                        //把用户名发送给客户端
                        	$server->push($frame->fd , $message);
                	}
			//吧新连接的用户放入数组，统一管理，以文件链接符标识为键，用户名为值。  
	                //进程隔离，导致各个进程间数据不一致，采用redis来储存用户。
       			$data['fd'] = $frame->fd;
		        $data['name'] = $this->filter($data['name']);
			if(empty($data['name']))
                                 return;
			$this->redis->zadd('allMembers',$frame->fd,$data['name']);
			$server->task($data);
			//发送当前用户连接组
			$server->task(['type'=>self::$code['allMembers']]);
		}
	}
	/**
	 * 异步任务
	 * @param Swoole\Server $server   [description]
	 * @param [type]        $task     [description]
	 * @param [type]        $workerId [description]
	 * @param [type]        $data     [description]
	 */
	public function OnTask(Swoole\Server $server,  $task, $workerId, $data) {
		switch($data['type']){
			case self::$code['serverMsg'] :
				//向客户端 发送文本信息
				$isData = $data;
		        // $isData['type'] = self::$code['othersMsg'];
           		$isData = json_encode($isData);
				if($data['target'] == 'all'){
					$this->msg($this->ws->connections,$isData,$data['fd']);
				}else if($this->ws->exist(intval($data['target']))){
					#$server->push($data['target'], $isData);
					$this->msg($data['target'],$isData);	
				}
				break;
			case self::$code['name'] :
				//新用户连接，广播全体成员
				$message = json_encode(['type'=>self::$code['others'],'content'=>"欢迎：".$data['name']." 加入聊天室。"]);
				$this->msg($this->ws->connections,$message); 
				break;
			case self::$code['allMembers'] :
				//向所有用户推送当前连接的用户
				$allMembers = json_encode(['type'=>self::$code['allMembers'],'content'=>$this->redis->zrange('allMembers',0,-1,true)]);
				$this->msg($this->ws->connections,$allMembers);
				break;
			case self::$code['close'] :
				//有用户断开连接,广播全体成员
				$data['type'] = self::$code['close'];
				$message = json_encode($data);
				$this->msg($this->ws->connections,$message);
				break;
		}
	}
	/**
	 * 客户端断开连接
	 * @param [type] $ser [description]
	 * @param [type] $fd  [description]
	 */
	public function OnClose($ser, $fd){
#		echo '断开连接：'.$fd.PHP_EOL;
		$name = $this->redis->ZRANGEBYSCORE('allMembers',$fd,$fd)[0];
		//用户断开连接，删除该用户的链接信息。
		$this->redis->zremrangebyscore('allMembers',$fd,$fd);		
		
		$data = ['type' => self::$code['close'], 'fd' => $fd ,'name'=>$name, 'content' => $name.'：离开聊天室！','target'=>'all'];
		$ser->task($data);
		//发送当前用户连接组
		$ser->task(['type'=>self::$code['allMembers']]);
	}
	/**
	 * 数据过滤
	 * @param  [type] $data [description]
	 * @return [type]       [description]
	 */
	public function filter($data){
		$data = trim($data);
		$data = htmlspecialchars_decode($data);
		$data = strip_tags($data);
		// $data = htmlspecialchars($data);
		return $data;
	}
	/**
	 *  向客户端发送消息
	 * @param  [type] $connections [所有链接]
	 * @param  [type] $message     [发送的消息]
	 * @param  string $all         [不发送给哪个链接]
	 * @return [type]              [description]
	 */
	public function msg($connections,$message,$all = ''){
		if(is_array($connections) or is_object($connections)){
			foreach($this->ws->connections as $fd){
				$status = $this->ws->exist($fd);
	                        if($all != $fd and $status)
                        	        $this->ws->push($fd,$message);
				if(!$status){
					$this->ws->disconnect($fd);

				}
                	}
		}else{
			if($this->ws->exist($connections)){
				$this->ws->push($connections,$message);
			}else{
				$this->ws->disconnect($connections);
			}
		}
	}
}
new WebSocket();








