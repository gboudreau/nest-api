<?php
namespace Nest;

class Constants {
    const DATE_FORMAT = 'Y-m-d';
    const DATETIME_FORMAT =  'Y-m-d H:i:s';
    const TARGET_TEMP_MODE_COOL =  'cool';
    const TARGET_TEMP_MODE_HEAT =  'heat';
    const TARGET_TEMP_MODE_RANGE =  'range';
    const TARGET_TEMP_MODE_OFF =  'off';
    const FAN_MODE_AUTO =  'auto';
    const FAN_MODE_ON =  'on';
    const FAN_MODE_EVERY_DAY_ON =  'on';
    const FAN_MODE_EVERY_DAY_OFF =  'auto';
    const FAN_MODE_MINUTES_PER_HOUR =  'duty-cycle';
    const FAN_MODE_MINUTES_PER_HOUR_15 =  'duty-cycle,900';
    const FAN_MODE_MINUTES_PER_HOUR_30 =  'duty-cycle,1800';
    const FAN_MODE_MINUTES_PER_HOUR_45 =  'duty-cycle,2700';
    const FAN_MODE_MINUTES_PER_HOUR_ALWAYS_ON =  'on,3600';
    const FAN_MODE_TIMER =  '';
    const FAN_TIMER_15M = ',900';
    const FAN_TIMER_30M = ',1800';
    const FAN_TIMER_45M = ',2700';
    const FAN_TIMER_1H = ',3600';
    const FAN_TIMER_2H = ',7200';
    const FAN_TIMER_4H = ',14400';
    const FAN_TIMER_8H = ',28800';
    const FAN_TIMER_12H = ',43200';
    const AWAY_MODE_ON =  TRUE;
    const AWAY_MODE_OFF =  FALSE;
    const DUALFUEL_BREAKPOINT_ALWAYS_PRIMARY =  'always-primary';
    const DUALFUEL_BREAKPOINT_ALWAYS_ALT =  'always-alt';
    const DEVICE_WITH_NO_NAME =  'Not Set';
    const DEVICE_TYPE_THERMOSTAT =  'thermostat';
    const DEVICE_TYPE_PROTECT =  'protect';

    const NESTAPI_ERROR_UNDER_MAINTENANCE =  1000;
    const NESTAPI_ERROR_EMPTY_RESPONSE =  1001;
    const NESTAPI_ERROR_NOT_JSON_RESPONSE =  1002;
    const NESTAPI_ERROR_API_JSON_ERROR =  1003;
    const NESTAPI_ERROR_API_OTHER_ERROR =  1004;
}
?>