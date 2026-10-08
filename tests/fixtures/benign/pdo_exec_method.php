<?php
// @expect: none
function migrate(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE t (id INT)');
}
