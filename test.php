<?php

require 'dictionary.php';

$dict = dict_init();

dict_set($dict, "test1", 1);
dict_set($dict, "test2", 2);
dict_set($dict, "test3", -3);
dict_set($dict, "test4", 0);
dict_set($dict, "test5", "5tset");

echo dict_get($dict, "test1", '[not exist]')."\n";
echo dict_get($dict, "test2", '[not exist]')."\n";
echo dict_get($dict, "test3", '[not exist]')."\n";
echo dict_get($dict, "test4", '[not exist]')."\n";
echo dict_get($dict, "test5", '[not exist]')."\n\n";

dict_delete($dict, "test2");

echo dict_get($dict, "test1", '[not exist]')."\n";
echo dict_get($dict, "test2", '[not exist]')."\n";
echo dict_get($dict, "test3", '[not exist]')."\n";
echo dict_get($dict, "test4", '[not exist]')."\n";
echo dict_get($dict, "test5", '[not exist]')."\n\n";

dict_delete($dict, "test4");

echo dict_get($dict, "test1", '[not exist]')."\n";
echo dict_get($dict, "test2", '[not exist]')."\n";
echo dict_get($dict, "test3", '[not exist]')."\n";
echo dict_get($dict, "test4", '[not exist]')."\n";
echo dict_get($dict, "test5", '[not exist]')."\n\n";

?>