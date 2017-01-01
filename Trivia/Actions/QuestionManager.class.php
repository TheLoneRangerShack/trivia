<?php
	class QuestionManager {
		private $question;
		private $random_url = "http://en.wikipedia.org/wiki/Special:Random";
		private $max_retries = 3;
		private $log_file = "/home3/theloner/logs/Trivia/error_log2";
		private $final_url;
		private $answer;
		private $clue;
		
		const QUESTION_CLUE = 1;
		const QUESTION_CLOSED_WAITING = 2;
		const QUESTION_CLOSED = 3;
		
		#Milliseconds
		const EVENT_LENGTH = 12000;
		const MAX_CLUES = 4;
		const MAX_SCORE = 100;
		
		const CLUE_LETTER = '@';
		
		private $remaining_clues = 2;
		private $state;
		
		#The final clue
		private $second_statement = "";
		
		#Getters
		public function get_question() {
			return $this->question;
		}
		public function get_answer() {
			return $this->answer;
		}
		public function get_clue() {
			return $this->clue;
		}
		public function get_remaining_clues() {
			return $this->remaining_clues;
		}
		public function get_state() {
			return $this->state;
		}
		public function get_second() {
			return $this->second_statement;
		}
		
		public function load_question( $question, $second, $answer, $clue, $state, $remaining_clues ) {
			$this->question = $question;
			$this->second_statement = $second;
			$this->answer = $answer;
			$this->state = $state;
			$this->clue = $clue;
			
			#How many clues have been given out already? Does not include the original fully empty clue
			$this->remaining_clues = $remaining_clues;
		}
		
		public function fetch_question() {
			$break_out = false;
			$max_retries = 3;
			$retries = 0;
				
			while ( !$break_out ) {
				
				#URL for debugging UTF-8 multibyte character problems
				#$this->random_url = "http://en.wikipedia.org/wiki/Stielers_Handatlas";
				
				#URL for debugging italicized title name
				#$this->random_url = "http://en.wikipedia.org/wiki/The_Firm_%28album%29";
				
				$curl_handle = curl_init();
				
				curl_setopt($curl_handle, CURLOPT_URL, $this->random_url);
				curl_setopt($curl_handle, CURLOPT_FOLLOWLOCATION, true);
				curl_setopt($curl_handle, CURLOPT_HEADER, false);
				curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($curl_handle, CURLOPT_USERAGENT,'TheWikiTriviaServer (+http://thelonerangershack.com/contact.html)');
				
				$curl_data = curl_exec($curl_handle);
				$curl_status_code = curl_getinfo( $curl_handle, CURLINFO_HTTP_CODE);
				
				$final_url = curl_getinfo( $curl_handle, CURLINFO_EFFECTIVE_URL);
				
				#Save the final URL
				$final_url_decoded = urldecode( $final_url );
				$this->final_url = $final_url_decoded;

				
				#It's not 200 OK for whatever reason
				if( $curl_status_code != "200" ) {
					$error_message = "HTTP Return Code: $curl_status_code for $final_url_decoded";
					error_log("$error_message\n", 3, $this->log_file);
						
					if( $retries == $max_retries ) {
						break;
					}
					$error_message = "";
					$retries++;
					continue; #Retry with another URL
				}
				
			
				/*Now for some html parsing*/
				$dom = new DOMDocument();
				$return_code = $dom->loadHTML($curl_data);
				
				if( $return_code === false ) {
					$error_message = "DOM Parse status: $return_code for article $final_url_decoded";
					error_log("$error_message\n", 3, $this->log_file);
					
					if( $retries == $max_retries ) {
						break;
					}
					$error_message = "";
					$retries++;
					continue;
				}
				
				$secondary_title_element = $dom->getElementById( "firstHeading" );
				
				if( !is_null( $secondary_title_element) ) {
					$secondary_title = $secondary_title_element->textContent;
					if( mb_stripos($secondary_title, "list of") !== false ) {
						$error_message = "'List of' article: $final_url_decoded";
						error_log("$error_message\n", 3, $this->log_file);
						
						if( $retries == $max_retries ) {
							break;
						}
						$error_message = "";
						$retries++;
						continue;	
					}
				}	
				
				#Parsing is getting more and more complicated here
				$xpath = new DOMXPath( $dom );
				$title_query = "//div[@id='bodyContent']/*/p/b";
				$paragraphs_query = "$title_query/..";
				
				$title_nodes = $xpath->query("//div[@id='bodyContent']/*/p/b");
				
				if( $title_nodes->length == 0 ) {
					#Look for italicized title
					$title_query = "//div[@id='bodyContent']/*/p/i/b";
					$paragraphs_query = "$title_query/../..";
					$title_nodes = $xpath->query($title_query);
					
					
					if( $title_nodes->length== 0 ) {
						$error_message = "Failed to parse article $final_url_decoded for title";
						error_log("$error_message\n", 3, $this->log_file);
						
						if( $retries == $max_retries ) {
							break;
						}
						$error_message = "";
						$retries++;
						continue;
					}	
				}
				
				
				$title_node = $title_nodes->item(0);
				$title = $title_node->textContent;
				
				$this->answer = $title;
		
				if( preg_match( '/[^\x00-\x7F]/', $title ) > 0 ) {
					$error_message = "UTF-8 character in title: $title for article $final_url_decoded";
					error_log("$error_message\n", 3, $this->log_file);
					
					#UTF-8 characters in the title, we retry
					if( $retries == $max_retries ) {
						break;
					}
					$error_message = "";
					$retries++;
					continue; #Retry with another URL
				}
				
				#Replace the 'dot' character in the title with a special code (@#!) temporarily
				$title_dedotted = str_replace(".", "@#!", $title);
				
				#Get the first paragraph from the node containing the title
				$paragraphs = $xpath->query($paragraphs_query);
				
				if( $paragraphs->length == 0 ) {
					$error_message = "Failed to fetch paragraph containing title $title from article $final_url_decoded";
					error_log("$error_message\n", 3, $this->log_file);
									
					if( $retries == $max_retries ) {
						break;
					}
					$error_message = "";
					$retries ++;
					continue;
				}
				$first_paragraph = $paragraphs->item(0);
				$text = $first_paragraph->textContent;
				
				
			
				#'Dedot' the text - possible UTF-8 problem here (but since we're checking for UTF-8 int the title, should be OK)
				$text_dedotted = str_replace($title, $title_dedotted, $text);
				
				if( !preg_match_all('/[^.]+\./u', $text_dedotted, $matches) ) {
					$error_message = "Failed to break paragraph into sentences: $text for article $final_url_decoded";
					error_log("$error_message\n", 3, $this->log_file);
					
					if( $retries == $max_retries ) {
						break;
					}
					
					$error_message = "";
					$retries++;
					continue;
				}		
				
				$match_index = 0;
				foreach ( $matches[0] as $sentence ) {
					if( mb_strpos( $sentence, $title_dedotted ) !== false ) {
						$statement_dedotted = trim($sentence);
						
						#Redot the statement again
						$statement = str_replace( "@#!", ".", $statement_dedotted);
						
						break;
						
						$match_index++;
					}
				}
				
				if( empty($statement) ) {
					$error_message = "Failed to find titular statement $title in text: $text for article $final_url_decoded";
					#Save it here for debugging in any case
					error_log("$error_message\n", 3, $this->log_file);
							
					if( $retries == $max_retries ) {
						break;
					}
					$error_message = "";
					$retries++;
					continue;
				}
				
				#Check for disambiguation
				if( mb_stripos( $statement, "may refer to") !== false ) {
					$error_message = "Disambiguation page: $final_url_decoded";
					#Save it here for debugging in any case
					error_log("$error_message\n", 3, $this->log_file);
					
					if( $retries == $max_retries ) {
						break;
					}
					
					#Clear the error message
					$error_message = "";
					$retries++;
					continue;
				}
				#Try to match up parantheses - for some reason this only works, if I pass the third dummy parameter
				$left_paran_count = preg_match_all('/\(/u', $statement, $dummy_matches);
				$right_paran_count = preg_match_all('/\)/u', $statement, $dummy_matches);
				
				#If they don't match, try to combine 'sentences' till they do, or we run out of sentences
				if( $left_paran_count != $right_paran_count ) {
					for( $match_index = $match_index + 1; $match_index<count($matches[0]); $match_index++ ) {
						$statement = $statement.$matches[0][$match_index];
						$left_paran_count = preg_match_all('/\(/u', $statement, $dummy_matches);
						$right_paran_count = preg_match_all('/\)/u', $statement, $dummy_matches);
						if( $left_paran_count == $right_paran_count ) {
							break;
						}
					}
				}
				
				if( $left_paran_count != $right_paran_count ) {
					$error_message = "Failed to find titular sentence (paran pair failure): $text for article $final_url_decoded";
					error_log("$error_message\n", 3, $this->log_file);
					
					if( $retries == $max_retries ) {
						break;
					}
					
					$error_message = "";
					$retries++;
					continue;
				}
				
				#The full statement is the question - at the time of writing into room_event we replace the answer with the clue
				$this->question = $statement;
				
				#Do we have a second statement in this page?
				if( $match_index+1 < count($matches[0])) {
					$this->second_statement = $matches[0][$match_index + 1];
				}
				else {
					$second_paragraphs_query = "$paragraphs_query/following-sibling::p";
					$second_paragraphs = $xpath->query($second_paragraphs_query);
					
					if( $second_paragraphs->length > 0 ) {
							$second_paragraph = $second_paragraphs->item(0);
							$second_text = $second_paragraph->textContent;
						
							#'Dedot' the text - possible UTF-8 problem here (but since we're checking for UTF-8 int the title, should be OK)
							$second_text_dedotted = str_replace($title, $title_dedotted, $second_text);
							
							if( preg_match_all('/[^.]+\./u', $second_text_dedotted, $second_matches) ) {
									$second_statement_dedotted = trim($second_matches[0][0]);
									$second_statement = str_replace( "@#!", ".", $second_statement_dedotted);
									$this->second_statement = $second_statement;
							}
					}
				
				}
				$break_out = true;
			}
			
			
			#Error state
			if( !empty($error_message) ) {
				return false;
			}
			else {
				$this->state = self::QUESTION_CLUE;
				$this->clue = preg_replace( '/\w/', self::CLUE_LETTER, $title);
				$this->remaining_clues = self::MAX_CLUES;
				
				return true;
			}
		}
		
		#Get the next clue for this question 
		public function update_clue() {
			#No more clues for this question
			if( $this->remaining_clues == 0 ) {
				return false;
			}
			
			$fillable_max_clues = self::MAX_CLUES;
			#If we have a second statement in this article, and we're at the last clue, we just append it to the question and leave it at that
			if( !empty($this->second_statement) ) {
				if( $this->remaining_clues == 1 ) {
					$this->question = $this->question.$this->second_statement;
					$this->remaining_clues = 0;
					return true;
				}
				else {
					$fillable_max_clues = self::MAX_CLUES - 1;
				}
			}
			
			/*Otherwise, we get clues using this formula
			.. Max visibility = 60%, never more than 50% of the answer string should be visible
			.. Each time we show, 60%/(max_clues) *more* letters
			.. How, we run through the clue string, identifying 'dashed' positions and saving them in an array*/
			$dash_positions = array();
			
			for( $i = 0; $i< strlen($this->clue); $i++ ) {
				if( $this->clue[$i] == self::CLUE_LETTER) {
					$dash_positions[] = array(0, $i);
				}
			}
			$fillable_positions = count($dash_positions);
			
			$to_fill = ceil( (0.6*(float)$fillable_positions)/$fillable_max_clues);
			
			
			#Now reveal to_fill number of those dash positions
			for( $i = 0; $i<$to_fill; $i++ ) {
				$rand_pos = rand(0, count($dash_positions)-1);
				
				#If it's already full, then iterate
				$find_pos = $dash_positions[$rand_pos][1];
				for ( $j = 0 ; $j<count($dash_positions); $j++, $find_pos = ($find_pos+1)%(count($dash_positions)) ) {
					if( !$dash_positions[$find_pos][0] ) {
						break;
					}
				}
				#Now, this one's occupied
				$dash_positions[$find_pos][0] = 1;
				
				#Reveal this position
				$this->clue[$find_pos] = $this->answer[$find_pos];
			}
			
			#Reduce the number of remaining clues
			$this->remaining_clues = $this->remaining_clues - 1;
			return true;
		}
	}
?>