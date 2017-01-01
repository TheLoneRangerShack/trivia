var pingCount = 0;
var maxPings = 2;
function ping() {
	
	if( pingCount == 0 ) {
		$.ajax({
			async: false,
			type: "GET",
			url: "/trivia/Actions/Ping.php",
			data: "reset=1",
			cache: false,
			dataType: "json",
			success: function( response ) {
				if( pingCount==maxPings ) {
					//Time for us to process the response
					getLatency( response );
				}
			},
			error: function( xhr, options, thrown ) {
				console.log(xhr.status);
				console.log(xhr.statusText);
				console.log(thrown);
			},
			complete: function() {
				if( pingCount < maxPings ) {
					pingCount++;
					ping();
				}
			}
		});
	}
	else {
		$.ajax({
			async: false,
			type: "GET",
			url: "/trivia/Actions/Ping.php",
			cache: false,
			dataType: "json",
			success: function( response ) {
				if( pingCount==maxPings ) {
					//Time for us to process the response
					getLatency( response );
				}
			},
			error: function( xhr, options, thrown ) {
				console.log(xhr.status);
				console.log(xhr.statusText);
				console.log(thrown);
			},
			complete: function() {
				if( pingCount < maxPings ) {
					pingCount++;
					ping();
				}
				else {
					//We're done pinging but we'll call it again every 5 minutes to reset the ping value.
					pingCount = 0;
					
					//Inform the server that the DB saved ping value should be updated
					savePing();
					setTimeout(ping, 60000);
				}
			}
		});

	}

}

/*After we're done with the pinging, we ask the server to save the updated ping value*/
function savePing() {
	$.ajax({
		async: false,
		type: "GET",
		url: "/trivia/Actions/Ping.php",
		data: "save=1",
		cache: false,
		dataType: "json"
		/*We don't really care if this call succeeded or not*/
	});
}

/*
function resetSession() {
	$.ajax({
		async: false,
		type: "GET",
		url: "/trivia/Actions/ping.php",
		data: "action=reset",
		cache: false,
		dataType: "json"
	});		

}
*/

function getLatency( response ) {
	if( response.status == 'Success' ) {
			updatePingStatus( response.latency );
	}
	else {
			updatePingStatus( -1 );
	}
}

function updatePingStatus( latency ) {
	if( latency > 0 ) {
		$('#ping').text('Your latency is '+ latency+ ' ms.');
	}
	else {
		$('#ping').text('Failed to compute latency.');
	}
}
