<?php
namespace Nest;

class BaseHttp {
    private $headers = array();
    private $certificateAuthorityInfo;
    protected $cookie_file;
    protected $ch;
    protected $user_agent;
    
    public function __construct($value=''){
        $this->certificateAuthorityInfo = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cacert.pem';
        $this->updateCAInfo();
        $this->ch = curl_init();
    }
    
        private function GET($url) {
        return $this->request('GET', $url);
    }
    
    private function POST($url, $data_fields) {
        return $this->request('POST', $url, $data_fields);
    }

    public function addHeader($header, $value){
        $this->headers[] = $header . ': ' . $value;
    }
    
    public function setCookieFile($cookie_file){
        $this->cookieFile = $cookie_file;
    }
    
    public function setUserAgent($user_agent){
        $this->user_agent = $user_agent;
    }

    private function request($method, $url, $data_fields=null, $with_retry=TRUE) {
        if (is_array($data_fields)) {
            $data = array();
            foreach($data_fields as $k => $v) {
                $data[] = "$k=" . urlencode($v);
            }
            $data = implode('&', $data);
        } else if (is_string($data_fields)) {
            $data = $data_fields;
        }
        curl_setopt($this-ch, CURLOPT_URL, $url);
        curl_setopt($this-ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($this-ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($this-ch, CURLOPT_HEADER, FALSE);
        curl_setopt($this-ch, CURLOPT_FOLLOWLOCATION, TRUE);
        curl_setopt($this-ch, CURLOPT_AUTOREFERER, TRUE);
        curl_setopt($this-ch, CURLOPT_USERAGENT, $this->user_agent); 
        curl_setopt($this-ch, CURLOPT_COOKIEJAR, $this->cookie_file);
        curl_setopt($this-ch, CURLOPT_COOKIEFILE, $this->cookie_file);
        if ($method == 'POST') {
            curl_setopt($this-ch, CURLOPT_POST, TRUE);
            curl_setopt($this-ch, CURLOPT_POSTFIELDS, $data);
            $this->headers[] = 'Content-length: ' . strlen($data);
        }
        curl_setopt($this-ch, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($this-ch, CURLOPT_SSL_VERIFYPEER, TRUE); // for security this should always be set to true.
        curl_setopt($this-ch, CURLOPT_SSL_VERIFYHOST, 2);    // for security this should always be set to 2.
        curl_setopt($this-ch, CURLOPT_SSLVERSION, 1);        // Nest servers now require TLSv1; won't work with SSLv2 or even SSLv3!
        
        if (file_exists($this->certificateAuthorityInfo) && filesize($this->certificateAuthorityInfo) > 100000) {
            curl_setopt($this-ch, CURLOPT_CAINFO, $this->certificateAuthorityInfo);
        }
        $response = curl_exec($this-ch);
        $info = curl_getinfo($this-ch);
        
        return array(
            'response' => $response,
            'info' => $info
        );
    }
    
    private function updateCAInfo(){
        // Update cacert.pem (valid CA certificates list) from the cURL website once a month
        $last_month = time()-30*24*60*60;
        if (!file_exists($this->certificateAuthorityInfo) || filemtime($this->certificateAuthorityInfo) < $last_month || filesize($this->certificateAuthorityInfo) < 100000) {
            file_put_contents($this->certificateAuthorityInfo, file_get_contents('https://curl.haxx.se/ca/cacert.pem'));
        }
    }
}

?>