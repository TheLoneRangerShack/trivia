<?php
header("Content-Type: application/json; charset=utf-8");
session_start();

/*This is a simple round trip latency computation script
.. Step 1: the client sends an HTTP request. At this point there'd be no associated session variable in the session
.. We note down the system time and save it in the session, and send an 'OK' response to the client.
.. The client immediately fires off another request. We note down the second system time, and send the OK response
.. (OPtional? Repeat steps 1,2 how many ever times the client wishes)
.. Average the times we've noted and store the latency, but only if a reset isn't requested
*/

#Maximum latency of 1000 ms to avoid cheating from client side pings
define('MAX_LATENCY', 1000.0);
define('LOG_FILE', '/home3/theloner/logs/Trivia/error_log2');
$reset = strip_tags( $_GET['reset'] ); 
$save = strip_tags( $_GET['save'] );

$latency = $_SESSION['latency'];
$last_time = $_SESSION['time'];

#Weight - how many times we've already computed the latency
$weight = $_SESSION['weight'];
$cur_time = (microtime(true))*1000.0;	


#If we've been asked to save it, we write to the DB and exit
if( $save ) {
	$room = $_SESSION['room'];	

	if( isset($room)) {
		$dsn = 'mysql:dbname=theloner_trivia;host=127.0.0.1';
		$db_user = 'theloner_sindar';
		$db_password = 'gandalf==42';
		
		try {
			$db_handle = new PDO($dsn, $db_user, $db_password);
		} catch ( PDOException $e ) {
			error_log('Connection failed: '.$e->getMessage(), 3, LOG_FILE);
			echo json_encode( array("status" => "Error", "data" => "Database connect failed") );
			exit(1);
		}
		$update_latency = $db_handle->prepare('UPDATE user_room SET latency = :latency WHERE room_id = :room AND user_id = :session_id');
		$update_latency->execute(array(':room' => $room, ':session_id' => session_id(), ':latency' => $latency));
		if( $update_latency->rowCount() == 0 ) {
			echo json_encode( array("status" => "Error", "data" => "Failed to update latency") );
		}
		else {
			echo json_encode( array("status" => "Success", "data" => "Successfully updated latency to $latency") );
		}
	}
	else {
		echo json_encode( array("status" => "Error", "data" => "Room ID not found in session") );
	}

}
else {
	#We restart if either a reset is requested or we find no evidence of a last run (really a first ime, or session delete or whatever)
	if( $reset || !isset($last_time) ) {
		#First visit in a while
		$_SESSION['time'] = (microtime(true))*1000.0;
		
		#error_log("Coming into the firsty group\n", 3, "/home3/theloner/logs/Trivia/error_log");
		
		#Really the first time ever - no precomputed latency/weight found or reset is invoked
		if( $reset || !isset( $latency ) || !isset($weight) ) {
			#Client understands this is as an error code
			$_SESSION['latency'] = -1;
			$_SESSION['weight'] = 0;
		}
		
	}
	else {
		#Compute the weighted average
		#error_log("Coming into the secondy group: $latency, $weight, $cur_time, $last_time \n", 3, "/home3/theloner/logs/Trivia/error_log");
		
		$latency = (($latency*$weight) + ($cur_time - $last_time))/($weight+1);
		$_SESSION['latency'] = $latency;
		$_SESSION['weight'] = $weight + 1;
		$_SESSION['time'] = $cur_time;
	}

	if( $_SESSION['latency'] > MAX_LATENCY ) {
		$_SESSION['latency'] = MAX_LATENCY;
	}

	echo json_encode( array("status"=>"Success", "latency"=>$latency));

}
	
?>