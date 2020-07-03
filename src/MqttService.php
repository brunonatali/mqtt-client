<?php declare(strict_types=1);

namespace BrunoNatali\MqttClient;

use React\EventLoop\Factory;
use React\Socket\DnsConnector;
use React\Socket\TcpConnector;

use BrunoNatali\Tools\Queue;
use BrunoNatali\Tools\OutSystem;
use BrunoNatali\Tools\File\JsonFile;
use BrunoNatali\Tools\Communication\SimpleUnixServer;
use BrunoNatali\Tools\Communication\SimpleUnixClient;
use BrunoNatali\WebInterface\HIDInterface;

use BinSoul\Net\Mqtt\Message;
use BinSoul\Net\Mqtt\Connection;
use BinSoul\Net\Mqtt\Subscription;
use BinSoul\Net\Mqtt\DefaultMessage;
use BinSoul\Net\Mqtt\DefaultConnection;
use BinSoul\Net\Mqtt\DefaultSubscription;
use BinSoul\Net\Mqtt\Client\React\ReactMqttClient;

class MqttService implements MqttServiceInterface
{
    private $loop;
    private $queue;
    private $client;
    private $hidClient;
    private $server;

    private $config;
    private $sysConfig;
    private $dns;

    private $id;
    private $queueTO;
    private $reconnectionScheduled;

    Protected $outSystem;
    
    function __construct()
    {
        $this->loop = Factory::create();

        // Get serial
        $id = @\file_get_contents('/etc/desh/serial');
        if ($id === false)
            $this->id = self::MQTT_CLIENT_ID;
        else
            $this->id = \trim($id);

        $this->loadConfig();

        $this->queueTO = null;

        $this->sysConfig = [
            'outSystemName' => 'MQTT',
            "outSystemEnabled" => true
        ];

        $this->server = new SimpleUnixServer($this->loop, self::MQTT_SERVICE_SOCKET, $this->sysConfig);

        // Connect to HID 
        $this->hidClient = new SimpleUnixClient($this->loop, 'hid.sock', $this->sysConfig);

        $this->client = null; // Set MQTT client

        $this->queue = new Queue($this->loop);

        $this->outSystem = new OutSystem($this->sysConfig);

        $this->reconnectionScheduled = false;
    }

    private function loadConfig()
    {
        $rC = JsonFile::readAsArray('/etc/desh/config.json');
        if (isset($rC['mqtt_broker']))
            $this->config = $rC['mqtt_broker'];
        else
            $this->config = [
                'enabled' => false,
                'config' => []
            ];
        
        if (isset($rC['network']) && isset($rC['network']['dns']))
            $this->dns = $rC['network']['dns'];
        else
            $this->dns = self::MQTT_DEFAULT_DNS;
    }

    private function saveConfig($conf): bool
    {
        $this->loadConfig();

        $config = [
            'mqtt_broker' => \array_merge($this->config, $conf)
        ];

        if (JsonFile::saveArray( '/etc/desh/config.json', $config, 
            false, JSON_PRETTY_PRINT))
            return true;

        return false;
    }

    public function start()
    {
        $this->configureCallbacks();

        $this->connectToBroker();

        $this->hidClient->connect();

        $this->loop->run();
    }

    // '#'
    public function subscribe($topic)
    {
        if ($this->client->isConnected()) {
            $this->client->subscribe(new DefaultSubscription($topic))
                ->then(function (Subscription $subscription) {
                    $this->outSystem->stdout("Subscribed: " . $subscription->getFilter(), OutSystem::LEVEL_NOTICE);
                })
                ->otherwise(function (\Exception $e) {
                    $this->outSystem->stdout("Subscription error: " . $e->getMessage(), OutSystem::LEVEL_NOTICE);
                });

            return true;
        }

        return false;
    }

    // 'sensors/humidity', '55%'
    public function publish($topic, $value)
    {
        if ($this->client->isConnected()) {
            $this->client->publish(new DefaultMessage($topic, $value))
                ->then(function (Message $message) {
                    $this->outSystem->stdout("Published: " . $message->getTopic() . ' => ' . $message->getPayload(), OutSystem::LEVEL_NOTICE);
                })
                ->otherwise(function (\Exception $e) {
                    $this->outSystem->stdout("Publish error: " . $e->getMessage(), OutSystem::LEVEL_NOTICE);
                });

            return true;
        }
        $this->outSystem->stdout("[publish] Broker disconnected, aborting ...", OutSystem::LEVEL_NOTICE);

        return false;
    }

    public function postSensor($sensor, $value, $ts)
    {
        $topic = $this->config['config']['tenant']  . '/sensors/' . $this->id;
        $value = [
            'sensor' => $sensor,
            'value' => $value,
            'ts' => $ts
        ];

        return $this->publish($topic, \json_encode($value));
    }

