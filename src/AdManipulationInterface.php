<?php

namespace BrunoNatali\MqttClient;

interface AdManipulationInterface
{
    const AD_BASE_GENERAL_ERR = 0xFFFF;

    public function open(): bool;

    public function getValue($renew = false): int;
}