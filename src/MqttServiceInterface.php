<?php

namespace BrunoNatali\MqttClient;

use BrunoNatali\SystemInteraction\MainInterface;

interface MqttServiceInterface extends MainInterface
{

    const MQTT_SERVICE_SOCKET = 'mqtt-service.sock';
}