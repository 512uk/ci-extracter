<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

	require_once('Zend/Mail/Storage/Imap.php');

	/**
	 * Extracter CodeIgniter Library
	 *
	 * This library provides functionality that allows you to work with Emails, and specifically, Email Attachments.
	 * Currently supports working with an IMAP server only.
	 *
	 * The abstraction is provided through the use of some Zend libraries.
	 *
	 * @author George Edwards
	 *
	 */
	class Extracter {
		
		/**
		 * Configuration variables and CodeIgniter
		 */
		private $hostname, $username, $password, $ssl_mode;
		protected $ci;

		/**
		 * The Zend mail object
		 */
		protected $mail;

		public function __construct($config = array())
		{
			log_message("debug", "Extracter -> class initialised");

			// Fetch CodeIgniter
			$this->ci =& get_instance();
			$this->ci->load->helper('file');

			// Set our config. $config should hold the information automagically
			// if we have set up a config/extracter.php 
			$this->hostname = $config['hostname'];
			$this->username = $config['username'];
			$this->password = $config['password'];
			$this->ssl_mode = $config['ssl_mode'];

			// Connect to imap
			$this->_imap_connect();
		}

		// ----------------------------------------------------------------------------------------------------------------

		/**
		 * Set the current working folder to a given path, handles a Zend exception by using show_error().
		 *
		 * @param 	mixed 		string folder path, e.g. Foo/Bar, depending on mail server settings
		 * 						or if you feel adventurous, a Zend_Mail_Folder instance. See the Zend API for more information
		 * @return 	void
		 */
		public function set_folder($folder_string = 'INBOX')
		{
			try
			{
				$this->mail->selectFolder($folder_string);
				log_message("debug", "Extracter -> set folder to {$folder_string}");
			}
			catch (Exception $e)
			{
				log_message("error", 'Extracter: Could not set folder on the imap server, maybe it does not exist?');
				return false;
			}
		}

		// ----------------------------------------------------------------------------------------------------------------

		/**
		 * Returns an array of messages and their relevant details/attachments, from the current working folder
		 * The key will be the Zend message number, which can be used to move messages
		 *
		 * @param 	void
		 * @return 	array 		of message details. Just print_r/var_dump the output to see what's extracted
		 */
		public function get_all()
		{
			$messages = array();

			foreach ($this->mail as $mnum => $mail)
			{
				$messages[$mnum] = $this->get_message($mnum);
			}

			return $messages;
		}

		// ----------------------------------------------------------------------------------------------------------------

		/**
		 * Get all message detail from the passed message
		 * Returns a pretty array
		 *
		 * @param 	integer 	the message number to look up
		 * @return 	array 		the message details, see below, it's self explanatory, numb nuts
		 * @throws	nothing 	handles Zend exception, using show_error() instead
		 */
		public function get_message($message_number)
		{
			try
			{
				$message = $this->mail->getMessage($message_number);
			}
			catch (Exception $e)
			{
				log_message("error", "Extracter -> could not find that message on the server");
			}

			// Tidy up the bits of the message that we need, 
			// and return an array of message data
			$message_details = array(
				'number' => $message_number,
				'unique_id' => $this->mail->getUniqueId($message_number),
				'from' => $message->getHeader('from'),
				'to' => $message->getHeader('to'),
				'date' => $message->getHeader('date'),
				'subject' => $message->getHeader('subject'),
				'flags' => $message->getFlags(),
				'attachments' => $this->get_attachments($message), // pass the message object - will access it by reference
				'content' => $message->getPart(1)->getContent(),
				'smtp_history' => 	is_array($message->getHeader('received')) == false 
									? array($message->getHeader('received')) 
									: $message->getHeader('received')
			);

			log_message("debug", "Extracter -> got message details from the server. from: {$message_details['from']}, date: {$message_details['date']}");
			return $message_details;
		}

		// ----------------------------------------------------------------------------------------------------------------

		/**
		 * Zend is a pain in the ass when you want to iterate/move over messages.
		 * My guess is that when you move a message, numbers in the current folder change.
		 * To counter this, use the unique_id of the message, pass it to this and get the number.
		 *
		 * @param 	integer 		the unique_id of the message
		 * @return 	integer 		the number of the message
		 *
		 */
		public function get_number($unique_id)
		{
			try
			{
				log_message("debug", "Extracter -> got message from unique id: {$unique_id}");
				return $this->mail->getNumberByUniqueId($unique_id);
			}
			catch (Exception $e)
			{
				log_message("error", "Extracter -> could not get number from the unique id: {$unique_id}");
				return false;
			}
		}

		// ----------------------------------------------------------------------------------------------------------------

		/**
		 * Move a given message (from it's number) to the given destination
		 *
		 * @param 	integer 		the message number to move. use get_number above if iterating over many messages
		 * @param 	string 			the destination on the mail server e.g. Folder/foo/bar
		 */
		public function move_message($n, $destination)
		{
			try 
			{
				$this->mail->moveMessage($n, $destination);
				log_message("debug", "Extracter -> moved message {$n} to {$destination}");
			}
			catch (Exception $e)
			{
				log_message('error', "Extracter -> could not move message, maybe the message or folder no longer exists");
				return false;
			}
		}

		// ----------------------------------------------------------------------------------------------------------------

		/**
		 * Extract attachments from the current email message
		 *
		 * @param 	object 		Zend_Mail_Message object
		 * @return 	array 		an array of attachments. will be empty if no attachments.
		 */
		public function get_attachments(&$message)
		{
			// Get the total number of parts in this message
			$num_parts = $message->countParts();
			$attachments = array();
			
			// If this message has more than 1 part
			if ($num_parts > 1)
			{
				for($i=1; $i<=$num_parts; $i++)
				{
					// Is this attachment valid? Zend will throw an exception if we try to access the header of a 
					// non-ordinary attachment
					try
					{
						$part = $message->getPart($i);

						$attachments[] = array(
							'name' => (string)$part->getHeader('content-description'),
							'mime_type' => get_mime_by_extension((string)$part->getHeader('content-description')),
							'content' => base64_decode($part->getContent())
						);

						log_message("debug", "Extracter -> got part {$i} successfully");
					}
					catch (Exception $e)
					{
						// Obviously not... Moving on
						log_message("debug", "Extracter -> failed trying to get part {$i} of a mail attachment");
						continue;
					}
				}
			}
			return $attachments;
		}

		// ----------------------------------------------------------------------------------------------------------------

		/**
		 * Little wrapper to CodeIgniter's file helper. Takes some content, as a base64_decoded string, and
		 * write it to the path provided.
		 *
		 * @param 	string 		the path to write to, relative to CodeIgniter's main index.php file, including filename and extension
		 * @param 	string 		the attachment string, e.g. contents of CSV/text file etc.
		 * @return 	boolean 	the result from write_file. true on success.. you know the drill				
		 */
		public function save_attachment($path, $attachment)
		{
			$result = write_file($path, $attachment);
			log_message("debug", "Extracter -> saving an attachment. the write_file result was: {$result}");
			return $result;
		}

		// ----------------------------------------------------------------------------------------------------------------

		/**
		 * Creates a new local instance of Zend_Mail_Storage_Imap, using our configured settings
		 * This is in a private method because one day we could use $this->_pop3_connect(), on instantiation..?
		 *
		 * Handles a Zend exception in the case of failure.
		 *
		 * @param 	void
		 * @return 	void
		 *
		 */
		private function _imap_connect()
		{
			try
			{
				$this->mail = new Zend_Mail_Storage_Imap(array(
					'host' => $this->hostname,
					'user' => $this->username,
					'password' => $this->password,
					'ssl' => $this->ssl_mode
				));

				log_message("debug", "Extracter -> connected to IMAP server {$this->hostname} as {$this->username}");
			}
			catch (Exception $e)
			{
				log_message('error', 'Extracter -> could not connect to mail server: '.$e->getMessage());
				return false;
			}
		}

		// ----------------------------------------------------------------------------------------------------------------

	}