<?php
// demo/app/pages/sair.php

declare(strict_types=1);

audit('logout');
$_SESSION = [];
session_destroy();
header('Location: '.url('login'));
exit;
