<?php declare(strict_types=1);

namespace BrunoNatali\MqttClient;

use React\EventLoop\Factory;
use React\Socket\DnsConnector;
use React\Socket\TcpConnector;

use BrunoNatali\Tools\Queue;
use BrunoNatali\Tools\OutSystem;
use BrunoNatali\Tools\Communication\SimpleUnixServer;

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
    private $client;
    private $server;
    private $hid;

    private $dns;

    Protected $outSystem;
    
    function __construct()
    {
        $this->loop = Factory::create();
        $this->client = null;

        $config = [
            'outSystemName' => 'MqttSrv',
            "outSystemEnabled" => true
        ];

        $this->server = new SimpleUnixServer($this->loop, self::MQTT_SERVICE_SOCKET, [$config]);

        $this->outSystem = new OutSystem($config);

        $this->hid = new HID($this->loop, $config);
        
        $this->dns = self::MQTT_DEFAULT_DNS;
    }

    public function start()
    {
        $this->connectToBroker();

        // $this->configureCallbacks();

        $this->hid->start(function ($sensor, $value) {
            $this->postSensor($sensor, $value);
        });

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

    public function postSensor($sensor, $value)
    {
        $topic = self::MQTT_TENANT  . '/sensors/' . self::MQTT_CLIENT_ID;
        $value = [
            'sensor' => $sensor,
            'value' => $value,
            'ts' => \time()
        ];

        return $this->publish($topic, \json_encode($value));
    }

    public function configureByJson($config)
    {
        if (!isset($config['user']))
            return "No user defined";

        $user = $config['user'];
        unset($config['user']);

        foreach ($config as $key => $conf) {
            switch ($key) {
                case 'eth':
                    if (!isset($conf['active']))
                        return "Misconfigured activation";

                        if ($conf['active']) {
                            
                            if (isset($conf['dns1']))
                                if (!$this->setDns($conf['dns1']))
                                    return "Misconfigured dns1";
                                    
                        } else {
    
                        }

                    break;
                case 'wifi':
                    if (!isset($conf['active']))
                        return "Misconfigured activation";

                    if ($conf['active']) {
                        
                        if (isset($conf['dns1']))
                            if (!$this->setDns($conf['dns1']))
                                return "Misconfigured dns1";

                    } else {

                    }

                    break;
                case 'broker':
                    $this->connectToBroker($conf);
                    break;
                
                default: // Sensor
                    if (!isset($conf['sensor']))
                        return "Misconfigured sensor";
                    
                    if (!isset($conf['period']))
                        return "Misconfigured period";
                        
                    if (!isset($conf['ts']))
                        return "Misconfigured ts";    

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
                        
                    if (($interface = $this->hid->getInterfaceByName($conf['sensor'])) === false)
                        return "Mismatch sensor";

                    $interface['time'] = $conf['period'];
                    $this->hid->registerInterface(
                        $interface, 
                        function ($sensor, $value) {
                            $this->postSensor($sensor, $value);
                        }
                    );
                    break;
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

    private function createClient($config)
    {
        $dnsResolverFactory = new \React\Dns\Resolver\Factory();
        $connector = new DnsConnector(new TcpConnector($this->loop), $dnsResolverFactory->createCached($this->dns, $this->loop));
        $this->client = new ReactMqttClient($connector, $this->loop);
        

        $this->client->on('open', function () {
            $this->outSystem->stdout("Opened -> " . $this->client->getHost() . ':' . $this->client->getPort(), OutSystem::LEVEL_NOTICE);
        });
        
        $this->client->on('close', function () {
            $this->outSystem->stdout("Closed -> " . $this->client->getHost() . ':' . $this->client->getPort(), OutSystem::LEVEL_NOTICE);
        });
        
        $this->client->on('connect', function (Connection $connection) {
            // Broker connected
            $this->outSystem->stdout("Broker connected: " . $connection->getClientID(), OutSystem::LEVEL_NOTICE);
        });
        
        $this->client->on('disconnect', function (Connection $connection) {
            // Broker disconnected
            $this->outSystem->stdout("Broker disconnected: " . $connection->getClientID(), OutSystem::LEVEL_NOTICE);
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
                ': ' . $message->getTopic().' => ' . mb_strimwidth($data, 0, 50, '...'), 
                OutSystem::LEVEL_NOTICE
            );

            if (is_array($data = \json_decode($data, true))) {
                if (!is_bool($result = $this->configureByJson($data)))
                    $this->outSystem->stdout( "Error config: $result" , OutSystem::LEVEL_NOTICE);
                else 
                    $this->outSystem->stdout( "Configured." , OutSystem::LEVEL_NOTICE);

            } else {
                $this->outSystem->stdout( "Wrong data" , OutSystem::LEVEL_NOTICE);
            }
        });
        
        $this->client->on('warning', function (\Exception $e) {
            $this->outSystem->stdout("Warning: " . $e->getMessage(), OutSystem::LEVEL_NOTICE);
        });
        
        $this->client->on('error', function (\Exception $e) use ($config) {
            $this->outSystem->stdout("Broker error: " . $e->getMessage() .
                ' Scheduling error handler to 10s ...' , OutSystem::LEVEL_NOTICE);

            $this->loop->addTimer(10, function () use ($config) {
                $this->connectToBroker($config);
            });
        });
    }

    private function connectToBroker($config = [])
    {
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
            $this->createClient($config);
        }

        $this->outSystem->stdout(
            "Connecting to: " . $config['uri'] . 
                ($config['port'] !== null ? ':' . $config['port'] : ':1883'), 
            OutSystem::LEVEL_NOTICE
        );
            
        $this->client->connect(
            $config['uri'],
            ($config['port'] !== null ? $config['port'] : 1883),
            new DefaultConnection(
                ($config['user'] !== null && $config['password'] !== null ? $config['user'] : ''), 
                ($config['user'] !== null && $config['password'] !== null ? $config['password'] : ''), 
                null,
                self::MQTT_CLIENT_ID
            )
        )
        ->then( function () {

            // Subscribe to all configs
            $this->subscribe(self::MQTT_TENANT . '/config/+/' . self::MQTT_CLIENT_ID);
                
        }/*, function ($e) use ($config) { // Check if necessary -> Error already handled in createClient()
            $this->outSystem->stdout("Broker connect error: " . $e->getMessage() .
                ' Rescheduling to 10s ...' , OutSystem::LEVEL_NOTICE);

            $this->loop->addTimer(10, function () use ($config) {
                $this->connectToBroker($config);
            });
        }*/);
    }

    private function configureCallbacks()
    {

        $this->server->onData(function ($data, $id) {

        });
    }
}