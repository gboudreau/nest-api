<?php
namespace Nest;

class Authentication{
    const login_url = 'https://home.nest.com/user/login';

    private $username;
    private $password;

    private $transport_url;
    private $access_token;
    private $user;
    private $userid;
    private $cookie_file;
    private $cache_file;
    private $cache_expiration;

    function __construct($username=null, $password=null) {
        if ($username === null && defined('USERNAME')) {
            $username = USERNAME;
        }
        if ($password === null && defined('PASSWORD')) {
            $password = PASSWORD;
        }

        $this->username = $username;
        $this->password = $password;

        $fileId = md5($username);

        $this->cookie_file = sys_get_temp_dir() . '/nest_php_cookies_' . $fileId;
        static::secure_touch($this->cookie_file);

        $this->cache_file = sys_get_temp_dir() . '/nest_php_cache_' . $fileId;

        // Log in, if needed
        $this->login();
    }

    private function login() {
        if ($this->loadCache()) {
            // No need to login; we'll use cached values for authentication.
            static::secure_touch($this->cache_file);
            return;
        }

        if ($this->username === null || $this->password === null) {
            throw new \InvalidArgumentException('Nest credentials were not provided.');
        }

        $httpRequest = new BaseHttp();

        $httpResponse = $httpRequest->POST(self::login_url, array('username' => $this->username, 'password' => $this->password));

        $result = json_decode($httpResponse['response']);
        if (!isset($result->urls)) {
            die("Error: Response to login request doesn't contain required transport URL. Response: '" . var_export($result, TRUE) . "'\n");
        }
        $this->transport_url = $result->urls->transport_url;
        $this->access_token = $result->access_token;
        $this->userid = $result->userid;
        $this->user = $result->user;
        $this->cache_expiration = strtotime($result->expires_in);
        $this->saveCache();
    }

    public function getAccessToken(){
        return $this->access_token;
    }

    public function getCookieFile(){
        return $this->cookie_file;
    }

    public function getTransportUrl(){
        return $this->transport_url;
    }

    public function getUser(){
        return $this->user;
    }

    public function getUserId(){
        return $this->userid;
    }

    private function loadCache() {
        $cacheIsValid = false;
        if (!file_exists($this->cache_file) && !file_exists($this->cookie_file)) {
            return $cacheIsValid;
        }
        $vars = @unserialize(file_get_contents($this->cache_file));
        if ($vars === false) {
            return $cacheIsValid;
        }
        $this->transport_url = $vars['transport_url'];
        $this->access_token = $vars['access_token'];
        $this->user = $vars['user'];
        $this->userid = $vars['userid'];
        $this->cache_expiration = $vars['cache_expiration'];
        $this->last_status = $vars['last_status'];

        $cacheIsValid = $this->cache_expiration > time();

        return $cacheIsValid;
    }

    private function saveCache() {
        $vars = array(
            'transport_url' => $this->transport_url,
            'access_token' => $this->access_token,
            'user' => $this->user,
            'userid' => $this->userid,
            'cache_expiration' => $this->cache_expiration,
            'last_status' => @$this->last_status
        );
        file_put_contents($this->cache_file, serialize($vars));
    }

    private static function secure_touch($fname) {
        if (file_exists($fname)) {
            return;
        }
        $temp = tempnam(sys_get_temp_dir(), 'NEST');
        rename($temp, $fname);
    }
}
?>