<?php declare(strict_types=1);

/**
 * 
 * NOTAS
 * 
 * Número de linhas recuperadas da base de dados precisa ser maior que o número de sensores, 
 *  se não uma coleta de todos os sensores no mesmo minuto irá fazer com que o sistema que 
 *  reenvia os dados não publicados permaneça em loop infinito por não identificar corretamente 
 *  o último segundo de publicação bem sucedida 
 * 
*/


namespace BrunoNatali\MqttClient;

use React\Promise\Deferred;
use React\EventLoop\Factory;
use React\Socket\DnsConnector;
use React\Socket\TcpConnector;

use BrunoNatali\Tools\Queue;
use BrunoNatali\Tools\Mysql;
use BrunoNatali\Tools\OutSystem;
use BrunoNatali\Tools\File\JsonFile;
use BrunoNatali\Tools\Communication\SimpleUnixServer;
use BrunoNatali\Tools\Communication\SimpleUnixClient;
use BrunoNatali\Tools\Data\Conversation\UnixServicePort;
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
    private $publishQueue;
    private $client;
    private $hidClient;
    private $server;
    private $service;
    private $mysql;

    private $config;
    private $sysConfig;
    private $dns;

    private $hidConnStatus = false;
    private $brokerConnStatus = false;
    private $dbStatus = true;

    private $id;
    private $queueTO;
    private $reconnectionScheduled = null;
    private $postSensorBuffer = [];
    private $postSensorsTimer = null;
    private $postErrorSensor = true; // Starts with true to send every unsended

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

        $dbCreddentials = JsonFile::readAsArray('/etc/desh/db.json');

        // Using default user & password
        $dbCreddentials += [
            'USER' => 'root', 
            'PASSWORD' => ''
        ];

        try {
            $this->mysql = new Mysql(
                $this->loop,
                \array_merge(
                    $this->sysConfig,
                    [
                        'SERVER' => 'localhost',
                        'SOLVE_PROBLEMS' => true, 
                        'DB' => 'mqtt'
                    ],
                    $dbCreddentials
                )
            );
        } catch (\Throwable $e) {
            $this->dbStatus = false;
            $this->service->reportStatus(
                self::MQTT_ERROR_COULD_NOT_INITILIZE_DB, 
                false, 
                "DB init error: " . $e->getMessage()
            );
        }

        $this->server = new SimpleUnixServer($this->loop, self::MQTT_SERVICE_SOCKET, $this->sysConfig);

        // Connect to HID 
        $this->hidClient = new SimpleUnixClient($this->loop, 'hid-service.sock', $this->sysConfig);

        $this->client = null; // Set MQTT client

        $this->queue = new Queue($this->loop);
        $this->publishQueue = new Queue($this->loop);

        $this->outSystem = new OutSystem($this->sysConfig);
        
        $this->service = new UnixServicePort($this->loop, 'mqtt', $this->sysConfig);

        /* NEED TO IMPLEMENT CONFIGURATION BY SERVICE PORT - IMPORTANT!!
            $this->service->addParser(self::SERIAL_CONFIG_PORT, function($content, $id, $myServer) {
                if (isset($content['port'])) {
                    if (!$this->portExists($content['port'])) {
                        $this->service->info = 'Port error ' . $content['port'];
                        return false;
                    }

                    $port = $content['port'];
                    unset($content['port']);

                    $newConfig = [
                        'config' => [
                            $port => []
                        ]
                    ];

                    foreach ($content as $key => $value) 
                        if ($key === 'enabled') { 
                            // Only bool values accepeted
                            if (\is_bool($value)) {
                                $newConfig['config'][$port]['enabled'] = $value;
                            } else {
                                $this->service->info = 'Enable error';
                                return false;
                            }
                        } else if ($key === 'baud') {
                            // Only int values accepeted & with specific range
                            if (\is_int($value) && 
                                $value >= self::SERIAL_MINIMUM_BAUD &&
                                $value <= self::SERIAL_MAXIMUM_BAUD) {
                                $newConfig['config'][$port]['baud'] = $value;
                            } else {
                                $this->service->info = 'Baud error';
                                return false;
                            }
                        } else if ($key === 'bits') {
                            // Only int values accepeted
                            if (\is_int($value)) {
                                $newConfig['config'][$port]['bits'] = $value;
                            } else {
                                $this->service->info = 'Bits error';
                                return false;
                            }
                        } else if ($key === 'stop') {
                            // Only int values accepeted
                            if (\is_int($value)) {
                                $newConfig['config'][$port]['stop'] = $value;
                            } else {
                                $this->service->info = 'Stop error';
                                return false;
                            }
                        } else if ($key === 'parity') {
                            // Only int values accepeted
                            if (\is_int($value)) {
                                $newConfig['config'][$port]['parity'] = $value;
                            } else {
                                $this->service->info = 'Parity error';
                                return false;
                            }
                        } else if ($key === 'pack_len') {
                            // Only int values accepeted
                            if (\is_int($value)) {
                                $newConfig['config'][$port]['pack_len'] = $value;
                            } else {
                                $this->service->info = 'pack_len error';
                                return false;
                            }
                        } else if ($key === 'close_pack_time') {
                            // Only int values accepeted
                            if (\is_int($value)) {
                                $newConfig['config'][$port]['close_pack_time'] = $value;
                            } else {
                                $this->service->info = 'close_pack_time error';
                                return false;
                            }
                        } else if ($key === 'flow_ctrl') { 
                            // Only bool values accepeted
                            if (\is_bool($value)) {
                                $newConfig['config'][$port]['flow_ctrl'] = $value;
                            } else {
                                $this->service->info = 'flow_ctrl error';
                                return false;
                            }
                        }  else if ($key === 'remove_method') { 
                            // Only bool values accepeted
                            if (\is_bool($value)) {
                                $newConfig['config'][$port]['data']['method'] = null;
                            } else {
                                $this->service->info = 'disable meth';
                                return false;
                            }
                        } else {
                            $this->service->info = "Unrecognized '$key'";
                            return false; // Return if any provided value is not expected
                        }
                        
                    if (!empty($newConfig['config'][$port])) {
                        if ($this->saveConfig($newConfig)) {
                            if ($this->close($port)) {
                                $this->config['config'][ $port ] = 
                                    $newConfig['config'][ $port ];

                                if ($newConfig['config'][ $port ]['enabled'])
                                    return $this->openPort($port);
                                else
                                    return true;
                            } else {
                                $this->service->info = "Close error";
                            }
                        } else {
                            $this->service->info = "Save error";
                        }
                    } else {
                        $this->service->info = "New config";
                    }
                } else {
                    $this->service->info = "Undefined port";
                }

                if (!$this->service->info)
                    $this->service->info = "General error";

                return false;
            });
        */
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
            'mqtt_broker' => \BrunoNatali\Tools\UsefulFunction::array_merge_recursive($this->config, $conf)
        ];

        if (JsonFile::saveArray( '/etc/desh/config.json', $config, 
            false, JSON_PRETTY_PRINT))
            return true;

        $this->service->reportStatus(self::MQTT_ERROR_SAVE_CONFIG, false);

        return false;
    }

    public function start()
    {
        if (!$this->config['enabled']) {
            $this->outSystem->stdout("Module is disabled. Enable & re-run", OutSystem::LEVEL_NOTICE);
            return;
        }


        $this->configureCallbacks();

        $this->connectToBroker();

        $this->hidClient->connect();
        
        $this->service->start();

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
    public function publish($topic, $value, $ts = false)
    {
        // if (empty($this->postSensorBuffer))
        //    return \React\Promise\resolve(null);

        if ($this->client->isConnected()) {
            $deferred = new Deferred();

            $this->client->publish(new DefaultMessage($topic, $value))
                ->then(function (Message $message) use ($topic, $value, $ts, $deferred) {

                $this->outSystem->stdout(
                    "Published: " . $message->getTopic() . ' => ' . $message->getPayload(), 
                    OutSystem::LEVEL_NOTICE
                );
                
                if (\is_int($ts)) { // If is from postUncommited()
                    $this->mysql->insert(
                        [
                            'topic' => $topic,
                            'len' => \strlen($value),
                            'ts' => $ts,
                            'real_ts' => \time()
                        ],
                        [
                            'table' => 'post_history' 
                        ]
                    );
                } else {

                    if ($this->postUncommited()) { // Is something not commited to post
                        $this->publishQueue->pause(); // Pause queue before add new
                    } else {
                        $this->publishQueue->resume();
                    }

                    $this->publishQueue->push(function () use ($topic, $value, $ts) {
        
                        $this->mysql->insert(
                            [
                                'topic' => $topic,
                                'len' => \strlen($value),
                                'ts' => \time()
                            ],
                            [
                                'table' => 'post_history' 
                            ]
                        );
                        
                    });
                }

                $deferred->resolve(true);
            })
            ->otherwise(function (\Exception $e) use ($deferred) {
                $this->outSystem->stdout("Publish error: " . $e->getMessage(), OutSystem::LEVEL_NOTICE);

                $deferred->reject(new \Exception($e->getMessage()));
            });

            return $deferred->promise();
        }

        $this->outSystem->stdout("[publish] Broker disconnected, aborting ...", OutSystem::LEVEL_NOTICE);

        return \React\Promise\reject(new \Exception("Broker disconnected"));
    }

    private function postUncommited()
    {
        if ($this->postErrorSensor) {
            $this->outSystem->stdout("[Uncommited] Starting ... ", OutSystem::LEVEL_NOTICE);

            $lastPost = $this->mysql->read(
                [
                    'table' => 'post_history',
                    'select' => ['id','topic', 'ts']
                ],
                " WHERE topic LIKE '" . $this->config['config']['tenant']  . 
                    '/sensors/' . $this->id . "' ORDER BY id DESC LIMIT 1" // Get last line
            );

            if (!empty($lastPost)) {
                $this->outSystem->stdout(
                    '[Uncommited] Using id ' . $lastPost[0]['id'] . ', topic ' . $lastPost[0]['topic'] .
                        ', from ' . $lastPost[0]['ts'],
                    OutSystem::LEVEL_NOTICE
                );

                $sensors = $this->mysql->read(
                    [ 
                        'table' => 'sensor_history',
                        'select' => ['sensor','value', 'ts']
                    ],
                    "WHERE ts >= " . $lastPost[0]['ts'] . " LIMIT 30" // GET last 30 lines
                );
                
                if (!empty($sensors)) {

                    $numSensors = count($sensors);

                    $this->outSystem->stdout(
                        "[Uncommited] Publishing to " . $sensors[($numSensors - 1)]['ts'], 
                        OutSystem::LEVEL_NOTICE
                    );

                    $this->publish(
                        $lastPost[0]['topic'], 
                        \json_encode($sensors),
                        intval($sensors[($numSensors - 1)]['ts']) // register last sensor ts as post time
                    )->then(function ($ret) {
                        $this->postUncommited();
                    });

                    $this->outSystem->stdout("[Uncommited] Selected $numSensors", OutSystem::LEVEL_NOTICE);

                    if ($numSensors < 30) // Last lines
                        $this->postErrorSensor = false;

                } else {
                    $this->outSystem->stdout("[Uncommited] Selected ZERO", OutSystem::LEVEL_NOTICE);
                    $this->postErrorSensor = false;
                }
            } else {
                $this->outSystem->stdout("[Uncommited] No last posts", OutSystem::LEVEL_NOTICE);
                return false; // <---- Check if it will not cause any error
            }
        }

        return $this->postErrorSensor;

        if ($this->postErrorConfig) {
            $lastPost = $this->mysql->read(
                [
                    'table' => 'post_history',
                    'select' => ['id','topic', 'ts']
                ],
                " WHERE topic LIKE '" . $this->config['config']['tenant']  . 
                    "/config/%' ORDER BY id DESC LIMIT 1" // Get last line
            );

            if (!empty($lastPost)) {
                $sensors = $this->mysql->read(
                    [ 
                        'table' => 'sensor_history',
                        'select' => ['sensor','value', 'ts']
                    ],
                    " WHERE ts >= " . $lastPost[0]['ts'] . " LIMIT 20" // GET last 20 lines
                );

                if (!empty($sensors)) {

                    $this->loop->futureTick(function () use ($lastPost, $sensors) {
                        $this->publish(
                            $lastPost[0]['topic'], 
                            $value,
                            $sensors[count($sensors) - 1]['ts'] // register last sensor ts as post time
                        )->then(function () {
                            $this->postUncommited();
                        });
                    });

                    if (count($sensors) < 20) // Last lines
                        $this->postErrorSensor = false;

                } else {
                    $this->postErrorSensor = false;
                }
            }
        }
        
        return ($this->postErrorSensor === false);

    }


    public function sensorData($sensor, $value, $ts)
    {
        $data = [
            'sensor' => $sensor,
            'value' => $value,
            'ts' => $ts
        ];

        $this->postSensorBuffer[] = $data;

        $this->mysql->insert(
            $data,
            [
                'table' => 'sensor_history' 
            ]
        );

        return true;
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

        $this->publish($topic, $data)->otherwise(function () {
            $this->postErrorConfig = true;
        });

        return;
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
            if ($key === 'eth-disabled') {
                if (!isset($conf['active']))
                return "Misconfigured activation";

                if ($conf['active']) {
                    
                    if (isset($conf['dns1']))
                        if (!$this->setDns($conf['dns1']))
                            return "Misconfigured dns1";
                            
                } else {

                }
            } else if ($key === 'wifi-disabled') {
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
                    // Remove post sensors schedulle
                    if ($this->postSensorsTimer !== null) {
                        $this->loop->cancelTimer($this->postSensorsTimer);
                        $this->postSensorsTimer = null;
                    }

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

            $this->brokerConnStatus = true;
            
            if ($this->hidConnStatus && $this->dbStatus && $this->service->getGlobalStatus() !== 0)
                $this->service->reportStatus(0, false);

            $this->outSystem->stdout("Opened -> " . $this->client->getHost() . ':' . $this->client->getPort(), OutSystem::LEVEL_NOTICE);
        });
        
        $this->client->on('close', function () {
            $this->outSystem->stdout("Closed -> " . $this->client->getHost() . ':' . $this->client->getPort(), OutSystem::LEVEL_NOTICE);
       
            $this->brokerConnStatus = false;

            if ($this->service->getGlobalStatus() !== self::MQTT_ERROR_BROKER_CONNECTION_LOST)
                $this->service->reportStatus(self::MQTT_ERROR_BROKER_CONNECTION_LOST, false, "Broker conn lost");

            $this->scheduleReconnection();
        });
        
        $this->client->on('connect', function (Connection $connection) {

            $this->brokerConnStatus = true;
            
            if ($this->hidConnStatus && $this->dbStatus && $this->service->getGlobalStatus() !== 0)
                $this->service->reportStatus(0, false);

            $this->outSystem->stdout("Broker connected: " . $connection->getClientID(), OutSystem::LEVEL_NOTICE);
        });
        
        $this->client->on('disconnect', function (Connection $connection) {
            $this->outSystem->stdout("Broker disconnected: " . $connection->getClientID(), OutSystem::LEVEL_NOTICE);

            $this->brokerConnStatus = false;

            if ($this->service->getGlobalStatus() !== self::MQTT_ERROR_BROKER_CONNECTION_LOST)
                $this->service->reportStatus(self::MQTT_ERROR_BROKER_CONNECTION_LOST, false, "Broker conn lost");

            $this->scheduleReconnection();
        });
        
        $this->client->on('warning', function (\Exception $e) {
            $this->outSystem->stdout("Warning: " . $e->getMessage(), OutSystem::LEVEL_NOTICE);
        });
        
        $this->client->on('error', function (\Exception $e) {
            $this->outSystem->stdout("Broker error: " . $e->getMessage() .
                ' Scheduling error handler to ' . self::MQTT_RECONNECT_TO . 's ...' , OutSystem::LEVEL_NOTICE);

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

            $data = \json_decode($data, true);
            if (\is_array($data)) {
                $result = $this->configureByJson($data);

                if (!\is_bool($result))
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
        if ($this->reconnectionScheduled !== null)
            return;     

        $this->brokerConnStatus = false;

        if ($this->service->getGlobalStatus() !== self::MQTT_ERROR_BROKER_CONNECTION_LOST)
            $this->service->reportStatus(self::MQTT_ERROR_BROKER_CONNECTION_LOST, false, "Broker conn lost");

        $this->reconnectionScheduled =
            $this->loop->addTimer(self::MQTT_RECONNECT_TO, function () {
                $this->reconnectionScheduled = null;

                $this->connectToBroker();
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

        if (\is_object($this->client)) {

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

            // Send unpublished data
            $this->postUncommited();

            $this->brokerConnStatus = true;

            if ($this->hidConnStatus && $this->dbStatus && $this->service->getGlobalStatus() !== 0)
                $this->service->reportStatus(0, false);
                
        }, function () { // Force reconnection on error
            $this->brokerConnStatus = false;

            if ($this->service->getGlobalStatus() !== self::MQTT_ERROR_BROKER_CONNECTION_LOST)
                $this->service->reportStatus(self::MQTT_ERROR_BROKER_CONNECTION_LOST, false, "Broker conn lost");

            $this->scheduleReconnection();
        });

        // Schedule post sensors
        $this->postSensorsTimer = $this->loop->
            addPeriodicTimer($this->config['config']['post']['time'] , function() {
            if (empty($this->postSensorBuffer))
                return;

            $this->outSystem->stdout("Broadcasting sensors", OutSystem::LEVEL_NOTICE);
            
            $data = \json_encode($this->postSensorBuffer);
            
            // Post sensors on mqtt server
            $topic = $this->config['config']['tenant']  . '/sensors/' . $this->id;
            
            $this->publish($topic, $data)->otherwise(function () {
                $this->postErrorSensor = true;
            });

            // Sent data to unix service clients too
            $this->service->server->write($data);

            $this->postSensorBuffer = []; // Empty buffer
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

                        $this->outSystem->stdout("SENSOR DATA", OutSystem::LEVEL_NOTICE);
                        $this->sensorData($data['name'], $data['value'], $data['ts']);

                    } else if ($data['ident'] === HIDInterface::HID_DATA_TYPE_CFG) { // Sensor configured

                        $this->outSystem->stdout("SENSOR CONFIG", OutSystem::LEVEL_NOTICE);
                        $this->postConfig('sensor', $data['name'], $data['time'], \time());

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

            $this->hidConnStatus = true;

            if ($this->brokerConnStatus && $this->dbStatus && $this->service->getGlobalStatus() !== 0)
                $this->service->reportStatus(0, false);

            $this->dequeue();
        });

        $this->hidClient->onClose(function () {
            $this->outSystem->stdout("Connection to HID closed", OutSystem::LEVEL_NOTICE);

            $this->hidConnStatus = false;

            if ($this->service->getGlobalStatus() !== self::MQTT_ERROR_HID_CONNECTION_LOST)
                $this->service->reportStatus(self::MQTT_ERROR_HID_CONNECTION_LOST, false, "HID conn lost");
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