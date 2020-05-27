<?php declare(strict_types=1);

namespace BrunoNatali\MqttClient;

use React\EventLoop\Factory;
use React\Socket\DnsConnector;
use React\Socket\TcpConnector;

use BrunoNatali\Tools\OutSystem;
use BrunoNatali\Tools\Queue;
use BrunoNatali\Tools\Communication\SimpleUnixServer;

use BinSoul\Net\Mqtt\Client\React\ReactMqttClient;
use BinSoul\Net\Mqtt\Connection;
use BinSoul\Net\Mqtt\DefaultMessage;
use BinSoul\Net\Mqtt\DefaultSubscription;
use BinSoul\Net\Mqtt\Message;
use BinSoul\Net\Mqtt\Subscription;

class MqttService implements MqttServiceInterface
{
    private $loop;
    private $client;
    private $server;

    Protected $outSystem;
    
    function __construct()
    {
        $this->loop = Factory::create();

        $config = ['outSystemName' => 'MqttSrv'];

        $this->server = new SimpleUnixServer($this->loop, self::MQTT_SERVICE_SOCKET, [$config]);

        $this->outSystem = new OutSystem($config);
        
        $dnsResolverFactory = new \React\Dns\Resolver\Factory();
        $connector = new DnsConnector(new TcpConnector($this->loop), $dnsResolverFactory->createCached('8.8.8.8', $this->loop));
        $this->client = new ReactMqttClient($connector, $this->loop);

        $this->configureCallbacks();
    }

    public function start()
    {

        $this->client->connect('test.mosquitto.org')->then( function () {
                // Subscribe to all topics
                $this->client->subscribe(new DefaultSubscription('#'))
                    ->then(function (Subscription $subscription) {
                        echo sprintf("Subscribe: %s\n", $subscription->getFilter());
                    })
                    ->otherwise(function (\Exception $e) {
                        echo sprintf("Error: %s\n", $e->getMessage());
                    });
        
                // Publish humidity once
                $this->client->publish(new DefaultMessage('sensors/humidity', '55%'))
                    ->then(function (Message $message) {
                        echo sprintf("Publish: %s => %s\n", $message->getTopic(), $message->getPayload());
                    })
                    ->otherwise(function (\Exception $e) {
                        echo sprintf("Error: %s\n", $e->getMessage());
                    });
        
                // Publish a random temperature every 10 seconds
                $generator = function () {
                    return mt_rand(-20, 30);
                };
        
                $this->client->publishPeriodically(10, new DefaultMessage('sensors/temperature'), $generator)
                    ->progress(function (Message $message) {
                        echo sprintf("Publish: %s => %s\n", $message->getTopic(), $message->getPayload());
                    })
                    ->otherwise(function (\Exception $e) {
                        echo sprintf("Error: %s\n", $e->getMessage());
                    });
        });

        $this->loop->run();
    }

    private function connectToBrocker()
    {
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
        
            
            $this->outSystem->stdout(
                ': ' . $message->getTopic().' => ' . mb_strimwidth($message->getPayload(), 0, 50, '...'), 
                OutSystem::LEVEL_NOTICE
            );
        });
        
        $this->client->on('warning', function (\Exception $e) {
            $this->outSystem->stdout("Warning: " . $e->getMessage(), OutSystem::LEVEL_NOTICE);
        });
        
        $this->client->on('error', function (\Exception $e){
            $this->outSystem->stdout("Error: " . $e->getMessage(), OutSystem::LEVEL_NOTICE);
        });
    }

}