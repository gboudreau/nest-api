<?php

require_once 'Nest/Constants.php';

use Nest\Http as Http;
use Nest\Authentication as Authentication;

class Nest {
    private $auth;
    private $http;
    private $days_maps = array('Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun');

    private $where_map = array(
        '00000000-0000-0000-0000-000100000000' => 'Entryway',
        '00000000-0000-0000-0000-000100000001' => 'Basement',
        '00000000-0000-0000-0000-000100000002' => 'Hallway',
        '00000000-0000-0000-0000-000100000003' => 'Den',
        '00000000-0000-0000-0000-000100000004' => 'Attic', // Invisible in web UI
        '00000000-0000-0000-0000-000100000005' => 'Master Bedroom',
        '00000000-0000-0000-0000-000100000006' => 'Downstairs',
        '00000000-0000-0000-0000-000100000007' => 'Garage', // Invisible in web UI
        '00000000-0000-0000-0000-000100000008' => 'Kids Room',
        '00000000-0000-0000-0000-000100000009' => 'Garage "Hallway"', // Invisible in web UI
        '00000000-0000-0000-0000-00010000000a' => 'Kitchen',
        '00000000-0000-0000-0000-00010000000b' => 'Family Room',
        '00000000-0000-0000-0000-00010000000c' => 'Living Room',
        '00000000-0000-0000-0000-00010000000d' => 'Bedroom',
        '00000000-0000-0000-0000-00010000000e' => 'Office',
        '00000000-0000-0000-0000-00010000000f' => 'Upstairs',
        '00000000-0000-0000-0000-000100000010' => 'Dining Room',
    );
    /*
    private $transport_url;
    private $access_token;
    private $user;
    private $userid;
    private $cookie_file;
    private $cache_file;
    private $cache_expiration;
    */
    private $last_status;
    
    function __construct($username=null, $password=null) {
        $this->auth = new Authentication($username,$password);
        $this->http = new Http($this->auth);
        
        $this->auth->login();
    }
    
    /* Getters and setters */

    public function getWeather($postal_code, $country_code=NULL) {
        try {
            $url = "https://home.nest.com/api/0.1/weather/forecast/$postal_code";
            if (!empty($country_code)) {
                $url .= ",$country_code";
            }
            $weather = $this->http->GET($url);
        } catch (RuntimeException $ex) {
            // NESTAPI_ERROR_NOT_JSON_RESPONSE is kinda normal. The forecast API will often return a '502 Bad Gateway' response... meh.
            if ($ex->getCode() != NESTAPI_ERROR_NOT_JSON_RESPONSE) {
                throw new RuntimeException("Unexpected issue fetching forecast.", $ex->getCode(), $ex);
            }
        }

        return (object) array(
            'outside_temperature' => isset($weather->now) ? $this->temperatureInUserScale((float) $weather->now->current_temperature) : NULL,
            'outside_humidity'    => isset($weather->now) ? $weather->now->current_humidity : NULL
        );
    }

