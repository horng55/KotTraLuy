<?php

// Keep Laravel's development router anchored to this application when PHP is
// launched by a process manager with a different current working directory.
chdir(__DIR__);

require __DIR__.'/../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
