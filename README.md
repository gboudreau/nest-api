Unofficial Nest Learning Thermostat API
=======================================

This is a PHP class that will allow you to monitor and control your [Nest Learning Thermostat](http://www.nest.com/), and Nest Protect.

__Note that since I started this, Nest have started an official [Developer program](https://developer.nest.com/). You might be better served using the official APIs, versus this PHP class here in which you need to store your credentials in plain text, and which use the non-supported APIs used by the mobile & web apps.  
i.e. if you're building a serious commercial application, go sign-up into Nest's Developer program. If you just want to build something for yourself, then you're probably fine with this PHP class here.__

Features
--------

- Caching so that it doesn't re-login when it doesn't need to. i.e. faster operations.
- Getters:
    - Current & target temperatures, humidity
    - Time to target temperature
    - Target temperature mode, fan mode
    - AC, heat, and fan status: on or off
    - Manual and automatic away mode
    - Location information
    - Network information (local & WAN IPs, MAC address, online status)
    - Currently active schedule (by day)
    - Next scheduled event
    - Last 10 days energy report
    - Device name, devices list
    - Battery level (voltage)
    - Nest Protect device information
- Setters:
    - Target temperatures (single, or range)
    - Target temperature mode: cool, heat, range
    - Fan mode: auto, on, minutes per hour
    - Fan: every day schedule (start & stop time)
    - Fan: on with timer (stops after X minutes/hours)
    - Away mode: on, off, min/max temperatures, Auto-Away
    - Dual fuel: breakpoint (use alt. fuel when outdoor temp is below X), always alt, always primary
    - Humidity (on, off, %)
    - Turn off HVAC

Usage
-----

You can just download nest.class.php and require/include it, or use composer: `require "gboudreau/nest-api": "dev-master"`.

See examples.php for details, but here's a Quick Start.

```php
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
```

Example output for `getDeviceInfo()`:

```json
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
    "leaf": false,
    "battery_level": 3.948
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
```

Troubleshooting
---------------
If you have any issues, try adding this at the top of your PHP script, to ask PHP to echo all errors and warnings.

```php
error_reporting(E_ALL);
```

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

Developed mainly using a free open-source license of  
![PHPStorm](https://d3uepj124s5rcx.cloudfront.net/items/0V0z2p0e0K1D0F3t2r1P/logo_PhpStorm.png)  
kindly provided by [JetBrains](http://www.jetbrains.com/). Thanks guys!
