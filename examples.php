<?php

require_once('nest.class.php');

// Your Nest username and password.
define('USERNAME', 'you@gmail.com');
define('PASSWORD', 'Something other than 1234 right?');

// The timezone you're in.
// See http://php.net/manual/en/timezones.php for the possible values.
date_default_timezone_set('America/Montreal');

// Here's how to use this class:

$nest = new Nest();

echo "Location information:\n";
$locations = $nest->getUserLocations();
jlog($locations);
echo "----------\n\n";

echo "Device information:\n";
$infos = $nest->getDeviceInfo();
jlog($infos);
echo "----------\n\n";

echo "Current temperature:\n";
printf("%.02f degrees %s\n", $infos->current_state->temperature, $infos->scale);
echo "----------\n\n";

echo "Setting target temperature...\n";
// Note: setting temperatures will use the units you set on the device. I'm using celsius on my device, so I'm using celsius here.
$success = $nest->setTargetTemperature(26);
var_dump($success);

echo "Setting target temperatures (range)...\n";
$success = $nest->setTargetTemperatures(23.0, 26.0);
var_dump($success);

echo "Setting target temperature mode...\n";
$success = $nest->setTargetTemperatureMode(TARGET_TEMP_MODE_COOL, 26.0); // Available: TARGET_TEMP_MODE_COOL, TARGET_TEMP_MODE_HEAT, TARGET_TEMP_MODE_RANGE
var_dump($success);

echo "Setting target temperature mode (range)...\n";
$success = $nest->setTargetTemperatureMode(TARGET_TEMP_MODE_RANGE, array(23.0, 26.0)); // Available: TARGET_TEMP_MODE_COOL, TARGET_TEMP_MODE_HEAT, TARGET_TEMP_MODE_RANGE
var_dump($success);

echo "Setting fan mode...\n";
$success = $nest->setFanMode(FAN_MODE_ON); // Available: FAN_MODE_AUTO, FAN_MODE_ON
var_dump($success);

echo "Turning system off...\n";
$success = $nest->turnOff();
var_dump($success);

echo "Setting away mode...\n";
$success = $nest->setAway(AWAY_MODE_ON); // Available: AWAY_MODE_ON, AWAY_MODE_OFF
var_dump($success);

echo "Setting dual-fuel breakpoint (use alternative heat when the outdoor temperature is below -5°)...\n";
// Note: when using temperatures, it will use the units you set on the device. I'm using celsius on my device, so I'm using celsius here.
$success = $nest->setDualFuelBreakpoint(-5); // Available: DUALFUEL_BREAKPOINT_ALWAYS_PRIMARY, DUALFUEL_BREAKPOINT_ALWAYS_ALT, or a temperature between -12°C and 9°C (10-50°F)
var_dump($success);
echo "----------\n\n";

sleep(1);

echo "Device information:\n";
$infos = $nest->getDeviceInfo();
jlog($infos);
echo "----------\n\n";

echo "Device schedule:\n";
// Returns as array, one element for each day of the week for which there has at least one scheduled event.
// Array keys are a textual representation of a day, three letters, as returned by `date('D')`. Array values are arrays of scheduled temperatures, including a time (in minutes after midnight), and a mode (one of the TARGET_TEMP_MODE_* defines).
$schedule = $nest->getDeviceSchedule();
jlog($schedule);
echo "----------\n\n";

echo "Device next scheduled event:\n";
$next_event = $nest->getNextScheduledEvent();
jlog($next_event);
echo "----------\n\n";

/* Helper functions */

function json_format($json) { 
    $tab = "  "; 
    $new_json = ""; 
    $indent_level = 0; 
    $in_string = false; 

    $json_obj = json_decode($json); 

    if($json_obj === false) 
        return false; 

    $json = json_encode($json_obj); 
    $len = strlen($json); 

    for($c = 0; $c < $len; $c++) 
    { 
        $char = $json[$c]; 
        switch($char) 
        { 
            case '{': 
            case '[': 
                if(!$in_string) 
                { 
                    $new_json .= $char . "\n" . str_repeat($tab, $indent_level+1); 
                    $indent_level++; 
                } 
                else 
                { 
                    $new_json .= $char; 
                } 
                break; 
            case '}': 
            case ']': 
                if(!$in_string) 
                { 
                    $indent_level--; 
                    $new_json .= "\n" . str_repeat($tab, $indent_level) . $char; 
                } 
                else 
                { 
                    $new_json .= $char; 
                } 
                break; 
            case ',': 
                if(!$in_string) 
                { 
                    $new_json .= ",\n" . str_repeat($tab, $indent_level); 
                } 
                else 
                { 
                    $new_json .= $char; 
                } 
                break; 
            case ':': 
                if(!$in_string) 
                { 
                    $new_json .= ": "; 
                } 
                else 
                { 
                    $new_json .= $char; 
                } 
                break; 
            case '"': 
                if($c > 0 && $json[$c-1] != '\\') 
                { 
                    $in_string = !$in_string; 
                } 
            default: 
                $new_json .= $char; 
                break;                    
        } 
    } 

    return $new_json; 
}

function jlog($json) {
    if (!is_string($json)) {
        $json = json_encode($json);
    }
    echo json_format($json) . "\n";
}
