Nest Learning Thermostat API
============================

This is a PHP class that will allow you to monitor and control your [Nest Learning Thermostat](http://www.nest.com/).

Features
--------

- Caching so that it doesn't re-login when it doesn't need to. i.e. faster operations.
- Getters:
    - Current & target temparatures, humidity
    - Time to target temperature
    - Target temperature mode, fan mode
    - AC, heat, and fan status: on or off
    - Manual and automatic away mode
    - Location information
    - Network information (local & WAN IPs, MAC address, online status)
    - Currently active schedule (by day)
    - Next scheduled event
    - Last 10 days energy report
- Setters:
    - Target temperatures (single, or range)
    - Target temperature mode: cool, heat, range
    - Fan mode: auto, on, minutes per hour
    - Fan: every day schedule (start & stop time)
    - Fan: on with timer (stops after X minutes/hours)
    - Away mode: on, off
    - Dual fuel: breakpoint (use alt. fuel when outdoor temp is below X), always alt, always primary
    - Turn off HVAC

Usage
-----

See examples.php for details, but here's a Quick Start.

    <?php
    
    require_once('nest.class.php');

    // Your Nest username and password.
    define('USERNAME', 'you@gmail.com');
    define('PASSWORD', 'Something other than 1234 right?');

    $nest = new Nest();

    // Get the device information:
    $infos = $nest->getDeviceInfo();
    print_r($infos);
    
    // Print the current temperature
    printf("Current temperature: %.02f degrees %s\n", $infos->current_state->temperature, $infos->scale);
    
    // Cool to 23
    $nest->setTargetTemperatureMode(TARGET_TEMP_MODE_COOL, 23.0);
    
    // Set Away mode
    $nest->setAway(TRUE);

    // Turn off Away mode
    $nest->setAway(FALSE);

Example output for getDeviceInfo():

    {
      "current_state": {
        "mode": "range",
        "temperature": 24.09999,
        "humidity": 42,
        "ac": false,
        "heat": false,
        "fan": true,
        "auto_away": 0,
        "manual_away": false,
        "leaf": false
      },
      "target": {
        "mode": "range",
        "temperature": [
          23,
          26
        ],
        "time_to_target": 0
      },
      "serial_number": "01AB02BA117210S5",
      "scale": "C",
      "location": "1061f350-a2f1-111e-b9eb-123e8b139117",
      "network": {
        "online": true,
        "last_connection": "2012-09-30 21:26:25",
        "wan_ip": "173.246.19.71",
        "local_ip": "192.168.1.201",
        "mac_address": "18b430046194"
      }
    }

Troubleshooting
---------------
If you have any issues, try adding this at the top of your PHP script, to ask PHP to echo all errors and warnings.

    error_reporting(E_ALL);

Acknowledgements
----------------

- Andy Blyler, http://andyblyler.com/
    for https://github.com/ablyler/nest-php-api
- Scott M Baker, http://www.smbaker.com/
    for https://github.com/smbaker/pynest
- Chris Burris, http://www.chilitechno.com/
    for https://github.com/chilitechno/SiriProxy-NestLearningThermostat
- Chad Corbin
    for http://code.google.com/p/jnest/
- Aaron Cornelius
    for http://www.wiredprairie.us/blog/index.php/archives/1442