    private function postConfig($type, $name, $value, $ts)
    {
        //a/config/+/1234567890abcdef12

        if ($type === 'sensor') {
            $topic = $this->config['config']['tenant']  . '/config/sensors/' . $this->id;
            $value = [
                'user' => 'device',
                [
                    'sensor' => $name,
                    'period' => $value,
                    'ts' => $ts
                ]
            ];
        } else if ($type === 'device') {
            $topic = $this->config['config']['tenant']  . '/config/device/' . $this->id;
            $value = [
                'user' => 'device',
                [
                    $name => $value,
                    'ts' => $ts
                ]
            ];
        }

        return $this->publish($topic, \json_encode($value));
    }

    private function configureSensor($name, $time)
    {
        $this->queue->push(function () use ($name, $time) {
            $this->hidClient->write(\json_encode([
                'ident' => HIDInterface::HID_DATA_TYPE_CFG,
                'name' => $name,
                'time' => $time
            ]));
        });
    }

    public function configureByJson($config)
    {
        if (!isset($config['user']))
            return "No user defined";

        if ($config['user'] === 'device')
            return "Configuration from device";

        $user = $config['user'];
        unset($config['user']);

        foreach ($config as $key => $conf) {
            if ($key === 'eth') {
                if (!isset($conf['active']))
                return "Misconfigured activation";

                if ($conf['active']) {
                    
                    if (isset($conf['dns1']))
                        if (!$this->setDns($conf['dns1']))
                            return "Misconfigured dns1";
                            
                } else {

                }
            } else if ($key === 'wifi') {
                if (!isset($conf['active']))
                    return "Misconfigured activation";

                if ($conf['active']) {
                    
                    if (isset($conf['dns1']))
                        if (!$this->setDns($conf['dns1']))
                            return "Misconfigured dns1";

                } else {

                }
            } else if ($key === 'broker') {
                if ($this->saveConfig($conf)) {
                    $this->loadConfig();
                    $this->connectToBroker();
                } else {
                    return "Broker config not accepted";
                }
            } else {// Sensor
                if (!isset($conf['sensor']))
                    return "Misconfigured sensor";
                
                if (!isset($conf['period']))
                    return "Misconfigured period";
                    
                if (!isset($conf['ts']))
                    return "Misconfigured ts";    

                /* Not handled in this version
                if ($conf['sensor'] === 'global') {
                    foreach ($this->hid->getInterfaceByName(true) as $interface) {
                        $interface['time'] = $conf['period'];
                        $this->hid->registerInterface(
                            $interface, 
                            function ($sensor, $value) {
                                $this->postSensor($sensor, $value);
                            }
                        );
                    }

                    break;
                }
                */
                $this->configureSensor($conf['sensor'], $conf['period']);
            }

            
        }

        return true;
    }

