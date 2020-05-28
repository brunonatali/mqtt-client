<?php declare(strict_types=1);

namespace BrunoNatali\MqttClient;

use BrunoNatali\Tools\OutSystem;

class IoManipulation implements IoManipulationInterface
{
    private $io;
    private $direction;
    private $value;
    private $exported;
    private $autoExport;

    Protected $outSystem;

    /**
     * @ $ioNumeber - Linux IO number
     * @ $autoExport - Exports IO automaticaly
     * @ $configs - [
     *  'alias' => As nick for this instance (ex. 5 => [IO5])
     *  OutSystem::configs
     * ]
    */
    function __construct($ioNumeber, $autoExport = false, $configs = [])
    {
        $this->io = $ioNumeber;
        $this->direction = null;
        $this->value = null;
        $this->exported = false;
        $this->autoExport = $autoExport;
        
        $config = OutSystem::helpHandleAppName( 
            $configs,
            [
                "outSystemName" => (isset($configs['alias']) ? $configs['alias'] : 'IO' . $this->io),
                "outSystemEnabled" => true
            ]
        );
        $this->outSystem = new OutSystem($config);

        $this->outSystem->stdout("Handled.", OutSystem::LEVEL_NOTICE);
    }

    public function open(): bool
    {
        if (\file_exists('/sys/class/gpio/gpio' . $this->io)) {
            $this->outSystem->stdout("Already exported.", OutSystem::LEVEL_NOTICE);
            $this->exported = true;
            return true;
        }

        if (\file_put_contents('/sys/class/gpio/export', (string) $this->io) === false ||
            !\file_exists('/sys/class/gpio/gpio' . $this->io)) {

            $this->outSystem->stdout("Unable to export.", OutSystem::LEVEL_NOTICE);

            $this->exported = false;
            return false;
        }
        
        $this->outSystem->stdout("Exported.", OutSystem::LEVEL_NOTICE);
        $this->exported = true;
        return true;
    }

    public function getDirection($renew = false): string
    {
        if ($this->direction === null || $renew) {
            if ($val = \file_get_contents('/sys/class/gpio/gpio' . $this->io . '/direction')) {
                $val = trim($val);
            } else {
                $this->outSystem->stdout("Unable to get direction ", OutSystem::LEVEL_NOTICE);
                return self::IO_BASE_DIR_ERR;
            }
    
            if ($val === self::IO_BASE_DIR_IN) 
                $this->direction = self::IO_BASE_DIR_IN;
            else if ($val === self::IO_BASE_DIR_OUT)  
                $this->direction = self::IO_BASE_DIR_OUT;
            else 
                $this->direction = self::IO_BASE_DIR_ERR;
        } 

        $this->outSystem->stdout("Direction: " . $this->direction, OutSystem::LEVEL_NOTICE);

        return $this->direction;
    }

    public function setDirection(string $dir): string
    {
        \file_put_contents('/sys/class/gpio/gpio' . $this->io . '/direction', (string) $dir);

        return $this->getDirection(true);
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
                return self::IO_BASE_VAL_ERR;
            }
        }

        // If is input direction value may change without notice
        if ($this->value === null || 
            $this->getDirection() === self::IO_BASE_DIR_IN || 
            $renew) {
            $val = (int) \file_get_contents('/sys/class/gpio/gpio' . $this->io . '/value');
    
            if ($val === self::IO_BASE_VAL_UP) 
                $this->value = self::IO_BASE_VAL_UP;
            else if ($val === self::IO_BASE_VAL_DOWN)  
                $this->value = self::IO_BASE_VAL_DOWN;
            else 
                $this->value = self::IO_BASE_VAL_ERR;
        } 

        $this->outSystem->stdout("Value: " . $this->value, OutSystem::LEVEL_NOTICE);

        return $this->value;
    }

    public function setValue(int $val): int
    {
        if ($this->getDirection() === self::IO_BASE_DIR_IN)
            return self::IO_BASE_VAL_ERR;

        \file_put_contents('/sys/class/gpio/gpio' . $this->io . '/value', (string) $val);

        return $this->getValue(true);
    }
}