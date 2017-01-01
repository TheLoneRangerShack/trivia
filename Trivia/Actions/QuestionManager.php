<?php
header("Content-Type: application/json; charset=utf-8");
session_start();
	
	include "QuestionManager.class.php";
	$received_timestamp = microtime(true) * 1000.0;
	$log_file = "/home3/theloner/logs/Trivia/error_log"; 
	
	#Get some session data
	$nick = $_SESSION['nick'];
	$room = $_SESSION['room'];
	$latency = $_SESSION['latency'];
	$max_latency = $_SESSION['max_latency'];
	
	$session_id = session_id();	
	#We've got all our session data - free up the session lock
	session_write_close();
	
	#If these parameters are missing, we can't continue
	if( !isset($nick) || !isset($room)) {
		echo json_encode(array('status'=>'Error', 'data'=>'Missing nick and/or room!'));
		exit(1);
	}
	if( !isset($latency) ) {
		echo json_encode(array('status'=>'Error', 'data'=>'Missing latency'));
		exit(1);
	}
	
	#Correct for the latency
	$ping_corrected_timestamp = $received_timestamp - $latency;
	
	$answer = stripslashes(urldecode(strip_tags($_GET['answer'])));
	/*This page either serves a question, or verifies an answer (by looking into the session)*/
	if( !isset($answer) ) {
		echo json_encode(array('status'=>'Error', 'data'=>'No data'));
		exit(1);
	}
	
	/*Set up the DB connection*/
	$dsn = 'mysql:dbname=theloner_trivia;host=127.0.0.1';
	$db_user = 'theloner_sindar';
	$db_password = 'gandalf==42';
	
	try {
		$db_handle = new PDO($dsn, $db_user, $db_password);
	} catch ( PDOException $e ) {
		error_log('Connection failed: '.$e->getMessage(), 3, $log_file);
		echo json_encode(array('status'=>'Error', 'data'=>'Database connect failed'));
		exit(1);
	}
	
	/*Get the max latency if it's somehow not loaded in the session*/
	if( !isset($max_latency) ) {
		$get_max_latency = $db_handle->prepare('SELECT max(latency) from user_room where room_id = :room');
		$get_max_latency->execute(array(':room' => $room));
		
		$max_latency_row = $get_max_latency->fetch(PDO::FETCH_NUM);
		$max_latency = $max_latency_row[0];
	}
	
	/*First check if this is an answer - or a 'command'*/
	if( $answer[0] == "!" ) {
		/*This is a command..
		..write a command handler that switch cases the given command - and writes the appropriate unicast events to the room_event table
		..As PoC I'm just writing a simple command handler that just echoes the same text for every command*/
		$add_event = $db_handle->prepare('INSERT INTO room_event (room_id, user_id, text, recipient, event_time) VALUES (:room, :session_id, :answer, :session_id, :ping_corrected_timestamp)');
		$add_event->execute( array(
							':room' => $room,
							':session_id' => $session_id,
							':answer' => $answer,
							':ping_corrected_timestamp' => $ping_corrected_timestamp
						));
	
		
		$add_event = $db_handle->prepare('INSERT INTO room_event (room_id, user_id, text, recipient, event_time) VALUES (:room, :session_id, :text, :recipient, :received_timestamp)');
		$add_event->execute( array(
								':room' => $room,
								':session_id' => 0,
								':text' => "Give me something that I can do, fool.",
								':recipient' => $session_id,
								':received_timestamp' => $received_timestamp
							));
	}
	else {	
		/*We do only three DB operations here..
		..First, add this text to the room_event table so that it can be picked up by the RoomManager and returned immediately - pseudo ping - to see your own text, you'll have to wait for the RoomManager to get back*/
		
		$add_event = $db_handle->prepare('INSERT INTO room_event (room_id, user_id, text, event_time) VALUES (:room, :session_id, :answer, :ping_corrected_timestamp)');
		$add_event->execute( array(
								':room' => $room,
								':session_id' => $session_id,
								':answer' => $answer,
								':ping_corrected_timestamp' => $ping_corrected_timestamp
							));
		/*
		
		#Fire and forget our handler to add this event to our table
		exec('php-cli QuestionStateHandler.php '.escapeshellarg($room).' '.escapeshellarg($session_id).' '.escapeshellarg($answer).' '.escapeshellarg($ping_corrected_timestamp).' >/dev/null 2>&1&'); 					
		*/					
		
		$correct_answer = false;
		
		/*..Next, change the state of the question to CLOSED_WAITING if it's a state lesser than that
		..if it's already in CLOSED_WAITING, update it 
		.. both only if the answer matches of course*/
		$verify_answer = $db_handle->prepare('UPDATE room_question SET next_event = :received_timestamp + :max_latency, state = :waiting, solver_time = :ping_corrected_timestamp, solver_id = :session_id'.
											' WHERE room_id = :room AND state < :closed AND lower(answer)=lower(:answer) AND next_event > :ping_corrected_timestamp');
		$verify_answer->execute( array(
									':received_timestamp' => $received_timestamp,
									':max_latency'=>1.2*$max_latency, 
									':waiting'=>QuestionManager::QUESTION_CLOSED_WAITING, 
									':ping_corrected_timestamp'=>$ping_corrected_timestamp, 
									':session_id'=>$session_id,
									':room' => $room,
									':closed' => QuestionManager::QUESTION_CLOSED_WAITING,
									':answer' => $answer
								));
		
		if( $verify_answer->rowCount() == 0 ) {
			#This means we didn't update - this either means the answer is wrong, or the question is already closed or closed_waiting
			#If closed waiting, we can still change the solver of the question - if the corrected ping timestamp is less than the solver timestamp associated with the question
			$update_answer = $db_handle->prepare('UPDATE room_question SET solver_id = :session_id, solver_time = :ping_corrected_timestamp WHERE room_id = :room AND state = :waiting AND lower(answer) = lower(:answer) AND solver_time > :ping_corrected_timestamp AND next_event > :ping_corrected_timestamp');
			$update_answer->execute( array(
										':session_id'=>$session_id,
										':ping_corrected_timestamp' => $ping_corrected_timestamp,
										':room' => $room,
										':waiting' => QuestionManager::QUESTION_CLOSED_WAITING,
										':answer' => $answer
									));	
			if( $update_answer->rowCount() > 0 ) {
				$correct_answer = true;
			}
		}
		else {
			$correct_answer = true;
		}
		
		#If we've got the right answer, let's inform everybody else
		if( $correct_answer ) {
			$add_event = $db_handle->prepare('INSERT INTO room_event (room_id, user_id, text, event_time) VALUES (:room, :session_id, :text, :received_timestamp)');
			$add_event->execute( array(
									':room' => $room,
									':session_id' => 0,
									':text' => $nick.' has got it! Anybody else? Tick tock. Tick tock.',
									':received_timestamp' => $received_timestamp
								));
		}
	}
	echo json_encode(array('status'=>'Success', 'data'=>''));
	
?>
