<?php declare(strict_types=1);

namespace BrunoNatali\MqttClient;

use React\EventLoop\Factory;

use BrunoNatali\Tools\Queue;
use BrunoNatali\Tools\OutSystem;
use BrunoNatali\Tools\Communication\SimpleUnixServer;

class HID implements HIDInterface
{
    private $loop;

    private $interfaces;
    private $interfacesByNick; // Links using interface nickaneme to device 

    private $sysConfig;

    function __construct(&$loop, $configs = [])
    {
        $this->loop = &$loop;

        $this->interfaces = [];
        $this->interfacesByNick = [];
        
        $this->sysConfig = OutSystem::helpHandleAppName( 
            $configs,
            [
                "outSystemName" => 'HID',
                "outSystemEnabled" => true
            ]
        );
        $this->outSystem = new OutSystem($this->sysConfig);
    }

    public function start($callback)
    {
        $this->outSystem->stdout("Initializing interfaces.", OutSystem::LEVEL_NOTICE);

        foreach (self::HID_INTERFACES as $interface) {
            $this->registerInterface($interface, $callback);
        }
        
    }

    /**
     * @ $interface - [
     *      'name' => 'DI1',
     *      'pin' => 'P8-4',
     *      'gpio' => 39, // For gpio only
     *      'index' => 0, // For AD only
     *      'type' => self::HID_TYPE_IO, // self::HID_TYPE_AD
     *      'direction' => self::HID_DEFAULT_DIRECTION, // For gpio only
     *      'acquisition_type' => self::HID_DEFAULT_ACQ_TYPE,
     *      'time' => self::HID_DEFAULT_ACQ_TIME
     *  ]
    */
    public function registerInterface($interface, $callback)
    {
        switch ($interface['type']) {
            case self::HID_TYPE_IO:
                $gpio = $interface['gpio'];

                $out = 'Interface ';
                if (isset($this->interfaces[ $gpio ])) {
                    $out .= "'$gpio (" . $this->interfaces[ $gpio ]['config']['name'] . ")' reconfigured";
                    $this->loop->cancelTimer($this->interfaces[ $gpio ]['timer']);
                } else {
                    $out .= "'$gpio (" . $interface['name'] . ")' configured";
                }

                $this->interfaces[ $gpio ] = [
                    'config' => $interface,
                    'dev' => new IoManipulation(
                        $gpio,
                        true, 
                        array_merge(
                            $this->sysConfig, 
                            ['alias' => $interface['name']] 
                        )
                    )
                ];

                $this->registerInterfaceAcquisition($gpio);

                $this->interfacesByNick[ $interface['name'] ] = &$this->interfaces[ $gpio ];
                break;
            
            case self::HID_TYPE_AD:
                $index = $interface['index'];

                $out = 'Interface ';
                if (isset($this->interfaces[ $index ])) {
                    $out .= "'$index (" . $this->interfaces[ $index ]['config']['name'] . ")' reconfigured";
                    $this->loop->cancelTimer($this->interfaces[ $index ]['timer']);
                } else {
                    $out .= "'$index (" . $interface['name'] . ")' configured";
                }

                $this->interfaces[ $index ] = [
                    'config' => $interface,
                    'dev' => new AdManipulation(
                        $index,
                        true, 
                        array_merge(
                            $this->sysConfig, 
                            ['alias' => $interface['name']] 
                        )
                    )
                ];

                $this->registerInterfaceAcquisition($index);

                $this->interfacesByNick[ $interface['name'] ] = &$this->interfaces[ $index ];

                break;
        }

        $this->outSystem->stdout($out, OutSystem::LEVEL_NOTICE);
    }

    private function registerInterfaceAcquisition($nuber)
    {
        $interface = &$this->interfaces[ $nuber ]['config'];

        if ($interface['acquisition_type'] === self::HID_ACQ_TYPE_POLLING) {
            $time = intval($interface['time']);
            if ($time >= 1) {
                $this->interfaces[ $nuber ]['timer'] = $this->loop->addPeriodicTimer(
                    $time,
                    function () use ($nuber, $callback) {
                        $callback(
                            $this->interfaces[ $nuber ]['config']['name'], // Use name instead gpio value
                            $this->interfaces[ $nuber ]['dev']->getValue()
                        );
                    }
                );
            }
        }
    }
}