<?php

define('DEBUG', FALSE);

define('TARGET_TEMP_MODE_COOL', 'cool');
define('TARGET_TEMP_MODE_HEAT', 'heat');
define('TARGET_TEMP_MODE_RANGE', 'range');
define('FAN_MODE_AUTO', 'auto');
define('FAN_MODE_ON', 'on');
define('AWAY_MODE_ON', TRUE);
define('AWAY_MODE_OFF', FALSE);
define('DUALFUEL_BREAKPOINT_ALWAYS_PRIMARY', 'always-primary');
define('DUALFUEL_BREAKPOINT_ALWAYS_ALT', 'always-alt');

class Nest {
	const user_agent = 'Nest/2.1.3 CFNetwork/548.0.4';
	const protocol_version = 1;
	const login_url = 'https://home.nest.com/user/login';
	
	private $transport_url;
	private $access_token;
	private $user;
	private $userid;
	private $cookie_file;
	private $cache_file;
	private $cache_expiration;
	private $last_status;
	
	function __construct() {
	    $this->cookie_file = sys_get_temp_dir() . '/nest_php_cookies';
	    $this->cache_file = sys_get_temp_dir() . '/nest_php_cache';
	    if (file_exists($this->cache_file)) {
	        $this->loadCache();
	    }
		// Log in, if needed
	    $this->login();
	}
	
	/* Getters and setters */

	public function getUserLocations() {
		$this->getStatus();
		$structures = (array) $this->last_status->structure;
		$user_structures = array();
		foreach ($structures as $structure) {
			$weather = $this->doGET("https://home.nest.com/api/0.1/weather/forecast/" . $structure->postal_code);
			$user_structures[] = (object) array(
				'name' => $structure->name,
				'address' => $structure->street_address,
				'city' => $structure->location,
				'postal_code' => $structure->postal_code,
				'country' => $structure->country_code,
				'outside_temperature' => (float) $weather->now->current_temperature,
				'away' => $structure->away,
				'away_last_changed' => date('Y-m-d H:i:s', $structure->away_timestamp),
				'thermostats' => array_map(array('Nest', 'cleanDevices'), $structure->devices)
			);
		}
		return $user_structures;
	}

	public function getDeviceInfo($serial_number=null) {
		$this->getStatus();
	    $serial_number = $this->getDefaultSerial($serial_number);
		list(, $structure) = explode('.', $this->last_status->link->{$serial_number}->structure);
		$manual_away = $this->last_status->structure->{$structure}->away;
		$mode = strtolower($this->last_status->device->{$serial_number}->current_schedule_mode);
		$target_mode = $this->last_status->shared->{$serial_number}->target_temperature_type;
		if ($manual_away || $mode == 'away' || $this->last_status->shared->{$serial_number}->auto_away !== 0) {
			$mode = $mode . ',away';
			$target_mode = 'range';
			$target_temperatures = array($this->last_status->device->{$serial_number}->away_temperature_low, $this->last_status->device->{$serial_number}->away_temperature_high);
		} else if ($mode == 'range') {
			$target_mode = 'range';
			$target_temperatures = array($this->last_status->shared->{$serial_number}->target_temperature_low, $this->last_status->shared->{$serial_number}->target_temperature_high);
		} else {
			$target_temperatures = $this->last_status->shared->{$serial_number}->target_temperature;
		}
		$infos = (object) array(
			'current_state' => (object) array(
				'mode' => $mode,
				'temperature' => $this->last_status->shared->{$serial_number}->current_temperature,
				'humidity' => $this->last_status->device->{$serial_number}->current_humidity,
				'ac' => $this->last_status->shared->{$serial_number}->hvac_ac_state,
				'heat' => $this->last_status->shared->{$serial_number}->hvac_heater_state,
				'alt_heat' => $this->last_status->shared->{$serial_number}->hvac_alt_heat_state,
				'fan' => $this->last_status->shared->{$serial_number}->hvac_fan_state,
				'auto_away' => $this->last_status->shared->{$serial_number}->auto_away,
				'manual_away' => $manual_away,
				'leaf' => $this->last_status->device->{$serial_number}->leaf,
			),
			'target' => (object) array(
				'mode' => $target_mode,
				'temperature' => $target_temperatures,
				'time_to_target' => $this->last_status->device->{$serial_number}->time_to_target
			),
			'serial_number' => $this->last_status->device->{$serial_number}->serial_number,
			'scale' => $this->last_status->device->{$serial_number}->temperature_scale,
			'location' => $structure,
			'network' => $this->getDeviceNetworkInfo($serial_number)
		);

		return $infos;
	}

