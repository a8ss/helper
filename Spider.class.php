<?php
/**
 *
 * 例：
 * $url = 'xxx.com:80/a.php';//请求地址
 * $postdata = array('a' => 332, 'b' => 'value');//如果有POST数据，请使用关联数组
 *
 * $sp = new Spider($url);
 * $sp->setPostData($postdata); //如果post方式 设置post数据
 * $sp->setBrowser('ie');   //模拟客户端信息 取值：chrome、firefox、ie 默认chrome
 *
 * //发送请求 返回请求状态码（HTTP状态码）
 * if ($sp->send() == 200) {
 *      echo $sp->getBody();
 * }
 *
 * //获得相应头信息。Type：HTTP头中的Content-type 可能值：text/html  text/js  text/css
 * $sp->getResponseHeader('Type');
 *
 */
class Spider
{
    private $requestHost;
    private $requestUri;
    private $requestPort;
    private $requestMethod = 'GET';
    private $httpVersion = '1.1';

    private $postData = array();

    /**
     * HTTP请求头
     * @var array
     */
    private $requestHeaders = array(
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


    public $errorMsg = '';


    /**
     *
     * @param String $url
     *
     *
     */
    public function __construct($url)
    {
        if (0 !== strpos($url, 'http')) {
            $url = 'http://' . $url;
        }
        // 处理URL
        $parse = parse_url($url);
        $this->requestHost = $parse ['host'];
        $this->requestUri = isset ($parse ['path']) ? $parse ['path'] : '/';
        $this->requestPort = isset ($parse ['port']) ? $parse ['port'] : 80;
        if ($this->requestPort == 80) {
            $this->setRequestHeaders('Host', $this->requestHost);
        } else {
            $this->setRequestHeaders('Host', $this->requestHost . ':' . $this->requestPort);
        }


        // 读取Cookie
        if (!is_dir($this->cookieDir)) {
            mkdir($this->cookieDir, 755, true);
        }
        $this->fileCookie = $this->cookieDir . $this->requestHost . '.cookie';
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
        if ($this->requestMethod == 'POST' && !empty($this->postData)) {
            $postStr = '';
            foreach ($this->postData as $key => $value) {
                $postStr .= $key . '=' . urlencode($value) . '&';
            }
            $postStr = rtrim($postStr, '&');
            //Content-Length: 29
            $this->setRequestHeaders('Content-Length', strlen($postStr));
        }

        $requestStr = "{$this->requestMethod} {$this->requestUri} HTTP/{$this->httpVersion}\r\n";

        foreach ($this->requestHeaders as $key => $value) {
            $requestStr .= $key . ": " . $value . "\r\n";
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

        $fp = fsockopen($this->requestHost, $this->requestPort, $errno, $this->errorMsg, 30);
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

            return $this->responseHeader['Code'];
        } else {
            // Error!
            return false;
        }
    }


    public function getBody()
    {
        return $this->responseBody;
    }

    public function getResponseHeader($key = '')
    {
        if (empty($key))
            return $this->responseHeader;
        elseif (isset($this->responseHeader[$key])) {
            return $this->responseHeader[$key];
        } else {
            return false;
        }

    }

    /**
     * 解析头信息
     *
     * @param String $header
     */
    private function resolveHeader($header)
    {
        // 处理第一行
        $pattern = '/HTTP\/1.\d\s(?<Code>\d{3})\s(?<Codestr>[\w ]+)[\r\n]/i';
        preg_match($pattern, $header, $mat);
        $this->responseHeader['Code'] = $mat['Code'];
        $this->responseHeader['Codestr'] = $mat['Codestr'];

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
            $this->errorMsg = '保存Cookie失败';
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
        $key = ucfirst($key);
        if ($key == 'Method' && strtoupper($value) == 'POST') {
            $this->requestMethod = 'POST';
            //Content-Type: application/x-www-form-urlencoded
            $this->requestHeaders['Content-Type'] = 'application/x-www-form-urlencoded';
            return true;
        }
        $this->requestHeaders [$key] = $value;
        return true;
    }

    /**
     * 设置post数据
     * @param $data
     * @return bool
     */
    public function setPostData($data)
    {
        if (is_array($data)) {
            $this->postData = array_merge($this->postData, $data);
            $this->setRequestHeaders('Method','POST');
            return true;
        } else {
            return false;
        }
    }

    /**
     * 设置浏览器类型默认0 Google Chrome
     *
     * @param String $browser
     *            0:google chrome,1:Mozilla Firefox,2:IE8
     */
    public function setBrowser($browser)
    {
        $browser = strtolower($browser);
        switch ($browser) {
            case 'chrome' :
                $this->setRequestHeaders('User-Agent', "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.17 (KHTML, like Gecko) Chrome/24.0.1312.56 Safari/537.17");
                break;
            case 'firefox' :
                $this->setRequestHeaders('User_Agent', "User-Agent: Mozilla/5.0 (Windows NT 6.1; WOW64; rv:18.0) Gecko/20100101 Firefox/18.0");
                break;
            case 'ie' :
                $this->setRequestHeaders('User-Agent', "Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 6.1; WOW64; Trident/4.0; SLCC2; .NET CLR 2.0.50727; .NET CLR 3.5.30729; .NET CLR 3.0.30729; Media Center PC 6.0; .NET4.0C; .NET4.0E)");
                break;
            default:
                $this->setRequestHeaders('User-Agent', "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.17 (KHTML, like Gecko) Chrome/24.0.1312.56 Safari/537.17");
        }
    }
}