    public function getUserLocations() {
        $this->getStatus();
        $structures = (array) $this->last_status->structure;
        $user_structures = array();
        $class_name = get_class($this);
        $topaz = isset($this->last_status->topaz) ? $this->last_status->topaz : array();
        foreach ($structures as $struct_id => $structure) {
            // Nest Protects at this location (structure)
            $protects = array();
            foreach ($topaz as $protect) {
                if ($protect->structure_id == $struct_id) {
                    $protects[] = $protect->serial_number;
                }
            }

            $weather_data = $this->getWeather($structure->postal_code, $structure->country_code);
            $user_structures[] = (object) array(
                'name' => isset($structure->name)?$structure->name:'',
                'address' => !empty($structure->street_address) ? $structure->street_address : NULL,
                'city' => $structure->location,
                'postal_code' => $structure->postal_code,
                'country' => $structure->country_code,
                'outside_temperature' => $weather_data->outside_temperature,
                'outside_humidity' => $weather_data->outside_humidity,
                'away' => $structure->away,
                'away_last_changed' => date(DATETIME_FORMAT, $structure->away_timestamp),
                'thermostats' => array_map(array($class_name, 'cleanDevices'), $structure->devices),
                'protects' => $protects,
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
        $topaz = isset($this->last_status->topaz) ? $this->last_status->topaz : array();
        foreach ($topaz as $protect) {
            if ($serial_number == $protect->serial_number) {
                // The specified device is a Nest Protect
                $infos = (object) array(
                    'co_status' => $protect->co_status == 0 ? "OK" : $protect->co_status,
                    'co_previous_peak' => isset($protect->co_previous_peak) ? $protect->co_previous_peak : null,
                    'co_sequence_number' => $protect->co_sequence_number,
                    'smoke_status' => $protect->smoke_status == 0 ? "OK" : $protect->smoke_status,
                    'smoke_sequence_number' => $protect->smoke_sequence_number,
                    'model' => $protect->model,
                    'software_version' => $protect->software_version,
                    'line_power_present' => $protect->line_power_present,
                    'battery_level' => $protect->battery_level,
                    'battery_health_state' => $protect->battery_health_state == 0 ? "OK" : $protect->battery_health_state,
                    'wired_or_battery' => isset($protect->wired_or_battery) ? $protect->wired_or_battery : null,
                    'born_on_date' => isset($protect->device_born_on_date_utc_secs) ? date(DATE_FORMAT, $protect->device_born_on_date_utc_secs) : null,
                    'replace_by_date' => date(DATE_FORMAT, $protect->replace_by_date_utc_secs),
                    'last_update' => date(DATETIME_FORMAT, $protect->{'$timestamp'}/1000),
                    'last_manual_test' => $protect->latest_manual_test_start_utc_secs == 0 ? NULL : date(DATETIME_FORMAT, $protect->latest_manual_test_start_utc_secs),
                    'ntp_green_led_brightness' => isset($protect->ntp_green_led_brightness) ? $protect->ntp_green_led_brightness : null,
                    'tests_passed' => array(
                        'led'       => $protect->component_led_test_passed,
                        'pir'       => $protect->component_pir_test_passed,
                        'temp'      => $protect->component_temp_test_passed,
                        'smoke'     => $protect->component_smoke_test_passed,
                        'heat'      => $protect->component_heat_test_passed,
                        'wifi'      => $protect->component_wifi_test_passed,
                        'als'       => $protect->component_als_test_passed,
                        'co'        => $protect->component_co_test_passed,
                        'us'        => $protect->component_us_test_passed,
                        'hum'       => $protect->component_hum_test_passed,
                        'speaker'   => isset($protect->component_speaker_test_passed) ? $protect->component_speaker_test_passed : null,
                        'buzzer'    => isset($protect->component_buzzer_test_passed) ? $protect->component_buzzer_test_passed : null,                        
                    ),
                    'nest_features' => array(
                        'night_time_promise' => !empty($protect->ntp_green_led_enable) ? $protect->ntp_green_led_enable : 0,
                        'night_light'        => !empty($protect->night_light_enable) ? $protect->night_light_enable : 0,
                        'auto_away'          => !empty($protect->auto_away) ? $protect->auto_away : 0,
                        'heads_up'           => !empty($protect->heads_up_enable) ? $protect->heads_up_enable : 0,
                        'steam_detection'    => !empty($protect->steam_detection_enable) ? $protect->steam_detection_enable : 0,
                        'home_alarm_link'    => !empty($protect->home_alarm_link_capable) ? $protect->home_alarm_link_capable : 0,
                        'wired_led_enable'   => !empty($protect->wired_led_enable) ? $protect->wired_led_enable : 0,                        
                    ),
                    'serial_number' => $protect->serial_number,
                    'location' => $protect->structure_id,
                    'network' => (object) array(
                        'online' => $protect->component_wifi_test_passed,
                        'local_ip' => $protect->wifi_ip_address,
                        'mac_address' => $protect->wifi_mac_address
                    ),
                    'name' => !empty($protect->description) ? $protect->description : DEVICE_WITH_NO_NAME,
                    'where' => isset($this->where_map[$protect->spoken_where_id]) ? $this->where_map[$protect->spoken_where_id] : $protect->spoken_where_id,
                    'color' => isset($protect->device_external_color) ? $protect->device_external_color : null,                    
                );
                return $infos;
            }
        }

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
                'auto_away' => $this->last_status->shared->{$serial_number}->auto_away, // -1 when disabled, 0 when enabled (thermostat can set auto-away), >0 when enabled and active (thermostat is currently in auto-away mode)
                'manual_away' => $manual_away,
                'leaf' => $this->last_status->device->{$serial_number}->leaf,
                'battery_level' => $this->last_status->device->{$serial_number}->battery_level,
            ),
            'target' => (object) array(
                'mode' => $target_mode,
                'temperature' => $target_temperatures,
                'time_to_target' => $this->last_status->device->{$serial_number}->time_to_target
            ),
            'serial_number' => $this->last_status->device->{$serial_number}->serial_number,
            'scale' => $this->last_status->device->{$serial_number}->temperature_scale,
            'location' => $structure,
            'network' => $this->getDeviceNetworkInfo($serial_number),
            'name' => !empty($this->last_status->shared->{$serial_number}->name) ? $this->last_status->shared->{$serial_number}->name : DEVICE_WITH_NO_NAME,
            'auto_cool' => ((int) $this->last_status->device->{$serial_number}->leaf_threshold_cool === 0) ? false : ceil($this->temperatureInUserScale((float) $this->last_status->device->{$serial_number}->leaf_threshold_cool)),
            'auto_heat' => ((int) $this->last_status->device->{$serial_number}->leaf_threshold_heat === 1000) ? false : floor($this->temperatureInUserScale((float) $this->last_status->device->{$serial_number}->leaf_threshold_heat)),
            'where' => isset($this->last_status->device->{$serial_number}->where_id) ? isset($this->where_map[$this->last_status->device->{$serial_number}->where_id]) ? $this->where_map[$this->last_status->device->{$serial_number}->where_id] : $this->last_status->device->{$serial_number}->where_id : ""
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
            'objects' => array(
                array('object_key' => "energy_latest.$serial_number")
            )
        );

        $url = '/v5/subscribe';
    
        return $this->http->POST($url, json_encode($payload));
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
            $set_temp_result = $this->http->POST("/v2/put/shared." . $serial_number, $data);
        } else if ($mode != TARGET_TEMP_MODE_OFF) {
            // heat or cool
            if (!is_numeric($temperature)) {
                echo "Error: when using TARGET_TEMP_MODE_HEAT or TARGET_TEMP_MODE_COLD, you need to set the target temperature (second argument of setTargetTemperatureMode) using an numeric value.\n";
                return FALSE;
            }
            $temperature = $this->temperatureInCelsius($temperature, $serial_number);
            $data = json_encode(array('target_change_pending' => TRUE, 'target_temperature' => $temperature));
            $set_temp_result = $this->http->POST("/v2/put/shared." . $serial_number, $data);
        }

