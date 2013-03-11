<?php
/**
 * Created by JetBrains PhpStorm.
 * User: Administrator
 * Date: 13-3-11
 * Time: 下午8:24
 * To change this template use File | Settings | File Templates.
 */

$db = new PDO('mysql:host=localhost;dbname=jobtest','root','111');
$db->exec("set names utf8");

$stmt = $db->query("select `id`,`C` from `topic`");
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($data);
