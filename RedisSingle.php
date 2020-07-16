<?php 

/**
 * redis单例
 */
// include_once('./redis.php');
class RedisSingle
{
	private static $obj = null;
	private function __construct(){
	
	}	
	public static function getRedis()
	{
		if (self::$obj == null) {
			self::$obj = new Redis();
			self::$obj->connect('127.0.0.1', 6379);
		}
		return self::$obj;
	}
	private function __clone(){
		
	}

}
