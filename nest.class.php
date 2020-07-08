<?php

defined('DATE_FORMAT') OR define('DATE_FORMAT', 'Y-m-d');
defined('DATETIME_FORMAT') OR define('DATETIME_FORMAT', DATE_FORMAT . ' H:i:s');
defined('USE_STATUS_BUCKETS') OR define('USE_STATUS_BUCKETS', FALSE);
define('TARGET_TEMP_MODE_COOL', 'cool');
define('TARGET_TEMP_MODE_HEAT', 'heat');
define('TARGET_TEMP_MODE_RANGE', 'range');
define('TARGET_TEMP_MODE_OFF', 'off');
define('ECO_MODE_MANUAL', 'manual-eco');
define('ECO_MODE_SCHEDULE', 'schedule');
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
define('DEVICE_WITH_NO_NAME', 'Not Set');
define('DEVICE_TYPE_THERMOSTAT', 'thermostat');
define('DEVICE_TYPE_PROTECT', 'protect');
define('DEVICE_TYPE_SENSOR', 'sensor');

define('NESTAPI_ERROR_UNDER_MAINTENANCE', 1000);
define('NESTAPI_ERROR_EMPTY_RESPONSE', 1001);
define('NESTAPI_ERROR_NOT_JSON_RESPONSE', 1002);
define('NESTAPI_ERROR_API_JSON_ERROR', 1003);
define('NESTAPI_ERROR_API_OTHER_ERROR', 1004);

/**
 * Unofficial Nest API
 *
 * This is an unofficial PHP class that will allow you to monitor and control your Nest Learning Thermostat, and Nest Protect.
 *
 * @category Algorithm
 * @package  PommePause\Nest\API
 * @author   Guillaume Boudreau <guillaume@pommepause.com>
 * @license  GNU LESSER GENERAL PUBLIC LICENSE Version 3
 * @link     https://github.com/gboudreau/nest-api/
 * @link     https://nest.com/
 */
class Nest
{
    const USER_AGENT = 'Nest/5.0.0.23 (iOScom.nestlabs.jasper.release) os=11.0';
    const PROTOCOL_VERSION = 1;
    const LOGIN_URL = 'https://home.nest.com/session';

    protected $days_maps = array('Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun');

    protected $transport_url;
    protected $access_token;
    protected $user;
    protected $userid;
    protected $cookie_file;
    protected $cache_file;
    protected $cache_expiration;
    protected $last_status;

    /**
     * Constructor
     *
     * @param string|null $username    Your Nest username.
     * @param string|null $password    Your Nest password.
     * @param string|null $issue_token Issue-token URL
     * @param string|null $cookies     Google cookies
     *
     * @throws InvalidArgumentException|UnexpectedValueException|RuntimeException
     */
    public function __construct($username = NULL, $password = NULL, $issue_token = NULL, $cookies = NULL) {
        if ($issue_token === NULL && defined('ISSUE_TOKEN')) {
            $issue_token = constant('ISSUE_TOKEN');
        }
        if ($cookies === NULL && defined('COOKIES')) {
            $cookies = constant('COOKIES');
        }
        if (!empty($issue_token)) {
            $this->issue_token = $issue_token;
            if (empty($cookies)) {
                throw new InvalidArgumentException('Google login requires issue_token and cookie.');
            }
            $this->cookies = $cookies;

            $this->cookie_file = static::getTempFile('cookies', md5($this->issue_token));
            $this->cache_file = static::getTempFile('cache', md5($this->issue_token));
        } else {
            if ($username === NULL && defined('USERNAME')) {
                $username = constant('USERNAME');
            }
            if ($password === NULL && defined('PASSWORD')) {
                $password = constant('PASSWORD');
            }
            if ($username === NULL || $password === NULL) {
                throw new InvalidArgumentException('Nest credentials were not provided.');
            }
            $this->username = $username;
            $this->password = $password;

            $this->cookie_file = static::getTempFile('cookies', md5($username . $password));
            $this->cache_file = static::getTempFile('cache', md5($username . $password));
        }

        static::secureTouch($this->cookie_file);
        static::secureTouch($this->cache_file);

        // Attempt to load the cache
        $this->loadCache();

        // Log in, if needed
        $this->login();
    }

    protected static function getTempFile($type, $suffix) {
        $file = sys_get_temp_dir() . "/nest_php_{$type}_{$suffix}";
        if (!is_file($file) || !is_writable($file)) {
            if (function_exists('posix_geteuid')) {
                // Use the posix function if available. This requires php-posix or php-process package
                $u = posix_getpwuid(posix_geteuid());
                $unix_user = $u['name'];
            } else {
                $unix_user = get_current_user();
            }
            $file = sys_get_temp_dir() . "/nest_php_{$type}_{$unix_user}_{$suffix}";
        }
        return $file;
    }

    /**
     * Get the outside temperature & humidity, given a location (zip/postal code & optional country code).
     *
     * @param string $postal_code  Zip or postal code
     * @param string $country_code (Optional) Country code
     *
     * @return stdClass
     *
     * @throws RuntimeException
     */
    public function getWeather($postal_code, $country_code = NULL) {
        try {
            $url = "https://home.nest.com/api/0.1/weather/forecast/$postal_code";
            if (!empty($country_code)) {
                $url .= ",$country_code";
            }
            $weather = $this->doGET($url);
        } catch (RuntimeException $ex) {
            // NESTAPI_ERROR_NOT_JSON_RESPONSE is kinda normal. The forecast API will often return a '502 Bad Gateway' response... meh.
            if ($ex->getCode() != NESTAPI_ERROR_NOT_JSON_RESPONSE) {
                throw new RuntimeException("Unexpected issue fetching forecast.", $ex->getCode(), $ex);
            }
        }

        return (object) array(
            'outside_temperature' => isset($weather->now->current_temperature) ? $this->temperatureInUserScale((float) $weather->now->current_temperature) : NULL,
            'outside_humidity'    => isset($weather->now->current_humidity) ? $weather->now->current_humidity : NULL
        );
    }

    /**
     * Get a list of all the locations configured in the Nest account.
     *
     * @return array
     */
    public function getUserLocations() {
        $this->prepareForGet();
        $structures = (array) $this->last_status->structure;
        $user_structures = array();
        $class_name = get_class($this);
        $topaz = isset($this->last_status->topaz) ? $this->last_status->topaz : array();
        $kryptonite = isset($this->last_status->kryptonite) ? $this->last_status->kryptonite : array();
        foreach ($structures as $struct_id => $structure) {
            // Nest Protects at this location (structure)
            $protects = array();
            $sensors = array();
            foreach ($topaz as $protect) {
                if ($protect->structure_id == $struct_id) {
                    $protects[] = $protect->serial_number;
                }
            }
            foreach ($kryptonite as $serial_number => $sensor) {
                if ($sensor->structure_id == $struct_id) {
                    $sensors[] = $serial_number;
                }
            }
            if (empty($protects) && empty($sensors) && empty($structure->devices)) {
                continue;
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
                'away_last_changed' => !empty($structure->away_timestamp) ? date(DATETIME_FORMAT, $structure->away_timestamp) : NULL,
                'thermostats' => array_map(array($class_name, 'cleanDevices'), $structure->devices),
                'protects' => $protects,
                'sensors'  => $sensors,
            );
        }
        return $user_structures;
    }

