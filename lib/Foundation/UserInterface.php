<?php

namespace PHPMVC\Foundation;

interface UserInterface
{
    public function isAdministrator();
    
    public function isOpen();
    
    public static function findByLogin($username, $password);
    
    public static function findBySession($session);
}