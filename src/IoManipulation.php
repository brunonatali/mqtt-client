<?php declare(strict_types=1);

namespace BrunoNatali\MqttClient;

use BrunoNatali\Tools\OutSystem;

class IoManipulation implements IoManipulationInterface
{
    private $io;
    private $direction;
    private $value;
    private $exported;

    Protected $outSystem;

    /**
     * @ $ioNumeber - Linux IO number
     * @ $autoExport - Exports IO automaticaly
     * @ $configs - [
     *  'alias' => As nick for this instance (ex. 5 => [IO5])
     *  OutSystem::configs
     * ]
    */
    function __construct($ioNumeber, $autoExport = true, $configs = [])
    {
        $this->io = $ioNumeber;
        $this->direction = null;
        $this->value = null;
        $this->exported = false;
        
        $config = OutSystem::helpHandleAppName( 
            $configs,
            [
                "outSystemName" => (isset($configs['alias']) ? $configs['alias'] : 'IO' . $this->io),
                "outSystemEnabled" => true
            ]
        );
        $this->outSystem = new OutSystem($config);
    }

    public function open()
    {
        if (\file_put_contents('/sys/class/gpio/export', (string) $this->io) === false ||
            !\file_exists('/sys/class/gpio/gpio' . $this->io)) {
            
            $this->exported = false;
            return false;
        }

        $this->exported = true;
        return true;
    }

    public function getDirection($renew = false)
    {
        if ($this->direction === null || $renew) {
            $val = trim(\file_get_contents('/sys/class/gpio/gpio' . $this->io . '/direction'));
    
            if ($val === self::IO_BASE_DIR_IN) 
                $this->direction = self::IO_BASE_DIR_IN;
            else if ($val === self::IO_BASE_DIR_OUT)  
                $this->direction = self::IO_BASE_DIR_OUT;
            else 
                $this->direction = self::IO_BASE_DIR_ERR;
        } 

        return $this->direction;
    }

    public function setDirection(string $dir)
    {
        \file_put_contents('/sys/class/gpio/gpio' . $this->io . '/direction', (string) $dir);

        return $this->getDirection(true);
    }

    public function getValue($renew = false)
    {
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

        return $this->value;
    }

    public function setValue(int $val)
    {
        if ($this->getDirection() === self::IO_BASE_DIR_IN)
            return self::IO_BASE_VAL_ERR;

        \file_put_contents('/sys/class/gpio/gpio' . $this->io . '/value', (string) $val);

        return $this->getValue(true);
    }
}