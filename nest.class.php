<?php

define('DEBUG', FALSE);

define('TARGET_TEMP_MODE_COOL', 'cool');
define('TARGET_TEMP_MODE_HEAT', 'heat');
define('TARGET_TEMP_MODE_RANGE', 'range');
define('TARGET_TEMP_MODE_OFF', 'off');
define('FAN_MODE_AUTO', 'auto');
define('FAN_MODE_ON', 'on');
define('FAN_MODE_EVERY_DAY_ON', 'on');
define('FAN_MODE_EVERY_DAY_OFF', 'auto');
define('FAN_MODE_MINUTES_PER_HOUR', 'duty-cycle');
define('FAN_MODE_MINUTES_PER_HOUR_15', FAN_MODE_MINUTES_PER_HOUR . ',900');
define('FAN_MODE_MINUTES_PER_HOUR_30', FAN_MODE_MINUTES_PER_HOUR . ',1800');
define('FAN_MODE_MINUTES_PER_HOUR_45', FAN_MODE_MINUTES_PER_HOUR . ',2700');
define('FAN_MODE_MINUTES_PER_HOUR_ALWAYS_ON', 'on,3600');
define('FAN_MODE_TIMER', '');
define('FAN_TIMER_15M', ',900');
define('FAN_TIMER_30M', ',1800');
define('FAN_TIMER_45M', ',2700');
define('FAN_TIMER_1H', ',3600');
define('FAN_TIMER_2H', ',7200');
define('FAN_TIMER_4H', ',14400');
define('FAN_TIMER_8H', ',28800');
define('FAN_TIMER_12H', ',43200');
define('AWAY_MODE_ON', TRUE);
define('AWAY_MODE_OFF', FALSE);
define('DUALFUEL_BREAKPOINT_ALWAYS_PRIMARY', 'always-primary');
define('DUALFUEL_BREAKPOINT_ALWAYS_ALT', 'always-alt');

class Nest {
    const user_agent = 'Nest/2.1.3 CFNetwork/548.0.4';
    const protocol_version = 1;
    const login_url = 'https://home.nest.com/user/login';
    private $days_maps = array('Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun');
    
    private $transport_url;
    private $access_token;
    private $user;
    private $userid;
    private $cookie_file;
    private $cache_file;
    private $cache_expiration;
    private $last_status;
    
