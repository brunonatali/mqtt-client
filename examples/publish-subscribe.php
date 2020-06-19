<?php

var_dump(json_encode([
	'user' => 'device', 
		[
			'sensor' => 'di01', 
			'period' => 1, 
			'ts' => 1592591730
		]
]));

// Broker
mosquitto.conf {allow_anonymous false}
// create encrypted user & password file
.\mosquitto_passwd -b credentials user 1234 // user = user, password = 1234
.\mosquitto.exe -v -c mosquitto.conf

// Subscribe
PS C:\Program Files\mosquitto> .\mosquitto_sub.exe -h 127.0.0.1 -p 1883 -u user -P 1234 -t atech/sensors/1234567890abcdef12

// Publish 1
PS C:\Program Files\mosquitto> .\mosquitto_pub.exe -h 127.0.0.1 -p 1883 -u user -P 1234 -t atech/config/sensors/1234567890abcdef12 -m '{\"user\":\"device\",\"0\":{\"sensor\":\"AI0\",\"period\":120,\"ts\":1592591730}}'

// Publish 2
PS C:\Program Files\mosquitto> .\mosquitto_pub.exe -h 127.0.0.1 -p 1883 -u user -P 1234 -t atech/config/sensors/1234567890abcdef12 -m '{\"user\":\"device\",\"0\":{\"sensor\":\"AI0\",\"period\":120,\"ts\":1592591730},\"1\":{\"sensor\":\"AI2\",\"period\":120,\"ts\":1592591730}}'