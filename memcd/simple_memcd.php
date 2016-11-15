<?php

/*
 * Простая реализация однопоточного а-ля memcache сервера.
 *
 * PHP 5.6 без ООП + pcntl + posix.
 */

require 'dictionary.php';

define("SIMPLE_MEMC_VERSION",      "0.0.0.1");
define("SIMPLE_MEMC_DEFAULT_PORT", 12345);
define("SIMPLE_MEMC_DEFAULT_PID",  'memcd.pid');
define("SIMPLE_MEMC_ERROR_LOG",    'error.log');
define("SIMPLE_MEMC_APP_LOG",      'application.log');
define("SIMPLE_MEMC_APP_ERROR_LOG",'application.error.log');
define("SIMPLE_MEMC_DEFAULT_HOST", '127.0.0.1');

$maintain_daemon_loop = true;
$memcache_dict = dict_init();
$init_time = time();

/**
 * Проверяем есть ли pid файл на диске и если он есть,
 * то не запущено ли приложение с эти pid'ом.
 *
 * @param $pid_file Полный путь к pid файлу
 * @return bool     true если файл существует и процесс с этим pid запущен
 */

function memc_check_pid_file($pid_file) {
    if (is_file($pid_file)) {
        $pid = file_get_contents($pid_file);

        if (!is_int($pid)) {
            return false;
        }

        if (posix_kill($pid, 0)) {
            return true;
        }
    }

    return false;
}


/**
 * Создаем на диске pid файл.
 *
 * @param  $pid_file Полный путь к файлу.
 * @return bool      true если смогли создать файл и записать в него pid.
 */

function memc_save_pid_file($pid_file) {
    return file_put_contents($pid_file, getmypid()) ? true : false;
}

/**
 * Обработчик системных сигналов ОС.
 *
 * @param $signal id системного сигнала
 */
function memc_signal_handler($signal) {
    global $maintain_daemon_loop;

    switch ($signal) {
        // SIGHUP не обрабатываем, т.к. нет конфиг. файла.
        case SIGTERM:
        case SIGINT:
            echo "Shutting down daemon.\n";
            $maintain_daemon_loop = false;
            break;
        default:
            echo "Recieved unprocessed signal $signal";
            break;
    }
}

/**
 * Обработчик REPL цикла.
 *
 * @param  mixed  $memcache_dict Хранилище ключей
 * @param  string $request       Строка запроса
 * @param  int    $init_time     Время запуска скрипта (для uptime)
 *
 * @return string Ответ, который нужно направить клиенту.
 */
function memc_process_request(&$memcache_dict, $request, $init_time) {
    $request = trim($request);
    if (!strlen($request)) {
        return '';
    }

    $command_array = explode(' ', $request, 2);
    var_dump($command_array);

    $command = '';
    $remainder = '';
    $response = '';

    if (is_array($command_array)) {
        if (count($command_array) > 0) {
            $command = trim($command_array[0]);
        }

        if (count($command_array) == 2) {
            $remainder = trim($command_array[1]);
        }
    }

    echo "Command '$command'\n";
    echo "Remainder '$remainder'\n";

    switch ($command) {
        case 'set':
            $data = explode('\n', $remainder, 2);
            var_dump($data);

            if (count($data) != 2) {
                $response = "ERROR\r\n";
                break;
            }

            $key_and_expire = trim($data[0]);
            $store_data = trim($data[1]);

            $data = explode(" ", $key_and_expire, 2);
            var_dump($data);

            if (count($data) != 2) {
                $response = "ERROR\r\n";
                break;
            }

            $key = $data[0];
            $expire = (int)$data[1];

            if ($expire >= 0 && $expire <= 30 * 24 * 3600) {
                $response = dict_set($memcache_dict,
                    $key,
                    $store_data,
                    $expire == 0 ? PHP_INT_MAX : (time() + $expire)) ? "STORED\r\n" : "ERROR\r\n";
            } else {
                $response = "ERROR\r\n";
            }

            break;
        case 'get':
            if (($value = dict_get($memcache_dict, $remainder)) == null) {
                $response = "NOT_FOUND\r\n";
            } else {
                $response  = "VALUE $remainder\r\n";
                $response .= $value."\r\n";
                $response .= "END\r\n";
            }

            break;
        case 'delete':
            $response = dict_delete($memcache_dict, $remainder) ? "DELETED\r\n" : "NOT_FOUND\r\n";
            break;
        case 'flush_all':
            $memcache_dict = dict_init();
            $response = "OK\r\n";
            break;
        case 'stats':
            $response  = "STAT pid ".getmypid()."\r\n";
            $response  = "STAT uptime ".(time() - $init_time)."\r\n";
            $response .= "STAT version ".SIMPLE_MEMC_VERSION."\r\n";
            $response .= "STAT hashtable_size ".count($memcache_dict[DICT_TABLE])."\r\n";
            $response .= "STAT hashtable_free ".$memcache_dict[DICT_FREE]."\r\n";
            $response .= "STAT hashtable_deleted ".$memcache_dict[DICT_DELETED]."\r\n";
            $response .= "END\r\n";
            break;
        default:
            $response = "UNKNOWN\r\n";
    }

    echo "Response\n'$response'\n";
    return $response;
}

