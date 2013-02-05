<?php
/**
 * 蜘蛛程序
 * 
 */
class Spider {
	private $host; // 主机
	private $port; // 端口
	private $uri; // 去除主机名的请求路径
	private $browserID; // 0:google chrome,1:Mozilla Firefox,2:IE8
	private $headerOtherStr; // 其他头信息
	/**
	 * Array([0] => HTTP/1.1 200 OK
	 * [code] => 200
	 * [codestr] => OK)
	 * 
	 * @var Array
	 *
	 */
	public $receiveHttpCode;
	/**
	 * 
	 * @var array
	 */
	private $receiveCookieArray = array();
	
	/**
	 * Array(
	 * [0] => text/html;charset=gbk
	 * [type] => text/html
	 * [charset] => gbk
	 * )
	 * 
	 * @var Array
	 */
	public $receiveContentType;
	private $fileCookie; // 当前COOKIE存储的文件
	public $cookieDir = 'cookie/'; // cookie保存的文件夹,需要以 / 结尾
	
	/**
	 * 给我一个网址 还给你内容
	 *
	 * @param Str $url
	 *        	例子：http://translate.google.cn 或 translate.google.cn
	 */
	public function __construct($url) {
		$url = is_array( $url ) ? $url[0] : $url;
		if (0 !== strpos ( $url, 'http' )) {
			$url = 'http://' . $url;
		}
		// 处理URL
		$parse = parse_url ( $url );
		$this->host = $parse ['host'];
		$this->uri = isset ( $parse ['path'] ) ? $parse ['path'] : '/';
		$this->port = isset ( $parse ['port'] ) ? $parse ['port'] : 80;
		
		// 读取Cookie
		$this->fileCookie = $this->cookieDir . $this->host . '.cookie';
		if (file_exists ( $this->fileCookie )) {
			// 判断是否过期，默认三天
			if (fileatime ( $this->fileCookie ) + 259200 > time ()) {
				$this->receiveCookieArray = unserialize ( file_get_contents ( $this->fileCookie ) );
			} else {
				unlink ( $this->fileCookie );
			}
		}
	}
	
	/**
	 * 根据错误代码得到错误信息。
	 *
	 * @param INT $errNo        	
	 */
	public function getErrMsg($errNo) {
		switch ($errNo) {
			case 99 :
				return "Error! fsconkopen错误";
			case 100 :
				return "Cookie保存失败！请检查";
			
			default :
				return "未知错误！";
		}
	}
	
	/**
	 * 记录错误日志
	 */
	private function writeErrLog($errNo, $line = NULL) {
		$content = date ( 'Y-m-d H:i:s', time () ) . '    ';
		$content .= $this->getErrMsg ( $errNo ) . '    ';
		
		if (isset ( $line ))
			$content .= $line . '行    ';
		
		$content .= $this->host . $this->uri . "\r\n";
		
		file_put_contents ( 'sperrorlog.log', $content, FILE_APPEND );
	}
	
	/**
	 * 设置浏览器类型默认0 Google Chrome
	 *
	 * @param INT $browserID
	 *        	0:google chrome,1:Mozilla Firefox,2:IE8
	 */
	public function setBrowserID($browserID) {
		$this->browserID = $browserID;
	}
	
	// 获取浏览器ID
	private function getUserAgent($browserID = 0) {
		switch ($browserID) {
			case 0 :
				return "User-Agent: Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.17 (KHTML, like Gecko) Chrome/24.0.1312.56 Safari/537.17";
			case 2 :
				return "User-Agent	Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 6.1; WOW64; Trident/4.0; SLCC2; .NET CLR 2.0.50727; .NET CLR 3.5.30729; .NET CLR 3.0.30729; Media Center PC 6.0; .NET4.0C; .NET4.0E)";
		}
	}
	
