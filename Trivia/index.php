<?php
	session_start();
	$connect_status = 1;
	
	define('LOG_FILE', 'error_log');
	/*Some PHPing
	..check if all the session variables we need are present
	..if not we clean up the session
	..if they do, then we change the connection state to 'already' connected*/
	$dsn = 'mysql:dbname=theloner_trivia;host=127.0.0.1';
	$db_user = 'theloner_sindar';
	$db_password = 'gandalf==42';
	$session_id = session_id();
			
	try {
		$db_handle = new PDO($dsn, $db_user, $db_password);
	} catch ( PDOException $e ) {
		error_log('Connection failed: '.$e->getMessage(), 3, LOG_FILE);
		exit(1);
	}
	if( isset($_SESSION['latency']) && isset($_SESSION['nick']) && isset($_SESSION['room']) && isset($_SESSION['max_latency']) ) {
		#OK, so our session's intact - but we'll have to check if the DB data's still around
		$check_user_exists = $db_handle->prepare('SELECT 1 FROM user_room WHERE user_id = :session_id LIMIT 1');
		$check_user_exists->execute(array(':session_id' => $session_id));
		if( $check_user_exists->fetchColumn() ) {
			$connect_status = 2;
		}
		else {
			#Clean up the session - it's out of sync with the DB
			session_unset();
		}
	}
	else {
		#error_log('My session ID is: '.$session_id.'\n', 3, LOG_FILE);
		$remove_user = $db_handle->prepare('DELETE FROM user_room WHERE user_id = :session_id');
		$remove_status = $remove_user->execute(array(':session_id'=>$session_id));
		/*if( !$remove_status ) {
			$err_info = $remove_user->errorInfo();
			$err_line = $err_info[2];
			error_log('Query Execution Failed: '.$err_line.'\n', 3, LOG_FILE);
		}*/
		
		
		$remove_user_events = $db_handle->prepare('DELETE FROM room_event WHERE user_id = :session_id');
		$remove_status = $remove_user_events->execute(array(':session_id'=>$session_id));
		/*if( !$remove_status ) {
			$err_info = $remove_user_events->errorInfo();
			$err_line = $err_info[2];
			error_log('Query Execution Failed: '.$err_line.'\n', 3, LOG_FILE);
		}*/
		session_unset();
	}

?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN"
"http://www.w3.org/TR/html4/loose.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
		<title>The Trivia Engine!</title>
		<link rel=StyleSheet href="CSS/main.css" type="text/css">
		<script type="text/javascript" src="JavaScript/jQuery.js"></script>
		<script type="text/javascript" src="JavaScript/QuestionManager.js"></script>
		<script type="text/javascript" src="JavaScript/Ping.js"></script>
    </head>
    <body class="all">
        <div id="welcome">
			<p> Welcome to the Infinite Trivia engine! </p>
			<p> </p>
			<p> Keep answering questions till your fingers fall off! Choose a nick to begin.<br> If you're bored, if you've something profoundly witty to say, if you'd like to compliment me on my fashion sense, or if you've just discovered a bug, drop a mail to LoneRanger at his Internet postbox (abhinav.neelam@yahoo.com).</p>
		</div>
		
		<div >
			<p id="ping">Calculating ping time...</p>
			<script type="text/javascript">
				connectionState = <?php echo $connect_status; ?>;
				if( connectionState == 1 ) {
					ping();
				}
				else {
					updatePingStatus(<?php echo $_SESSION['latency']; ?>);
					setTimeout( ping, 60000);
				}
			</script>
		</div>
		
		<div id="left">
			<div id="commandlinewrapper">
				<div id="commandline">
					<span id="prompt"><span id="prompttext"></span>&nbsp;</span>
					<script type="text/javascript"> $('#prompttext').text(userPrompt);</script>
					<span id="command"><input type="text" id="inputline" value=""></input></span>
					<script type="text/javascript">
						/*Clear the text already present in the input textfield*/
						$('#inputline').val('');
						
						if( connectionState == 1 ) {
							/*Ask him for a nick*/
							writeTerminal(clientPrompt + ' Pick a nick!');
						}
						else {
							/*We have an active session -we're just coming back*/
							userPrompt = '<?php echo $_SESSION['nick'].'@room'.$_SESSION['room'].'.thelonerangershack$'; ?>';
							$('#prompt').text(userPrompt);
							connectRoom();
							setTimeout(checkRoom, 1000);
						}
						$('#inputline').focus();
						
						/*I need to refocus as soon as it's lost
						.. Apparently I can't simply call focus() in the onblur() handler
						.. so this magic timeout route*/
						$('#inputline').blur( function(){ 
							setTimeout( function(){ 
								$('#inputline').focus(); 
							}, 0); 
						} );
						
						/*Handle the press of the 'Enter' key*/
						$('#inputline').keydown( function (event) {
							//The Enter key
							if( event.keyCode == '13' ) {
								event.preventDefault();
								handleSubmit();
							}
						});
						
						/*Irritate the user a little
						$(window).blur( function() {
							resetSession();
							saveAndMove( 'You cheat! Your session has been removed! Do not move away from the window again.');
						});*/
					</script>
				</div>
			</div>
		</div>
		
		<div id="right">
		</div>
		
    </body>
</html>
