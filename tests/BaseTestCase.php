<?php


/**
 ** first:
 ** cd laravel_fly_root
 ** git clone -b 5.6 https://github.com/laravel/framework.git /vagrant/www/zc/vendor/scil/laravel-fly-local/vendor/laravel/framework
 ** composer update
 ** //cd laravel_project_root
 *
 ** Mode Map
 * vendor/bin/phpunit  --stop-on-failure -c phpunit.xml.dist --testsuit LaravelFly_Map_Process
 *
 * vendor/bin/phpunit  --stop-on-failure -c phpunit.xml.dist --testsuit LaravelFly_Map_Other
 *
 * vendor/bin/phpunit  --stop-on-failure -c phpunit.xml.dist --testsuit LaravelFly_Map_LaravelTests
 *
 ** Mode Backup
 * vendor/bin/phpunit  --stop-on-failure -c phpunit.xml.dist --testsuit LaravelFly_Backup
 *
 ** example for debugging with gdb:
 * gdb ~/php/7.1.14root/bin/php       // this php is a debug versioin, see D:\vagrant\ansible\files\scripts\php-debug\
 * r  vendor/bin/phpunit  --stop-on-failure -c phpunit.xml.dist --testsuit LaravelFly_Map_LaravelTests
 *
 */

namespace LaravelFly\Tests;

use LaravelFly\Server\Common;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use LaravelFly\Server\HttpServer;

require_once __DIR__ . "/swoole_src_tests/include/swoole.inc";
require_once __DIR__ . "/swoole_src_tests/include/lib/curl_concurrency.php";

/**
 * Class Base
 * @package LaravelFly\Tests
 *
 * why abstract? stop phpunit to use this testcase
 */
abstract class BaseTestCase extends TestCase
{
    use DirTest;

    /**
     * @var EventDispatcher
     */
    static protected $dispatcher;

    /**
     * @var \LaravelFly\Server\ServerInterface
     */
    static protected $flyServer;


    // get default server options
    static $default = [];

    /**
     * @var string
     */
    static protected $workingRoot = WORKING_ROOT;
    static protected $laravelAppRoot = LARAVEL_APP_ROOT;

    static $flyDir;

    static $backOfficalDir;

    /**
     * @var \Swoole\Channel
     */
    static protected $chan;


    static function setUpBeforeClass()
    {

        $r = static::$laravelAppRoot;


        if (!is_dir($r . '/app')) {
            exit("[NOTE] FORCE setting \$laravelAppRoot= $r,please make sure laravelfly code or its soft link is in laravel_app_root/vendor/scil/\n");
        }

        $mainVersion = self::process(function () use($r) {
            return Common::getApplicationVersion(false,$r);
        });

        static::$flyDir = __DIR__ . '/../src/fly/' . $mainVersion . '/';
        static::$backOfficalDir = __DIR__ . '/offcial_files/' . $mainVersion . '/';




        $d = static::processGetArray(function () {
            return include static::$laravelAppRoot . '/vendor/scil/laravel-fly/config/laravelfly-server-config.example.php';
        });

        static::$default = array_merge([
            'mode' => 'Map',
            'conf' => null, // server config file
        ], $d);
    }

    /**
     * @param array $constances
     * @param array $options
     * @param string $config_file
     * @param bool $swoole
     * @return \LaravelFly\Server\HttpServer|\LaravelFly\Server\ServerInterface
     */
    static protected function makeNewFlyServer($constances = [], $options = [], $config_file = __DIR__ . '/../config/laravelfly-server-config.example.php')
    {
        static $step = 0;

        foreach ($constances as $name => $val) {
            if (!defined($name))
                define($name, $val);
        }

        $options['colorize'] = false;

        if (!isset($options['pre_include']))
            $options['pre_include'] = false;

        if (!isset($options['listen_port'])) {
            $options['listen_port'] = 9022 + $step;
            ++$step;
        }

        // why @ ? For the errors like : Constant LARAVELFLY_MODE already defined
        $file_options = @ include $config_file;

        $options = array_merge($file_options, $options);

        $flyServer = \LaravelFly\Fly::init($options, null);

        static::$dispatcher = $flyServer->getDispatcher();

        return static::$flyServer = $flyServer;
    }