// Парсим параметры командной строки.
$options  = getopt('p:P:h:l:');
$pid_file = array_key_exists('p', $options) ? realpath($options['p']) : realpath(dirname($argv[0])).'/'.SIMPLE_MEMC_DEFAULT_PID;
$port     = array_key_exists('P', $options) ? (int)$options['P']      : SIMPLE_MEMC_DEFAULT_PORT;
$host     = array_key_exists('h', $options) ? $options['h']           : SIMPLE_MEMC_DEFAULT_HOST;
$log_dir  = array_key_exists('l', $options) ? realpath($options['l']) : realpath(dirname($argv[0]));

// Демонизируем

if (memc_check_pid_file($pid_file)) {
    echo "Process already executed.\n";
    exit(0);
}

umask(0);
$child_pid = pcntl_fork();

if ($child_pid < 0) {
    echo "Error during pcntl_fork()\n";
    exit(0);
} else if ($child_pid) {
    echo "Started with pid $child_pid\n";
    exit(0);
}

if (posix_setsid() < 0) {
    echo "posix_setsid() < 0\n";
    exit(0);
}

chdir('/');

if (!memc_save_pid_file($pid_file)) {
    echo "Cannot store pid file.\n";
    exit(0);
}

ini_set("error_log", $log_dir.'/'.SIMPLE_MEMC_ERROR_LOG);

fclose(STDIN);
fclose(STDOUT);
fclose(STDERR);

$STDIN = fopen('/dev/null', 'r');
$STDOUT = fopen($log_dir.'/'.SIMPLE_MEMC_APP_LOG, 'ab');
$STDERR = fopen($log_dir.'/'.SIMPLE_MEMC_APP_ERROR_LOG, 'ab');

pcntl_signal(SIGTERM, "memc_signal_handler");
pcntl_signal(SIGINT, "memc_signal_handler");

if (($socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) === false) {
    syslog(LOG_ERR, "Failed socket_create() with reason: ".socket_strerror(socket_last_error()));
    exit(0);
}

socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 5, 'usec' => 0));
socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, array('sec' => 5, 'usec' => 0));

if (socket_bind($socket, $host, $port) === false) {
    echo "Failed socket_bind() with reason: " . socket_strerror(socket_last_error($socket))."\n";
    exit(0);
}

echo "simple_memcd started at ".$host.":".$port."\n";

if (socket_listen($socket, 5) === false) {
    echo "Failed socket_listen() with reason: ".socket_strerror(socket_last_error($socket))."\n";
    exit(0);
}

while ($maintain_daemon_loop) {
    pcntl_signal_dispatch();

    if (($message_socket = socket_accept($socket)) === false) {
        if (!socket_last_error($socket)) {
            continue;
        } else {
            echo "Failed socket_accept() with reason: ".socket_strerror(socket_last_error($socket))."\n";
            break;
        }
    } else {
        echo "connected!\n";
    }

    if (false === ($buf = socket_read($message_socket, 2048, PHP_NORMAL_READ))) {
        if (!socket_last_error($message_socket)) {
            continue;
        } else {
            echo "Failed socket_read() with reason: ".socket_strerror(socket_last_error($message_socket))."\n";
            break;
        }
    }

    $response = memc_process_request($memcache_dict, $buf, $init_time);
    socket_write($message_socket, $response);
    socket_close($message_socket);
}

socket_close($socket);
@unlink($pid_file);
closelog();

echo "simple_memcd stopped\n";

?>