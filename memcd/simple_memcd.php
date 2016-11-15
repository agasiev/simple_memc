<?php

/*
 * Простая реализация однопоточного а-ля memcache сервера.
 *
 * Собственная реализация ассоциативного массива. Т.к. условие было не использовать сторонние
 * библиотеки, а только чистый php без ООП, то за основу брались обычные массивы (хоть они по факту
 * тоже хэш таблицы и едят много памяти, но в данной задаче мы закрываем глаза на этот факт и
 * используем их как С-style массивы).
 *
 */

require 'dictionary.php';

define("SIMPLE_MEMC_VERSION", "0.0.0.1");
define("SIMPLE_MEMC_DEFAULT_PORT", 12345);
define("SIMPLE_MEMC_DEFAULT_PID", 'memcd.pid');
define("SIMPLE_MEMC_DEFAULT_HOST", '127.0.0.1');

$maintain_daemon_loop = true;
$memcache_dict = dict_init();

function check_pid_file($pid_file) {
    if (is_file($pid_file)) {
        $pid = file_get_contents($pid_file);

        if (posix_kill($pid, 0)) {
            return true;
        }
    }
    return false;
}

function save_pid_file($pid_file) {
    return file_put_contents($pid_file, getmypid());
}

function signal_handler($signal) {
    global $maintain_daemon_loop;

    switch ($signal) {
        case SIGTERM:
        case SIGINT:
            echo "Shutting down daemon.\n";
            $maintain_daemon_loop = false;
            break;
        default:
            echo "Recieved unprocessed signal $signal";
    }
}

//$child_pid = pcntl_fork();
//
//if ($child_pid < 0) {
//    echo "Error during pcntl_fork()\n";
//    exit(0);
//} else if ($child_pid) {
//    echo "Started with pid $child_pid\n";
//    exit(0);
//}
//
//if (posix_setsid() < 0) {
//    exit(0);
//}

$init_time = time();

$options = getopt('p:P:h:');
$pid_file = array_key_exists('p', $options) ? $options['p'] : dirname($argv[0]).'/'.SIMPLE_MEMC_DEFAULT_PID;
$port = array_key_exists('P', $options) ? (int)$options['P'] : SIMPLE_MEMC_DEFAULT_PORT;
$host = array_key_exists('h', $options) ? $options['h'] : SIMPLE_MEMC_DEFAULT_HOST;

//fclose(STDIN);
//fclose(STDOUT);
//fclose(STDERR);

openlog("simple_memcd", LOG_PID | LOG_NDELAY, LOG_DAEMON);
echo "simple_memcd started at ".$host.":".$port."\n";
//syslog(LOG_ERR, "simple_memcd started at ".$host.":".$port);

if (check_pid_file($pid_file)) {
    echo "Process already executed.\n";
    exit(0);
}

if (!save_pid_file($pid_file)) {
    echo "Cannot store pid file.\n";
    exit(0);
}

pcntl_signal(SIGTERM, "signal_handler");
pcntl_signal(SIGINT,  "signal_handler");

if (($socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) === false) {
    echo "socket_create() failed: reason: " . socket_strerror(socket_last_error()) . "\n";
}
socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

if (socket_bind($socket, $host, $port) === false) {
    echo "socket_bind() failed: reason: " . socket_strerror(socket_last_error($socket)) . "\n";
}

socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 5, 'usec' => 0));
socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, array('sec' => 5, 'usec' => 0));

if (socket_listen($socket, 5) === false) {
    echo "socket_listen() failed: reason: " . socket_strerror(socket_last_error($socket)) . "\n";
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

    $buf = trim($buf);
    if (!strlen($buf)) {
        continue;
    }

    $command_array = explode(' ', $buf, 2);

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
            $data = explode('\r\n', $remainder, 2);
            var_dump($data);

            if (count($data) != 2) {
                $response = "ERROR\r\n";
                break;
            }

            $key_and_expire = $data[0];
            $store_data = $data[1];

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
        case 'quit':
            $response = "OK\r\n";
            $maintain_current_connection = false;
            break;
        default:
            $response = "UNKNOWN\r\n";
    }

    var_dump($memcache_dict[DICT_TABLE]);

    foreach ($memcache_dict[DICT_TABLE] as $value) {
        echo $value[HASHTABLE_KEY]."\n";
    }

    echo "Response\n'$response'\n";

    socket_write($message_socket, $response);
    socket_close($message_socket);
}

socket_close($socket);
@unlink($pid_file);
closelog();

echo "Stopped\n";

?>