    /**
     * Get the schedule details for the specified device.
     *
     * @param string $serial_number The device (thermostat or protect) serial number. Defaults to the first device of the account.
     *
     * @return array Returns as array, one element for each day of the week for which there has at least one scheduled event.
     *               Array keys are a textual representation of a day, three letters, as returned by `date('D')`.
     *               Array values are arrays of scheduled temperatures, including a time (in minutes after midnight),
     *               invoke_timestamp of when the event would be activated,
     *               and a mode (one of the TARGET_TEMP_MODE_* defines).
     */
    public function getDeviceSchedule($serial_number = NULL) {
        $this->prepareForGet();
        $serial_number = $this->getDefaultSerial($serial_number);
        $schedule_days = $this->last_status->schedule->{$serial_number}->days;

        $schedule = array();
        foreach ((array)$schedule_days as $day => $scheduled_events) {
            $events = array();
            foreach ($scheduled_events as $scheduled_event) {
                if ($scheduled_event->entry_type == 'setpoint') {
                    $invoke_at = strtotime("{$this->days_maps[(int) $day]} 0:00:00") + $scheduled_event->time;
                    $events[(int)$scheduled_event->time] = (object) array(
                        'time' => $scheduled_event->time/60, // in minutes
                        'invoke_timestamp' => ($invoke_at >= time()) ? $invoke_at : strtotime("+7 days", $invoke_at),
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

    /**
     * Get the next scheduled event.
     *
     * @param string $serial_number The device (thermostat or protect) serial number. Defaults to the first device of the account.
     *
     * @return stdClass|bool Returns the next scheduled event, or FALSE is there is none.
     */
    public function getNextScheduledEvent($serial_number = NULL) {
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

    /**
     * Get the specified device (thermostat or protect) information.
     *
     * @param string $serial_number The device (thermostat, sensor, or protect) serial number. Defaults to the first device of the account.
     *
     * @return stdClass
     */
    public function getDeviceInfo($serial_number = NULL) {
        $this->prepareForGet();
        $serial_number = $this->getDefaultSerial($serial_number);
        $topaz = isset($this->last_status->topaz) ? $this->last_status->topaz : array();
        $kryptonite = isset($this->last_status->kryptonite) ? $this->last_status->kryptonite : array();
        foreach ($topaz as $protect) {
            if ($serial_number == $protect->serial_number) {
                // The specified device is a Nest Protect
                $infos = (object) array(
                    'co_status' => $protect->co_status == 0 ? "OK" : $protect->co_status,
                    'co_previous_peak' => isset($protect->co_previous_peak) ? $protect->co_previous_peak : NULL,
                    'co_sequence_number' => $protect->co_sequence_number,
                    'smoke_status' => $protect->smoke_status == 0 ? "OK" : $protect->smoke_status,
                    'smoke_sequence_number' => $protect->smoke_sequence_number,
                    'model' => $protect->model,
                    'software_version' => $protect->software_version,
                    'line_power_present' => $protect->line_power_present,
                    'battery_level' => $protect->battery_level,
                    'battery_health_state' => $protect->battery_health_state == 0 ? "OK" : $protect->battery_health_state,
                    'wired_or_battery' => isset($protect->wired_or_battery) ? $protect->wired_or_battery : NULL,
                    'born_on_date' => isset($protect->device_born_on_date_utc_secs) ? date(DATE_FORMAT, $protect->device_born_on_date_utc_secs) : NULL,
                    'replace_by_date' => date(DATE_FORMAT, $protect->replace_by_date_utc_secs),
                    'last_update' => date(DATETIME_FORMAT, $protect->{'$timestamp'}/1000),
                    'last_manual_test' => $protect->latest_manual_test_start_utc_secs == 0 ? NULL : date(DATETIME_FORMAT, $protect->latest_manual_test_start_utc_secs),
                    'ntp_green_led_brightness' => isset($protect->ntp_green_led_brightness) ? $protect->ntp_green_led_brightness : NULL,
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
                        'speaker'   => isset($protect->component_speaker_test_passed) ? $protect->component_speaker_test_passed : NULL,
                        'buzzer'    => isset($protect->component_buzzer_test_passed) ? $protect->component_buzzer_test_passed : NULL,
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
                    'where' => $this->getWhereById($protect->spoken_where_id),
                    'color' => isset($protect->device_external_color) ? $protect->device_external_color : NULL,
                );
                return $infos;
            }
        }
        foreach ($kryptonite as $sensor_serial => $sensor) {
            if ($serial_number == $sensor_serial) {
                // The specified device is a Nest Sensor
                $infos = (object) array(
                    'temperature'           => $this->temperatureInUserScale((float) $sensor->current_temperature),
                    'battery_level'         => $sensor->battery_level,
                    'last_status'           => date(DATETIME_FORMAT, $sensor->last_updated_at),
                    'location'              => $sensor->structure_id,
                    'where'                 => $this->getWhereById($sensor->where_id),
                );
                return $infos;
            }
        }

        list(, $structure) = explode('.', $this->last_status->link->{$serial_number}->structure);
        $structure_away = $this->last_status->structure->{$structure}->away;
        $mode = strtolower($this->last_status->device->{$serial_number}->current_schedule_mode);
        $target_mode = $this->last_status->shared->{$serial_number}->target_temperature_type;
        $eco_mode = $this->last_status->device->{$serial_number}->eco->mode; // manual-eco, auto-eco, schedule

        if ($target_mode == TARGET_TEMP_MODE_OFF) {
            $target_temperatures = FALSE; // No target due to it being off
            $mode = TARGET_TEMP_MODE_OFF;
        } elseif ($eco_mode !== "schedule") {
            // We are in eco, thus not actively using the schedule

            if ($this->last_status->device->{$serial_number}->away_temperature_low_enabled && $this->last_status->device->{$serial_number}->away_temperature_high_enabled) {
                // We have both low and high temp eco temperatures
                $mode = TARGET_TEMP_MODE_RANGE;
                $target_temperatures = array(
                    $this->temperatureInUserScale((float) $this->last_status->device->{$serial_number}->away_temperature_low),
                    $this->temperatureInUserScale((float) $this->last_status->device->{$serial_number}->away_temperature_high)
                );
            } elseif ($this->last_status->device->{$serial_number}->away_temperature_low_enabled) {
                // We have only an eco temp low, i.e. we're heating
                $mode = TARGET_TEMP_MODE_HEAT;
                $target_temperatures = $this->temperatureInUserScale((float) $this->last_status->device->{$serial_number}->away_temperature_low);
            } elseif ($this->last_status->device->{$serial_number}->away_temperature_high_enabled) {
                // We have only an eco temp high, i.e. we're cooling
                $mode = TARGET_TEMP_MODE_COOL;
                $target_temperatures = $this->temperatureInUserScale((float) $this->last_status->device->{$serial_number}->away_temperature_high);
            } else {
                // We're in eco with no away temperatures set, i.e. we're technically off (safety temps would still kick in)
                $mode = TARGET_TEMP_MODE_OFF;
                $target_temperatures = FALSE;
            }
        } elseif ($target_mode === 'range') {
            $target_temperatures = array(
                $this->temperatureInUserScale((float) $this->last_status->shared->{$serial_number}->target_temperature_low),
                $this->temperatureInUserScale((float) $this->last_status->shared->{$serial_number}->target_temperature_high)
            );
        } else {
            // It is either heat or cool mode
            $target_temperatures = $this->temperatureInUserScale((float) $this->last_status->shared->{$serial_number}->target_temperature);
        }

        $current_modes = array();
        $current_modes[] = $mode;
        if ($eco_mode !== "schedule") {
            $current_modes[] = $eco_mode;
        }
        if ($structure_away) {
            $current_modes[] = 'away';
        }

        //Process sensors associated to this thermostat
        $sensors = (object)array('all' => array(), 'active' => array(), 'active_temperatures' => array());
        foreach ($this->last_status->rcs_settings->{$serial_number}->associated_rcs_sensors as $sensor_serial) {
            $current_sensor = $this->getDeviceInfo(self::cleanDevices($sensor_serial));
            $current_sensor->is_active = in_array($sensor_serial, $this->last_status->rcs_settings->{$serial_number}->active_rcs_sensors);
            if ($current_sensor->is_active) {
                $sensors->active[] = $current_sensor;
                $sensors->active_temperatures[] = $current_sensor->temperature;
            }
            $sensors->all[] = $current_sensor;
        }
        $infos = (object) array(
            'current_state' => (object) array(
                'mode' => implode(',', $current_modes),
                'temperature' => $this->temperatureInUserScale((float) $this->last_status->shared->{$serial_number}->current_temperature),
                'backplate_temperature' => $this->temperatureInUserScale((float) $this->last_status->device->{$serial_number}->backplate_temperature),
                'humidity' => $this->last_status->device->{$serial_number}->current_humidity,
                'ac' => $this->last_status->shared->{$serial_number}->hvac_ac_state,
                'heat' => $this->last_status->shared->{$serial_number}->hvac_heater_state,
                'alt_heat' => $this->last_status->shared->{$serial_number}->hvac_alt_heat_state,
                'hot_water' => isset($this->last_status->device->{$serial_number}->has_hot_water_control) ? $this->last_status->device->{$serial_number}->hot_water_active : NULL,
                'auto_away' => $this->last_status->shared->{$serial_number}->auto_away, // -1 when disabled, 0 when enabled (thermostat can set auto-away), >0 when enabled and active (thermostat is currently in auto-away mode)
                'manual_away' => $structure_away, //Leaving this for others - but manual away really doesn't exist anymore and should be removed eventually
                'structure_away' => $structure_away,
                'leaf' => $this->last_status->device->{$serial_number}->leaf,
                'battery_level' => $this->last_status->device->{$serial_number}->battery_level,
                'active_stages' => (object) array(
                    'heat' => (object) array(
                        'stage1' => $this->last_status->shared->{$serial_number}->hvac_heater_state,
                        'stage2' => $this->last_status->shared->{$serial_number}->hvac_heat_x2_state,
                        'stage3' => $this->last_status->shared->{$serial_number}->hvac_heat_x3_state,
                        'alt' => $this->last_status->shared->{$serial_number}->hvac_alt_heat_state,
                        'alt_stage2' => $this->last_status->shared->{$serial_number}->hvac_alt_heat_x2_state,
                        'aux' => $this->last_status->shared->{$serial_number}->hvac_aux_heater_state,
                        'emergency' => $this->last_status->shared->{$serial_number}->hvac_emer_heat_state,
                    ),
                    'cool' => (object) array(
                        'stage1' => $this->last_status->shared->{$serial_number}->hvac_ac_state,
                        'stage2' => $this->last_status->shared->{$serial_number}->hvac_cool_x2_state,
                        'stage3' => $this->last_status->shared->{$serial_number}->hvac_cool_x3_state,
                    ),
                ),
                'eco_mode' => $eco_mode,
                'eco_temperatures_assist_enabled' => $this->last_status->device->{$serial_number}->auto_away_enable,
                'eco_temperatures' => (object) array(
                    'low' => ($this->last_status->device->{$serial_number}->away_temperature_low_enabled) ? $this->temperatureInUserScale((float)$this->last_status->device->{$serial_number}->away_temperature_low) : FALSE,
                    'high' => ($this->last_status->device->{$serial_number}->away_temperature_high_enabled) ? $this->temperatureInUserScale((float)$this->last_status->device->{$serial_number}->away_temperature_high) : FALSE,
                ),
                'safety_temperatures' => (object) array(
                    'low' => ($this->last_status->device->{$serial_number}->lower_safety_temp_enabled) ? $this->temperatureInUserScale((float)$this->last_status->device->{$serial_number}->lower_safety_temp) : FALSE,
                    'high' => ($this->last_status->device->{$serial_number}->upper_safety_temp_enabled) ? $this->temperatureInUserScale((float)$this->last_status->device->{$serial_number}->upper_safety_temp) : FALSE,
                ),
            ),
            'target' => (object) array(
                'mode' => $target_mode,
                'temperature' => $target_temperatures,
                'time_to_target' => $this->last_status->device->{$serial_number}->time_to_target
            ),
            'sensors' => $sensors,
            'serial_number' => $this->last_status->device->{$serial_number}->serial_number,
            'scale' => $this->last_status->device->{$serial_number}->temperature_scale,
            'location' => $structure,
            'network' => $this->getDeviceNetworkInfo($serial_number),
            'name' => !empty($this->last_status->shared->{$serial_number}->name) ? $this->last_status->shared->{$serial_number}->name : DEVICE_WITH_NO_NAME,
            'auto_cool' => ((int) $this->last_status->device->{$serial_number}->leaf_threshold_cool === 0) ? FALSE : ceil($this->temperatureInUserScale((float) $this->last_status->device->{$serial_number}->leaf_threshold_cool)),
            'auto_heat' => ((int) $this->last_status->device->{$serial_number}->leaf_threshold_heat === 1000) ? FALSE : floor($this->temperatureInUserScale((float) $this->last_status->device->{$serial_number}->leaf_threshold_heat)),
            'where' => isset($this->last_status->device->{$serial_number}->where_id) ? $this->getWhereById($this->last_status->device->{$serial_number}->where_id) : "",
        );
        if ($this->last_status->device->{$serial_number}->has_humidifier) {
            $infos->current_state->humidifier = $this->last_status->device->{$serial_number}->humidifier_state;
            $infos->target->humidity = $this->last_status->device->{$serial_number}->target_humidity;
            $infos->target->humidity_enabled = $this->last_status->device->{$serial_number}->target_humidity_enabled;
        }
        if ($this->last_status->device->{$serial_number}->has_fan) {
            //Retained the 'fan' attribute for LTS
            $infos->current_state->fan = $this->last_status->shared->{$serial_number}->hvac_fan_state;
            $infos->current_state->fan_info = (object) array(
                'is_active' => $this->last_status->shared->{$serial_number}->hvac_fan_state,
                'mode' => $this->last_status->device->{$serial_number}->fan_mode,
                'current_speed' => $this->last_status->device->{$serial_number}->fan_current_speed,
                'duty_cycle' => $this->last_status->device->{$serial_number}->fan_duty_cycle, //Run time per hour (in seconds)
                //Seconds since midnight
                'duty_start_time' => $this->last_status->device->{$serial_number}->fan_duty_start_time,
                'duty_end_time' => $this->last_status->device->{$serial_number}->fan_duty_end_time,
                //Seconds remaining
                'timer_timeout' => $this->last_status->device->{$serial_number}->fan_timer_timeout > 0 ? $this->last_status->device->{$serial_number}->fan_timer_timeout - time() : false,
            );
        }
        if (isset($this->last_status->demand_response->{$serial_number}->active_events)) {
            $infos->demand_response = (object)array(
                'has_active_event' => false,
                'has_active_peak_period' => false,
                'events' => $this->last_status->demand_response->{$serial_number}->active_events
            );
            foreach ($infos->demand_response->events as &$event) {
                $event->is_peak_period_active = $event->peak_period_start_time_utc <= time() && time() < $event->stop_time_utc;
                $event->is_event_active = $event->start_time_utc <= time() && time() < $event->stop_time_utc;
                $infos->demand_response->has_active_peak_period = $infos->demand_response->has_active_peak_period || $event->is_peak_period_active;
                $infos->demand_response->has_active_event = $infos->demand_response->has_active_event || $event->is_event_active;
            }
        }

        return $infos;
    }

    /**
     * Get the last 10 days energy report.
     *
     * @param string $serial_number The thermostat serial number. Defaults to the first device of the account.
     *
     * @return stdClass|bool
     */
    public function getEnergyLatest($serial_number = NULL) {
        $serial_number = $this->getDefaultSerial($serial_number);

        $payload = array(
            'objects' => array(
                array('object_key' => "energy_latest.$serial_number")
            )
        );

        $url = '/v5/subscribe';

        return $this->doPOST($url, json_encode($payload));
    }

    /**
     * Change the thermostat target mode and temperature
     *
     * @param string      $mode          One of the TARGET_TEMP_MODE_* constants.
     * @param float|array $temperature   Target temperature; specify a float when setting $mode = TARGET_TEMP_MODE_HEAT or TARGET_TEMP_MODE_COLD, and a array of two float values when setting $mode = TARGET_TEMP_MODE_RANGE. Not needed when setting $mode = TARGET_TEMP_MODE_OFF. Send NULL if you want to keep the previous temperature(s) value(s).
     * @param string      $serial_number The thermostat serial number. Defaults to the first device of the account.
     *
     * @return stdClass|bool The object returned by the API call, or FALSE on error.
     */
    public function setTargetTemperatureMode($mode, $temperature = NULL, $serial_number = NULL) {
        $serial_number = $this->getDefaultSerial($serial_number);

        if ($temperature !== NULL) {
            if ($mode == TARGET_TEMP_MODE_RANGE) {
                if (!is_array($temperature) || count($temperature) != 2 || !is_numeric($temperature[0]) || !is_numeric($temperature[1])) {
                    echo "Error: when using TARGET_TEMP_MODE_RANGE, you need to set the target temperatures (second argument of setTargetTemperatureMode) using an array of two numeric values.\n";
                    return FALSE;
                }
                $temp_low = $this->temperatureInCelsius($temperature[0], $serial_number);
                $temp_high = $this->temperatureInCelsius($temperature[1], $serial_number);
                $data = json_encode(array('target_change_pending' => TRUE, 'target_temperature_low' => $temp_low, 'target_temperature_high' => $temp_high));
                $set_temp_result = $this->doPOST("/v2/put/shared." . $serial_number, $data);
            } elseif ($mode != TARGET_TEMP_MODE_OFF) {
                // heat or cool
                if (!is_numeric($temperature)) {
                    echo "Error: when using TARGET_TEMP_MODE_HEAT or TARGET_TEMP_MODE_COLD, you need to set the target temperature (second argument of setTargetTemperatureMode) using an numeric value.\n";
                    return FALSE;
                }
                $temperature = $this->temperatureInCelsius($temperature, $serial_number);
                $data = json_encode(array('target_change_pending' => TRUE, 'target_temperature' => $temperature));
                $set_temp_result = $this->doPOST("/v2/put/shared." . $serial_number, $data);
            }
        }

        $data = json_encode(array('target_change_pending' => TRUE, 'target_temperature_type' => $mode));
        return $this->doPOST("/v2/put/shared." . $serial_number, $data);
    }

    /**
     * Change the thermostat target temperature, when the thermostat is not using a range.
     *
     * @param float  $temperature   Target temperature.
     * @param string $serial_number The thermostat serial number. Defaults to the first device of the account.
     *
     * @return stdClass|bool The object returned by the API call, or FALSE on error.
     */
    public function setTargetTemperature($temperature, $serial_number = NULL) {
        $serial_number = $this->getDefaultSerial($serial_number);
        $temperature = $this->temperatureInCelsius($temperature, $serial_number);
        $data = json_encode(array('target_change_pending' => TRUE, 'target_temperature' => $temperature));
        return $this->doPOST("/v2/put/shared." . $serial_number, $data);
    }

    /**
     * Change the thermostat target temperatures, when the thermostat is using a range.
     *
     * @param float  $temp_low      Target low temperature.
     * @param float  $temp_high     Target high temperature.
     * @param string $serial_number The thermostat serial number. Defaults to the first device of the account.
     *
     * @return stdClass|bool The object returned by the API call, or FALSE on error.
     */
    public function setTargetTemperatures($temp_low, $temp_high, $serial_number = NULL) {
        $serial_number = $this->getDefaultSerial($serial_number);
        $temp_low = $this->temperatureInCelsius($temp_low, $serial_number);
        $temp_high = $this->temperatureInCelsius($temp_high, $serial_number);
        $data = json_encode(array('target_change_pending' => TRUE, 'target_temperature_low' => $temp_low, 'target_temperature_high' => $temp_high));
        return $this->doPOST("/v2/put/shared." . $serial_number, $data);
    }

    /**
     * Set the thermostat to use ECO mode ($mode = ECO_MODE_MANUAL) or not ($mode = ECO_MODE_SCHEDULE).
     *
     * @param string $mode          One of the ECO_MODE_* constants.
     * @param string $serial_number The thermostat serial number. Defaults to the first device of the account.
     *
     * @return stdClass|bool The object returned by the API call, or FALSE on error.
     */
    public function setEcoMode($mode, $serial_number = NULL) {
        $serial_number = $this->getDefaultSerial($serial_number);
        $data = array();
        $data['mode'] = $mode;
        $data['touched_by'] = 4;
        $data['mode_update_timestamp'] = time();
        $data = json_encode(array('eco' => $data));
        return $this->doPOST("/v2/put/device." . $serial_number, $data);
    }

    /**
     * (Deprecated) Change the thermostat away temperatures. This method is an alias for setEcoTemperatures().
     *
     * @param float  $temp_low      Away low temperature.
     * @param float  $temp_high     Away high temperature.
     * @param string $serial_number The thermostat serial number. Defaults to the first device of the account.
     *
     * @return stdClass|bool The object returned by the API call, or FALSE on error.
     *
     * @deprecated
     * @see        Nest::setEcoTemperatures()
     */
    public function setAwayTemperatures($temp_low, $temp_high, $serial_number = NULL) {
        return $this->setEcoTemperatures($temp_low, $temp_high, $serial_number);
    }

    /**
     * Change the thermostat ECO temperatures.
     *
     * @param float|bool $temp_low      ECO low temperature. Use FALSE to turn it Off (only the safety minimum temperature will apply).
     * @param float|bool $temp_high     ECO high temperature. Use FALSE to turn it Off (only the safety maximum temperature will apply).
     * @param string     $serial_number The thermostat serial number. Defaults to the first device of the account.
     *
     * @return stdClass|bool The object returned by the API call, or FALSE on error.
     */
    public function setEcoTemperatures($temp_low, $temp_high, $serial_number = NULL) {
        $serial_number = $this->getDefaultSerial($serial_number);
        $temp_low = $this->temperatureInCelsius($temp_low, $serial_number);
        $temp_high = $this->temperatureInCelsius($temp_high, $serial_number);
        $data = array();
        if ($temp_low === FALSE) {
            $data['away_temperature_low_enabled'] = FALSE;
        } elseif ($temp_low != NULL) {
            $data['away_temperature_low_enabled'] = TRUE;
            $data['away_temperature_low'] = $temp_low;
        }
        if ($temp_high === FALSE) {
            $data['away_temperature_high_enabled'] = FALSE;
        } elseif ($temp_high != NULL) {
            $data['away_temperature_high_enabled'] = TRUE;
            $data['away_temperature_high'] = $temp_high;
        }
        $data = json_encode($data);
        return $this->doPOST("/v2/put/device." . $serial_number, $data);
    }

    /**
     * Change the thermostat safety temperatures.
     *
     * @param float|bool $temp_low      Safety low temperature. Use FALSE to turn it Off (not recommended)
     * @param float|bool $temp_high     Safety high temperature. Use FALSE to turn it Off (not recommended)
     * @param string     $serial_number The thermostat serial number. Defaults to the first device of the account.
     *
     * @return stdClass|bool The object returned by the API call, or FALSE on error.
     */
    public function setSafetyTemperatures($temp_low, $temp_high, $serial_number = NULL) {
        $serial_number = $this->getDefaultSerial($serial_number);
        $temp_low = $this->temperatureInCelsius($temp_low, $serial_number);
        $temp_high = $this->temperatureInCelsius($temp_high, $serial_number);
        $data = array();
        if ($temp_low === FALSE) {
            $data['lower_safety_temp_enabled'] = FALSE;
        } elseif ($temp_low != NULL) {
            $data['lower_safety_temp_enabled'] = TRUE;
            $data['lower_safety_temp'] = $temp_low;
        }
        if ($temp_high === FALSE) {
            $data['upper_safety_temp_enabled'] = FALSE;
        } elseif ($temp_high != NULL) {
            $data['upper_safety_temp_enabled'] = TRUE;
            $data['upper_safety_temp'] = $temp_high;
        }
        $data = json_encode($data);
        return $this->doPOST("/v2/put/device." . $serial_number, $data);
    }

    /**
     * Set the thermostat-controlled fan mode.
     *
     * @param string|array $mode          One of the following constants: FAN_MODE_AUTO, FAN_MODE_ON, FAN_MODE_EVERY_DAY_ON or FAN_MODE_EVERY_DAY_OFF.
     * @param string       $serial_number The thermostat serial number. Defaults to the first device of the account.
     *
     * @return stdClass|bool The object returned by the API call, or FALSE on error.
     *
     * @throws InvalidArgumentException
     */
    public function setFanMode($mode, $serial_number = NULL) {
        $duty_cycle = NULL;
        $timer = NULL;
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
                throw new InvalidArgumentException("setFanMode(array \$mode[, ...]) needs at least a mode and a value in the \$mode array.");
            }
        } elseif (!is_string($mode)) {
            throw new InvalidArgumentException("setFanMode() can only take a string or an array as it's first parameter.");
        }
        return $this->_setFanMode($mode, $duty_cycle, $timer, $serial_number);
    }

    /**
     * Set the thermostat-controlled fan to be ON for a specific number of minutes each hour.
     *
     * @param string|array $mode          One of the FAN_MODE_MINUTES_PER_HOUR_* constants.
     * @param string       $serial_number The thermostat serial number. Defaults to the first device of the account.
     *
     * @return stdClass|bool The object returned by the API call, or FALSE on error.
     */
    public function setFanModeMinutesPerHour($mode, $serial_number = NULL) {
        $modes = explode(',', $mode);
        $mode = $modes[0];
        $duty_cycle = $modes[1];
        return $this->_setFanMode($mode, $duty_cycle, NULL, $serial_number);
    }

    /**
     * Set the thermostat-controlled fan to be ON using a timer.
     *
     * @param string|array $mode          One of the FAN_TIMER_* constants.
     * @param string       $serial_number The thermostat serial number. Defaults to the first device of the account.
     *
     * @return stdClass|bool The object returned by the API call, or FALSE on error.
     */
    public function setFanModeOnWithTimer($mode, $serial_number = NULL) {
        $modes = explode(',', $mode);
        $mode = $modes[0];
        $timer = (int) $modes[1];
        return $this->_setFanMode($mode, NULL, $timer, $serial_number);
    }

    /**
     * Cancels the timer for the thermostat-controlled fan.
     *
     * @param string $serial_number The thermostat serial number. Defaults to the first device of the account.
     *
     * @return stdClass|bool The object returned by the API call, or FALSE on error.
     */
    public function cancelFanModeOnWithTimer($serial_number = NULL) {
        $serial_number = $this->getDefaultSerial($serial_number);
        $data = json_encode(array('fan_timer_timeout' => 0));
        return $this->doPOST("/v2/put/device." . $serial_number, $data);
    }

    /**
     * Set the thermostat-controlled fan to run only between the specified hours.
     *
     * @param int    $start_hour    When the fan should start.
     * @param int    $end_hour      When the fan should stop.
     * @param string $serial_number The thermostat serial number. Defaults to the first device of the account.
     *
     * @return stdClass|bool The object returned by the API call, or FALSE on error.
     */
    public function setFanEveryDaySchedule($start_hour, $end_hour, $serial_number = NULL) {
        $serial_number = $this->getDefaultSerial($serial_number);
        $data = json_encode(array('fan_duty_start_time' => $start_hour*3600, 'fan_duty_end_time' => $end_hour*3600));
        return $this->doPOST("/v2/put/device." . $serial_number, $data);
    }

    /**
     * Turn off the thermostat (no heating, cooling or fan).
     *
     * @param string $serial_number The thermostat serial number. Defaults to the first device of the account.
     *
     * @return stdClass|bool The object returned by the API call, or FALSE on error.
     */
    public function turnOff($serial_number = NULL) {
        return $this->setTargetTemperatureMode(TARGET_TEMP_MODE_OFF, 0, $serial_number);
    }

    /**
     * Change the location (structure) to away or present. Can also set the specified thermostat to use ECO temperatures, when enabling Away mode.
     *
     * @param string $away_mode     AWAY_MODE_ON or AWAY_MODE_OFF
     * @param string $serial_number The thermostat serial number. Defaults to the first device of the account.
     * @param bool   $eco_when_away Specify if you want to use Eco temperatures or not, when using AWAY_MODE_ON. Default to TRUE.
     *
     * @return stdClass|bool The object returned by the API call, or FALSE on error.
     */
    public function setAway($away_mode, $serial_number = NULL, $eco_when_away = TRUE) {
        $serial_number = $this->getDefaultSerial($serial_number);
        $data = json_encode(array('away' => $away_mode, 'away_timestamp' => time(), 'away_setter' => 0));
        $structure_id = $this->getDeviceInfo($serial_number)->location;
        if ($away_mode == AWAY_MODE_ON && $eco_when_away) {
            $this->setEcoMode(ECO_MODE_MANUAL, $serial_number);
        } else {
            $this->setEcoMode(ECO_MODE_SCHEDULE, $serial_number);
        }
        return $this->doPOST("/v2/put/structure." . $structure_id, $data);
    }

    /**
     * (Deprecated) Enable or disable Nest Sense Auto-Away.
     *
     * @param bool   $enabled       True to enable auto-away.
     * @param string $serial_number The thermostat serial number. Defaults to the first device of the account.
     *
     * @return stdClass|bool The object returned by the API call, or FALSE on error.
     *
     * @deprecated Nest Sense Auto-Away is not available anymore. This now controls if the thermostat should use Eco temperatures if it detects you are away.
     * @see        Nest::useEcoTempWhenAway()
     */
    public function setAutoAwayEnabled($enabled, $serial_number = NULL) {
        return $this->useEcoTempWhenAway($enabled, $serial_number);
    }

    /**
     * Enable or disable using Eco temperatures when you're Away.
     *
     * @param bool   $enabled       True to enable Eco temperatures when Away.
     * @param string $serial_number The thermostat serial number. Defaults to the first device of the account.
     *
     * @return stdClass|bool The object returned by the API call, or FALSE on error.
     */
    public function useEcoTempWhenAway($enabled, $serial_number = NULL) {
        $serial_number = $this->getDefaultSerial($serial_number);
        $data = json_encode(array('auto_away_enable' => $enabled));
        return $this->doPOST("/v2/put/device." . $serial_number, $data);
    }

    /**
     * Change the dual-fuel breakpoint temperature.
     *
     * @param float|string $breakpoint    DUALFUEL_BREAKPOINT_ALWAYS_PRIMARY, DUALFUEL_BREAKPOINT_ALWAYS_ALT, or a temperature: thermostat will force usage of alt-heating when the outside temperature is below this value.
     * @param string       $serial_number The thermostat serial number. Defaults to the first device of the account.
     *
     * @return stdClass|bool The object returned by the API call, or FALSE on error.
     */
    public function setDualFuelBreakpoint($breakpoint, $serial_number = NULL) {
        $serial_number = $this->getDefaultSerial($serial_number);
        if (!is_string($breakpoint)) {
            $breakpoint = $this->temperatureInCelsius($breakpoint, $serial_number);
            $data = json_encode(array('dual_fuel_breakpoint_override' => 'none', 'dual_fuel_breakpoint' => $breakpoint));
        } else {
            $data = json_encode(array('dual_fuel_breakpoint_override' => $breakpoint));
        }
        return $this->doPOST("/v2/put/device." . $serial_number, $data);
    }

    /**
     * Enable or disable Nest Sense Humidifier.
     *
     * @param bool   $enabled       True to enable auto-away.
     * @param string $serial_number The thermostat serial number. Defaults to the first device of the account.
     *
     * @return stdClass|bool The object returned by the API call, or FALSE on error.
     */
    public function enableHumidifier($enabled, $serial_number = NULL) {
        $serial_number = $this->getDefaultSerial($serial_number);
        $data = json_encode(array('target_humidity_enabled' => ((boolean)$enabled)));
        return $this->doPOST("/v2/put/device." . $serial_number, $data);
    }

    /**
     * Change the dual-fuel breakpoint temperature.
     *
     * @param float  $humidity      The target humidity value.
     * @param string $serial_number The thermostat serial number. Defaults to the first device of the account.
     *
     * @return stdClass|bool The object returned by the API call, or FALSE on error.
     */
    public function setHumidity($humidity, $serial_number = NULL) {
        $serial_number = $this->getDefaultSerial($serial_number);
        $data = json_encode(array('target_humidity' => ((double)$humidity)));
        return $this->doPOST("/v2/put/device." . $serial_number, $data);
    }

    /**
     * Convert a temperature value from the device-prefered scale to Celsius.
     *
     * @param float  $temperature   The temperature to convert.
     * @param string $serial_number The thermostat serial number. Defaults to the first device of the account.
     *
     * @return float Temperature in Celsius.
     */
    public function temperatureInCelsius($temperature, $serial_number = NULL) {
        $serial_number = $this->getDefaultSerial($serial_number);
        $temp_scale = $this->getDeviceTemperatureScale($serial_number);
        if ($temp_scale == 'F') {
            return ($temperature - 32) / 1.8;
        }
        return $temperature;
    }

    /**
     * Convert a temperature value from Celsius to the device-preferred scale.
     *
     * @param float  $temperature_in_celsius The temperature to convert.
     * @param string $serial_number          The thermostat serial number. Defaults to the first device of the account.
     *
     * @return float Temperature in device-preferred scale.
     */
    public function temperatureInUserScale($temperature_in_celsius, $serial_number = NULL) {
        $serial_number = $this->getDefaultSerial($serial_number);
        $temp_scale = $this->getDeviceTemperatureScale($serial_number);
        if ($temp_scale == 'F') {
            return ($temperature_in_celsius * 1.8) + 32;
        }
        return $temperature_in_celsius;
    }

    /**
     * Get the thermostat preferred scale: Celsius or Fahrenheit.
     *
     * @param string $serial_number The thermostat serial number. Defaults to the first device of the account.
     *
     * @return string 'F' or 'C'
     */
    public function getDeviceTemperatureScale($serial_number = NULL) {
        $serial_number = $this->getDefaultSerial($serial_number);
        return $this->last_status->device->{$serial_number}->temperature_scale;
    }


    /**
     * Get all the devices of a specific type from the user's account.
     *
     * @param string $type DEVICE_TYPE_THERMOSTAT or DEVICE_TYPE_PROTECT or DEVICE_TYPE_SENSOR
     *
     * @return array Devices
     */
    public function getDevices($type = DEVICE_TYPE_THERMOSTAT) {
        $this->prepareForGet();
        $devices_serials = array();
        if ($type == DEVICE_TYPE_PROTECT) {
            $topaz = isset($this->last_status->topaz) ? $this->last_status->topaz : array();
            foreach ($topaz as $protect) {
                $devices_serials[] = $protect->serial_number;
            }
            return $devices_serials;
        }
        if ($type == DEVICE_TYPE_SENSOR) {
            return isset($this->last_status->kryptonite) ? array_keys(get_object_vars($this->last_status->kryptonite)) : array();
        }
        foreach ($this->last_status->structure as $structure) {
            foreach ($structure->devices as $device) {
                $devices_serials[] = self::cleanDevices($device);
            }
        }
        return $devices_serials;
    }

    /**
     * Either return the parameter as-is, or, if empty, return the serial number of the first device found in the user's account.
     *
     * @param string $serial_number Serial number will be returned, if specified.
     *
     * @return string Serial number of the first defined device.
     */
    protected function getDefaultSerial($serial_number) {
        if (empty($serial_number)) {
            $devices_serials = $this->getDevices();
            if (count($devices_serials) == 0) {
                $devices_serials = $this->getDevices(DEVICE_TYPE_PROTECT);
            }
            $serial_number = $devices_serials[0];
        }
        return $serial_number;
    }

    /**
     * Return the device information for the default device.
     *
     * @return stdClass
     */
    public function getDefaultDevice() {
        $serial_number = $this->getDefaultSerial(NULL);
        return $this->last_status->device->{$serial_number};
    }

    /**
     * Get the specified device network information.
     *
     * @param string $serial_number The device (thermostat or protect) serial number. Defaults to the first device of the account.
     *
     * @return stdClass
     */
    protected function getDeviceNetworkInfo($serial_number = NULL) {
        $this->prepareForGet();
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

    /**
    * Boost hot water.
    *
    * @param int  $seconds   Duration of boost.
    * @param string $serial_number The thermostat serial number. Defaults to the first device of the account.
    *
    * @return stdClass|bool The object returned by the API call, or FALSE on error.
    */
    public function setHotWaterBoost($boost_in_seconds = 30, $serial_number = NULL) {
        $serial_number = $this->getDefaultSerial($serial_number);
        $data = json_encode(array('hot_water_boost_time_to_end' => time() + $boost_in_seconds));
        return $this->doPOST("/v2/put/device." . $serial_number, $data);
    }

    /**
    * Cancel hot water boost. Sets boost timer to zero
    *
    * @param string $serial_number The thermostat serial number. Defaults to the first device of the account.
    *
    * @return stdClass|bool The object returned by the API call, or FALSE on error.
    * */
    public function cancelHotWaterBoost($serial_number = NULL) {
        return $this->setHotWaterBoost(0,$serial_number);
    }


    /**
     * Get hot water status
     *
     * @param string $serial_number The thermostat serial number. Defaults to the first device of the account.
     *
     * @return string.
     */
    public function getHotWaterStatus($serial_number = NULL) {
        $serial_number = $this->getDefaultSerial($serial_number);
        if ($this->last_status->device->{$serial_number}->has_hot_water_control) {
                return ($this->last_status->device->{$serial_number}->hot_water_active ? "On" : "Off");
        } else {
                return "device has no hot water control";
        }
        return "error";
    }

    /* Helper functions */

    public function clearStatusCache() {
        unset($this->last_status);
    }

        /**
     * Abstraction function to load all status information from server. Calls getStatusUserBuckets or getStatusMobileUser to obtain data
     *
     * @param boolean $retry If needed, rety loading the status from the server a second time.
     *
     * @return \stdClass
     *
     * @throws RuntimeException
     */
    public function getStatus($retry = TRUE) {
        $status = USE_STATUS_BUCKETS ? $this->getStatusUserBuckets() : $this->getStatusMobileUser($retry);
        $this->last_status = $status;
        $this->saveCache();
        return $status;
    }

    /**
     * Load all status information from server.
     *
     * @param boolean $retry If needed, retry loading the status from the server a second time.
     *
     * @return \stdClass
     *
     * @throws RuntimeException
     */
    protected function getStatusMobileUser($retry = TRUE) {
        $url = "/v3/mobile/" . $this->user;
        $status = $this->doGET($url);
        if (!is_object($status)) {
            throw new RuntimeException("Error: Couldn't get status from NEST API: $status");
        }
        if (@$status->cmd == 'REINIT_STATE') {
            if ($retry) {
                @unlink($this->cookie_file);
                @unlink($this->cache_file);
                $this->login();
                return $this->getStatusMobileUser(FALSE);
            }
            throw new RuntimeException("Error: HTTP request to $url returned cmd = REINIT_STATE. Retrying failed.");
        }
        return $status;
    }

    /**
     * Load all status information from server by utilizing app launch buckets.  Responses are similar to getStatusMobileUser function, but improves support for nonowner devices
     *
     * @return \stdClass
     *
     * @throws RuntimeException
     */
    protected function getStatusUserBuckets() {
        $params = array(
            'known_bucket_types' => array("buckets", "delayed_topaz", "demand_response", "device", "device_alert_dialog", "geofence_info", "kryptonite", "link", "message", "message_center", "metadata", "occupancy", "quartz", "safety", "rcs_settings", "safety_summary", "schedule", "shared", "structure", "structure_history", "structure_metadata", "topaz", "topaz_resource", "track", "trip", "tuneups", "user", "user_alert_dialog", "user_settings", "where", "widget_track"),
            'known_bucket_versions' => array(),
        );
        $result = $this->doPOST("https://home.nest.com/api/0.1/user/{$this->userid}/app_launch", json_encode($params), array('Content-type: text/json'));

        if (!is_object($result) || !is_array($result->updated_buckets)) {
            throw new RuntimeException("Error: Couldn't get status from NEST API: $result");
        }

        $status = (object)array();
        foreach ($result->updated_buckets as $bucket) {
            list($category, $property) = explode('.', $bucket->object_key);
            if (is_null($category) || is_null($property) || !isset($bucket->value)) {
                continue;
            }
            if (!isset($status->{$category})) {
                $status->{$category} = (object)array();
            }
            $status->{$category}->{$property} = $bucket->value;
        }

        //Topaz timestamp is in widget track, also place it to in the protect object for backwards compatibility
        $topaz = isset($status->topaz) ? $status->topaz : array();
        foreach($topaz as $serial => &$protect) {
            $protect->{'$timestamp'} = isset($status->widget_track->{$serial}->last_connection) ? $status->widget_track->{$serial}->last_connection : 0;
        }
        return $status;
    }

    public static function cleanDevices($device) {
        list(, $device_id) = explode('.', $device);
        return $device_id;
    }

    protected function _setFanMode($mode, $fan_duty_cycle = NULL, $timer = NULL, $serial_number = NULL) {
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

    protected function prepareForGet() {
        if (!isset($this->last_status)) {
            $this->getStatus();
        }
    }

    /**
     * Login
     *
     * @param bool $retry Should retry (once)?
     *
     * @return void
     *
     * @throws UnexpectedValueException|RuntimeException
     */
    protected function login($retry = TRUE) {
        if ($this->use_cache()) {
            // No need to login; we'll use cached values for authentication.
            return;
        }
        if (!empty($this->issue_token)) {
            // Get a Bearer token using the Google cookies and issue_token
            $headers = array(
                'Sec-Fetch-Mode: cors',
                'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/75.0.3770.100 Safari/537.36',
                'X-Requested-With: XmlHttpRequest',
                'Referer: https://accounts.google.com/o/oauth2/iframe',
                'Cookie: ' . $this->cookies,
            );
            try {
                $result = $this->doGET($this->issue_token, $headers);
            } catch (RuntimeException $ex) {
                if ($retry) {
                    // Delete cookie and cache files, and retry
                    @unlink($this->cookie_file);
                    @unlink($this->cache_file);
                    $this->login(FALSE);
                    return;
                }
            }
            if (!isset($result->access_token)) {
                throw new UnexpectedValueException("Response to login request doesn't contain required access token. Response: " . json_encode($result));
            }

            // Use Bearer token to get an access token, and user ID
            $headers = array(
                'Authorization: Bearer ' . $result->access_token,
                'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/75.0.3770.100 Safari/537.36',
                'X-Goog-API-Key: AIzaSyAdkSIMNc51XGNEAYWasX9UOWkS5P6sZE4', // Nest website's (public) API key,
                'Referer: https://home.nest.com',
            );
            $params = array(
                'embed_google_oauth_access_token' => TRUE,
                'expire_after' => '3600s',
                'google_oauth_access_token' => $result->access_token,
                'policy_id' => 'authproxy-oauth-policy',
            );
            $result = $this->doPOST("https://nestauthproxyservice-pa.googleapis.com/v1/issue_jwt", $params, $headers);
            if (empty($result->claims->subject->nestId->id)) {
                throw new RuntimeException("Response to login request doesn't contain required User ID. Response: " . json_encode($result));
            }
            if (empty($result->jwt)) {
                throw new RuntimeException("Response to login request doesn't contain required (JWT) access token. Response: " . json_encode($result));
            }
            $this->userid = $result->claims->subject->nestId->id;
            $this->access_token = $result->jwt;
            $this->cache_expiration = strtotime($result->claims->expirationTime);

            // Get user
            $params = array(
                'known_bucket_types' => array("user"),
                'known_bucket_versions' => array(),
            );
            $result = $this->doPOST("https://home.nest.com/api/0.1/user/{$this->userid}/app_launch", json_encode($params), array('Content-type: text/json'));
            if (empty($result->service_urls->urls->transport_url)) {
                throw new RuntimeException("Response to login request doesn't contain required transport_url. Response: " . json_encode($result));
            }
            $this->transport_url = $result->service_urls->urls->transport_url;

            foreach ($result->updated_buckets as $bucket) {
                if (strpos($bucket->object_key, 'user.') === 0) {
                    $this->user = $bucket->object_key;
                    break;
                }
            }
            if (empty($this->user)) {
                $this->user = "user.{$this->userid}"; // meh; no need to get it from API; it's simple enough!
            }
        } else {
            $result = $this->doPOST(self::LOGIN_URL, array('username' => $this->username, 'password' => $this->password));
            if (!isset($result->urls)) {
                throw new RuntimeException("Response to login request doesn't contain required transport URL. Response: " . json_encode($result));
            }
            $this->transport_url = $result->urls->transport_url;
            $this->access_token = $result->access_token;
            $this->userid = $result->userid;
            $this->user = $result->user;
            $this->cache_expiration = strtotime($result->expires_in);
        }
        $this->saveCache();
    }

    protected function use_cache() {
        return file_exists($this->cookie_file) && file_exists($this->cache_file) && !empty($this->cache_expiration) && $this->cache_expiration > time();
    }

    protected function loadCache() {
        if (!file_exists($this->cache_file)) {
            return;
        }
        $vars = @unserialize(file_get_contents($this->cache_file));
        if ($vars === FALSE) {
            return;
        }
        $this->transport_url = $vars['transport_url'];
        $this->access_token = $vars['access_token'];
        $this->user = $vars['user'];
        $this->userid = $vars['userid'];
        $this->cache_expiration = $vars['cache_expiration'];
        // Let's not load this from the disk cache; otherwise, prepareForGet() would always skip getStatus()
        // $this->last_status = $vars['last_status'];
    }

    protected function saveCache() {
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

    /**
     * Obtain the Nest "where name" by the where id
     *
     * @param string $device_where_id     device where id
     *
     * @return string of the where name or value of parameter
     *
     */
    protected function getWhereById($device_where_id) {
        foreach($this->last_status->where as $structure) {
            foreach($structure->wheres as $where) {
                if($where->where_id === $device_where_id) {
                    return $where->name;
                }
            }
        }
        return $device_where_id;
    }

    /**
     * Send a GET HTTP request.
     *
     * @param string $url     URL
     * @param array  $headers HTTP headers
     *
     * @return stdClass|bool JSON-decoded object, or boolean if no response was returned.
     *
     * @throws RuntimeException
     */
    protected function doGET($url, $headers = array()) {
        return $this->doRequest('GET', $url, NULL, TRUE, $headers);
    }

    /**
     * Send a POST HTTP request.
     *
     * @param string       $url         URL
     * @param array|string $data_fields Data to send via POST.
     * @param array        $headers     HTTP headers
     *
     * @return stdClass|bool JSON-decoded object, or boolean if no response was returned.
     *
     * @throws RuntimeException
     */
    protected function doPOST($url, $data_fields, $headers = array()) {
        return $this->doRequest('POST', $url, $data_fields, TRUE, $headers);
    }

    /**
     * Send a HTTP request.
     *
     * @param string       $method      HTTP method: GET or POST
     * @param string       $url         URL
     * @param array|string $data_fields Data to send via POST.
     * @param bool         $with_retry  Retry if request fails?
     * @param array        $headers     HTTP headers
     *
     * @return stdClass|bool JSON-decoded object, or boolean if no response was returned.
     *
     * @throws RuntimeException
     */
    protected function doRequest($method, $url, $data_fields = NULL, $with_retry = TRUE, $headers = array()) {
        $ch = curl_init();
        if ($url[0] == '/') {
            $url = $this->transport_url . $url;
        }
        $headers[] = 'X-nl-protocol-version: ' . self::PROTOCOL_VERSION;
        if (isset($this->userid)) {
            $headers[] = 'X-nl-user-id: ' . $this->userid;
            $headers[] = 'Authorization: Basic ' . $this->access_token;
        }
        if (is_array($data_fields)) {
            $data = array();
            foreach ($data_fields as $k => $v) {
                $data[] = "$k=" . urlencode($v);
            }
            $data = implode('&', $data);
        } elseif (is_string($data_fields)) {
            $data = $data_fields;
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_AUTOREFERER, TRUE);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookie_file);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookie_file);
        if ($method == 'POST') {
            if (!isset($data)) {
                throw new RuntimeException("Error: You need to specify \$data when sending a POST.");
            }
            curl_setopt($ch, CURLOPT_POST, TRUE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            $headers[] = 'Content-length: ' . strlen($data);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, TRUE); // for security this should always be set to true.
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);    // for security this should always be set to 2.
        curl_setopt($ch, CURLOPT_SSLVERSION, 1);        // Nest servers now require TLSv1; won't work with SSLv2 or even SSLv3!

        // Update cacert.pem (valid CA certificates list) from the cURL website once a month
        $curl_cainfo = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cacert.pem';
        $last_month = time()-30*24*60*60;
        if (!file_exists($curl_cainfo) || filemtime($curl_cainfo) < $last_month || filesize($curl_cainfo) < 100000) {
            $certs = static::getCURLCerts();
            if ($certs) {
                file_put_contents($curl_cainfo, $certs);
            }
        }
        if (file_exists($curl_cainfo) && filesize($curl_cainfo) > 100000) {
            curl_setopt($ch, CURLOPT_CAINFO, $curl_cainfo);
        }
        $response = curl_exec($ch);
        $info = curl_getinfo($ch);

        if ($info['http_code'] == 401 || (!$response && curl_errno($ch) != 0)) {
            if ($with_retry) {
                // Received 401; let's re-login then try again this same request
                @unlink($this->cookie_file);
                @unlink($this->cache_file);
                if ($info['http_code'] == 401) {
                    $this->login();
                }
                return $this->doRequest($method, $url, $data_fields, FALSE);
            } else {
                if (curl_errno($ch) != 0) {
                    throw new RuntimeException("Error: HTTP request to $url returned a cURL error: [" . curl_errno($ch) . "] " . curl_error($ch), curl_errno($ch));
                } else {
                    throw new RuntimeException("Error: HTTP request to $url returned an HTTP error code " . $info['http_code'] . ". Response: " . str_replace(array("\n","\r"), '', $response), $info['http_code']);
                }
            }
        }

        $json = json_decode($response);
        if (!is_object($json) && ($method == 'GET' || $url == self::LOGIN_URL)) {
            if (strpos($response, "currently performing maintenance on your Nest account") !== FALSE) {
                throw new RuntimeException("Error: Account is under maintenance; API temporarily unavailable.", NESTAPI_ERROR_UNDER_MAINTENANCE);
            }
            if (empty($response)) {
                throw new RuntimeException("Error: Received empty response from request to $url.", NESTAPI_ERROR_EMPTY_RESPONSE);
            }
            throw new RuntimeException("Error: Response from request to $url is not valid JSON data. Response: " . str_replace(array("\n","\r"), '', $response), NESTAPI_ERROR_NOT_JSON_RESPONSE);
        }

        if ($info['http_code'] == 400) {
            if (!is_object($json)) {
                throw new RuntimeException("Error: HTTP 400 from request to $url. Response: " . str_replace(array("\n","\r"), '', $response), NESTAPI_ERROR_API_OTHER_ERROR);
            }
            throw new RuntimeException("Error: HTTP 400 from request to $url. JSON error: $json->error - $json->error_description", NESTAPI_ERROR_API_JSON_ERROR);
        }

        // No body returned; return a boolean value that confirms a 200 OK was returned.
        if ($info['download_content_length'] == 0) {
            return $info['http_code'] == 200;
        }

        return $json;
    }

    /**
     * Get latest CA certs from curl.haxx.se
     *
     * @return string
     */
    protected static function getCURLCerts() {
        $url = 'https://curl.haxx.se/ca/cacert.pem';
        $certs = @file_get_contents($url);
        if (!$certs) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, TRUE); // for security this should always be set to true.
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);    // for security this should always be set to 2.
            $response = curl_exec($ch);
            $info = curl_getinfo($ch);
            curl_close($ch);
            if ($info['http_code'] == 200) {
                $certs = $response;
            }
        }
        return $certs;
    }

    /**
     * Create a temporary file in the system temp folder.
     *
     * @param string $fname Filename
     *
     * @return void
     */
    protected static function secureTouch($fname) {
        if (file_exists($fname)) {
            return;
        }
        $temp = tempnam(sys_get_temp_dir(), 'NEST');
        rename($temp, $fname);
    }
}
