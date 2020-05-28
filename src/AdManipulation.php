<?php declare(strict_types=1);

namespace BrunoNatali\MqttClient;

use BrunoNatali\Tools\OutSystem;

class AdManipulation implements AdManipulationInterface
{
    private $index;
    private $value;
    private $exported;
    private $autoExport;

    Protected $outSystem;

    /**
     * @ $index - Linux AD index number
     * @ $autoExport - Exports IO automaticaly
     * @ $configs - [
     *  'alias' => As nick for this instance (ex. 5 => [AD5])
     *  OutSystem::configs
     * ]
    */
    function __construct($index, $autoExport = false, $configs = [])
    {
        $this->index = $index;
        $this->value = null;
        $this->exported = false;
        $this->autoExport = $autoExport;
        
        $config = OutSystem::helpHandleAppName( 
            $configs,
            [
                "outSystemName" => (isset($configs['alias']) ? $configs['alias'] : 'AD' . $this->index),
                "outSystemEnabled" => true
            ]
        );
        $this->outSystem = new OutSystem($config);

        $this->outSystem->stdout("Handled.", OutSystem::LEVEL_NOTICE);
    }

    /**
     * Ads are created automaticaly during boot.
     * 
     * open() still here just for reference
    */
    public function open(): bool
    {
        if (\file_exists('/sys/bus/iio/devices/iio:device0')) {
            $this->outSystem->stdout("Exported.", OutSystem::LEVEL_NOTICE);
            $this->exported = true;
            return true;
        } else {
            $this->outSystem->stdout("Unable to export.", OutSystem::LEVEL_NOTICE);

            $this->exported = false;
            return false;
        }
    }

    public function getValue($renew = false): int
    {
        if (!$this->exported) {
            if ($this->autoExport) {
                $this->open();
                $this->autoExport = false; // Prevent inifinite opening on error
                return $this->getValue($renew);
            } else {
                $this->outSystem->stdout("open() first.", OutSystem::LEVEL_NOTICE);
                return self::AD_BASE_GENERAL_ERR;
            }
        }

        $this->value = (int) \file_get_contents('/sys/bus/iio/devices/iio:device0/in_voltage' . $this->index . '_raw');

        if (!\is_int($this->value)) {
            $this->outSystem->stdout("Unable to get value.", OutSystem::LEVEL_NOTICE);
            return self::AD_BASE_GENERAL_ERR;
        } else {
            $this->outSystem->stdout("Value: " . $this->value, OutSystem::LEVEL_NOTICE);
        }
            
        return $this->value;
    }
}