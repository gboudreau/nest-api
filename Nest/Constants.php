<?php
namespace Nest\Constants;

define('DATE_FORMAT','Y-m-d');
define('DATETIME_FORMAT', DATE_FORMAT . ' H:i:s');
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
define('DEVICE_WITH_NO_NAME', 'Not Set');
define('DEVICE_TYPE_THERMOSTAT', 'thermostat');
define('DEVICE_TYPE_PROTECT', 'protect');

define('NESTAPI_ERROR_UNDER_MAINTENANCE', 1000);
define('NESTAPI_ERROR_EMPTY_RESPONSE', 1001);
define('NESTAPI_ERROR_NOT_JSON_RESPONSE', 1002);
define('NESTAPI_ERROR_API_JSON_ERROR', 1003);
define('NESTAPI_ERROR_API_OTHER_ERROR', 1004);

?>