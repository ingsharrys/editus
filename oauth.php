<?php $permissions = ['email']; // los permisos que necesites

// Usa una de las URLs registradas
$callbackUrl = 'https://app.editus.online/auth/facebook/callback';

$loginUrl = $helper->getLoginUrl($callbackUrl, $permissions);