	private function getDeviceNetworkInfo($serial_number=null) {
		$this->getStatus();
	    $serial_number = $this->getDefaultSerial($serial_number);
		$connection_info = $this->last_status->track->{$serial_number};
		return (object) array(
			'online' => $connection_info->online,
			'last_connection' => date('Y-m-d H:i:s', $connection_info->last_connection/1000),
			'wan_ip' => $connection_info->last_ip,
			'local_ip' => $this->last_status->device->{$serial_number}->local_ip,
			'mac_address' => $this->last_status->device->{$serial_number}->mac_address
		);
	}

	public function setTargetTemperatureMode($mode, $serial_number=null) {
	    $serial_number = $this->getDefaultSerial($serial_number);
	    $data = json_encode(array('target_change_pending' => TRUE, 'target_temperature_type' => $mode));
	    return $this->doPOST("/v2/put/shared." . $serial_number, $data);
	}

	public function setTargetTemperature($temperature, $serial_number=null) {
	    $serial_number = $this->getDefaultSerial($serial_number);
	    $temperature = $this->temperatureInCelsius($temperature, $serial_number);
	    $data = json_encode(array('target_change_pending' => TRUE, 'target_temperature' => $temperature));
	    return $this->doPOST("/v2/put/shared." . $serial_number, $data);
	}
	
	public function setTargetTemperatures($temp_low, $temp_high, $serial_number=null) {
	    $serial_number = $this->getDefaultSerial($serial_number);
	    $temp_low = $this->temperatureInCelsius($temp_low, $serial_number);
		$temp_high = $this->temperatureInCelsius($temp_high, $serial_number);
	    $data = json_encode(array('target_change_pending' => TRUE, 'target_temperature_low' => $temp_low, 'target_temperature_high' => $temp_high));
	    return $this->doPOST("/v2/put/shared." . $serial_number, $data);
	}

	public function setFanMode($mode, $serial_number=null) {
	    $serial_number = $this->getDefaultSerial($serial_number);
	    $data = json_encode(array('fan_mode' => $mode));
	    return $this->doPOST("/v2/put/device." . $serial_number, $data);
	}

	public function turnOff($serial_number=null) {
		return $this->setTargetTemperatureMode('off', $serial_number);
	}

	public function setAway($away, $serial_number=null) {
	    $serial_number = $this->getDefaultSerial($serial_number);
	    $data = json_encode(array('away' => $away, 'away_timestamp' => time(), 'away_setter' => 0));
		$structure_id = $this->getDeviceInfo($serial_number)->location;
	    return $this->doPOST("/v2/put/structure." . $structure_id, $data);
	}
	
	public function setDualFuelBreakpoint($breakpoint, $serial_number=null) {
	    $serial_number = $this->getDefaultSerial($serial_number);
		if (!is_string($breakpoint)) {
		    $breakpoint = $this->temperatureInCelsius($breakpoint, $serial_number);
		    $data = json_encode(array('dual_fuel_breakpoint_override' => 'none', 'dual_fuel_breakpoint' => $breakpoint));
		} else {
			$data = json_encode(array('dual_fuel_breakpoint_override' => $breakpoint));
		}
	    return $this->doPOST("/v2/put/device." . $serial_number, $data);
	}

	/* Helper functions */

	public function getStatus() {
	    $status = $this->doGET("/v2/mobile/" . $this->user);
	    if (!is_object($status)) {
            die("Couldn't get status from NEST API: $status\n");
	    }
	    $this->last_status = $status;
	    $this->saveCache();
	    return $status;
	}

	public static function cleanDevices($device) {
		list(, $device_id) = explode('.', $device);
		return $device_id;
	}

	public function temperatureInCelsius($temperature, $serial_number=null) {
	    $serial_number = $this->getDefaultSerial($serial_number);
	    $temp_scale = $this->getDeviceTemperatureScale($serial_number);
	    if ($temp_scale == 'F') {
            return ($temperature - 32) / 1.8;
        }
        return $temperature;
	}

	public function getDeviceTemperatureScale($serial_number=null) {
	    $serial_number = $this->getDefaultSerial($serial_number);
	    return $this->last_status->device->{$serial_number}->temperature_scale;
    }

