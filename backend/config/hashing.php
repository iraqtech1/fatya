<?php

return [
    'driver' => 'argon2id',
    'bcrypt' => ['rounds' => 12, 'verify' => true, 'limit' => 72],
    'argon' => ['memory' => 65536, 'threads' => 1, 'time' => 4, 'verify' => true],
    'rehash_on_login' => true,
];
