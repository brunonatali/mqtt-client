<?php

namespace BrunoNatali\MqttClient;

interface IoManipulationInterface
{
    const IO_BASE_DIR_IN = 'in';
    const IO_BASE_DIR_OUT = 'out';
    const IO_BASE_DIR_ERR = 'error';

    const IO_BASE_VAL_UP = 1;
    const IO_BASE_VAL_DOWN = 0;
    const IO_BASE_VAL_ERR = 'error';
}