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

// Use a Nest account:
$username = 'you@gmail.com';
$pasword = 'Something other than 1234 right?';
$nest = new Nest($username, $pasword);

// Or use a Google account (see instructions below on how to find those values):
$issue_token = 'https://accounts.google.com/o/oauth2/iframerpc?action=issueToken&response_type=token%20id_token&login_hint=UNIQUE_VALUE_HERE&client_id=733249279899-44tchle2kaa9afr5v9ov7jbuojfr9lrq.apps.googleusercontent.com&origin=https%3A%2F%2Fhome.nest.com&scope=openid%20profile%20email%20https%3A%2F%2Fwww.googleapis.com%2Fauth%2Fnest-account&ss_domain=https%3A%2F%2Fhome.nest.com';
$cookies = '#YOUR_COOKIES_HERE#'; // All on one line; remove any new-line character you might have
$nest = new Nest(NULL, NULL, $issue_token, $cookies);

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

Use try...catch to catch exceptions that could occur:
```php
try {
    $nest = new Nest(NULL, NULL, $issue_token, $cookies);
    // Execute all Nest-related code here
} catch (UnexpectedValueException $ex) {
    // Happens when the issue_token or cookie is not working, for whatever reason
    $error_message = $ex->getMessage();
    mail(...);
} catch (RuntimeException $ex) {
    // Probably a temporary server-error
} catch (Exception $ex) {
    // Other errors; should not happen if it worked in the past
}

// Continue your code here, for example to save the result in a database
```

Using a Google Account
----------------------
The values of `$issue_token`, and `$cookies` are specific to your Google Account. To get them, follow these steps (only needs to be done once, as long as you stay logged into your Google Account).

- Open a Chrome browser tab in Incognito Mode (or clear your cache).
- Open Developer Tools (View/Developer/Developer Tools).
- Click on `Network` tab. Make sure `Preserve Log` is checked.
- In the `Filter` box, enter `issueToken`
- Go to https://home.nest.com, and click `Sign in with Google`. Log into your account.
- One network call (beginning with `iframerpc`) will appear in the Dev Tools window. Click on it.
- In the `Headers` tab, under `General`, copy the entire `Request URL` (beginning with `https://accounts.google.com`, ending with `nest.com`). This is your `$issue_token`.
- In the `Filter` box, enter `oauth2/iframe`
- Several network calls will appear in the Dev Tools window. Click on the last iframe call.
- In the `Headers` tab, under `Request Headers`, copy the entire cookie value (include the whole string which is several lines long and has many field/value pairs - do not include the `Cookie:` prefix). This is your `$cookies`; make sure all of it is on a single line.

Troubleshooting
---------------
If you have any issues, try adding this at the top of your PHP script, to ask PHP to echo all errors and warnings.

```php
error_reporting(E_ALL);
```

Acknowledgements
----------------

- Jacob McSwain, https://github.com/USA-RedDragon
    for https://github.com/USA-RedDragon/badnest
- Chris J. Shull, https://github.com/chrisjshull
    for https://github.com/chrisjshull/homebridge-nest/
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

Developed mainly using a free open-source license of PHPStorm kindly provided by [JetBrains](http://www.jetbrains.com/). Thanks guys!
