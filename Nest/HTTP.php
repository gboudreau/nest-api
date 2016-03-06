<?php
namespace Nest;

use Nest\Authentication as Auth;

class Http extends BaseHttp {
    const user_agent = 'Nest/2.1.3 CFNetwork/548.0.4';
    const protocol_version = 1;
    
    private $auth;
    
    public function __construct(Auth $Authentication){
        parent::__construct();
        if($Authentication === NULL){
            throw new InvalidArgumentException('No Nest\Authentication passed in.');
        }
        $this->auth = $Authentication;
        $this->setUserAgent(self::user_agent);
        $this->addHeader('X-nl-protocol-version', self::protocol_version);
    }
    
    public function GET($url) {
        return $this->request('GET', $url);
    }
    
    public function POST($url, $data_fields) {
        return $this->request('POST', $url, $data_fields);
    }
    
    protected function request($method, $url, $data_fields=null, $with_retry=TRUE) {
        if ($url[0] == '/') {
            $url = $this->auth->getTransportUrl() . $url;
        }
        
        if (!empty($this->auth->getUserId())) {
            $this->addHeader('X-nl-user-id', $this->auth->getUserId());
            $this->addHeader('Authorization', ('Basic ' . $this->auth->getAccessToken()));
        }
        
        if(!empty($this->auth->getCookieFile())){
            parent::setCookieFile($this->auth->getCookieFile());
        }
        
        $response = parent::request($method, $url, $data_fields);
    
        if ($response['info']['http_code'] == 401 || (!$response['response'] && curl_errno($this->ch) != 0)) {
            if ($with_retry && $this->auth->loadCache()) {
                // Received 401, and was using cached data; let's try to re-login and retry.
                @unlink($this->auth->getCookieFile());
                @unlink($this->auth->cache_file);
                if ($response['info']['http_code'] == 401) {
                    $this->auth->login();
                }
                return self::request($method, $url, $data_fields, !$with_retry);
            } else {
                throw new RuntimeException("Error: HTTP request to $url returned an error: " . curl_error($this->ch), curl_errno($this->ch));
            }
        }
    
        $json = json_decode($response['response']);
        if (!is_object($json) && ($method == 'GET' || $url == $this->auth->login_url)) {
            if (strpos($response['response'], "currently performing maintenance on your Nest account") !== FALSE) {
                throw new RuntimeException("Error: Account is under maintenance; API temporarily unavailable.", NESTAPI_ERROR_UNDER_MAINTENANCE);
            }
            if (empty($response['response'])) {
                throw new RuntimeException("Error: Received empty response from request to $url.", NESTAPI_ERROR_EMPTY_RESPONSE);
            }
            throw new RuntimeException("Error: Response from request to $url is not valid JSON data. Response: " . str_replace(array("\n","\r"), '', $response['response']), NESTAPI_ERROR_NOT_JSON_RESPONSE);
        }
    
        if ($response['info']['http_code'] == 400) {
            if (!is_object($json)) {
                throw new RuntimeException("Error: HTTP 400 from request to $url. Response: " . str_replace(array("\n","\r"), '', $response['response']), NESTAPI_ERROR_API_OTHER_ERROR);
            }
            throw new RuntimeException("Error: HTTP 400 from request to $url. JSON error: $json->error - $json->error_description", NESTAPI_ERROR_API_JSON_ERROR);
        }
    
        // No body returned; return a boolean value that confirms a 200 OK was returned.
        if ($response['info']['download_content_length'] == 0) {
            return $response['info']['http_code'] == 200;
        }
    
        return $json;
    }
}

?>