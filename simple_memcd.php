<?php

require 'dictionary.php';

define("SIMPLE_MEMC_VERSION", "0.0.0.1");

$run_daemon_loop = true;
$memc_dict = dict_init();

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
    global $run_daemon_loop;

    switch ($signal) {
        case SIGTERM:
        case SIGINT:
            echo "Shutting down daemon.\n";
            $run_daemon_loop = false;
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
//    echo "Child started with pid $child_pid\n";
//    exit(0);
//}
//
//$sid = posix_setsid();
//if ($sid < 0) {
//    exit(0);
//}

$init_time = time();

$options = getopt('ap:l:P');


$pid_file = $options['p'];
$log_file = $options['l'];
$port = $options['P'];
$address = '127.0.0.1';

var_dump($options);

if (check_pid_file($pid_file)) {
    echo "Process already executed.\n";
    exit(0);
}

if (!save_pid_file($pid_file)) {
    echo "Cannot store pid file.\n";
    exit(0);
}

pcntl_signal(SIGTERM, "signal_handler");
pcntl_signal(SIGINT, "signal_handler");

if (($sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) === false) {
    echo "socket_create() failed: reason: " . socket_strerror(socket_last_error()) . "\n";
}

if (socket_bind($sock, $address, $port) === false) {
    echo "socket_bind() failed: reason: " . socket_strerror(socket_last_error($sock)) . "\n";
}

if (socket_listen($sock, 5) === false) {
    echo "socket_listen() failed: reason: " . socket_strerror(socket_last_error($sock)) . "\n";
}

socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, array("sec"=>5, "usec"=>0));

while ($run_daemon_loop) {
    pcntl_signal_dispatch();

    if (($msgsock = socket_accept($sock)) === false) {
        if (!socket_last_error($sock)) {
            continue;
        } else {
            echo "socket_accept() failed: reason (" . socket_last_error($sock) . "): " . socket_strerror(socket_last_error($sock)) . "\n";
            break;
        }
    }

    $maintain_current_connection = true;

    do {
        if (false === ($buf = socket_read($msgsock, 2048, PHP_NORMAL_READ))) {
            echo "socket_read() failed: reason: " . socket_strerror(socket_last_error($msgsock)) . "\n";
            break 2;
        }

        if (!$buf = trim($buf)) {
            continue;
        }

        $command_array = explode(" ", $buf, 1);
        $command = '';
        $remainder = '';
        $response = '';

        if (is_array($command_array)) {
            if (count($command_array) == 1) {
                $command = $command_array[0];
            } else if (count($command_array) > 1) {
                $remainder = $command_array[1];
            }
        }

        switch ($command) {
            case 'set':
//                $key, $expire, $value
                break;
            case 'get':
                $response = dict_get($memc_dict, $remainder, '') ? "1\r\n" : "0\r\n";
                break;
            case 'delete':
                $response = dict_delete($memc_dict, $remainder) ? "OK\r\n" : "ERROR\r\n";
                break;
            case 'flush_all':
                $memc_dict = dict_init();
                $response = "OK\n";
                break;
            case 'stats':
                $response  = "STAT pid ".getmypid()."\n";
                $response  = "STAT uptime ".(time() - $init_time)."\n";
                $response .= "STAT version ".SIMPLE_MEMC_VERSION."\n";
                $response .= "STAT hashtable_size ".count($memc_dict[DICT_TABLE])."\n";
                $response .= "STAT hashtable_free ".$memc_dict[DICT_FREE]."\n";
                $response .= "STAT hashtable_deleted ".$memc_dict[DICT_DELETED]."\n";
                $response .= "END\r\n";
                break;
            case 'quit':
                $response = "OK\r\n";
                $maintain_current_connection = false;
                break;
        }

        socket_write($msgsock, $response, strlen($response));
    } while ($maintain_current_connection);

    socket_close($msgsock);
}

socket_close($sock);
@unlink($pid_file);
echo "Stopped\n";

?>