<?php
header("Content-Type: application/json; charset=utf-8");
session_start();
	
	include "QuestionManager.class.php";
	$current_timestamp = microtime(true)*1000.0;

	define('MAX_EVENTS', 100);
	define('EVENT_TIMEOUT', 60000.0);  //milliseconds//
	define('POLL_INTERVAL', 200000.0); //microseconds//
	
	if( count($argv) < 4 ){
		die("Not enough arguments");
	}
	$action = $argv[1];
	$nick = $argv[2];
	$latency = $argv[3];
	
	$valid_actions = array( 'join' );
	
	/*Script to test if adding somebody to a room works*/
	if( !isset($action) || !in_array($action, $valid_actions ) ){
		echo json_encode( array('status'=>'Error', 'data'=>'Invalid action!') );
		exit(1);
	}	

	#Set up database connection
	$dsn = 'mysql:dbname=theloner_trivia;host=127.0.0.1';
	$db_user = 'theloner_sindar';
	$db_password = 'gandalf==42';
	$log_file = "/home3/theloner/logs/Trivia/error_log";
	
	try {
		$db_handle = new PDO($dsn, $db_user, $db_password);
	} catch ( PDOException $e ) {
		error_log('Connection failed: '.$e->getMessage(), 3, $log_file);
		echo json_encode(array('status'=>'Error', 'data'=>'Database connect failed'));
		exit(1);
	}
	
	try_add_room( $nick, $latency );
	
	
	#Check if the nick exists in the DB for this room_id, if not, accept it and add it
	function try_add_room( $nick, $latency ) {
		global $log_file, $db_handle, $current_timestamp;
	
		#If there's no latency value in the session, the ping's been missed out somehow. 
		#Quit and ask the client to do it again
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
			$session_id = -1;
			$add_nick = $db_handle->prepare('INSERT INTO user_room (room_id, user_id, nick, latency) VALUES( :room_id, :user_id, :nick, :latency)');
			
			$add_nick->execute(array(':room_id' => $room_id, ':user_id' => $session_id, ':nick' => $nick, ':latency' => $latency ));  
			
			#Save in the sesion for quick retrieval
			$_SESSION['nick'] = $nick;
			$_SESSION['room'] = $room_id;
			
			#Save the max_latency in the room for quick retrieval
			$get_max_latency = $db_handle->prepare('SELECT max(latency) from user_room where room_id = :room_id');
			$get_max_latency->execute(array(':room_id' => $room_id));
			
			$max_latency_row = $get_max_latency->fetch(PDO::FETCH_NUM);
			$max_latency = $max_latency_row[0];
			$_SESSION['max_latency'] = $max_latency;
			
			$message = "You've joined trivia room $room_id, $nick!";
			$server_prompt = "trivia@room$room_id.thelonerangershack$";
			$user_prompt = "$nick@room$room_id.thelonerangershack$";
			echo json_encode( array('status'=>'Success', 'data'=>array('prompt'=>$server_prompt, 'text'=>$message, 'userprompt'=>$user_prompt)) );
		}
		else {
			$message = "That nick's already taken. Pick another one!";
			$server_prompt = "trivia@room$room_id.thelonerangershack";
			echo json_encode( array('status'=>'Error', 'data'=>array('prompt'=>$server_prompt, 'text'=>$message)) );
		}
	}

	
?>