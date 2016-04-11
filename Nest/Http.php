<?php
namespace Nest;

class Http extends BaseHttp
{
    const USER_AGENT = 'Nest/2.1.3 CFNetwork/548.0.4';
    const PROTOCOL_VERSION = 1;

    /** @var Authentication */
    private $auth;

    public function __construct(Authentication $auth) {
        parent::__construct();
        if (empty($auth) || !($auth instanceof Authentication)) {
            throw new \InvalidArgumentException('No Nest\Authentication passed in.');
        }
        $this->auth = $auth;
        $this->setUserAgent(static::USER_AGENT);
        $this->addHeader('X-nl-protocol-version', static::PROTOCOL_VERSION);
    }

    protected function request($method, $url, $data_fields = NULL, $with_retry = TRUE) {
        if ($url[0] == '/') {
            $url = $this->auth->getTransportUrl() . $url;
        }

        if (!empty($this->auth->getUserId()) && strpos($url, '/weather/') === FALSE) {
            $this->addHeader('X-nl-user-id', $this->auth->getUserId());
            $this->addHeader('Authorization', ('Basic ' . $this->auth->getAccessToken()));
        }

        if (!empty($this->auth->getCookieFile()) && strpos($url, '/weather/') === FALSE) {
            $this->setCookieFile($this->auth->getCookieFile());
        }

        $response = parent::request($method, $url, $data_fields);

        if ($response['info']['http_code'] == 401 || (!$response['response'] && curl_errno($this->ch) != 0)) {
            if ($with_retry && $this->auth->isFromCache()) {
                // Received 401, and was using cached data; let's try to re-login and retry.
                $this->auth->logout();
                if ($response['info']['http_code'] == 401) {
                    $this->auth->login();
                }
                return self::request($method, $url, $data_fields, !$with_retry);
            } else {
                throw new \RuntimeException("Error: HTTP request to $url returned an error: " . curl_error($this->ch), curl_errno($this->ch));
            }
        }

        $json = json_decode($response['response']);
        if (!is_object($json) && ($method == 'GET' || $url == Authentication::LOGIN_URL)) {
            if (strpos($response['response'], "currently performing maintenance on your Nest account") !== FALSE) {
                throw new \RuntimeException("Error: Account is under maintenance; API temporarily unavailable.", Nest::NESTAPI_ERROR_UNDER_MAINTENANCE);
            }
            if (empty($response['response'])) {
                throw new \RuntimeException("Error: Received empty response from request to $url.", Nest::NESTAPI_ERROR_EMPTY_RESPONSE);
            }
            throw new \RuntimeException("Error: Response from request to $url is not valid JSON data. Response: " . str_replace(array("\n","\r"), '', $response['response']), Nest::NESTAPI_ERROR_NOT_JSON_RESPONSE);
        }

        if ($response['info']['http_code'] == 400) {
            if (!is_object($json)) {
                throw new \RuntimeException("Error: HTTP 400 from request to $url. Response: " . str_replace(array("\n","\r"), '', $response['response']), Nest::NESTAPI_ERROR_API_OTHER_ERROR);
            }
            throw new \RuntimeException("Error: HTTP 400 from request to $url. JSON error: $json->error - $json->error_description", Nest::NESTAPI_ERROR_API_JSON_ERROR);
        }

        // No body returned; return a boolean value that confirms a 200 OK was returned.
        if ($response['info']['download_content_length'] == 0) {
            return $response['info']['http_code'] == 200;
        }

        return $json;
    }
}
