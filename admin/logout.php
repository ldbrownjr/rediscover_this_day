<?php
require_once '../common.php';

// Logout
$_SESSION = [];
session_destroy();

header('Location: login.php');
exit;
