<?php
function varExist( $var, $key = FALSE ){
     if( $key ) return array_key_exists( $key, $var ) ? $var[$key] : FALSE;
     if( !isset( $var ) ) return FALSE;
     return $var;
}

function writeLog( $log, $append = true ){
    $file = __DIR__.'/../log/debug.log';
    $str  = date("Y-m-d H:i:s -> " ).print_r( $log, TRUE )."\n";
    $append? file_put_contents( $file, $str, FILE_APPEND ) : file_put_contents( $file, $str );
}