        $data = json_encode(array('target_change_pending' => TRUE, 'target_temperature_type' => $mode));
        return $this->http->POST("/v2/put/shared." . $serial_number, $data);
    }

    public function setTargetTemperature($temperature, $serial_number=null) {
        $serial_number = $this->getDefaultSerial($serial_number);
        $temperature = $this->temperatureInCelsius($temperature, $serial_number);
        $data = json_encode(array('target_change_pending' => TRUE, 'target_temperature' => $temperature));
        return $this->http->POST("/v2/put/shared." . $serial_number, $data);
    }
    
    public function setTargetTemperatures($temp_low, $temp_high, $serial_number=null) {
        $serial_number = $this->getDefaultSerial($serial_number);
        $temp_low = $this->temperatureInCelsius($temp_low, $serial_number);
        $temp_high = $this->temperatureInCelsius($temp_high, $serial_number);
        $data = json_encode(array('target_change_pending' => TRUE, 'target_temperature_low' => $temp_low, 'target_temperature_high' => $temp_high));
        return $this->http->POST("/v2/put/shared." . $serial_number, $data);
    }

    public function setAwayTemperatures($temp_low, $temp_high, $serial_number=null) {
        $serial_number = $this->getDefaultSerial($serial_number);
        $temp_low = $this->temperatureInCelsius($temp_low, $serial_number);
        $temp_high = $this->temperatureInCelsius($temp_high, $serial_number);
        $data = array();
        if ($temp_low === FALSE || $temp_low < 4) {
            $data['away_temperature_low_enabled'] = FALSE;
        } else if ($temp_low != NULL) {
            $data['away_temperature_low_enabled'] = TRUE;
            $data['away_temperature_low'] = $temp_low;
        }
        if ($temp_high === FALSE || $temp_high > 32) {
            $data['away_temperature_high_enabled'] = FALSE;
        } else if ($temp_high != NULL) {
            $data['away_temperature_high_enabled'] = TRUE;
            $data['away_temperature_high'] = $temp_high;
        }
        $data = json_encode($data);
        return $this->http->POST("/v2/put/device." . $serial_number, $data);
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
        return $this->http->POST("/v2/put/device." . $serial_number, $data);
    }

    public function setFanEveryDaySchedule($start_hour, $end_hour, $serial_number=null) {
        $serial_number = $this->getDefaultSerial($serial_number);
        $data = json_encode(array('fan_duty_start_time' => $start_hour*3600, 'fan_duty_end_time' => $end_hour*3600));
        return $this->http->POST("/v2/put/device." . $serial_number, $data);
    }

    public function turnOff($serial_number=null) {
        return $this->setTargetTemperatureMode(TARGET_TEMP_MODE_OFF, $serial_number);
    }

    public function setAway($away, $serial_number=null) {
        $serial_number = $this->getDefaultSerial($serial_number);
        $data = json_encode(array('away' => $away, 'away_timestamp' => time(), 'away_setter' => 0));
        $structure_id = $this->getDeviceInfo($serial_number)->location;
        return $this->http->POST("/v2/put/structure." . $structure_id, $data);
    }
    
    public function setAutoAwayEnabled($enabled, $serial_number=null) {
        $serial_number = $this->getDefaultSerial($serial_number);
        $data = json_encode(array('auto_away_enable' => $enabled));
        return $this->http->POST("/v2/put/device." . $serial_number, $data);
    }

    public function setDualFuelBreakpoint($breakpoint, $serial_number=null) {
        $serial_number = $this->getDefaultSerial($serial_number);
        if (!is_string($breakpoint)) {
            $breakpoint = $this->temperatureInCelsius($breakpoint, $serial_number);
            $data = json_encode(array('dual_fuel_breakpoint_override' => 'none', 'dual_fuel_breakpoint' => $breakpoint));
        } else {
            $data = json_encode(array('dual_fuel_breakpoint_override' => $breakpoint));
        }
        return $this->http->POST("/v2/put/device." . $serial_number, $data);
    }

    public function enableHumidifier($enabled, $serial_number=null) {
        $serial_number = $this->getDefaultSerial($serial_number);
        $data = json_encode(array('target_humidity_enabled' => ((boolean)$enabled)));
        return $this->http->POST("/v2/put/device." . $serial_number, $data);
    }

    public function setHumidity($humidity, $serial_number=null) {
        $serial_number = $this->getDefaultSerial($serial_number);
        $data = json_encode(array('target_humidity' => ((double)$humidity)));
        return $this->http->POST("/v2/put/device." . $serial_number, $data);
    }

    /* Helper functions */

    public function getStatus($retry=TRUE) {
        $url = "/v3/mobile/" . $this->auth->getUser();
        $status = $this->http->GET($url);
        if (!is_object($status)) {
            throw new RuntimeException("Error: Couldn't get status from NEST API: $status");
        }
        if (@$status->cmd == 'REINIT_STATE') {
            if ($retry) {
                @unlink($this->cookie_file);
                @unlink($this->cache_file);
                $this->auth->login();
                return $this->getStatus(FALSE);
            }
            throw new RuntimeException("Error: HTTP request to $url returned cmd = REINIT_STATE. Retrying failed.");
        }
        $this->last_status = $status;
        $this->auth->saveCache();
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

    public function getDevices($type=DEVICE_TYPE_THERMOSTAT) {
        $this->prepareForGet();
        if ($type == DEVICE_TYPE_PROTECT) {
            $protects = array();
            $topaz = isset($this->last_status->topaz) ? $this->last_status->topaz : array();
            foreach ($topaz as $protect) {
                $protects[] = $protect->serial_number;
            }
            return $protects;
        }
        $structure = $this->last_status->user->{$this->auth->getUserId()}->structures[0];
        list(, $structure_id) = explode('.', $structure);
        $devices_serials = array();
        foreach ($this->last_status->structure->{$structure_id}->devices as $device) {
            list(, $device_serial) = explode('.', $device);
            $devices_serials[] = $device_serial;
        }
        return $devices_serials;
    }

    private function getDefaultSerial($serial_number) {
        if (empty($serial_number)) {
            $devices_serials = $this->getDevices();
            if (count($devices_serials) == 0) {
                $devices_serials = $this->getDevices(DEVICE_TYPE_PROTECT);
            }
            $serial_number = $devices_serials[0];
        }
        return $serial_number;
    }

    public function getDefaultDevice() {
        $serial_number = $this->getDefaultSerial(null);
        return $this->last_status->device->{$serial_number};
    }

    private function getDeviceNetworkInfo($serial_number=null) {
        $this->getStatus();
        $serial_number = $this->getDefaultSerial($serial_number);
        $connection_info = $this->last_status->track->{$serial_number};
        return (object) array(
            'online' => $connection_info->online,
            'last_connection' => date(DATETIME_FORMAT, $connection_info->last_connection/1000),
            'last_connection_UTC' => gmdate(DATETIME_FORMAT, $connection_info->last_connection/1000),
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
        return $this->http->POST("/v2/put/device." . $serial_number, json_encode($data));
    }

    private function prepareForGet() {
        if (!isset($this->last_status)) {
            $this->getStatus();
        }
    }
}
