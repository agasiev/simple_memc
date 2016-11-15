<?php

/*
 * Веб-клиент для нашего простого мемкэша.
 * Предусмотрен только ограниченный набор команд: get, set, delete, flush_all, stats.
 */

error_reporting(E_ALL);

/**
 * Шлем запрос к серверу через блокирующий сокет с таймаутом.
 *
 * @param string $request строка с запросом к серверу.
 * @param int    $timeout таймаут при запросах к серверу.
 *
 * @return string ответ сервера или строка с ошибкой.
 */

function memc_send_request($request, $timeout = 10)
{
    $host = gethostbyname('127.0.0.1');
    $port = 12345;

    if (($socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) === false) {
        return "Failed socket_create() with reason: ".socket_strerror(socket_last_error());
    }

    socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $timeout, 'usec' => 0));
    socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, array('sec' => $timeout, 'usec' => 0));

    if (($result = socket_connect($socket, $host, $port)) === false) {
        if (!socket_last_error()) {
            return "socket_connect() timeout.";
        } else {
            return "Failed socket_connect() with reason: ".socket_strerror(socket_last_error());
        }
    }

    $request = trim($request)."\r\n";

    if (!socket_write($socket, $request)) {
        if (!socket_last_error()) {
            return "socket_write() timeout.";
        } else {
            return "Failed socket_write() with reason: ".socket_strerror(socket_last_error());
        }
    }

    $response = "";

    while ($out = socket_read($socket, 2048, PHP_NORMAL_READ)) {
        $response .= $out;
    }

    socket_close($socket);

    return trim($response);
}

$request = '[No request]';
$response = '[No response]';

$command = '';
$param = '';

if ($_POST["executed"] == 1) {
    $command = trim($_POST["command"]);
    $param = trim($_POST["param"]);

    $request = '';

    switch ($command) {
        case 'get':
        case 'delete':
            if (preg_match('/^[a-zA-Z0-9\_]+$/', $param)) {
                $request = "$command $param";
            }
            break;
        case 'stats':
        case 'flush_all':
            $request = $command;
            break;
        case 'set':
            $prepared_param = implode("\\r\\n", array_map(trim, explode("\n", $param)));
            if (preg_match('/^[a-zA-Z0-9\_]+\s+\d+\\r\\n.+/is', $param)) {
                $request = "$command $prepared_param";
            }
    }

    if ($request != '') {
        $response = memc_send_request($request);
    } else {
        $request = '[Bad request]';
    }

    $request = nl2br(htmlspecialchars(str_replace("\\r\\n", "\r\n", $request)));
    $response = nl2br(htmlspecialchars(str_replace("\\r\\n", "\r\n", $response)));
}

?>
<html>
<head>
    <meta charset="UTF-8">
    <title>Simple memcache demo page.</title>
    <script type="text/javascript">
        function update_placeholder() {
            var command = document.getElementById("command");
            var param = document.getElementById("param");

            switch (command.value) {
                case "set":
                    param.placeholder = "key_name expire_time\r\nvalue";
                    break;
                case "get":
                    param.placeholder = "key_name";
                    break;
                case "delete":
                    param.placeholder = "key_name";
                    break;
                case "flush_all":
                    param.placeholder = "No parameter for this command";
                    break;
                case "stats":
                    param.placeholder = "No parameter for this command";
                    break;
            }
        }
    </script>
</head>
<body>
    <h2>Simple memcache web client</h2>
    <div>
        <form method="post" action="index.php">
            <label>Command:</label><br>
            <select name="command" id="command" onchange="update_placeholder();">
                <option value="get"<?= $command == '' || $command == 'get' ? 'selected' : '' ?>>get</option>
                <option value="set"<?= $command == 'set' ? 'selected' : '' ?>>set</option>
                <option value="delete"<?= $command == 'delete' ? 'selected' : '' ?>>delete</option>
                <option value="flush_all"<?= $command == 'flush_all' ? 'selected' : '' ?>>flush_all</option>
                <option value="stats"<?= $command == 'stats' ? 'selected' : '' ?>>stats</option>
            </select><br><br>
            <label>Parameters:</label><br>
            <textarea cols="50" rows="6" name="param" id="param"
                      placeholder="key_name" style="margin-top: 10px; margin-bottom: 10px;"><?= $param ?></textarea><br>
            <input type="hidden" name="executed" value="1"/><br>
            <input type="submit" value="Execute!"/>
        </form>
    </div>
    <div>
        <h3>Request</h3>
        <div style="background-color: antiquewhite; padding: 10px;"><?= $request ?></div>
        <h3>Response</h3>
        <div style="background-color: antiquewhite; padding: 10px;"><?= $response ?></div>
    </div>
</body>
</html>
