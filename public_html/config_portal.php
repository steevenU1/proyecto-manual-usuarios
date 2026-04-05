<?php
// config_portal.php
// Config exclusiva para Portal Intercentrales

if (!defined('PORTAL_API_BASE')) {
    define('PORTAL_API_BASE', 'https://lugaph.site');
}

if (!defined('PORTAL_API_CREATE_PATH')) {
    define('PORTAL_API_CREATE_PATH', '/api/api_portal_proyectos_crear.php');
}

if (!defined('PORTAL_API_LIST_PATH')) {
    define('PORTAL_API_LIST_PATH', '/api/api_portal_proyectos_listar.php');
}

if (!defined('PORTAL_API_VER_PATH')) {
    define('PORTAL_API_VER_PATH', '/api/api_portal_proyectos_ver.php');
}

if (!defined('PORTAL_API_AUTORIZAR_PATH')) {
    define('PORTAL_API_AUTORIZAR_PATH', '/api/api_portal_proyectos_autorizar.php');
}

/*
|--------------------------------------------------------------------------
| TOKEN DE NANO CONTRA LUGA
|--------------------------------------------------------------------------
| Debe coincidir EXACTAMENTE con el token que _auth.php en LUGA
| tenga asignado al origen NANO.
*/
if (!defined('PORTAL_TOKEN_NANO')) {
    define('PORTAL_TOKEN_NANO', '1Sp2gd3pa*1Fba23a326*');
}

/*
|--------------------------------------------------------------------------
| TOKEN DE MIPLAN CONTRA LUGA
|--------------------------------------------------------------------------
| Lo dejamos listo para cuando montes la otra central.
*/
if (!defined('PORTAL_TOKEN_MIPLAN')) {
    define('PORTAL_TOKEN_MIPLAN', 'PON_AQUI_EL_TOKEN_REAL_DE_MIPLAN');
}