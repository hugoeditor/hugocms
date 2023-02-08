<?php
$action = varExist( $_GET, 'action' ) ? $_GET['action'] : varExist( $_POST, 'action' );
$data   = varExist( $_GET, 'data' ) ? $_GET['data'] : varExist( $_POST, 'data' );
( $action and function_exists( $action ) ) or die( editor\resultInfo(false, "Param action or function `'.$action.'` not defined!") );
if( $data ) $action( $data ); else $action();

