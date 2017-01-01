<?php
header("Content-Type: application/json; charset=utf-8");
	session_start();
	$room = $_SESSION['room'];
	session_write_close();
	
	define('MAX_NICK_DISPLAY', 20);
	define('FLOAT_DECIMAL_PRECISION', 2);
	define('LOG_FILE', '/home3/theloner/logs/Trivia/error_log2');
	
	$dsn = 'mysql:dbname=theloner_trivia;host=127.0.0.1';
	$db_user = 'theloner_sindar';
	$db_password = 'gandalf==42';
			
	try {
		$db_handle = new PDO($dsn, $db_user, $db_password);
	} catch ( PDOException $e ) {
		error_log('Connection failed: '.$e->getMessage(), 3, LOG_FILE);
		echo json_encode(array('status'=>'Error', 'data'=>'Database connect failed'));
		exit(1);
	}
	
	$fetch_room_occupants = $db_handle->prepare('SELECT nick, latency, score FROM user_room WHERE room_id=:room AND user_id NOT LIKE :zero ORDER BY nick ASC');
	$fetch_room_occupants->execute(array(':room'=>$room, ':zero'=>0));
	
	$output = array();
	while( ($occupant_info_row = $fetch_room_occupants->fetch(PDO::FETCH_ASSOC)) ) {
		$output[] = array('nick'=>substr($occupant_info_row['nick'], 0, MAX_NICK_DISPLAY), 'latency' => sprintf("%.2f", $occupant_info_row['latency']), 'score'=> $occupant_info_row['score']);
	}
	
	/*Sort it in reverse order of score*/
	usort( $output, "score_comparator");
	
	function score_comparator( $output_line_1, $output_line_2 ) {
		if( $output_line_1['score'] > $output_line_2['score'] ) {
			return 1;
		}
		else if ( $output_line_1['score'] < $output_line_2['score'] ) {
			return -1;
		}
		else {
			return 0;
		}
	}
	echo json_encode(array('status'=>'Success', 'data'=>$output));
	
?>