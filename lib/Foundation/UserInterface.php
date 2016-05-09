<?php

namespace PHPMVC\Foundation;

interface UserInterface
{
    public static function findByLogin($username, $password);
    
    public static function findBySession($session);
}