<?php

namespace BrunoNatali\MqttClient;

interface HIDInterface extends IoManipulationInterface
{
    const HID_TYPE_IO = 0x10;
    const HID_TYPE_AD = 0x10;

    const HID_ACQ_TYPE_POLLING = 0x20;
    const HID_ACQ_TYPE_TRIGGER_VALUE = 0x21; 
    const HID_ACQ_TYPE_TRIGGER_CHANGE = 0x22;

    const HID_DEFAULT_ACQ_TIME = 5;
    const HID_DEFAULT_DIRECTION = self::IO_BASE_DIR_IN;
    const HID_DEFAULT_ACQ_TYPE = self::HID_ACQ_TYPE_POLLING;

    const HID_INTERFACES = [
        [
            'name' => 'DI1',
            'pin' => 'P8-4',
            'gpio' => 39,
            'type' => self::HID_TYPE_IO,
            'direction' => self::HID_DEFAULT_DIRECTION,
            'acquisition_type' => self::HID_DEFAULT_ACQ_TYPE,
            'time' => self::HID_DEFAULT_ACQ_TIME
        ], [
            'name' => 'DI2',
            'pin' => 'P8-6',
            'gpio' => 35,
            'type' => self::HID_TYPE_IO,
            'direction' => self::HID_DEFAULT_DIRECTION,
            'acquisition_type' => self::HID_DEFAULT_ACQ_TYPE,
            'time' => self::HID_DEFAULT_ACQ_TIME
        ], [
            'name' => 'DI3',
            'pin' => 'P8-8',
            'gpio' => 67,
            'type' => self::HID_TYPE_IO,
            'direction' => self::HID_DEFAULT_DIRECTION,
            'acquisition_type' => self::HID_DEFAULT_ACQ_TYPE,
            'time' => self::HID_DEFAULT_ACQ_TIME
        ], [
            'name' => 'DI4',
            'pin' => 'P8-10',
            'gpio' => 68,
            'type' => self::HID_TYPE_IO,
            'direction' => self::HID_DEFAULT_DIRECTION,
            'acquisition_type' => self::HID_DEFAULT_ACQ_TYPE,
            'time' => self::HID_DEFAULT_ACQ_TIME
        ], [
            'name' => 'DI5',
            'pin' => 'P9-15',
            'gpio' => 48,
            'type' => self::HID_TYPE_IO,
            'direction' => self::HID_DEFAULT_DIRECTION,
            'acquisition_type' => self::HID_DEFAULT_ACQ_TYPE,
            'time' => self::HID_DEFAULT_ACQ_TIME
        ], [
            'name' => 'DI6',
            'pin' => 'P9-23',
            'gpio' => 49,
            'type' => self::HID_TYPE_IO,
            'direction' => self::HID_DEFAULT_DIRECTION,
            'acquisition_type' => self::HID_DEFAULT_ACQ_TYPE,
            'time' => self::HID_DEFAULT_ACQ_TIME
        ], [
            'name' => 'DI7',
            'pin' => 'P9-25',
            'gpio' => 117,
            'type' => self::HID_TYPE_IO,
            'direction' => self::HID_DEFAULT_DIRECTION,
            'acquisition_type' => self::HID_DEFAULT_ACQ_TYPE,
            'time' => self::HID_DEFAULT_ACQ_TIME
        ], [
            'name' => 'DI8',
            'pin' => 'P9-27',
            'gpio' => 115,
            'type' => self::HID_TYPE_IO,
            'direction' => self::HID_DEFAULT_DIRECTION,
            'acquisition_type' => self::HID_DEFAULT_ACQ_TYPE,
            'time' => self::HID_DEFAULT_ACQ_TIME
        ]
    ];
}