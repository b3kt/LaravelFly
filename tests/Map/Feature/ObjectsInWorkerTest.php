<?php

namespace LaravelFly\Tests\Map\Feature;

use Dotenv\Loader;
use LaravelFly\Map\Illuminate\View\BladeCompiler_1;
use LaravelFly\Tests\BaseTestCase as Base;
use Symfony\Component\EventDispatcher\GenericEvent;

class ObjectsInWorkerTest extends Base
{

    protected $instances = [
        'path',
        'path.base',
        'path.lang',
        'path.config',
        'path.public',
        'path.storage',
        'path.database',
        'path.resources',
        'path.bootstrap',
        'app',
//        'Illuminate\Foundation\Container',
        'Illuminate\Foundation\PackageManifest',
        'events',
        'router',
        'routes',
        'url',
        'Illuminate\Contracts\Http\Kernel',
        'request',
        'config',

        'db.factory',
        'db',
        'view.engine.resolver',
        'files',
        'view',

        'Illuminate\Contracts\Auth\Access\Gate',
        'Illuminate\Contracts\Debug\ExceptionHandler',
        'blade.compiler',
        'translation.loader',
        'translator',
        'validation.presence',
        'validator',
        'session',

        'cache',
        'session.store',
        'Illuminate\Session\Middleware\StartSession',
        'hash',
        'hash.driver',
        'redis',
        'filesystem',
        'filesystem.disk',
        'encrypter',
        'cookie',
        'cache.store',
        'auth',
        'log',
    ];

    protected $allStaticProperties = [
        'app' => [
            'instance',
            'singletonMiddlewares'
        ],
        'router' => ['macros',
            'middlewareAlwaysStable',
            'middlewareStable',
            'singletonMiddlewares',
            'verbs'],
        'files' => ['macros'],
        'view' => [
            'macros',
            // 'parentPlaceholder'
        ],
        'url' => ['macros'],
        'translator' => ['macros'],
        'cache.store' => ['macros'],
        'blade.compiler' => ['mapFly'],

        // LaravelFly\Map\IlluminateBase\Request
        //
        // for !LARAVELFLY_SERVICES['request'],
        // don't worry about 'request', every request has it's own 'request'.
        // The 'request' object in worker is a fake request.
        // see: \LaravelFly\Server\HttpServer::onWorkerStart
        //
        'request' => [
            'formats',
            'httpMethodParameterOverride',
            'instance',
            'macros',
            'requestFactory',
            'trustedHostPatterns',
            'trustedHosts',
            'trustedProxies',
        ],

        // LaravelFly\Map\IlluminateBase\Dispatcher
        'events' => [
            'listenersStalbe',
            'swooleListeners',
            'swooleServer',
            'wildStable'
        ],
        'cookie' => [
            'macros',
        ],
        'Illuminate\Container\Container' => [
            'instance',
            'singletonMiddlewares'
        ],
    ];

    static function initConfig()
    {

        // for laravel-app-config.example.php
        $GLOBALS['IN_PRODUCTION'] = true;
        echo "set \$GLOBALS['IN_PRODUCTION']\n";

        @unlink(static::$laravelAppRoot . '/bootstrap/cache/config.php');
        @unlink(static::$laravelAppRoot . '/bootstrap/cache/laravelfly_ps_map.php');
        @unlink(static::$laravelAppRoot . '/bootstrap/cache/laravelfly_ps_simple.php');

    }

    static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        static::initConfig();
    }
    static function tearDownAfterClass()
    {
        parent::tearDownAfterClass();
        unset($GLOBALS['IN_PRODUCTION']);
        echo "unset \$GLOBALS['IN_PRODUCTION']\n";
    }

    function test()
    {
        self::assertTrue(True);

        static::$chan = $chan = new \Swoole\Channel(1024 * 256);

        $r = self::createFlyServerInProcess(
            [
            ],
            ['worker_num' => 1],
            function ($server) use ($chan) {

                $dispatcher = $server->getDispatcher();

                $dispatcher->addListener('worker.ready', function (GenericEvent $event) use ($chan) {
                    $appR = new \ReflectionObject($event['app']);
                    $corDictR = $appR->getProperty('corDict');
                    $corDictR->setAccessible(true);
                    $instances = $corDictR->getValue()[WORKER_COROUTINE_ID]['instances'];

                    $chan->push(array_keys($instances));

                    $allStaticProperties = [];
                    foreach ($instances as $name => $instance) {
                        if (!is_object($instance)) continue;
                        $instanceR = new \ReflectionObject($instance);
                        $staticProperties = array_keys($instanceR->getStaticProperties());
                        if ($staticProperties) {
                            $clean = array_diff($staticProperties, ['corDict', 'corStaticDict',
                                'normalAttriForObj', 'arrayAttriForObj', 'normalStaticAttri', 'arrayStaticAttri'
                            ]);
                            if ($clean) {
                                sort($clean); // force it index from 0 ,otherwise self::assertEqual fail
                                $allStaticProperties[$name] = $clean;
                            }
                        }
                    }
                    $chan->push($allStaticProperties);

                    $bladeR = new \ReflectionClass(BladeCompiler_1::class);
                    $s = $bladeR->getStaticProperties();
                    if ($s) {
                        $names = array_keys($s);
                        $chan->push($names);
                    }

//                $event['server']->getSwooleServer()->shutdown();
                });

                $server->start();


            }, 8);

    }

    function testInstances()
    {
        $instances = static::$chan->pop();
//         var_dump($instances);

        self::assertEquals([], array_diff($this->instances, $instances));

        echo "instances not wrote in test file:\n";
        var_dump(array_diff($instances, $this->instances));

        sort($instances);
        $exp = $this->instances;
        sort($exp);
//        self::assertEquals($exp, $instances);

    }

    function testStaticProperties()
    {

        $allStaticProperties = static::$chan->pop();
        self::assertFalse(array_key_exists('blade.compiler', $allStaticProperties), 'no static props for bloade.compiler in dev env');

        $exp = $this->allStaticProperties;
        unset($exp['blade.compiler']);

        self::assertEquals($exp, $allStaticProperties);
    }

    function testStaticPropertiesForBladeCompiler1()
    {
        self::assertEquals($this->allStaticProperties['blade.compiler'], static::$chan->pop());
    }
}

