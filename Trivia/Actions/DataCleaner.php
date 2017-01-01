<?php
	/*This PHP CLI script deletes all content older than THRESHOLD seconds older than current timestamp*/
	define('THRESHOLD', 30*60);
	define('LOG_FILE', '/home3/theloner/logs/Trivia/error_log2');
	
	$dsn = 'mysql:dbname=theloner_trivia;host=127.0.0.1';
	$db_user = 'theloner_sindar';
	$db_password = 'gandalf==42';
			
	try {
		$db_handle = new PDO($dsn, $db_user, $db_password);
	} catch ( PDOException $e ) {
		error_log('Connection failed: '.$e->getMessage(), 3, LOG_FILE);
		exit(1);
	}
	
	/*Cut off in milliseconds - we delete any record with timestamp < this value*/
	$milli_cutoff = microtime(true)*1000.0 - THRESHOLD*1000.0;
	error_log("Cutoff: $milli_cutoff\n", 3, LOG_FILE);

	/*Clean up room_event table*/
	$delete_events = $db_handle->prepare('DELETE FROM room_event WHERE event_time < :milli_cutoff');
	$delete_events->execute(array(':milli_cutoff' => $milli_cutoff));
	error_log("Deleted events: ".$delete_events->rowCount()."\n", 3, LOG_FILE);
	
	/*Clean up room_question table*/
	$delete_questions = $db_handle->prepare('DELETE FROM room_question WHERE next_event < :milli_cutoff');
	$delete_questions->execute(array(':milli_cutoff' => $milli_cutoff));
	error_log("Deleted questions: ".$delete_questions->rowCount()."\n", 3, LOG_FILE);
	
	
	/*OK, my plan is to clean up user_records that don't have any events in the room_event table*/
	$get_idle_users = $db_handle->prepare('SELECT ur.room_id, ur.user_id, ur.nick FROM user_room ur LEFT JOIN room_event re '.
											'ON ur.room_id = re.room_id AND ur.user_id = re.user_id '.
											'WHERE re.room_id IS NULL');
	$get_idle_users->execute();

	/*Now, delete each user one by one, so that we send them a notification that their session expired.*/
	while( ($get_idle_user_row = $get_idle_users->fetch(PDO::FETCH_NUM))) {
		/*Don't delete if this is the trivia user we're talking about*/
		if( $get_idle_user_row[1] == '0' ) {
			continue;
		}
		$delete_idle_user = $db_handle->prepare('DELETE FROM user_room WHERE user_id = :user_id AND room_id = :room_id AND NOT EXISTS (SELECT * FROM room_event WHERE user_id = :user_id AND room_id = :room_id)');
		$delete_idle_user->execute(array(':user_id'=>$get_idle_user_row[1], ':room_id'=>$get_idle_user_row[0]));
		if( $delete_idle_user->rowCount() > 0 ) {
			/*The user didn't add an event between us choosing him as an idle user and deleting him*/
			/*Inform him when he sees the browser window again - that he needs to refresh to continue playing*/
			$session_expire_event = $db_handle->prepare('INSERT INTO room_event (room_id, user_id, text, event_time) VALUES (:room, :session_id, :text, :timestamp)');
			$session_expire_event->execute(array(':room' => $get_idle_user_row[0],
												 ':session_id' => 0,
												 ':text' => 'Your session has expired, '.$get_idle_user_row[2].'! Refresh the page to continue playing',
												 ':timestamp' => microtime(true)*1000.0
												));
		}
	}
	
											
?>
