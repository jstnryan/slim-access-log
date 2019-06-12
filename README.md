# slim-access-log
A simple access auditing middleware intended for use with Slim Framework applications.

## Installation (the 'quick and dirty' version)

1. Include package (ensure you're using Slim's autoloader):

   (shell) `composer require jstnryan/slim-access-log`

2. Create an empty database (MySql, MariaDB, etc.), and build structure:

   (shell) `mysql -uUSER -pPASS < ./schema.sql`
   
3. Configure & use:

   ```php
   <?php
   require 'vendor/autoload.php';

   //instantiate the app
   $settings = require_once(__DIR__ . '/settings.php');
   $app = new \Slim\App($settings);

   //get container reference
   $container = $app->getContainer();

   //PDO for writing to DB
   $container['audit_database'] = function ($c) {
       $pdo = new PDO("mysql:host=127.0.0.1;dbname=audit;charset=utf8", 'username', 'password');
       $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
       $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
       return $pdo;   
   };
 
   //audit log middleware factory
   $container['accessLog'] = function($c) {
       $settings = [
           'tableName' => 'accessLog',
           'idColumn' => 'accessLogID',
           'writeOnce' => false,
           'custom' => [],
           'captureResponse' => false,
       ];
       return new \jstnryan\AccessLog\AccessLog($c->audit_database, $settings);
   };
   
   //attach middleware class
   $app->add($app->getContainer()->get('accessLog'));
   //more config, middleware, routes an' stuff here...
   $app->run();
   ```
