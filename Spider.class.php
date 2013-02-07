<?php
/**
 *
 * 例：
 *
$data = array(
'url' => 'www.baidu.com',
);
$sp = new Spider($data);
if($sp->send()){
echo $sp->getBody();
}
 */
class Spider
{

    private $postData = array();

    /**
     * HTTP请求头
     * @var array
     */
    private $requestHeaders = array(
        'Method' => 'GET',
        'Uri' => '/',
        'Version' => '1.1',
        'Host' => '',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language' => 'zh-CN,zh;q=0.8',
        'Accept-Charset' => 'GBK,utf-8;q=0.7,*;q=0.3',
        //'Accept-Encoding' => 'gzip,deflate,sdch',
        'User-Agent' => 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.17 (KHTML, like Gecko) Chrome/24.0.1312.56 Safari/537.17',
        'Connection' => 'Close',
    );

    private $responseBody;
    private $responseHeader;


    /**
     * 有关本站的COOKIE
     * @var array
     */
    private $cookies = array();

    private $fileCookie; // 当前COOKIE存储的文件
    public $cookieDir = 'cookie/'; // cookie保存的文件夹,需要以 / 结尾

    /**
     * 给我一个网址 还给你内容
     *
     * @param String $url
     *            例子：http://translate.google.cn 或 translate.google.cn
     */
    public function __construct($url)
    {
        $url = is_array($url) ? $url['url'] : $url;
        if (0 !== strpos($url, 'http')) {
            $url = 'http://' . $url;
        }
        // 处理URL
        $parse = parse_url($url);
        $this->requestHeaders['Host'] = isset($parse ['host']) ? $parse ['host'] : die('域名解析错误');
        $this->requestHeaders['Uri'] = isset ($parse ['path']) ? $parse ['path'] : '/';
        $this->requestHeaders['Port'] = isset($parse ['port']) ? $parse ['port'] : null;


        // 读取Cookie
        if (!is_dir($this->cookieDir)) {
            mkdir($this->cookieDir, 755, true);
        }
        $this->fileCookie = $this->cookieDir . $this->requestHeaders['Host'] . '.cookie';
        if (file_exists($this->fileCookie)) {
            // 判断是否过期，默认三天
            if (fileatime($this->fileCookie) + 259200 > time()) {
                $this->cookies = unserialize(file_get_contents($this->fileCookie));
            } else {
                unlink($this->fileCookie);
            }
        }
    }

    /**
     * 发送请求
     */
    public function send()
    {

        //post数据
        if (strtolower($this->requestHeaders['Method']) == 'post' && !empty($this->postData)) {
            $postStr = '';
            foreach ($this->postData as $key => $value) {
                $postStr .= $key . '=' . urlencode($value) . '&';
            }
            $postStr = rtrim($postStr, '&');
            //Content-Length: 29
            $this->requestHeaders['Content-Length'] = strlen($postStr);
        }

        $requestStr = "{$this->requestHeaders['Method']} {$this->requestHeaders['Uri']} HTTP/{$this->requestHeaders['Version']}\r\n";
        foreach ($this->requestHeaders as $key => $value) {
            if ($key == 'Host' && (!empty($this->requestHeaders['Port']))) {
                $requestStr .= $key . ": " . $value . ':' . $this->requestHeaders['Port'] . "\r\n";
                continue;
            }
            if ($key != 'Method' && $key != 'Uri' && $key != 'Version' && $key != 'Port') {
                $requestStr .= $key . ": " . $value . "\r\n";
            }
        }

        if (!empty ($this->cookies)) {
            $requestStr .= "Cookie: ";
            foreach ($this->cookies as $key => $value) {
                $requestStr .= $key . '=' . $value . '; ';
            }
            $requestStr = rtrim($requestStr, '; ');
            $requestStr .= "\r\n";
        }

        $requestStr .= empty($postStr) ? "\r\n" : "\r\n" . $postStr;

        $fp = fsockopen($this->requestHeaders['Host'], $this->requestHeaders['Port'], $errno, $errmsg, 30);
        if ($fp) {

            fwrite($fp, $requestStr);
            $content = '';
            while (!feof($fp)) {
                $content .= fgets($fp); // 第二个参数默认一次读1024字节
            }
            fclose($fp);

            $flag = strpos($content, "\r\n\r\n");
            $responseHeader = substr($content, 0, $flag);

            // 这里处理头信息
            $this->resolveHeader($responseHeader);

            /**
             * 这里判断是否是html
             *
             *
             * 如果使用GZIP在这里处理。。。。。。
             *
             **/
            $this->responseBody = trim(substr($content, $flag));
            if (isset($this->responseHeader['Type']) && strtolower($this->responseHeader['Type']) == 'text/html') {
                if (isset($this->responseHeader['Content-Encoding']) && (strtolower($this->responseHeader['Content-Encoding']) == 'gzip')) {
                    //GZIP压缩格式处理
                }
            }


            //如果头信息中没有charset
            if (!empty($this->responseHeader['Charset'])) {
                if (preg_match('/charset=(.+?)[\'\"]/i', $this->responseBody, $charset)) {
                    $this->responseHeader['Charset'] = $charset[1];
                } else {
                    $this->responseHeader['Charset'] = 'utf-8';
                }
            }

            if (isset($this->responseHeader ['Charset']) && $this->responseHeader ['Charset'] != 'utf-8') {
                $this->responseBody = mb_convert_encoding($this->responseBody, 'utf-8', $this->responseHeader['Charset']);
            }

            return true;
        } else {
            // Error!
            $this->writeErrLog($errno . $errmsg . ' 检查请求地址', __LINE__);
            return false;
        }
    }


