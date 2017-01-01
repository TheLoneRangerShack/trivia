<?php
header("Content-Type: application/json; charset=utf-8");
session_start();
	
	include "QuestionManager.class.php";
	$current_timestamp = microtime(true)*1000.0;

	define('MAX_EVENTS', 20);
	define('EVENT_TIMEOUT', 60000.0);  //milliseconds//
	define('POLL_INTERVAL', 200000.0); //microseconds//
	
	$action = stripslashes(urldecode(strip_tags($_GET['action'])));
	$valid_actions = array( 'fetch', 'join' );
	/*Either the user's already in a room, or he's submitting a nick to join a room*/
	if( !isset($action) || !in_array($action, $valid_actions ) ){
		echo json_encode( array('status'=>'Error', 'data'=>'Invalid action!') );
		exit(1);
	}	

	#Set up database connection
	$dsn = 'mysql:dbname=theloner_trivia;host=127.0.0.1';
	$db_user = 'theloner_sindar';
	$db_password = 'gandalf==42';
	$log_file = "/home3/theloner/logs/Trivia/error_log2";
	
	try {
		$db_handle = new PDO($dsn, $db_user, $db_password);
	} catch ( PDOException $e ) {
		error_log('Connection failed: '.$e->getMessage().'\n', 3, $log_file);
		echo json_encode(array('status'=>'Error', 'data'=>'Database connect failed'));
		exit(1);
	}
	
	if( $action == 'join' ) {
		$nick = strip_tags($_GET['nick']);
		if( !isset($nick) ) {
			echo json_encode( array('status'=>'Error', 'data'=>'Missing nick!'));
			exit(1);
		}
		try_add_room( $nick );
	}
	else {
		wait_for_data();
	}
	
	
	#Check if the nick exists in the DB for this room_id, if not, accept it and add it
	function try_add_room( $nick ) {
		global $log_file, $db_handle, $current_timestamp;
	
		#If there's no latency value in the session, the ping's been missed out somehow. 
		#Quit and ask the client to do it again
		$latency = $_SESSION['latency'];
		if( !isset($latency) ) {
			echo json_encode( array('status'=>'Error', 'data'=>'Missing latency!'));
			exit(1);
		}
	
		$check_nick = $db_handle->prepare('SELECT nick FROM user_room WHERE nick like :nick');
		$check_nick->execute( array(':nick' => $nick) );
		
		#I have to write some logic for picking a room
		#Right now, everybody goes into room 1
		$room_id = 1;
		
		if( !$check_nick->fetch(PDO::FETCH_NUM) ) {
			$session_id = session_id();
			
			#LoneRanger gets bright pink :P
			$colour = '0';
			if( $nick === 'LoneRanger' ) {
				$colour = '#FF66FF';
			}
			$add_nick = $db_handle->prepare('INSERT INTO user_room (room_id, user_id, nick, latency, colour) VALUES( :room_id, :user_id, :nick, :latency, :colour)');
			
			$add_nick->execute(array(
									':room_id' => $room_id,
									':user_id' => $session_id,
									':nick' => $nick,
									':latency' => $latency,
									':colour' => $colour
								));  			
			#Save in the sesion for quick retrieval
			$_SESSION['nick'] = $nick;
			$_SESSION['room'] = $room_id;
			
			#Save the max_latency in the room for quick retrieval
			$get_max_latency = $db_handle->prepare('SELECT max(latency) from user_room where room_id = :room_id');
			$get_max_latency->execute(array(':room_id' => $room_id));
			
			$max_latency_row = $get_max_latency->fetch(PDO::FETCH_NUM);
			$max_latency = $max_latency_row[0];
			$_SESSION['max_latency'] = $max_latency;
			
			#Send a message to the rest of the lot
			$room_join_message = $db_handle->prepare('INSERT INTO room_event (room_id, user_id, text, event_time) VALUES (:room_id, :user_id, :text, :event_time)');
			$room_join_message->execute(array(':room_id' => $room_id,
											  ':user_id' => $session_id,
											  ':text' => $nick.' has joined the room.',
											  ':event_time' => microtime(true)*1000.0
											 ));
			
			$message = "You've joined trivia room $room_id, $nick!";
			$server_prompt = "trivia@room$room_id.thelonerangershack$";
			$user_prompt = "$nick@room$room_id.thelonerangershack$";
			echo json_encode( array('status'=>'Success', 'data'=>array('prompt'=>$server_prompt, 'text'=>$message, 'userprompt'=>$user_prompt, 'time'=>time_string( microtime(true)*1000.0 ))) );
		}
		else {
			$message = "That one's already taken. Pick another one.";
			$server_prompt = "trivia@room$room_id.thelonerangershack";
			echo json_encode( array('status'=>'Error', 'data'=>array('prompt'=>$server_prompt, 'text'=>$message, 'time' => time_string( microtime(true)*1000.0))) );
		}
	}
	
	#The most important function in the whole damn thing
	/*The idea is this:
	..Step 1, if we've hit the next_event timestamp, we fork out a process and handle it
		.. this could include giving the next clue, closing a question and giving out the answer, or printing that so and so answered the question correctly
	..Step 2, we see if there are new events in the room_event table
		.. if there are, we order them by timestamp and return them
		.. if there aren't we wait for one minute (polling every 200ms) before closing the connection and returning nothing
	*/
	function wait_for_data() {
		global $log_file, $db_handle, $current_timestamp, $dsn, $db_user, $db_password;
		
		#All session handling here, and then call session_write_close()
		$room = $_SESSION['room'];
		/*... ADD IF POSSIBLE - ERROR Handling for missing room in the session - how can this happen?*/
		/*Get the last fetched event timestamp, if present*/
		$last_event = $_SESSION['last_event'];
		$user_id = session_id();
		
		if( !isset($last_event) ) {
			$last_event = 0;
		}
		session_write_close();
		
		#Sigh, get the last question - open or closed
		$get_next_event = $db_handle->prepare('SELECT next_event, question_id FROM room_question WHERE room_id = :room ORDER BY question_id DESC LIMIT 1');
		$get_next_event->execute(array(':room' => $room));
		
		//Next timestamp when something's supposed to happen//
		$next_event = $get_next_event->fetch(PDO::FETCH_NUM);
		
		$next_event_time = -1;
		
		/*We don't call the question handler more than once in a run of this script*/
		$question_handler_done = false;
		
		/*If there aren't any questions yet*/
		if( !$next_event ) {
			#error_log('Should be coming in here\n', 3, $log_file);
			/*Fire and forget - shell out to exec php script to update the clue, get a new question - background it and redirect the output*/
			exec('php-cli QuestionStateHandler.php '.escapeshellarg($room).' >/dev/null 2>&1&'); 
			$question_handler_done = true;
		}
		else {
			$next_event_time = $next_event[0];
			#error_log("Question ID: ".$next_event[1]." Next Event: $next_event_time\n", 3, $log_file);
		}	
		
		#Clean up the statement
		unset($next_event);
		
		/*We wait for one minute if there's no data*/
		$wait_timeout = $current_timestamp + EVENT_TIMEOUT;
		
		
		$get_events = $db_handle->prepare('SELECT re.event_id, ur.nick, re.text, re.event_time, ur.colour FROM room_event re INNER JOIN user_room ur ON re.room_id = ur.room_id AND re.user_id = ur.user_id WHERE (re.recipient = :user_id OR re.recipient = :broadcast_code) AND re.room_id = :room AND re.event_id >:last_event ORDER BY re.event_time DESC LIMIT '.MAX_EVENTS);
		
		$output = array('status'=>'Success', 'data'=>array());
		/*Not quite an infinite loop*/
		while( true ) {
			/*First check if we have a question state change to handle*/
			$cur_timestamp = microtime(true)*1000.0;
			#error_log("Cur_timestamp: $cur_timestamp, End_timestamp: $wait_timeout\n", 3, $log_file);
			
			if( !$question_handler_done && $cur_timestamp > $next_event_time ) {
				#error_log("Again, should NOT be here\n", 3, $log_file);
				/*Fire and forget - shell out to exec php script to update the clue, get a new question - background it and redirect the output*/
				exec('php-cli QuestionStateHandler.php '.escapeshellarg($room).' >/dev/null 2>&1&'); 
				$question_handler_done = true;
			}
		
			/*Try to get events - but not more than MAX_EVENTS*/
			$get_events->execute( array(
									':user_id' => $user_id,
									':broadcast_code' => 'ALL', 
									':room' => $room,
									':last_event' => $last_event
								));
			$data = array();
			while( ($event_row = $get_events->fetch(PDO::FETCH_NUM)) ){
				#Need to update the last event here
				if( $event_row[0] > $last_event ) {
					$last_event = $event_row[0];
				}
				$data[] = array('prompt' => $event_row[1].'@room'.$room.'.thelonerangershack$', 'text' => $event_row[2], 'time' => time_string($event_row[3]), 'colour' => $event_row[4]);
			}
			
			/*We've got some data, we quit*/
			if( !empty($data) ) {
				/*Reverse the array to sort events in ascending order of time, rather than the other way of round which was for limiting the number of events colelcted*/
				$data = array_reverse( $data );
				
				session_start();
				/*Save the last event*/
				$_SESSION['last_event'] = $last_event;
				session_write_close();
				
				/*Attach it to our output array*/
				$output['data'] = $data;
				break;
			}
								
			/*Break out if we're past our wait_timeout*/
			if( $cur_timestamp > $wait_timeout ) {
				break;
			}

			#Close and reopen the DB connection
			/*unset($get_events);
			unset($db_handle);
			try {
				$db_handle = new PDO($dsn, $db_user, $db_password);
			} catch ( PDOException $e ) {
				error_log('Connection failed: '.$e->getMessage().'\n', 3, $log_file);
				echo json_encode(array('status'=>'Error', 'data'=>'Database connect failed'));
				exit(1);
			}
			$get_events = $db_handle->prepare('SELECT re.event_id, ur.nick, re.text, re.event_time, ur.colour FROM room_event re INNER JOIN user_room ur ON re.room_id = ur.room_id AND re.user_id = ur.user_id WHERE re.room_id = :room AND re.event_id >:last_event ORDER BY re.event_time DESC LIMIT '.MAX_EVENTS);
			*/			
			/*Sleep before trying it again*/
			usleep(200000);
		}
		if( empty($output['data']) ) {
			#If we're here, we timed out without pulling any events.. we send a little reminder to the users - should I save this in the room_event table? NOTE
			$buzz = $db_handle->prepare('INSERT INTO room_event (room_id, user_id, text, event_time) VALUES (:room, :user_id, :text, :event_time)');
			$buzz->execute(array( 
								':room' => $room,
								':user_id' => 0,
								':text' => 'Knock, knock! Anybody home?',
								':event_time' => time_string(microtime(true)*1000.0)
							));	
		}
		echo json_encode( $output );
		
	}
	
	/*Get the formatted time string from epoch milliseconds*/
	function time_string( $milli_time ) {
		$seconds_time = $milli_time/1000.0;
		
		/*Only two decimal digits*/
		$milli_truncated = substr( sprintf('%.2f', $seconds_time - floor($seconds_time)), -3);
		$time_formatted = date('H:i:s', (int)floor($seconds_time));
		return $time_formatted.$milli_truncated;
	}
	
	
	
?>