	/**
	 * 发送请求
	 */
	public function send() {
		$fp = fsockopen ( $this->host, $this->port, $this->errno, $this->errstr, 30 );
		if ($fp) {
			$out = "GET {$this->uri} HTTP/1.1\r\n";
			$out .= "Host: {$this->host}\r\n";
			$out .= "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n";
			$out .= "Accept-Language: zh-CN,zh;q=0.8\r\n";
			$out .= "Accept-Charset: GBK,utf-8;q=0.7,*;q=0.3\r\n";
			
			$out .= $this->getUserAgent () . "\r\n";
			
			if (! empty ( $this->receiveCookieArray )) {
// 				var_dump ( $this->receiveCookieArray );
// 				exit ();
				$out .= "Cookie: ";
				foreach ( $this->receiveCookieArray as $key => $value ) {
					$out .= $key . '=' . $value . '; ';
				}
				$out = rtrim ( $out, '; ' );
				$out .= "\r\n";
			}
			$out .= "Connection: Close\r\n\r\n";
			
			fwrite ( $fp, $out );
			
			$content = '';
			while ( ! feof ( $fp ) ) {
				
				$content .= fgets ( $fp ); // 第二个参数默认一次读1024字节
			}
			fclose ( $fp );


			// 这里处理头信息
			preg_match ( '/(^[^<]*)/i', $content, $header );
			$this->resolveHeader ( $header [1] );
			// 判断是否是html
			$content = substr ( $content, strlen ( $header [1] ) );
			//如果头信息中没有charset
			if(!isset($this->receiveContentType['charset'])){
				if(preg_match('/charset=(.+?)[\'\"]/i', $content, $charset)){
					$this->receiveContentType['charset'] = $charset[1];
				}else{
					$this->receiveContentType['charset'] = 'utf-8';
				}
			}

            if ($this->receiveContentType ['charset'] != 'utf-8') {
                $content = mb_convert_encoding($content,'utf-8',$this->receiveContentType['charset']);
            }

			return $content;
		} else {
			// Error! fsconkopen错误
			$this->writeErrLog ( 99, __LINE__ );
			
			return false;
		}
	}


	/**
	 * 解析头信息
	 *
	 * @param String $header        	
	 */
	private function resolveHeader($header) {
		// echo $header,'<br/><br/><br/><br/>';
		
		// 处理第一行
		$pattern = '/HTTP\/1.\d\s(?<code>\d{3})\s(?<codestr>\w+)[\r\n]/i';
		preg_match ( $pattern, $header, $this->receiveHttpCode );
		
		// 之后的
		$pattern = '/([\w-_]+?):\s(.*)[\r\n]/i';
		preg_match_all ( $pattern, $header, $header );
		$header = array_combine ( $header [1], $header [2] );
		
		if (isset ( $header ['Content-Type'] )) {
			$pattern = '/(?<type>\w+\/\w+)(;\s?charset=(?<charset>.+))?/i';
			preg_match ( $pattern, $header ['Content-Type'], $this->receiveContentType );
		}
		if (isset ( $header ['Set-Cookie'] )) {
			$cookie = $header ['Set-Cookie'] . '; ';
			$pattern = '/((.*?)=(.*?));\s/i';
			preg_match_all ( $pattern, $cookie, $cookie );
			
			
			$this->receiveCookieArray = array_merge($this->receiveCookieArray, array_combine ( $cookie [2], $cookie [3] ));
			
			// 去除无用的COOKIE
			unset ( $this->receiveCookieArray ['expires'] );
			unset ( $this->receiveCookieArray ['domain'] );
			unset ( $this->receiveCookieArray ['path'] );
			
			// 保存COOKIE到文件
			$this->saveCookie ();
		}
		
		// var_dump($headerArray);
		// var_dump($this->receiveHttpCode);
		// var_dump($this->receiveContentType);
		// var_dump($this->receiveCookieArray);
		// exit();
	}
	
	/**
	 * 保存$this->receiveCookieArray 到文件
	 */
	private function saveCookie() {
		if (false !== file_put_contents ( $this->cookieDir . $this->host . '.cookie', serialize ( $this->receiveCookieArray ) )) {
			// Cookie保存失败！请检查
			$this->writeErrLog ( 100, __LINE__ );
			return 100;
		} else {
			return true;
		}
	}
}