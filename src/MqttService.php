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

    Protected $outSystem;
    
    function __construct()
    {
        $this->loop = Factory::create();

        $config = [
            'outSystemName' => 'MqttSrv',
            "outSystemEnabled" => true
        ];

        $this->server = new SimpleUnixServer($this->loop, self::MQTT_SERVICE_SOCKET, [$config]);

        $this->outSystem = new OutSystem($config);

        $this->hid = new HID($this->loop, $config);
        
        $dnsResolverFactory = new \React\Dns\Resolver\Factory();
        $connector = new DnsConnector(new TcpConnector($this->loop), $dnsResolverFactory->createCached('8.8.8.8', $this->loop));
        $this->client = new ReactMqttClient($connector, $this->loop);

        $this->configureCallbacks();
    }

    public function start()
    {
        $this->connectToBroker();

        $me = &$this;
        $this->hid->start(function ($sensor, $value) use ($me) {
            $this->postSensor($sensor, $value);
        });

        $this->loop->run();
    }

    private function connectToBroker()
    {
        $this->outSystem->stdout(
            "Connecting to: " . self::MQTT_BROKER_URI . 
                (self::MQTT_BROKER_PORT !== null ? ':' . self::MQTT_BROKER_PORT : ':1883'), 
            OutSystem::LEVEL_NOTICE
        );
            
        $this->client->connect(
            self::MQTT_BROKER_URI,
            (self::MQTT_BROKER_PORT !== null ? self::MQTT_BROKER_PORT : 1883),
            new DefaultConnection(
                (self::MQTT_USER_NAME !== null && self::MQTT_PASSWORD !== null ? self::MQTT_USER_NAME : ''), 
                (self::MQTT_USER_NAME !== null && self::MQTT_PASSWORD !== null ? self::MQTT_PASSWORD : ''), 
                null,
                self::MQTT_CLIENT_ID
            )
        )
        ->then( function () {

            // Subscribe to all configs
            $this->subscribe(self::MQTT_TENANT . '/config/+/' . self::MQTT_CLIENT_ID);
                
        }, function ($e) {
            var_dump($e);
        });
    }

    private function configureCallbacks()
    {

        $this->server->onData(function ($data, $id) {

        });

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

            } else {
                $this->outSystem->stdout( "Wrong data" , OutSystem::LEVEL_NOTICE);
            }
        });
        
        $this->client->on('warning', function (\Exception $e) {
            $this->outSystem->stdout("Warning: " . $e->getMessage(), OutSystem::LEVEL_NOTICE);
        });
        
        $this->client->on('error', function (\Exception $e){
            $this->outSystem->stdout("Error: " . $e->getMessage(), OutSystem::LEVEL_NOTICE);
        });
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
}