    private function setDns($dns): bool
    {
        if (!\filter_var($dns, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
            return false;
        
        $this->dns = $dns;

        return true;
    }

    private function createClient()
    {
        $dnsResolverFactory = new \React\Dns\Resolver\Factory();

        $connector = new DnsConnector(
            new TcpConnector($this->loop), 
            $dnsResolverFactory->createCached($this->dns, $this->loop)
        );
        $this->client = new ReactMqttClient($connector, $this->loop);
        

        $this->client->on('open', function () {
            $this->outSystem->stdout("Opened -> " . $this->client->getHost() . ':' . $this->client->getPort(), OutSystem::LEVEL_NOTICE);
        });
        
        $this->client->on('close', function () {
            $this->outSystem->stdout("Closed -> " . $this->client->getHost() . ':' . $this->client->getPort(), OutSystem::LEVEL_NOTICE);
       
            $this->scheduleReconnection();
        });
        
        $this->client->on('connect', function (Connection $connection) {
            $this->outSystem->stdout("Broker connected: " . $connection->getClientID(), OutSystem::LEVEL_NOTICE);
        });
        
        $this->client->on('disconnect', function (Connection $connection) {
            $this->outSystem->stdout("Broker disconnected: " . $connection->getClientID(), OutSystem::LEVEL_NOTICE);

            $this->scheduleReconnection();
        });
        
        $this->client->on('warning', function (\Exception $e) {
            $this->outSystem->stdout("Warning: " . $e->getMessage(), OutSystem::LEVEL_NOTICE);
        });
        
        $this->client->on('error', function (\Exception $e) {
            $this->outSystem->stdout("Broker error: " . $e->getMessage() .
                ' Scheduling error handler to 10s ...' , OutSystem::LEVEL_NOTICE);

            $this->scheduleReconnection();
        });
        
        $this->client->on('message', function (Message $message) {
            // Incoming message
            $this->outSystem->stdout("Message", false, OutSystem::LEVEL_NOTICE);
        
            if ($message->isDuplicate()) 
                $this->outSystem->stdout(" (duplicate)", false, OutSystem::LEVEL_NOTICE);
        
            if ($message->isRetained()) 
                $this->outSystem->stdout(" (retained)", false, OutSystem::LEVEL_NOTICE);
        
            $data = $message->getPayload();
            $this->outSystem->stdout(
                //': ' . $message->getTopic().' => ' . mb_strimwidth($data, 0, 50, '...'), // Truncate $data 50 chars
                ': ' . $message->getTopic() . " => $data", 
                OutSystem::LEVEL_NOTICE
            );

            if (is_array($data = \json_decode($data, true))) {
                if (!is_bool($result = $this->configureByJson($data)))
                    $this->outSystem->stdout( "Error config: $result" , OutSystem::LEVEL_NOTICE);
                else 
                    $this->outSystem->stdout( "Configured / Scheduled." , OutSystem::LEVEL_NOTICE);

            } else {
                $this->outSystem->stdout( "Wrong data" , OutSystem::LEVEL_NOTICE);
            }
        });
    }

    /**
     * Help handle multiple errors in same time
    */
    private function scheduleReconnection()
    {
        if ($this->reconnectionScheduled)
            return;

        $this->reconnectionScheduled = true;

        $this->loop->addTimer(self::MQTT_RECONNECT_TO, function () use ($config) {
            $this->connectToBroker($config);
        });
    }

    private function connectToBroker()
    {
        $config = &$this->config['config'];

        if (!isset($config['uri']))
            $config['uri'] = self::MQTT_BROKER_URI;
            
        if (!isset($config['port']))
            $config['port'] = self::MQTT_BROKER_PORT;

        if (!isset($config['user']))
            $config['user'] = self::MQTT_USER_NAME;

        if (!isset($config['password']))
            $config['password'] = self::MQTT_PASSWORD;

        if (is_object($this->client)) {

            if ($this->client->isConnected()) {
                $this->outSystem->stdout("Disconnecting client first ...", OutSystem::LEVEL_NOTICE);
    
                $this->client->disconnect()->then( function () use ($config) {
                    $this->connectToBroker($config);
                }, function ($e) {
                    $this->outSystem->stdout("Disconnect fail, rescheduling to 10s. Detailed error: " .
                        $e->getMessage() , OutSystem::LEVEL_NOTICE);
    
                    $this->loop->addTimer(10, function () use ($config) {
                        $this->connectToBroker($config);
                    });
                });

                return;
            }

        } else {
            $this->createClient();
        }

        $this->outSystem->stdout(
            "Connecting to: " . $config['uri'] . 
                (is_integer($config['port']) && $config['port'] ? ':' . $config['port'] : ':1883'), 
            OutSystem::LEVEL_NOTICE
        );
            
        $this->client->connect(
            $config['uri'],
            (isset($config['port']) && $config['port'] ? $config['port'] : 1883),
            new DefaultConnection(
                ($config['user'] !== null && $config['password'] !== null ? $config['user'] : ''), 
                ($config['user'] !== null && $config['password'] !== null ? $config['password'] : ''), 
                null,
                $this->id
            )
        )
        ->then( function() use ($config) {

            // Subscribe to all configs
            $this->subscribe($config['tenant']. '/config/+/' . $this->id);
                
        });
    }

    private function configureCallbacks()
    {
        $this->hidClient->onData(function ($data){

            $this->outSystem->stdout("HIDClient Data: '$data' => ", false, OutSystem::LEVEL_NOTICE);

            $data = \json_decode($data, true);

            if (\is_array($data)) {

                if (isset($data['ident'])) {
                    if ($data['ident'] === HIDInterface::HID_DATA_TYPE_ACK) { // Config accept
                        $this->outSystem->stdout("ACK", OutSystem::LEVEL_NOTICE);
                        $this->dequeue();
                    } else if ($data['ident'] === HIDInterface::HID_DATA_TYPE_NACK) { // Config not accept
                        $this->outSystem->stdout("NACK", OutSystem::LEVEL_NOTICE);
                        $this->dequeue();
                    } else if ($data['ident'] === HIDInterface::HID_DATA_TYPE_ACQ) { // Sensor data
                        $this->postSensor($data['name'], $data['value'], $data['ts']);
                    } else if ($data['ident'] === HIDInterface::HID_DATA_TYPE_CFG) { // Sensor configured
                        $this->postConfig('sensor', $data['name'], $data['time'], \time());;
                    } else {
                        // unrecognized data
                        
                        $this->outSystem->stdout("UNKNW", OutSystem::LEVEL_NOTICE);
                    }
                }

            } else {
                $this->outSystem->stdout("ERROR", OutSystem::LEVEL_NOTICE);
            }
        });

        $this->hidClient->onConnect(function () {
            $this->outSystem->stdout("Connected to HID", OutSystem::LEVEL_NOTICE);
            $this->dequeue();
        });

        $this->hidClient->onClose(function () {
            //echo "CLOSED !!! " .PHP_EOL;
        });
    }

    private function dequeue()
    {
        
        if ($this->queueTO !== null) {
            $loop->cancelTimer($this->queueTO);
            $this->queueTO = null;
        }
        
        $this->queue->resume(false); // Just resume
        if ($run = $this->queue->next(1)) {
            $this->queueTO = $loop->addTimer(5.0, function () use ($disconnect, &$error) { // Wait 5s before force next
                $this->dequeue();
            });

            ($run);
        }
    }
}