    public function getBody()
    {
        return $this->responseBody;
    }


    /**
     * 解析头信息
     *
     * @param String $header
     */
    private function resolveHeader($header)
    {
        file_put_contents('thisHeaderStr.txt', $header);

        // 处理第一行
        $pattern = '/HTTP\/1.\d\s(?<code>\d{3})\s(?<codeStr>[\w ]+)[\r\n]/i';
        preg_match($pattern, $header, $mat);
        $this->responseHeader['code'] = $mat['code'];
        $this->responseHeader['codeStr'] = $mat['codeStr'];

        // 之后的
        $pattern = '/([\w-_]+?):\s([^\s]+)[\r\n]?/i';
        preg_match_all($pattern, $header, $mat);
        $this->responseHeader = array_merge(array_combine($mat [1], $mat [2]), $this->responseHeader);

        if (isset ($this->responseHeader ['Content-Type'])) {
            $pattern = '/(?<Type>\w+\/\w+)(;\s?charset=(?<Charset>.+))?/i';
            preg_match($pattern, $this->responseHeader ['Content-Type'], $mat);
            $this->responseHeader['Type'] = isset($mat['Type']) ? $mat['Type'] : null;
            $this->responseHeader['Charset'] = isset($mat['Charset']) ? $mat['Charset'] : null;
        }

        //COOKIE
        if (isset ($this->responseHeader ['Set-Cookie'])) {
            $pattern = '/\s*([^\s]+?)=([^;]+)/i';
            preg_match_all($pattern, $this->responseHeader ['Set-Cookie'], $mat);

            $this->cookies = array_merge($this->cookies, array_combine($mat [1], $mat [2]));

            // 去除无用的COOKIE
            unset ($this->cookies ['expires']);
            unset ($this->cookies ['domain']);
            unset ($this->cookies ['path']);

            // 保存COOKIE到文件
            $this->saveCookie();
        }

    }

    /**
     * 保存$this->receiveCookieArray 到文件
     */
    private function saveCookie()
    {
        if (false !== file_put_contents($this->cookieDir . $this->requestHeaders['Host'] . '.cookie', serialize($this->cookies))) {
            // Cookie保存失败！请检查
            $this->writeErrLog('保存Cookie失败', __LINE__);
            return false;
        } else {
            return true;
        }
    }


    /**
     * 设置请求头信息
     * @param string $key
     * @param string $value
     * @return bool
     */
    public function setRequestHeaders($key, $value)
    {

        if ($key == 'Method' && strtolower($value) == 'post') {
            //Content-Type: application/x-www-form-urlencoded
            $this->requestHeaders['Content-Type'] = 'application/x-www-form-urlencoded';
        }

        $this->requestHeaders [$key] = $value;
        return true;
    }

    /**
     * 设置post数据
     * @param $data
     */
    public function setPostData($data)
    {
        if (is_array($data)) {
            $this->postData = array_merge($this->postData, $data);
            return true;
        } else {
            return false;
        }
    }

    /**
     * 设置浏览器类型默认0 Google Chrome
     *
     * @param INT $browserID
     *            0:google chrome,1:Mozilla Firefox,2:IE8
     */
    public function setBrowser($browserID)
    {

        switch ($browserID) {
            case 0 :
                $this->requestHeaders['User-Agent'] = "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.17 (KHTML, like Gecko) Chrome/24.0.1312.56 Safari/537.17\r\n";
                break;
            case 2 :
                $this->requestHeaders['User-Agent'] = "Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 6.1; WOW64; Trident/4.0; SLCC2; .NET CLR 2.0.50727; .NET CLR 3.5.30729; .NET CLR 3.0.30729; Media Center PC 6.0; .NET4.0C; .NET4.0E)\r\n";
                break;
        }
    }

    /**
     * 记录错误日志
     */
    private function writeErrLog($msg, $line = NULL)
    {
        $content = date('Y-m-d H:i:s', time()) . '    ';
        $content .= $msg . '    ';

        if (isset ($line))
            $content .= $line . '行    ';

        $content .= $this->requestHeaders['Host'] . $this->requestHeaders['Uri'] . "\r\n";

        //增加写权限判断
        file_put_contents('sperrorlog.log', $content, FILE_APPEND);
    }
}