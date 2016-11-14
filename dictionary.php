<?php

define("HASHTABLE_KEY", 0);
define("HASHTABLE_VALUE", 1);
define("HASHTABLE_DELETED", 2);

define("DICT_FREE", 0);
define("DICT_DELETED", 1);
define("DICT_HASH", 2);
define("DICT_TABLE", 3);

define("INITIAL_SIZE", 1024);
define("EXTEND_STEP", 1024);       // Шаг увеличения размерности таблицы
define("CLEANUP_THRESHOLD", 0.1);  // % от удаленных в таблице, после которого следует сделать чистку
define("FREE_THRESHOLD", 0.2);     // % от свободных ячеек таблицы, опускаясь ниже которого ее следует расширить

/**
 * Инициализируем структуру ассоциативного массива.
 *
 * Пользуемся тем, что в php в массиве можно хранить значение любого типа (== tuple)
 * и не пользуемся тем фактом, что подобное можно провернуть с ключами (ибо в этом суть задачи).
 *
 * @param string $hash_function Фунция хэширования ключа string -> int.
 * @param int    $initial_size  Кол-во элементов в хэш таблице при инициализации.
 *
 * @return array Возвращает ассоциативный массив.
 */

function dict_init($hash_function='crc32', $initial_size = INITIAL_SIZE) {
    if (!is_int($initial_size) || $initial_size <= 0) {
        throw new InvalidArgumentException('Wrong $initial_size value.');
    }

    if (!is_callable($hash_function)) {
        throw new InvalidArgumentException('Wrong $hash_function is not callable.');
    }

    return array(
        $initial_size,  // Кол-во пустых ячеек в массиве
        0,              // Кол-во удаленных ячеек в массиве
        $hash_function, // Ф-я хэширования string -> int

        array_fill(
            0,
            $initial_size,
            array(null, null, false) // [ключ, значение, удалено или нет]
        ) // хэш-таблица
    );
}

/**
 * Получаем значение из ассоциативного массива $dict по ключу $key
 *
 * @param array  $dict    Ассоциативный массив.
 * @param string $key     Ключ, по которому мы хотим получить значение из $dict.
 * @param mixed  $default Значение по умолчанию, которое функция вернет, если не найдет $key в $dict.
 *
 * @throws InvalidArgumentException если были переданы неверные значения в $dict и $key.
 *
 * @return mixed Значение, хранимое по ключу $key в $dict или $default.
 */

function dict_get(&$dict, $key, $default = null) {
    if (!isset($dict) || !isset($key)) {
        throw new InvalidArgumentException('dict_get() some of mandatory variables are not set.');
    }

    $hash_table_size = count($dict[DICT_TABLE]);
    $hash_value_idx = $dict[DICT_HASH]($key) % $hash_table_size;

    for ($i = 0; $i < $hash_table_size; $i++) {
        $idx = ($hash_value_idx + $i) % $hash_table_size;

        if (is_null($dict[DICT_TABLE][$idx][HASHTABLE_KEY])) {
            break;
        }
        else if ($dict[DICT_TABLE][$idx][HASHTABLE_KEY] == $key) {
            return $dict[DICT_TABLE][$idx][HASHTABLE_DELETED] ? $default : $dict[DICT_TABLE][$idx][HASHTABLE_VALUE];
        }
    }

    return $default;
}

/**
 * Добавляем значение в ассоциативный массив по ключу.
 *
 * Если память сильно заполнена, расширяем массив.
 *
 * @param array  $dict   Ассоциативный массив.
 * @param string $key    Ключ, по которому запишем данные из $value.
 * @param mixed  $value  Значение, сохраняемое по ключу $key.
 * @param bool   $extend true - расширить таблицу при необходимости, false - не раширять.
 *
 * @throws InvalidArgumentException
 *
 * @return bool true - если смогли вставить значение по ключу, false - если не смогли.
 */