    function __construct() {
        $this->cookie_file = sys_get_temp_dir() . '/nest_php_cookies_' . md5(USERNAME . PASSWORD);
        $this->cache_file = sys_get_temp_dir() . '/nest_php_cache_' . md5(USERNAME . PASSWORD);
        if ($this->use_cache()) {
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
                'address' => !empty($structure->street_address) ? $structure->street_address : NULL,
                'city' => $structure->location,
                'postal_code' => $structure->postal_code,
                'country' => $structure->country_code,
                'outside_temperature' => isset($weather->now) ? $this->temperatureInUserScale((float) $weather->now->current_temperature) : NULL,
                'away' => $structure->away,
                'away_last_changed' => date('Y-m-d H:i:s', $structure->away_timestamp),
                'thermostats' => array_map(array('Nest', 'cleanDevices'), $structure->devices)
            );
        }
        return $user_structures;
    }

    public function getDeviceSchedule($serial_number=null) {
        $this->getStatus();
        $serial_number = $this->getDefaultSerial($serial_number);
        $schedule_days = $this->last_status->schedule->{$serial_number}->days;

        $schedule = array();
        foreach ((array)$schedule_days as $day => $scheduled_events) {
            $events = array();
            foreach ($scheduled_events as $scheduled_event) {
                if ($scheduled_event->entry_type == 'setpoint') {
                    $events[(int)$scheduled_event->time] = (object) array(
                       'time' => $scheduled_event->time/60, // in minutes
                       'target_temperature' => $scheduled_event->type == 'RANGE' ? array($this->temperatureInUserScale((float)$scheduled_event->{'temp-min'}), $this->temperatureInUserScale((float)$scheduled_event->{'temp-max'})) : $this->temperatureInUserScale((float) $scheduled_event->temp),
                       'mode' => $scheduled_event->type == 'HEAT' ? TARGET_TEMP_MODE_HEAT : ($scheduled_event->type == 'COOL' ? TARGET_TEMP_MODE_COOL : TARGET_TEMP_MODE_RANGE)
                    );
                }
            }
            if (!empty($events)) {
                ksort($events);
                $schedule[(int) $day] = array_values($events);
            }
        }
        
        ksort($schedule);
        $sorted_schedule = array();
        foreach ($schedule as $day => $events) {
            $sorted_schedule[$this->days_maps[(int) $day]] = $events;
        }
        
        return $sorted_schedule;
    }
    
    public function getNextScheduledEvent($serial_number=null) {
        $schedule = $this->getDeviceSchedule($serial_number);
        $next_event = FALSE;
        $time = date('H') * 60 + date('i');
        for ($i = 0, $day = date('D'); $i++ < 7; $day = date('D', strtotime("+ $i days"))) {
            if (isset($schedule[$day])) {
                foreach ($schedule[$day] as $event) {
                    if ($event->time > $time) {
                        return $event;
                    }
                }
            }
            $time = 0;
        }
        return $next_event;
    }

    public function getDeviceInfo($serial_number=null) {
        $this->getStatus();
        $serial_number = $this->getDefaultSerial($serial_number);
        list(, $structure) = explode('.', $this->last_status->link->{$serial_number}->structure);
        $manual_away = $this->last_status->structure->{$structure}->away;
        $mode = strtolower($this->last_status->device->{$serial_number}->current_schedule_mode);
        $target_mode = $this->last_status->shared->{$serial_number}->target_temperature_type;
        if ($manual_away || $mode == 'away' || $this->last_status->shared->{$serial_number}->auto_away > 0) {
            $mode = $mode . ',away';
            $target_mode = 'range';
            $target_temperatures = array($this->temperatureInUserScale((float) $this->last_status->device->{$serial_number}->away_temperature_low), $this->temperatureInUserScale((float) $this->last_status->device->{$serial_number}->away_temperature_high));
        } else if ($mode == 'range') {
            $target_mode = 'range';
            $target_temperatures = array($this->temperatureInUserScale((float) $this->last_status->shared->{$serial_number}->target_temperature_low), $this->temperatureInUserScale((float) $this->last_status->shared->{$serial_number}->target_temperature_high));
        } else {
            $target_temperatures = $this->temperatureInUserScale((float) $this->last_status->shared->{$serial_number}->target_temperature);
        }
        $infos = (object) array(
            'current_state' => (object) array(
                'mode' => $mode,
                'temperature' => $this->temperatureInUserScale((float) $this->last_status->shared->{$serial_number}->current_temperature),
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
        if($this->last_status->device->{$serial_number}->has_humidifier) {
          $infos->current_state->humidifier= $this->last_status->device->{$serial_number}->humidifier_state;
          $infos->target->humidity = $this->last_status->device->{$serial_number}->target_humidity;
          $infos->target->humidity_enabled = $this->last_status->device->{$serial_number}->target_humidity_enabled;
        }

        return $infos;
    }
  
    public function getEnergyLatest($serial_number=null) {
        $serial_number = $this->getDefaultSerial($serial_number);

        $payload = array(
            'keys' => array(
                array('key' => "energy_latest.$serial_number")
            )
        );

        $data = array(
            'jsonp=jsonp',
            'payload=' . urlencode(json_encode($payload)),
            '_method=POST',
        );
    
        $url = '/v2/subscribe?' . (implode('&', $data));
    
        return $this->doGET($url);
    }

    public function setTargetTemperatureMode($mode, $temperature, $serial_number=null) {
        $serial_number = $this->getDefaultSerial($serial_number);

        if ($mode == TARGET_TEMP_MODE_RANGE) {
            if (!is_array($temperature) || count($temperature) != 2 || !is_numeric($temperature[0]) || !is_numeric($temperature[1])) {
                echo "Error: when using TARGET_TEMP_MODE_RANGE, you need to set the target temperatures (second argument of setTargetTemperatureMode) using an array of two numeric values.\n";
                return FALSE;
            }
            $temp_low = $this->temperatureInCelsius($temperature[0], $serial_number);
            $temp_high = $this->temperatureInCelsius($temperature[1], $serial_number);
            $data = json_encode(array('target_change_pending' => TRUE, 'target_temperature_low' => $temp_low, 'target_temperature_high' => $temp_high));
            $set_temp_result = $this->doPOST("/v2/put/shared." . $serial_number, $data);
        } else if ($mode != TARGET_TEMP_MODE_OFF) {
            // heat or cool
            if (!is_numeric($temperature)) {
                echo "Error: when using TARGET_TEMP_MODE_HEAT or TARGET_TEMP_MODE_COLD, you need to set the target temperature (second argument of setTargetTemperatureMode) using an numeric value.\n";
                return FALSE;
            }
            $temperature = $this->temperatureInCelsius($temperature, $serial_number);
            $data = json_encode(array('target_change_pending' => TRUE, 'target_temperature' => $temperature));
            $set_temp_result = $this->doPOST("/v2/put/shared." . $serial_number, $data);
        }

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
        $duty_cycle = null;
        $timer = null;
        if (is_array($mode)) {
            $modes = $mode;
            $mode = $modes[0];
            if (count($modes) > 1) {
                if ($mode == FAN_MODE_MINUTES_PER_HOUR) {
                    $duty_cycle = (int) $modes[1];
                } else {
                    $timer = (int) $modes[1];
                }
            } else {
                throw new Exception("setFanMode(array \$mode[, ...]) needs at least a mode and a value in the \$mode array.");
            }
        } else if (!is_string($mode)) {
            throw new Exception("setFanMode() can only take a string or an array as it's first parameter.");
        }
        return $this->_setFanMode($mode, $duty_cycle, $timer, $serial_number);
    }

    public function setFanModeMinutesPerHour($mode, $serial_number=null) {
        $modes = explode(',', $mode);
        $mode = $modes[0];
        $duty_cycle = $modes[1];
        return $this->_setFanMode($mode, $duty_cycle, null, $serial_number);
    }

    public function setFanModeOnWithTimer($mode, $serial_number=null) {
        $modes = explode(',', $mode);
        $mode = $modes[0];
        $timer = (int) $modes[1];
        return $this->_setFanMode($mode, null, $timer, $serial_number);
    }

    public function cancelFanModeOnWithTimer($serial_number=null) {
        $serial_number = $this->getDefaultSerial($serial_number);
        $data = json_encode(array('fan_timer_timeout' => 0));
        return $this->doPOST("/v2/put/device." . $serial_number, $data);
    }

    public function setFanEveryDaySchedule($start_hour, $end_hour, $serial_number=null) {
        $serial_number = $this->getDefaultSerial($serial_number);
        $data = json_encode(array('fan_duty_start_time' => $start_hour*3600, 'fan_duty_end_time' => $end_hour*3600));
        return $this->doPOST("/v2/put/device." . $serial_number, $data);
    }

    public function turnOff($serial_number=null) {
        return $this->setTargetTemperatureMode(TARGET_TEMP_MODE_OFF, $serial_number);
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

    public function setHumidity($humidity, $serial_number=null) {
        $serial_number = $this->getDefaultSerial($serial_number);
        $data = json_encode(array('target_humidity' => ((double)$humidity)));
        return $this->doPOST("/v2/put/device." . $serial_number, $data);
    }

    /* Helper functions */

    public function getStatus() {
        $status = $this->doGET("/v2/mobile/" . $this->user);
        if (!is_object($status)) {
            die("Error: Couldn't get status from NEST API: $status\n");
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

    public function temperatureInUserScale($temperature_in_celsius, $serial_number=null) {
        $serial_number = $this->getDefaultSerial($serial_number);
        $temp_scale = $this->getDeviceTemperatureScale($serial_number);
        if ($temp_scale == 'F') {
            return ($temperature_in_celsius * 1.8) + 32;
        }
        return $temperature_in_celsius;
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

    private function getDeviceNetworkInfo($serial_number=null) {
        $this->getStatus();
        $serial_number = $this->getDefaultSerial($serial_number);
        $connection_info = $this->last_status->track->{$serial_number};
        return (object) array(
            'online' => $connection_info->online,
            'last_connection' => date('Y-m-d H:i:s', $connection_info->last_connection/1000),
            'wan_ip' => @$connection_info->last_ip,
            'local_ip' => $this->last_status->device->{$serial_number}->local_ip,
            'mac_address' => $this->last_status->device->{$serial_number}->mac_address
        );
    }

    private function _setFanMode($mode, $fan_duty_cycle=null, $timer=null, $serial_number=null) {
        $serial_number = $this->getDefaultSerial($serial_number);
        $data = array();
        if (!empty($mode)) {
            $data['fan_mode'] = $mode;
        }
        if (!empty($fan_duty_cycle)) {
            $data['fan_duty_cycle'] = (int) $fan_duty_cycle;
        }
        if (!empty($timer)) {
            $data['fan_timer_duration'] = $timer;
            $data['fan_timer_timeout'] = time() + $timer;
        }
        return $this->doPOST("/v2/put/device." . $serial_number, json_encode($data));
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
            die("Error: Response to login request doesn't contain required transport URL. Response: '" . var_export($result, TRUE) . "'\n");
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
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookie_file);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookie_file);
        if ($method == 'POST') {
            curl_setopt($ch, CURLOPT_POST, TRUE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            $headers[] = 'Content-length: ' . strlen($data);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if (DEBUG) {
            curl_setopt($ch, CURLOPT_PROXY, '127.0.0.1:8888');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, TRUE); // for security this should always be set to true.
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);    // for security this should always be set to 2.

            // Update cacert.pem (valid CA certificates list) from the cURL website once a month
            $curl_cainfo = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cacert.pem';
            $last_month = time()-30*24*60*60;
            if (!file_exists($curl_cainfo) || filemtime($curl_cainfo) < $last_month) {
                file_put_contents($curl_cainfo, file_get_contents('http://curl.haxx.se/ca/cacert.pem'));
            }
            if (file_exists($curl_cainfo)) {
                curl_setopt($ch, CURLOPT_CAINFO, $curl_cainfo);
            }
        }
        $response = curl_exec($ch);
        $info = curl_getinfo($ch);
        
        if ($info['http_code'] == 401 || (!$response && curl_errno($ch) != 0)) {
            if ($with_retry && $this->use_cache()) {
                // Received 401, and was using cached data; let's try to re-login and retry.
                @unlink($this->cookie_file);
                @unlink($this->cache_file);
                if ($info['http_code'] == 401) {
                    $this->login();
                }
                return $this->doRequest($method, $url, $data_fields, !$with_retry);
            } else {
                return "Error: HTTP request to $url returned an error: " . curl_error($ch);
            }
        }
        
        if (strpos($response, 'jsonp(') === 0) {
            $response = substr($response, 6, strlen($response)-7);
        }
        $json = json_decode($response);

        if (!is_object($json) && ($method == 'GET' || $url == self::login_url)) {
            if (strpos($response, "currently performing maintenance on your Nest account") !== FALSE) {
                die("Error: Account is under maintenance; API temporarily unavailable.\n");
            }
            if (empty($response)) {
                die("Error: Received empty response from request to $url.\n");
            }
            die("Error: Response from request to $url is not valid JSON data. Response: " . str_replace(array("\n","\r"), '', $response) . "\n");
        }

        if ($info['http_code'] == 400) {
            if (!is_object($json)) {
                die("Error: HTTP 400 from request to $url. Response: " . str_replace(array("\n","\r"), '', $response) . "\n");
            }
            die("Error: HTTP 400 from request to $url. JSON error: $json->error - $json->error_description\n");
        }

        // No body returned; return a boolean value that confirms a 200 OK was returned.
        if ($info['download_content_length'] == 0) {
            return $info['http_code'] == 200;
        }

        return $json;
    }
}
