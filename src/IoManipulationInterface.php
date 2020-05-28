<?php

namespace BrunoNatali\MqttClient;

interface IoManipulationInterface
{
    const IO_BASE_DIR_IN = 'in';
    const IO_BASE_DIR_OUT = 'out';
    const IO_BASE_DIR_ERR = 'error';

    const IO_BASE_VAL_UP = 1;
    const IO_BASE_VAL_DOWN = 0;
    const IO_BASE_VAL_ERR = 2;

    public function open(): bool;

    public function getDirection($renew = false): string;

    public function setDirection(string $dir): string;

    public function getValue($renew = false): int;

    public function setValue(int $val): int;
}