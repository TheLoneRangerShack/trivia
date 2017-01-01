var question = "";
var result = "";
var clientPrompt = "gatekeeper@guesthouse.com$";
var userPrompt = "guest@guesthouse.com$";

/*Monitor this flag to check whether we have to wait before connecting, or if we can connect directly
	..when do we wait? when there's an error for example (server error or connecti issue) - we don't want to bombard the server continuously*/
var connectRoomWait = 0;

/*State=1 implies, he's picking a nick
..State=2 implies, he's submitting an answer*/
var connectionState = 1;


/*Server side events
..This is what I plan to do -
.. Connect, and on receiving data, print
.. and reconnect
.. On error, wait for 2 seconds, reconnect
*/
function connectRoom () {
	$.ajax({
		async: true,
		timeout: 60000,
		type: "GET",
		url: "/trivia/Actions/RoomManager.php",
		data: "action=fetch",
		cache: false,
		dataType: "json",
		success: dataReceived,
		error: connectRoomError,
		complete: function (){
			if( connectRoomWait == 1) {
				/*Wait for two seconds before trying*/
				setTimeout( connectRoom, 2000);
			}
			else {
				connectRoom();
			}	
		}
	});		
}

function dataReceived( response ) {
	//We should be getting a JSON object here
	if( response.status == 'Error' ) {
		writeTerminal( response.prompt + ' Lost connection to trivia server. Retrying ...');
		connectRoomWait = 1;
	}
	else {
		for( var i=0; i<response.data.length; i++ ) {
			var colour = '0';
			if( 'colour' in response.data[i] ) {
				colour = response.data[i].colour;
			}
			writeTerminal( '[' + response.data[i].time + '] ' + response.data[i].prompt + ' ' + response.data[i].text, colour);
		}
		connectRoomWait = 0;
	}

}

function connectRoomError(xhr, ajaxOptions, thrownError) {
	/*Don't forget to remove this before going to production. Should have some way of saving these errors somewhere? Another XHR - that'd be too much methinks.*/
	//console.log(xhr.status);
	//console.log(xhr.statusText);
	//console.log(thrownError);
	writeTerminal( clientPrompt + ' Failed to connect to trivia server. Retrying ...');
	connectRoomWait = 1;
}

/*This function keeps sending text as soon as the enter key is pressed
.. Think about how to reduce number of http requests filed here*/
function submit (answer) {
	$.ajax({
		async: false,
		type: "GET",
		url: "/trivia/Actions/QuestionManager.php",
		data: {answer: answer},
		cache: false,
		dataType: "json",
		error: function ( xhr, ajaxOptions, thrownError) {
			/*Don't forget to remove this before going to production. Should have some way of saving these errors somewhere? Another XHR - that'd be too much methinks.*/
			//console.log(xhr.status);
			//console.log(xhr.statusText);
			//console.log(thrownError);
			writeTerminal( clientPrompt + ' Failed to submit answer. Please retry.');
		}
	});		
}

function chooseNick( nick ) {
	$.ajax({
		async: false,
		type: "GET",
		url: "/trivia/Actions/RoomManager.php",
		data: {action: 'join', nick: nick},
		cache: false,
		dataType: "json",
		success: function ( response ) {
			if( response.status == 'Success' ) {
				connectionState = 2;
				userPrompt = response.data.userprompt;
				$('#prompttext').text(userPrompt);
				connectRoom();
				setTimeout(checkRoom, 1000);
			}
			var colour = '0';
			if( 'colour' in response.data ) {
				colour = response.data.colour;
			}
			writeTerminal( '[' + response.data.time + '] ' + response.data.prompt + ' ' + response.data.text, colour );
			
		},
		error: function ( xhr, options, thrownError) {
			/*Don't forget to remove this before going to production. Should have some way of saving these errors somewhere? Another XHR - that'd be too much methinks.*/
			//console.log(xhr.status);
			//console.log(xhr.statusText);
			//console.log(thrownError);
			writeTerminal( clientPrompt + ' Failed to submit answer. Please retry.');
		}
	});		

}

/*Update the room occupants list on the right hand side (every 30 seconds)*/
function checkRoom() {
	$.ajax({
		async: false,
		type: "GET",
		url: "/trivia/Actions/RoomOccupancy.php",
		cache: false,
		dataType: "json",
		success: function ( response ) {
			if( response.status == 'Success' ) {
				roomListReceived(response);
			}
			
		},
		error: function ( xhr, options, thrownError) {
			/*Don't forget to remove this before going to production. Should have some way of saving these errors somewhere? Another XHR - that'd be too much methinks.*/
			//console.log(xhr.status);
			//console.log(xhr.statusText);
			//console.log(thrownError);
		},
		complete: function() {
			setTimeout( checkRoom, 30000);
		}
		
	});
}

/*Update the room occupants list*/
function roomListReceived( response ) {
	/*Clean up the current room list*/
	$('#right').empty();
	for( var i=0; i<response.data.length; i++ ) {
		var nick = response.data[i].nick;
		var latency = response.data[i].latency;
		var score = response.data[i].score;
		$('#right').append('<p>'+nick+' ('+latency+' ms)  '+score+'</p>');
	}
}

/*Update our little terminal port*/
function writeTerminal( content, colour ) {
	//Create a new tag and add before our commandline
	
	//If we're given a colour - we use that to style this div
	if( arguments.length==2 && colour != '0' ) {
		$('<div style="color: '+colour+'"></div>').insertBefore($('#commandlinewrapper'));
	}	
	else {	
		$('<div></div>').insertBefore($('#commandlinewrapper'));
	}
	//Set the text there
	$('#commandlinewrapper').prev().text(content);
	
	//This doesn't seem to be working for some reason?//
	//console.log(document.body.scrollHeight);
	//console.log(document.body.clientHeight);
	/*Scroll to bottom of scroll bar always*/
	window.scrollTo(0, document.body.scrollHeight);
	/*Ensure that the room user list also moves along with the scroll - let me trying setting padding-top*/
	
	//console.log($(window).height()+' '+$(document).height()+' '+$('#welcome').height()+' '+$('#ping').height()+' '+document.body.scrollHeight);
	
	/*Get scrollTop - since the property itself always seems to be returning zero*/
	var scrollTop = (document.body.scrollHeight - $(window).height());
	scrollTop = scrollTop<0?0:scrollTop;
	$('#right').css({top: scrollTop + $('#welcome').height() + $('#ping').height(), borderLeft: '3px dotted #cccccc'});
}
function handleSubmit() {

	/*Think about whether to make the input field read only during the execution of this function*/
	
	/*First pick up whatever's been typed so far: */
	var content = $('#inputline').val();
	
	if( content.length == 0 ) {
		/*We just create an empty line*/
		writeTerminal( userPrompt + ' ' );
		return;
	}
	
	/*Disable  our input field while we wait for a response*/
	$('#inputline').attr('disabled', 'true');
	
	/*Based on the state of the system, is he choosing a nick or submitting an answer?*/
	if( connectionState == 1) {
		chooseNick( content );
	}
	else {
		submit( content);
	}
	
	/*Clear the input field*/
	$('#inputline').val('');
		
	/*Re enable our disabled text field*/
	$('#inputline').removeAttr('disabled');
	
}
