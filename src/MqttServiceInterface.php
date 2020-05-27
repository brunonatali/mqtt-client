<?php

namespace BrunoNatali\MqttClient;

use BrunoNatali\SystemInteraction\MainInterface;

interface MqttServiceInterface extends MainInterface
{
    const MQTT_SERVICE_SOCKET = 'mqtt-service.sock';

    const MQTT_TENANT = 'atech';
    const MQTT_BROKER_URI = '192.168.7.1';
    const MQTT_BROKER_PORT = null; // 1883
    const MQTT_USER_NAME = 'user';
    const MQTT_PASSWORD = '1234';
    const MQTT_CLIENT_ID = '1234567890abcdef12';
}