    /**
     * @return \LaravelFly\Server\ServerInterface
     */
    public static function getFlyServer(): \LaravelFly\Server\ServerInterface
    {
        return self::$flyServer;
    }

    function compareFilesContent($map)
    {

        $diffOPtions = '--ignore-all-space --ignore-blank-lines';

        $same = true;

        foreach ($map as $back => $offcial) {
            $back = static::$backOfficalDir . $back;
            $offcial = static::$laravelAppRoot . $offcial;

            $cmdArguments = "$diffOPtions $back $offcial ";

            unset($a);
            exec("diff --brief $cmdArguments > /dev/null", $a, $r);
//            echo "\n\n[CMD] diff $cmdArguments\n\n";
//            print_r($a);
            if ($r !== 0) {
                $same = false;
                echo "\n\n[CMD] diff $cmdArguments\n\n";
                system("diff  $cmdArguments");
            }
        }

        self::assertEquals(true, $same);

    }

    static function processGetArray($func, $waittime = 1): array
    {
//        return self::process($func, $waittime);
        return json_decode(self::process($func, $waittime), true);
    }

    static function process($funcInNewProcess, $waittime = 1): string
    {
        return static::_process($funcInNewProcess, null, $waittime);
    }


    /**
     * @param $funcInNewProcess
     * @param int $waittime
     * @return string|int|boolean    it will json_encode array
     */
    static function _process($funcInNewProcess, $func, $waittime = 1): string
    {


        $pm = new \ProcessManager;

        $chan = new \Swoole\Channel(1024 * 1024 * 3);

        $pm->childFunc = function () use ($funcInNewProcess, $pm, $chan) {
            $r = $funcInNewProcess();
            if (is_array($r)) {
                $chan->push(json_encode($r));
            } else {
                $chan->push($r);
            }
            $pm->wakeup();

        };
        // server can not be made in parentFunc,because parentFunc run in current process
        $pm->parentFunc = function ($pid) use ($func, $chan, $waittime) {
            echo $chan->pop();

            if (is_callable($func))
                echo $func();
            sleep($waittime);

            \swoole_process::kill($pid);
        };
        $pm->childFirst();

        ob_start();
        $pm->run();
        return ob_get_clean();
    }

    static function createFlyServerInProcess($constances, $options, $serverFunc, $wait = 0): string
    {
        return self::process(function () use ($constances, $options, $serverFunc) {
            $server = self::makeNewFlyServer($constances, $options);
            $r = $serverFunc($server);
            return $r;
        }, $wait);

    }


    static function request($constances, $options, $urls, $serverFunc = null, $wait = 0): string
    {
        return self::_process(function () use ($constances, $options, $serverFunc) {

            $server = self::makeNewFlyServer($constances, $options);

            if (is_callable($serverFunc))
                $r = $serverFunc($server);

            return '';

        }, function () use ($urls) {
            $r = curl_concurrency($urls);

            return implode("\n", $r);

        }, $wait);

    }



    const testPort = '9503';

    const testBaseUrl = '/laravelfly-test/';

    const testCurlBaseUrl = '127.0.0.1:' . (self::testPort) . self::testBaseUrl;

    function requestForTest($routes, $curlPair)
    {

        foreach ($curlPair as $url => $result) {
            $urls[] = static::testCurlBaseUrl . $url;
            $results [] = $result;
        }

        $constances = [
        ];

        $options = [
            'worker_num' => 1,
            'mode' => 'Map',
            'listen_port' => self::testPort,
            'daemonize' => false,
            'pre_include' => false,
        ];

        $r = self::request($constances, $options, $urls,

            function (HttpServer $server) use ($routes) {

                $server->getDispatcher()->addListener('worker.ready', function () use ($routes) {
                    foreach ($routes as list($method, $url, $func)) {
                        \Route::$method($url, $func);
                    }
                });

                $server->start();

            }, 3);

        self::assertEquals(implode("\n", $results), $r);
    }
}

