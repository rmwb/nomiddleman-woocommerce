<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NMM_Util {

	public static function log($fileName, $lineNumber, $message) {
		
		// $logFileName = dirname(__DIR__) . '/' . NMM_LOGFILE_NAME;
		
		// if (file_exists($logFileName)) {
		// 	$file = fopen($logFileName, 'r+');
		// 	if ($file) {
		// 		fseek($file, 0, SEEK_END);
		// 	}
		// }
		// else
		// {
		// 	$file = fopen($logFileName, 'w');
		// }

		// if ($file) {
		// 	fwrite($file, "\r\n" . date("m-d-Y, G:i:s T") . "$fileName - $lineNumber: " . $message);
		// 	fclose($file);
		// }
	}

	public static function p_enabled() {
		return function_exists('NMMP_init');
	}

}

?>