	public function getDefaultDevice() {
	    $this->prepareForGet();
	    $structure = $this->last_status->user->{$this->userid}->structures[0];
	    list(, $structure_id) = explode('.', $structure);
        $device = $this->last_status->structure->{$structure_id}->devices[0];
        list(, $device_serial) = explode('.', $device);
	    return $this->last_status->device->{$device_serial};
	}

	private function getDefaultSerial($serial_number) {
	    $this->prepareForGet();
        if (empty($serial_number)) {
            $serial_number = $this->getDefaultDevice()->serial_number;
        }
        return $serial_number;
	}

	private function prepareForGet() {
	    if (!isset($this->last_status)) {
	        $this->getStatus();
	    }
	}

	private function login() {
	    if ($this->use_cache()) {
	        // No need to login; we'll use cached values for authentication.
	        return;
	    }
	    $result = $this->doPOST(self::login_url, array('username' => USERNAME, 'password' => PASSWORD));
		if (!isset($result->urls)) {
			die("Error: response to login request doesn't contain required transport URL. Response: '" . var_export($result, TRUE) . "'\n");
		}
	    $this->transport_url = $result->urls->transport_url;
	    $this->access_token = $result->access_token;
	    $this->userid = $result->userid;
	    $this->user = $result->user;
	    $this->cache_expiration = strtotime($result->expires_in);
        $this->saveCache();
	}

    private function use_cache() {
        return file_exists($this->cookie_file) && file_exists($this->cache_file) && !empty($this->cache_expiration) && $this->cache_expiration > time();
    }
	
	private function loadCache() {
        $vars = unserialize(file_get_contents($this->cache_file));
        $this->transport_url = $vars['transport_url'];
        $this->access_token = $vars['access_token'];
        $this->user = $vars['user'];
        $this->userid = $vars['userid'];
        $this->cache_expiration = $vars['cache_expiration'];
        $this->last_status = $vars['last_status'];
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

	private function doGET($url) {
	    return $this->doRequest('GET', $url);
	}
	
	private function doPOST($url, $data_fields) {
	    return $this->doRequest('POST', $url, $data_fields);
	}

    private function doRequest($method, $url, $data_fields=null, $with_retry=TRUE) {
	    $ch = curl_init();
	    if ($url[0] == '/') {
	        $url = $this->transport_url . $url;
	    }
	    $headers = array('X-nl-protocol-version: ' . self::protocol_version);
	    if (isset($this->userid)) {
	        $headers[] = 'X-nl-user-id: ' . $this->userid;
	        $headers[] = 'Authorization: Basic ' . $this->access_token;
	    }
	    if (is_array($data_fields)) {
	        $data = array();
    	    foreach($data_fields as $k => $v) {
    	        $data[] = "$k=" . urlencode($v);
    	    }
    	    $data = implode('&', $data);
	    } else if (is_string($data_fields)) {
	        $data = $data_fields;
	    }
	    curl_setopt($ch, CURLOPT_URL, $url);
	    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
	    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
	    curl_setopt($ch, CURLOPT_HEADER, FALSE);
	    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
	    curl_setopt($ch, CURLOPT_AUTOREFERER, TRUE);
	    curl_setopt($ch, CURLOPT_USERAGENT, self::user_agent); 
	    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); 
	    curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookie_file);
	    curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookie_file);
	    if ($method == 'POST') {
	        curl_setopt($ch, CURLOPT_POST, TRUE);
	        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
        if (DEBUG) {
            curl_setopt($ch, CURLOPT_PROXY, '127.0.0.1:8888');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        }
	    $response = curl_exec($ch);
	    $info = curl_getinfo($ch);
	    
	    if (($info['http_code'] == 401 || !$response) && $this->use_cache()) {
	        if ($with_retry) {
	            // Received 401, and was using cached data; let's try to re-login and retry.
	            @unlink($this->cookie_file);
	            @unlink($this->cache_file);
                if ($info['http_code'] == 401) {
	                $this->login();
                }
	            return $this->doRequest($method, $url, $data_fields, FALSE);
            } else {
                return "Error with request to $url: " . curl_error($ch);
            }
	    }
	    
        $json = json_decode($response);

		if ($json === NULL) {
			die("Error: Response from request to $url is not valid JSON data. Response: '$response'\n");
		}

	    if ($info['http_code'] == 400) {
	        die("$json->error: $json->error_description\n");
        }

		// No body returned; return a boolean value that confirms a 200 OK was returned.
		if ($info['download_content_length'] == 0) {
			return $info['http_code'] == 200;
		}

		return $json;
    }
}
?>
