<?php

namespace PHPMVC\Foundation\Service;

use PHPMVC\Foundation\Interfaces\ServiceInterface;
use PHPMVC\Foundation\Interfaces\ServiceableInterface;
use PHPMVC\Foundation\Services;

class DebugService implements ServiceInterface, ServiceableInterface
{
    /**
     * @var  array
     */
    private $profiles = [];

    /**
     * @var  Services
     */
    private $services = null;

    public function __construct()
    {
        error_reporting(E_ALL);
        ini_set('display_errors', 'On');
        ini_set('xdebug.var_display_max_depth', -1);
        ini_set('xdebug.var_display_max_children', -1);
        ini_set('xdebug.var_display_max_data', -1);
    }

    public function setServices(Services $services)
    {
        $this->services = $services;
    }

    public function collect($name, $data)
    {
        if (!isset($this->profiles[$name])) {
            $this->profiles[$name] = [];
        }

        $this->profiles[$name][] = $data;
    }

    public function finishProfiling()
    {
        $configService = $this->services->get('app.config', true);
        $profileName = strtolower(substr(sha1(microtime()), 0, 8));
        $rootPath = $configService->get('app.root') . "/var/profile";
        $profilePath = "$rootPath/$profileName";

        if (!file_exists(dirname($rootPath))) {
            mkdir(dirname($rootPath));
            chmod(dirname($rootPath), 0770);
        }

        if (!file_exists($rootPath)) {
            mkdir($rootPath);
            chmod($rootPath, 0770);
        }

        mkdir($profilePath);
        chmod($profilePath, 0770);

        foreach ($this->profiles as $profileName => $profileData) {
            file_put_contents(
                "$profilePath/$profileName.json",
                json_encode($profileData, JSON_PRETTY_PRINT),
                FILE_APPEND
            );
        }

        $rotateCount = intval($configService->get('profiler.rotate'));

        if ($rotateCount !== 0) {
            $files = glob("$rootPath/*");
            $filesCount = count($files);

            if ($filesCount > $rotateCount) {
                usort($files, function($a, $b) {
                    return filemtime($a) < filemtime($b);
                });

                while ($filesCount > $rotateCount) {
                    $filesCount--;
                    $folder = $files[$filesCount];

                    $subFiles = glob("$folder/*");

                    foreach ($subFiles as $subFile) {
                        unlink($subFile);
                    }

                    rmdir($folder);

                    unset($files[$filesCount]);
                }
            }
        }
    }
}
