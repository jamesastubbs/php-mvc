<?php

namespace PHPMVC\Foundation\Interfaces;

use PHPMVC\Foundation\Services;

interface ServiceableInterface
{
    /**
     * @param  Services  $services  Service container to store.
     */
    public function setServices(Services $services);
}