function dict_set(&$dict, $key, $value = null, $extend = true) {
    if (!isset($dict) || !isset($key)) {
        throw new InvalidArgumentException('dict_set() some of mandatory variables are not set.');
    }

    if ($dict[DICT_FREE] == 0 && !$extend) {
        return false;
    }
    else if ($dict[DICT_FREE] / (float)count($dict[DICT_TABLE]) < FREE_THRESHOLD && $extend) {
        _dict_extend($dict);
    }

    $hash_table_size = count($dict[DICT_TABLE]);
    $hash_value_idx = $dict[DICT_HASH]($key) % $hash_table_size;

    for ($i = 0; $i < $hash_table_size; $i++) {
        $idx = ($hash_value_idx + $i) % $hash_table_size;

        if (is_null($dict[DICT_TABLE][$idx][HASHTABLE_KEY]) ||
            $dict[DICT_TABLE][$idx][HASHTABLE_DELETED]
        ) {
            $dict[DICT_TABLE][$idx][HASHTABLE_KEY] = $key;
            $dict[DICT_TABLE][$idx][HASHTABLE_VALUE] = $value;
            $dict[DICT_FREE]--;

            return true;
        }
    }

    return false;
}

/**
 * Удаляет ключ из ассоциативном массиве.
 *
 * Помечает удаленным ключ в ассоциативном массиве. По превышению порога заданного в CLEANUP_THRESHOLD
 * выполняет чистку.
 *
 * @param array  $dict Ассоциативный массив.
 * @param string $key  Ключ, который надо удалить.
 *
 * @throws InvalidArgumentException
 *
 * @return bool true - если ключ был удален, false - если ключ не найден.
 */

function dict_delete(&$dict, $key) {
    if (!isset($dict) || !isset($key)) {
        throw new InvalidArgumentException('dict_delete() some of mandatory variables are not set.');
    }

    $hash_table_size = count($dict[DICT_TABLE]);
    $hash_value_idx = $dict[DICT_HASH]($key) % $hash_table_size;

    for ($i = 0; $i < $hash_table_size; $i++) {
        $idx = ($hash_value_idx + $i) % $hash_table_size;

        if (is_null($dict[DICT_TABLE][$idx][HASHTABLE_KEY])) {
            break;
        }

        else if ($dict[DICT_TABLE][$idx][HASHTABLE_KEY] == $key) {
            if ($dict[DICT_TABLE][$idx][HASHTABLE_DELETED]) {
                break;
            }

            $dict[DICT_TABLE][$idx][HASHTABLE_DELETED] = true;
            $dict[DICT_DELETED]++;

            // Чистим таблицу от удаленных ключей, если их кол-во в процентном
            // отношении к размерности таблицы превышает заданный порог.
            if ($dict[DICT_DELETED] / (float)$hash_table_size > CLEANUP_THRESHOLD) {
                _dict_extend($dict, 0);
            }

            return true;
        }
    }

    return false;
}

/**
 * Простейшая функция расширения и чистки хэш таблицы.
 *
 * Самая простая реализация расширения и чистки словаря на php. Увеличивает размер таблицы на $extend (EXTEND_STEP)
 * и чистит ключи помеченные как удаленные.
 *
 * Для улучшения, как минимум можно сделать:
 * 1) Реализовать другую политику расширения (например: чистить только удаленные, а не увеличивать размер таблицы
 * при каждом вызове).
 * 2) Хранить посчитаный хэш от ключа в самой таблице, чтобы не вычислять его заново при перестроении.
 * 3) Реализовать уменьшение размерности хэш таблицы.
 *
 * @param array $dict   Ассоциативный массив.
 * @param int   $extend Шаг расширения таблицы. Сделан отдельным параметром, чтобы было легко делать чистку в dict_del.
 *
 * @throws InvalidArgumentException
 */

function _dict_extend(&$dict, $extend = EXTEND_STEP) {
    if (!isset($dict) || !is_int($extend) || $extend < 0) {
        throw new InvalidArgumentException('_dict_extend() $dict is not set.');
    }

    $new_dict = dict_init($dict[DICT_HASH], count($dict[DICT_TABLE]) + $extend);

    for ($i = 0; $i < count($dict[DICT_TABLE]); $i++) {
        if (!is_null($dict[DICT_TABLE][$i][HASHTABLE_KEY]) && !$dict[DICT_TABLE][$i][HASHTABLE_DELETED]) {
            // Вызывать каждый раз функцию вставки не хорошо, но т.к. пишем простую реализацию,
            // то с её вызовом код становится сильно короче.
            dict_set($new_dict, $dict[DICT_TABLE][$i][HASHTABLE_KEY], $dict[DICT_TABLE][$i][HASHTABLE_VALUE], false);
        }
    }

    $dict = $new_dict;
}

?>
