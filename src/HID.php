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
        foreach (self::HID_INTERFACES as $interface) {
            $this->registerInterface($interface, $callback);
        }
        
    }

    /**
     * @ $interface - [
     *      'name' => 'DI1',
     *      'pin' => 'P8-4',
     *      'gpio' => 39,
     *      'type' => self::HID_TYPE_IO,
     *      'direction' => self::HID_DEFAULT_DIRECTION,
     *      'acquisition_type' => self::HID_DEFAULT_ACQ_TYPE,
     *      'time' => self::HID_DEFAULT_ACQ_TIME
     *  ]
    */
    public function registerInterface($interface, $callback)
    {
        switch ($interface['type']) {
            case self::HID_TYPE_IO:
                $gpio = $interface['gpio'];

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

                if ($interface['acquisition_type'] === self::HID_ACQ_TYPE_POLLING) {
                    $time = intval($interface['time']);
                    if ($time >= 1) {
                        $this->interfaces[ $gpio ]['timer'] = $this->loop->addPeriodicTimer(
                            $time,
                            function () use ($gpio, $callback) {
                                $callback(
                                    $this->interfaces[ $gpio ]['config']['name'], // Use name instead gpio value
                                    $this->interfaces[ $gpio ]['dev']->getValue()
                                );
                            }
                        );
                    }
                }

                $this->interfacesByNick[ $interface['name'] ] = &$this->interfaces[ $gpio ];
                break;
            
            case self::HID_TYPE_AD:

                break;
        }

    }
}