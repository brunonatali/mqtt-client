<?php

\chdir('..'); // Change to root dir
$appName = \basename(\getcwd()); // Get APP dir name
$pbin = __DIR__ . '/../pbin'; // Set bin folder 
$instructionsFile = __DIR__ . '/install-instructions.json'; // Set instructions file

echo 'Starting ' . $appName . ' instalation' . PHP_EOL;

// Make all bin file executable
if (\file_exists($pbin)) {
    $binFolder = \array_diff(\scandir($pbin), ['..', '.']);

    foreach ($binFolder as $bin) 
        \chmod($pbin . '/' . $bin, 0755);
}

// Break if no instructions
if (!\file_exists($instructionsFile) ||
    !\is_array(($instructions = json_decode(\file_get_contents($instructionsFile), true)))) { // Load instructions
    echo 'No instructions. Nothing else to do.' . PHP_EOL;
    exit;
}

// Install bin files 
if (isset($instructions['sys-bin-files'])) {
    foreach ($instructions['sys-bin-files'] as $bin) {
        $symLink = '/usr/sbin/' . basename($bin, '.php');

        if (\file_exists($symLink))
            unlink($symLink);

        $out = [];
        $result = run("ln -s $pbin/$bin $symLink", $out);

        if ($result !== 0)
            echo "Error while installing '$bin'" . PHP_EOL;
    }
}

// Install services
if (isset($instructions['service'])) {
    foreach ($instructions['service'] as $key => $service) {

        if (!isset($service['name']) || !isset($service['bin'])) {
            echo "Config error in service index $key" . PHP_EOL;
            continue;
        }

        $serviceName = $service['name'] . '.service';

        $out = [];
        $result = 0;
        // Remove old service before install new
        run('systemctl stop ' . $serviceName, $out);
        run('systemctl disable ' . $serviceName, $out);
        run('rm /etc/systemd/system/' . $serviceName, $out);
        // run('rm /usr/lib/systemd/system/' . $serviceName, $out);
        run('systemctl daemon-reload', $out);
        run('systemctl reset-failed', $out);

        $content = '[Unit]' . PHP_EOL;
        
        if (isset($service['description']))
            $content .= 'Description=' . $service['description'] . PHP_EOL;
        else
            $content .= 'Description=Automation service for ' . $service['name'] . PHP_EOL;
            
        if (isset($service['exec-only-after']))
            $content .= 'After=' . $service['exec-only-after'] . PHP_EOL; // network.target

        $content .= PHP_EOL . '[Service]' . PHP_EOL;
        //$content .= 'Alias=' . $serviceName . PHP_EOL;
        $content .= 'ExecStart=' . $pbin . '/' . $service['bin'] . PHP_EOL;

        $content .= PHP_EOL . '[Install]' . PHP_EOL;
        $content .= 'WantedBy=multi-user.target' . PHP_EOL;
        //$content .= 'KillSignal=SIGTERM' . PHP_EOL;
        //$content .= 'SendSIGKILL=no' . PHP_EOL; // Don't want to see an automated SIGKILL ever

        //$content .= 'Restart=on-abort' . PHP_EOL;
        //$content .= 'RestartSec=5s' . PHP_EOL;

        $serviceFile = '/etc/systemd/system/' . $serviceName;
        if (!\file_put_contents($serviceFile, $content) ||
            !\chmod($serviceFile, 0644) ||
            run('systemctl enable ' . $serviceName, $out) !== 0) {
            echo "Error while creating service '$serviceName'" . PHP_EOL;
            continue;
        }
    }
}

function run($cmd, &$out)
{
    $out = [];
    $result = 0;
    \exec($cmd, $out, $result);

    return $result;
}