<?php
#This script will run as a shell process
#Invoked by QuestionManager.php whenever it detects a state change timer go off
#Why shell out? To save time. Creating a new question for example can take up to 10 seconds to generate, it seems
#I don't know if this will work
/*Set up the DB connection*/
	
	include "QuestionManager.class.php";
	define('LOG_FILE', '/home3/theloner/logs/Trivia/error_log');
	
	$cur_timestamp = microtime(true)*1000.0;
	
	#Pick up the command line args
	if( count($argv) < 2 ) {
		error_log('No room ID supplied in command line arguments', 3, LOG_FILE);
		exit(1);
	}
	$room = $argv[1];
	
	
	$dsn = 'mysql:dbname=theloner_trivia;host=127.0.0.1';
	$db_user = 'theloner_sindar';
	$db_password = 'gandalf==42';
	
	try {
		$db_handle = new PDO($dsn, $db_user, $db_password);
	} catch ( PDOException $e ) {
		error_log('Connection failed: '.$e->getMessage(), 3, LOG_FILE);
		exit(1);
	}

	#If we've simply been asked to add an event to the event table
	if( count($argv) > 2 ) {
		$user_id = $argv[2];
		$message = $argv[3];
		$time = $argv[4];
		addEvent( $room, $user_id, $message, $time);
		exit(0);
	}
	
	/*First fetch the latest question*/
	$get_question = $db_handle->prepare('SELECT room_id, question_id, question, second, answer, clue, remaining_clues, state, solver_id, next_event, solver_time FROM room_question WHERE room_id = :room ORDER BY question_id DESC LIMIT 1');
	$get_question->execute(array(':room' => $room)); 
	
	$question_row = $get_question->fetch(PDO::FETCH_ASSOC);

	#We call functions based on the results
	if( !$question_row ) {
		insertFirstQuestion();
		exit;
	}
	
	#Check the timestamp - the next_event time may be already have been updated
	if( !($cur_timestamp > $question_row['next_event']) ) {
		#Somebody else has already done our job - nothng to do
		exit;
	}
	
	
	/*If we're here we have a job to do
	..What we do may or may not actually update the DB, but that's not in our hands*/
	if( $question_row['state'] == QuestionManager::QUESTION_CLOSED ) {
		insertNextQuestion();
	}
	else if( $question_row['state'] == QuestionManager::QUESTION_CLOSED_WAITING ) {
		closeQuestion();
	}
	else {
		updateQuestion();
	}
	
	/*Insert the first question*/
	function insertFirstQuestion() {
		global $cur_timestamp, $room, $db_handle;
        #echo "Entering the insert!";
	
		$question_handler = new QuestionManager();
		$fetch_status = $question_handler->fetch_question();
		

		if( !$fetch_status ) {
			#print "This ain't working!";
			return;
		}
		
		$try_add_question = $db_handle->prepare('INSERT INTO room_question (room_id, question, second, answer, clue, remaining_clues, state, solver_id, next_event, solver_time) '.
												'VALUES (:room, :question, :second, :answer, :clue, :remaining_clues, :state, :solver_id, :next_event, :solver_time)'); 
		$try_add_question->execute( array(
										':room' => $room,
										':question' =>$question_handler->get_question(),
										':second' => $question_handler->get_second(),
										':answer' =>$question_handler->get_answer(),
										':clue' => $question_handler->get_clue(),
										':remaining_clues' => $question_handler->get_remaining_clues(),
										':state' => $question_handler->get_state(),
										':solver_id' => '0',
										':next_event' => (microtime(true)*1000.0 + QuestionManager::EVENT_LENGTH),
										':solver_time' => 0.0,
									));	
		#If we managed to add something, well and good, otherwise, no biggie.								
		if( $try_add_question->rowCount() > 0 ) {
			#Now we need to update the event table
			addEvent( $room, 0, str_replace($question_handler->get_answer(), $question_handler->get_clue(), $question_handler->get_question()), $cur_timestamp);
		}		
	}
	
	
	/*Inserts next question*/
	function insertNextQuestion() {
		global $cur_timestamp, $room, $db_handle, $question_row;
		
		/*Save the question ID - so that we can ensure that we update the right question all the time*/
		$question_id = $question_row['question_id'];

	
		$question_handler = new QuestionManager();
		$fetch_status = $question_handler->fetch_question();
		
		if( !$fetch_status ) {
			return;
		}
		
		#Save the post-fetch timestamp (it affects the time for the first clue)
		$try_add_question = $db_handle->prepare('INSERT INTO room_question (room_id, question, second, answer, clue, remaining_clues, state, solver_id, next_event, solver_time) '.
												'SELECT :room, :question, :second, :answer, :clue, :remaining_clues, :state, :solver_id, :next_event, :solver_time FROM DUAL '.
												'WHERE NOT EXISTS (SELECT * FROM room_question WHERE room_id = :room AND question_id > :question_id)');
		$try_add_question->execute( array(
										':room' => $room,
										':question' =>$question_handler->get_question(),
										':second' => $question_handler->get_second(),
										':answer' =>$question_handler->get_answer(),
										':clue' => $question_handler->get_clue(),
										':remaining_clues' => $question_handler->get_remaining_clues(),
										':state' => $question_handler->get_state(),
										':solver_id' => '0',
										':next_event' => (microtime(true)*1000.0 + QuestionManager::EVENT_LENGTH),
										':solver_time' => 0.0,
										':question_id' => $question_id
									));	
		#If we managed to add something, well and good, otherwise, no biggie.								
		if( $try_add_question->rowCount() > 0 ) {
			#Now we need to update the event table
			addEvent( $room, 0, str_replace($question_handler->get_answer(), $question_handler->get_clue(), $question_handler->get_question()), $cur_timestamp);
		}		
	}
	
	function updateQuestion() {
		global $cur_timestamp, $room, $db_handle, $question_row;
		/*Save the question ID - so that we can ensure that we update the right question all the time*/
		$question_id = $question_row['question_id'];

		
		echo "Entering the update, this is really, really wrong!";
		$question_handler = new QuestionManager();
		$question_handler->load_question( $question_row['question'], $question_row['second'], $question_row['answer'], $question_row['clue'], $question_row['state'], $question_row['remaining_clues']);
		
		$update_status = $question_handler->update_clue();
		if( !$update_status ) {
			/*We're out of clues - now we wait a little bit for somebody to answer it correctly before closing*/
			closeWaitQuestion();
			return;
		}
		
		/*Now we try to update the question's status*/
		$try_update_question = $db_handle->prepare('UPDATE room_question SET clue = :clue, remaining_clues = remaining_clues - 1, next_event = :post_cur_timestamp + :event_length WHERE room_id = :room AND next_event < :cur_timestamp AND state = :open AND question_id = :question_id');
		$try_update_question->execute( array(
										':clue' => $question_handler->get_clue(),
										':event_length' => QuestionManager::EVENT_LENGTH,
										':room' => $room,
										':post_cur_timestamp' => microtime(true)*1000.0,
										':cur_timestamp' => $cur_timestamp,
										':open' => QuestionManager::QUESTION_CLUE,
										':question_id' => $question_id
									));
		
		#If our update went through - very important to handle potential concurrent updates
		if( $try_update_question->rowCount() > 0 ) {
			addEvent( $room, 0, str_replace($question_handler->get_answer(), $question_handler->get_clue(), $question_handler->get_question()), $cur_timestamp);
		}	
	}
	
	/*We've run out of clues, mark the question as closed but wait for a little bit (latency) before serving the next question*/
	function closeWaitQuestion() {
		global $cur_timestamp, $room, $db_handle, $question_row;
		/*Save the question ID - so that we can ensure that we update the right question all the time*/
		$question_id = $question_row['question_id'];

		//Get the max latency of this room//
		$get_max_latency = $db_handle->prepare('SELECT max(latency) from user_room where room_id = :room');
		$get_max_latency->execute(array(':room' => $room));
		
		$max_latency_row = $get_max_latency->fetch(PDO::FETCH_NUM);
		$max_latency = $max_latency_row[0];
		
		$try_update_question = $db_handle->prepare('UPDATE room_question SET next_event = :post_cur_timestamp + :max_latency, state = :waiting WHERE room_id = :room AND next_event < :cur_timestamp AND state = :open AND question_id = :question_id');
		$try_update_question->execute( array(
										':max_latency' => 1.2*$max_latency,
										':waiting' => QuestionManager::QUESTION_CLOSED_WAITING,
										':room' => $room,
										':post_cur_timestamp' => microtime(true)*1000.0,
										':cur_timestamp' => $cur_timestamp,
										':open' => QuestionManager::QUESTION_CLUE,
										':question_id' => $question_id
									));
		
		#We need to add something to our event_log otherwise the RoomManager will spin out doing nothing (it'll only quit if it detects something added to the event_queue
		if( $try_update_question->rowCount() > 0 ) {
			addEvent($room, 0, "Time is running out...", $cur_timestamp);
		}	
	}
	
	/*We detected a close event - if somebody has answered it, we print the answerer's name and give him points, or we print the right answer*/
	function closeQuestion() {
		global $cur_timestamp, $room, $db_handle, $question_row;
		/*Save the question ID - so that we can ensure that we update the right question all the time*/
		$question_id = $question_row['question_id'];

		
		$try_update_question = $db_handle->prepare('UPDATE room_question SET next_event = :post_cur_timestamp + :event_length, state = :closed WHERE room_id = :room AND next_event < :cur_timestamp AND state = :waiting AND question_id = :question_id');
		$try_update_question->execute( array(
											':event_length' => QuestionManager::EVENT_LENGTH/2,
											':closed' => QuestionManager::QUESTION_CLOSED,
											':room' => $room,
											':post_cur_timestamp' => microtime(true)*1000.0,
											':cur_timestamp' => $cur_timestamp,
											':waiting' => QuestionManager::QUESTION_CLOSED_WAITING,
											':question_id' => $question_id
										));
		#If our update went through, we add an event 
		if( $try_update_question->rowCount() > 0 ) {
			#Yes, we successfully closed the question. But between the first fetch of the question, and the update somebody may have solved the question
			#We have to refetch solver_id
			$get_solver_id = $db_handle->prepare('SELECT solver_id FROM room_question WHERE question_id = :question_id');
			$get_solver_id->execute(array(':question_id' => $question_id));
			$solver_id_row = $get_solver_id->fetch(PDO::FETCH_NUM);
			$solver_id = $solver_id_row[0];
			
			if( $solver_id != "0" ) {
				#Somebody actually answered the question
				$get_nick = $db_handle->prepare('SELECT nick from user_room where room_id = :room AND user_id = :solver_id');
				$get_nick->execute(array(':room' => $room, ':solver_id' => $solver_id));
				$nick_row = $get_nick->fetch(PDO::FETCH_NUM);
				$nick = $nick_row[0];
				
				#Calculate the score
				$score = floor((float)QuestionManager::MAX_SCORE/(QuestionManager::MAX_CLUES - $question_row['remaining_clues'] + 1.0));
				
				#Now update his score
				$update_score = $db_handle->prepare('UPDATE user_room SET score = score + :score WHERE room_id = :room AND user_id = :solver_id');
				$update_score->execute(array(':score' => $score, ':room' => $room, ':solver_id' => $solver_id));
				
				addEvent($room, 0, "$nick is correct. $score points! (Answer: ".$question_row['answer'].")", $cur_timestamp); 
			}
			else {
				#Nobody got it right. We just reveal the answer (and rebuke the silly fools)
				addEvent($room, 0, "Are you all dead? The right answer's ".$question_row['answer'], $cur_timestamp);
			}
		}		
	}

	#Add an event to the room_event table
	function addEvent( $room, $user_id, $text, $time ) {
		global $db_handle;
		$add_event = $db_handle->prepare('INSERT INTO room_event (room_id, user_id, text, event_time) VALUES (:room, :user_id, :text, :event_time)');
		$add_event->execute(array(
									':room'=>$room,
									':user_id'=>$user_id,
									':text'=>$text,
									':event_time'=>$time
							));
	}						
?>
