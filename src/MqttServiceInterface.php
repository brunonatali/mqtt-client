<?php

namespace BrunoNatali\SystemInteraction;

use BrunoNatali\SystemInteraction\MainInterface;

interface MqttServiceInterface extends MainInterface
{

    const MQTT_SERVICE_SOCKET = 'runas-root.sock';
}