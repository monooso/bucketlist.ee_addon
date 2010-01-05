<?php

/**
 * Fieldtype extension enabling integration of Amazon S3 with your ExpressionEngine website.
 *
 * @package   	BucketList
 * @version   	1.1.0b1
 * @author    	Stephen Lewis <addons@eepro.co.uk>
 * @copyright 	Copyright (c) 2009, Stephen Lewis
 * @link      	http://eepro.co.uk/bucketlist/
 */

require_once 'resources/S3.php';

class Bucketlist extends Fieldframe_Fieldtype {
  
	/**
	 * Basic fieldtype information.
	 *
	 * @access	public
	 * @var 	array
	 */
	public $info = array(
		'name'				=> 'BucketList',
		'version'			=> '1.1.0b1',
		'desc'				=> 'Effortlessly integrate Amazon S3 storage with your ExpressionEngine site.',
		'docs_url'			=> 'http://experienceinternet.co.uk/bucketlist/',
		'versions_xml_url'	=> 'http://experienceinternet.co.uk/addon-versions.xml'
	);

	/**
	 * Fieldtype requirements.
	 *
	 * @access	public
	 * @var 	array
	 */
	public $requirements = array(
		'ff'        => '1.3.4',
		'cp_jquery' => '1.1'
	);
  
	/**
	 * Fieldtype extension hooks.
	 *
	 * @access	public
	 * @var 	array
	 */
	public $hooks = array('sessions_start');
  
	/**
	 * Default site settings.
	 *
	 * @access	public
	 * @var 	array
	 */
	public $default_site_settings = array(
		'access_key_id'		=> '',
		'allow_upload'		=> 'y',
		'secret_access_key'	=> '',
		'cache_duration' 	=> '3600',		// 60 minutes
		'use_ssl' 			=> 'n',
		'custom_url'		=> 'y'
	);
	
	/**
	 * The site ID.
	 *
	 * @access	private
	 * @var 	string
	 */
	private $site_id = '';
	
	/**
	 * The class name.
	 *
	 * @access	private
	 * @var 	string
	 */
	private $class = '';
	
	/**
	 * Lower-class classname.
	 *
	 * @access	private
	 * @var 	string
	 */
	private $lower_class = '';
	
	/**
	 * The Session namespace.
	 *
	 * @access	private
	 * @var 	string
	 */
	private $namespace = '';


	/**
	 * ----------------------------------------------------------------
	 * PRIVATE METHODS
	 * ----------------------------------------------------------------
	 */
  
	/**
	 * Checks that the AWS credentials have been set.
	 *
	 * @access  private
	 * @return  bool
	 */
	private function check_amazon_credentials()
	{
		return (isset($this->site_settings['access_key_id'])
		  && $this->site_settings['access_key_id'] !== ''
		  && isset($this->site_settings['secret_access_key'])
		  && $this->site_settings['secret_access_key'] !== '');
	}


	/**
	 * Checks for the existence of a Session cache.
	 *
	 * @access  private
	 * @return  bool
	 */
	private function session_cache_exists()
	{
		global $SESS;

		return (isset($SESS->cache[$this->namespace])
		  && isset($SESS->cache[$this->namespace][$this->lower_class]));
	}
  
  
	/**
	 * Creates the Session cache, if it doesn't exist.
	 *
	 * @access  private
	 */
	private function create_session_cache()
	{
		global $SESS;

		if ( ! isset($SESS->cache[$this->namespace]))
		{
			$SESS->cache[$this->namespace] = array();
		}

		if ( ! isset($SESS->cache[$this->namespace][$this->lower_class]))
		{
			$SESS->cache[$this->namespace][$this->lower_class] = array();
		}
	}
	
	
	/**
	 * Returns an associative array containing two arrays, one of all
	 * the folders in the specified bucket, the other of all the files.
	 *
	 * The session cache is checked, followed by the database cache,
	 * before Amazon S3 is queried.
	 *
	 * @access	private
	 * @param 	string		$bucket		The parent bucket.
	 * @return 	array
	 */
	private function get_items($bucket_name = '')
	{
		global $DB, $SESS;
		
		$items = array();
		
		// Have we been given a bucket name?
		if ( ! $bucket_name)
		{
			return $items;
		}
		
		// Have we been given a *valid* bucket name?
		$db_bucket = $DB->query("SELECT
				bucket_id
			FROM exp_bucketlist_buckets
			WHERE bucket_name = '{$bucket_name}'
			AND site_id = '{$this->site_id}'");
			
		if ($db_bucket->num_rows !== 1)
		{
			return $items;
		}
		
		// Make a note of the bucket ID.
		$bucket_id = $db_bucket->row['bucket_id'];
		
		// Check the session cache.
		if ($this->session_cache_exists()
			&& is_array($SESS->cache[$this->namespace][$this->lower_class]['items'])
			&& array_key_exists($SESS->cache[$this->namespace][$this->lower_class]['items'][$bucket_name]))
		{
			$items = $SESS->cache[$this->namespace][$this->lower_class]['items'][$bucket_name];
		}
		
		// Check the database cache.
		if ( ! $items)
		{
			$db_folders = $DB->query("SELECT
					items.item_extension,
					items.item_name,
					items.item_path
				FROM exp_bucketlist_items AS items
				WHERE items.site_id = '{$this->site_id}'
				AND items.bucket_id = '{$bucket_id}'
				AND items.item_is_folder = 'y'
				ORDER BY items.item_name ASC");
			
			$db_files = $DB->query("SELECT
					items.item_extension,
					items.item_name,
					items.item_path
				FROM exp_bucketlist_items AS items
				WHERE items.site_id = '{$this->site_id}'
				AND items.bucket_id = '{$bucket_id}'
				AND items.item_is_folder = 'n'
				ORDER BY items.item_name ASC");
				
			if ($db_folders->num_rows > 0 OR $db_files->num_rows > 0)
			{
				$items = array('folders' => array(), 'files' => array());
				
				// Folders.
				foreach ($db_folders->result AS $db_folder)
				{
					$items['folders'][] = array(
						'item_extension' 	=> $db_folder['item_extension'],
						'item_name' 		=> $db_folder['item_name'],
						'item_path' 		=> $db_folder['item_path'],
						'item_is_folder'	=> 'y'
					);
				}
				
				// Files.
				foreach ($db_files->result AS $db_file)
				{
					$items['files'][] = array(
						'item_extension' 	=> $db_file['item_extension'],
						'item_name' 		=> $db_file['item_name'],
						'item_path' 		=> $db_file['item_path'],
						'item_is_folder'	=> 'n'
					);
				}
			}
		}

		// Load the items from S3.
		if ( ! $items)
		{
			// Create the S3 object.
			$s3 = new S3($this->site_settings['access_key_id'],
				$this->site_settings['secret_access_key'],
				FALSE);
				
			// Request the bucket content from Amazon.
			$s3_items = @$s3->getBucket($bucket_name);
			
			if (is_array($s3_items) && count($s3_items) > 0)
			{
				// Write the items to the database.
				$cache_date = time();
				$sql = "INSERT INTO exp_bucketlist_items (
					bucket_id,
					item_is_folder,
					item_last_updated,
					item_name,
					item_path,
					item_size,
					item_extension,
					site_id) VALUES ";
				
				foreach ($s3_items AS $s3_item)
				{
					$item_is_folder 	= (substr($s3_item['name'], -1) == '/') ? 'y' : 'n';
					$item_last_updated 	= $DB->escape_str($s3_item['time']);
					$item_extension		= $DB->escape_str(pathinfo($s3_item['name'], PATHINFO_EXTENSION));
					
					$item_name = $DB->escape_str(pathinfo($s3_item['name'], PATHINFO_BASENAME));
					$item_path = $DB->escape_str($bucket_name .'/' .$s3_item['name']);
					$item_size = $DB->escape_str($s3_item['size']);
					
					$sql .= "('{$bucket_id}',
						'{$item_is_folder}',
						'{$item_last_updated}',
						'{$item_name}',
						'{$item_path}',
						'{$item_size}',
						'{$item_extension}',
						'{$this->site_id}'), ";
				}
				
				$sql = rtrim($sql, ', ');
				$DB->query($sql);
				
				// Call this method again to load the items from the database, in name order.
				return $this->get_items($bucket_name);
			}
		}

		// Cache the results.
		$this->create_session_cache();
		
		if ( ! isset($SESS->cache[$this->namespace][$this->lower_class]['items'])
			OR ! is_array($SESS->cache[$this->namespace][$this->lower_class]['items']))
		{
			$SESS->cache[$this->namespace][$this->lower_class]['items'] = array();
		}
		
		$SESS->cache[$this->namespace][$this->lower_class]['items'][$bucket_name] = $items;
		
		// Return the items.
		return $items;
	}
	
	
	/**
	 * Returns a list of available S3 buckets. The session cache is checked,
	 * followed by the database cache, before Amazon S3 is queried.
	 *
	 * @access	private
	 * @return 	array
	 */
	private function get_buckets()
	{
		global $DB, $SESS;
		
		$buckets = array();
		
		// Does a Session cache exist? If yes, use it.
		if ($this->session_cache_exists() && is_array($SESS->cache[$this->namespace][$this->lower_class]['buckets']))
		{
			$buckets = $SESS->cache[$this->namespace][$this->lower_class]['buckets'];
		}
		
		// If we have no buckets, load them from the database.
		if ( ! $buckets)
		{
			$min_cache_date = time() - intval($this->site_settings['cache_duration']);
			
			$db_buckets = $DB->query("SELECT bucket_id, bucket_name, bucket_cache_date
				FROM exp_bucketlist_buckets
				WHERE site_id = '{$this->site_id}'
				AND bucket_cache_date > {$min_cache_date}
				ORDER BY bucket_name ASC");
			
			if ($db_buckets->num_rows > 0)
			{
				foreach ($db_buckets->result AS $db_bucket)
				{
					$buckets[$db_bucket['bucket_name']] = array(
						'bucket_id'			=> $db_bucket['bucket_id'],
						'bucket_name'		=> $db_bucket['bucket_name'],
						'bucket_cache_date'	=> $db_bucket['bucket_cache_date']
					);
				}
			}
		}
		
		// If we still have no buckets, it's time to place a call to Amazon.
		if ( ! $buckets)
		{
			// Create the S3 object.
			$s3 = new S3($this->site_settings['access_key_id'],
				$this->site_settings['secret_access_key'],
				FALSE);
			
			// Make the call.
			$s3_buckets = @$s3->listBuckets();
			
			// Do we have some results?
			if (is_array($s3_buckets) && count($s3_buckets) > 0)
			{
				// Delete the buckets from the database.
				$DB->query("DELETE FROM exp_bucketlist_buckets");
				
				// Delete the items from the database.
				$DB->query("DELETE FROM exp_bucketlist_items");
				
				// Write the new buckets to the database.
				$cache_date = time();
				$sql 		= 'INSERT INTO exp_bucketlist_buckets (site_id, bucket_name, bucket_cache_date) VALUES';
				
				foreach ($s3_buckets AS $s3_bucket)
				{
					$sql .= "('{$this->site_id}', '" .$DB->escape_str($s3_bucket) ."', '{$cache_date}'), ";
				}
				
				$sql = rtrim($sql, ', ');
				$DB->query($sql);
				
				// Call this method again to load the buckets from the database, in name order.
				return $this->get_buckets();
			}
		}
		
		// Cache the results.
		$this->create_session_cache();
		$SESS->cache[$this->namespace][$this->lower_class]['buckets'] = $buckets;
		
		// Return the buckets.
		return $buckets;
	}
	
	
	/**
	 * Returns an array of the available buckets. That is, the buckets that have
	 * been selected as being available for the current field.
	 *
	 * @access	private
	 * @return	array
	 */
	private function get_available_buckets()
	{
		/**
		 * STUB METHOD.
		 * This functionality has been removed, whilst I figure out how best to
		 * implement it.
		 *
		 * It turns out that retrieving field and cell settings
		 * from within an AJAX call isn't particularly straightforward, and I
		 * didn't want this to hold everything up.
		 *
		 * So, for now there's no option to specify which buckets are available
		 * for each field.
		 */
		
		return $this->get_buckets();
	}
	
	
	/**
	 * Builds the UL used to display the top-level buckets list.
	 *
	 * @access	private
	 * @return 	string
	 */
	private function build_buckets_ui()
	{	
		global $LANG;
		
		/**
		 * This method is called from sessions_start, which runs before
		 * the global $LANG variable is set.
		 *
		 * Just in case we ever need to run it at another point, we check
		 * if the Language class exists, before manually instantiating it.
		 */
		
		if ( ! isset($LANG))
		{
			require PATH_CORE .'core.language' .EXT;
			$LANG = new Language();
		}
		
		$LANG->fetch_language_file($this->lower_class);
		
		$buckets = $this->get_available_buckets();
		
		if ( ! $buckets)
		{
			$ret = '<ul class="bucketlist-tree" style="display : none;"><li class="empty">' .$LANG->line('no_buckets') .'</li></ul>';
		}
		else
		{
			$ret = "<ul class='bucketlist-tree' style='display : none;'>";
		
			foreach ($buckets AS $bucket)
			{
				$ret .= '<li class="directory bucket collapsed">';
				$ret .= "<a href='#' rel='{$bucket['bucket_name']}/'>{$bucket['bucket_name']}</a></li>";
			}
		
			$ret .= '</ul>';
		}
		
		return $ret;
	}
	

	/**
	 * Cleans a string for use within a regular expression.
	 *
	 * @access	private
	 * @param	string	$subject	The string to clean.
	 * @return	string
	 */
	private function clean_string_for_regexp($subject = '')
	{
		$find = array(
			'/\//', '/\^/', '/\./', '/\$/', '/\|/',
 			'/\(/', '/\)/', '/\[/', '/\]/', '/\*/',
			'/\+/', '/\?/', '/\{/', '/\}/', '/\,/');
			
		$replace = array(
			'\/', '\^', '\.', '\$', '\|',
			'\(', '\)', '\[', '\]', '\*',
			'\+', '\?', '\{', '\}', '\,');
		
		return preg_replace($find, $replace, $subject);
	}
	
	
	/**
	 * Builds the UL used to display the items within a bucket or directory.
	 *
	 * @access		private
	 * @param 		string		$file_path		The file path.
	 * @return 		string
	 */
	private function build_items_ui($file_path = '')
	{
		global $LANG;
		
		if ( ! $file_path)
		{
			return $this->build_buckets_ui();
		}
		
		/**
		 * This method is called from sessions_start, which runs before
		 * the global $LANG variable is set.
		 *
		 * Just in case we ever need to run it at another point, we check
		 * if the Language class exists, before manually instantiating it.
		 */
		
		if ( ! isset($LANG))
		{
			require PATH_CORE .'core.language' .EXT;
			$LANG = new Language();
		}
		
		$LANG->fetch_language_file($this->lower_class);
		
		// Extract the bucket name.
		$bucket_name = substr($file_path, 0, strpos($file_path, '/'));
		
		// Retrieve the items residing in the bucket.
		$items = $this->get_items($bucket_name);
		
		// Open the list of files and folders.
		$ret = "<ul class='bucketlist-tree' style='display : none;'>";
		
		if ($this->site_settings['allow_upload'] == 'y')
		{
			$ret .= '<li class="upload"><a href="#">' .$LANG->line('upload_here') .'</a></li>';
		}
		
		if (array_key_exists('folders', $items)
			&& array_key_exists('files', $items)
			&& (count($items['folders']) > 0 OR count($items['files']) > 0))
		{
			$files_and_folders = array_merge($items['folders'], $items['files']);
			$ret_files = $ret_folders = '';
			
			/**
			 * Filter the array of folders and files to match only those under
			 * the current file path.
			 */
			
			$file_path_pattern = '/^' .preg_quote($file_path, '/') .'$/';
			
			// Add the folders and files to the list.
			foreach ($files_and_folders AS $f)
			{
				/**
				 * The item path contains the item name. We need to
				 * remove that.
				 */
				
				$f_path = substr($f['item_path'], 0, (strlen($f['item_path']) - strlen($f['item_name']) - 1));
				
				if (preg_match($file_path_pattern, $f_path))
				{
					// URL encode the path, in case it contains quotes and the like.
					$f['item_path'] = urlencode($f['item_path']);
					
					// Add items to our folders or files lists.
					if ($f['item_is_folder'] == 'y')
					{
						$ret_folders .= "<li class='directory collapsed'>
							<a href='#' rel='{$f['item_path']}'>{$f['item_name']}</a></li>";
					}
					else
					{
						$ret_files .= "<li class='file ext_{$f['item_extension']}'>
							<a href='#' rel='{$f['item_path']}'>{$f['item_name']}</a></li>";
					}
				}
			}
			
			$ret .= $ret_folders .$ret_files;
		}
		
		// Close the list of files and folders.
		$ret .= '</ul>';
		
		return $ret;
	}
	
	
	/**
	 * Retrieves the requested file tree fragment, and returns it.
	 *
	 * @access	private
	 * @param 	string		$parent_directory		The root of this file tree branch.
	 * @return	void
	 */
	private function output_branch($parent_directory = '')
	{
		global $PREFS;
		
		if ($_SERVER['SERVER_PROTOCOL'] == 'HTTP/1.1' OR $_SERVER['SERVER_PROTOCOL'] == 'HTTP/1.0')
		{
			header($_SERVER['SERVER_PROTOCOL'] .' 200 OK', TRUE, 200);
		}
		else
		{
			header('HTTP/1.1 200 OK', TRUE, 200);
		}
		
		header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
		header('Last-Modified: ' .gmdate('D, d M Y H:i:s') .' GMT');
		header('Pragma: no-cache');
		header('Cache-Control: no-cache, must-revalidate');
		header('Content-Type: text/html; charset=' .$PREFS->ini('charset'));
		
		exit($this->build_items_ui($parent_directory));
	}
	
	
	/**
	 * Forwards the just-uploaded file to Amazon S3, and writes out a
	 * response document based on the success or failure of the operation.
	 *
	 * @access	private
	 * @return	void
	 */
	private function upload_file()
	{
		global $DB, $FNS, $IN, $LANG, $PREFS;
		
		// Paranoia.
		if ($this->site_settings['allow_upload'] != 'y')
		{
			return false;
		}
		
		/**
		 * This is being called from the sessions_start method, so the
		 * $LANG global variable probably hasn't been instantiated yet.
		 */
		
		if ( ! isset($LANG))
		{
			require PATH_CORE .'core.language' .EXT;
			$LANG = new Language();
		}
		
		$LANG->fetch_language_file($this->lower_class);

		/**
		 * Retrieve the submitted file from the field entitled "file".
		 */

		$file = isset($_FILES['file']) ? $_FILES['file'] : array();

		// Retrieve the bucket, file path, and upload ID.
		$bucket 	= $IN->GBL('bucket', 'POST');
		$path 		= $IN->GBL('path', 'POST');
		$upload_id	= $IN->GBL('upload_id', 'POST');

		if ( ! $file OR ! $bucket OR ! $upload_id OR $path === FALSE)
		{
			$status = 'failure';
			$message = $LANG->line('upload_failure_missing_info');
		}
		else
		{
			// Tidy up the path and filename.
			$path = urldecode($path);
			$file['name'] = trim($file['name'], '/');
			
			// Determine the full filename, with path.
			$uri = ($path == '') ? $file['name'] : $FNS->remove_double_slashes($path .'/' .$file['name']);
			
			// Retrieve the Amazon account credentials.
			$access_key = $this->site_settings['access_key_id'];
			$secret_key = $this->site_settings['secret_access_key'];

			// Create the S3 instance.
			$s3 = new S3($access_key, $secret_key, FALSE);

			// Generate the input array for our file.
			$input = $s3->inputFile($file['tmp_name']);
			
			// Upload the file.
			if ($s3->putObject($input, $bucket, $uri, S3::ACL_PUBLIC_READ))
			{
				$status = 'success';
				$message = $LANG->line('upload_success') .$file['name'];
				
				// Prepare ourselves for the upcoming database action.
				$now = time();
				$item_name = $file['name'];
				$item_path = $FNS->remove_double_slashes($bucket .$path .$item_name);
				$item_size = $file['size'];
				$item_extension = pathinfo($item_name, PATHINFO_EXTENSION);
				
				/**
				 * Does this item already exist in the database? If so, we're
				 * overwriting it on the S3 server, so we just need to update
				 * the item_last_updated time in the database.
				 *
				 * If it's a brand-spanking new item, add it to the DB, and
				 * return a new list item for the file tree.
				 *
				 * @todo Return information about an overwritten file, so
				 * the user can at least get some visual feedback.
				 */
				
				$db_existing_item = $DB->query("SELECT item_id
					FROM exp_bucketlist_items
					INNER JOIN exp_bucketlist_buckets
					USING (bucket_id)
					WHERE item_name = '{$item_name}'
					AND item_path = '{$item_path}'
					AND bucket_name = '{$bucket}'");
					
				if ($db_existing_item->num_rows == 1)
				{
					$list_item = '';
				}
				else
				{
					$db_bucket = $DB->query("SELECT bucket_id
						FROM exp_bucketlist_buckets
						WHERE bucket_name = '{$bucket}'");

					/**
					 * Quite how this could ever not be 1 is unclear,
					 * but it doesn't hurt to check.
					 */

					if ($db_bucket->num_rows != 1)
					{
						$list_item = '';
					}
					else
					{
						// Create the HTML for the new list item.
						$list_item = '<li class="file ext_' .$item_extension .'">
							<a href="#" rel="' .$item_path .'">' .$item_name .'</a></li>';

						$DB->query($DB->insert_string(
							'exp_bucketlist_items',
							array(
								'bucket_id'				=> $db_bucket->row['bucket_id'],
								'item_is_folder'		=> 'n',
								'item_last_updated'		=> $now,
								'item_name'				=> $item_name,
								'item_path'				=> $item_path,
								'item_size'				=> $item_size,
								'item_extension'		=> $item_extension,
								'site_id'				=> $this->site_id
							)
						));
					}
				}
			}
			else
			{
				$status = 'failure';
				$message = $LANG->line('upload_failure_generic') .$file['name'];
			}
		}

		// Ensure the message is SFW.
		$message = htmlspecialchars($message, ENT_COMPAT, 'UTF-8');

		/**
		 * Create the HTML document. Why, you may ask, do we not
		 * respond with XML, or perhaps even JSON?
		 *
		 * Simple, Internet Explorer and can't handle XML. JSON
		 * is even more problematic.
		 */

		$return = <<<_HTML_
<html>
<head>
	<title>Amazon S3 Response</title>
</head>
<body>
<p id="status">{$status}</p>
<p id="message">{$message}</p>
<p id="uploadId">{$upload_id}</p>
<ul id="listItem">{$list_item}</ul>
</body>
</html>
_HTML_;

		// Output the return document.
		header('Content-type: text/html; charset=' .$PREFS->ini('charset'));
		exit($return);
	}
	
	
	/**
	 * Temporarily retired method to display the fieldtype field settings form.
	 *
	 * @see		get_available_buckets
	 * @access	private
	 * @param	array		$field_settings		Any previously saved field settings.
	 * @return	array
	 */
	private function _display_field_settings($field_settings)
	{
		global $LANG;
		
		// Initialise a new instance of the SettingsDisplay class.
		$sd = new Fieldframe_SettingsDisplay();
		
		// Open the settings block.
		$ret = $sd->block('field_settings_heading');
		
		// Attempt to retrieve a list of the available buckets.
		if ( ! $this->check_amazon_credentials())
		{
			// Credentials not saved. Display an error message.
			$ret .= $sd->info_row('missing_credentials');
		}
		else
		{
			// Load the available buckets.
			if ( ! $buckets = $this->get_buckets())
			{
				$ret .= $sd->info_row('no_buckets');
			}
			else
			{
				$ret 		.= $sd->info_row('buckets_info');
				$options 	= array();
				$bucket_ids = array();
				$attributes = array('size' => 8, 'width' => '100%');
				
				foreach ($buckets AS $bucket)
				{
					$bucket_ids[] = $bucket['bucket_id'];
					$options[$bucket['bucket_id']] = $bucket['bucket_name'];
				}
				
				$selected = isset($field_settings['field_buckets']) ? $field_settings['field_buckets'] : $bucket_ids;
				
				$ret .= $sd->row(array(
					$sd->label('buckets_label'),
					$sd->multiselect('field_buckets[]', $selected, $options, $attributes)
				));
			}
		}
		
		// Close the settings block.
		$ret .= $sd->block_c();
		
		return array('cell2' => $ret);
	}
	
	
	
	/**
	 * ----------------------------------------------------------------
	 * PUBLIC METHODS
	 * ----------------------------------------------------------------
	 */
	
	/**
	 * Constructor function.
	 *
	 * @access	public
	 */
	public function __construct()
	{
		global $DB, $PREFS;
		
		$this->site_id 		= $DB->escape_str($PREFS->ini('site_id'));
		$this->class 		= get_class($this);
		$this->lower_class 	= strtolower($this->class);
		$this->namespace	= 'sl';
	}
	
	
	/**
	 * Handles AJAX requests.
	 *
	 * @access		public
	 * @param 		object		$session	The current Session class.
	 * @return		void
	 */
	public function sessions_start($session)
	{
		global $IN;
		
		if ($IN->GBL('ajax', 'GET') == 'y' && $IN->GBL('addon_id', 'GET') == $this->lower_class)
		{
			// We're either being summoned by the file tree, or the uploader. Which is it?
			$request = $IN->GBL('request', 'GET');
			
			switch ($request)
			{
				case 'tree':
					$this->output_branch(urldecode($IN->GBL('dir', 'GET')));
					break;
					
				case 'upload':
					$this->upload_file();
					break;
					
				default:
					// No idea.
					break;
			}
		}
	}
  
  
	/**
	 * Displays the standard fieldtype.
	 *
	 * @access		public
	 * @param		string		$field_name			The field ID.
	 * @param		string		$field_data			Previously saved field data.
	 * @param		array		$field_settings		The field settings.
	 * @return		string
	 */
	public function display_field($field_name, $field_data, $field_settings)
	{
		global $FNS, $IN, $LANG, $PREFS, $REGX;
		
		// Check that this isn't an AJAX request.
		if ($IN->GBL('ajax', 'GET') == 'y')
		{
			return FALSE;
		}
		
		// Retrieve the correct language file.
		$LANG->fetch_language_file($this->lower_class);
		
		// Initialise the return string.
		$ret = '';
		
		// Include external JS and CSS.
		$this->include_js('js/cp.js');
		$this->include_js('js/jquery.bucketlist.js');
		$this->include_css('css/cp.css');
		
		// Language strings, for use in the JS.
		$upload_failure = str_replace(array('"', '"'), '', $LANG->line('upload_failure_unknown'));
		$js_language = "var languageStrings = {uploadFailureGeneric : '{$upload_failure}'};";
		
		$this->insert_js($js_language);
		
		// Check the AWS credentials.
		if ( ! $this->check_amazon_credentials())
		{
			$ret .= '<div class="eepro-co-uk">';
			$ret .= '<p class="alert">' .$LANG->line('missing_credentials'). '</p>';
			$ret .= '</div>';
			return $ret;
		}
		
		// Retrieve the buckets that are available for this field.
		$buckets = $this->get_available_buckets();
		
		if ( ! $buckets)
		{
			$ret .= '<div class="eepro-co-uk">';
			$ret .= '<p class="error">' .$LANG->line('no_available_buckets'). '</p>';
			$ret .= '</div>';
			return $ret;
		}
		
		/**
		 * If we have previously-saved field data, things get quite tricky.
		 * First up, we need to check that the saved bucket still exists.
		 * Then, we need automatically 'open' the bucket / folders / sub-folders / etc
		 * within which the saved file is contained.
		 */
		
		$saved_bucket = $field_data ? substr($field_data, 0, strpos($field_data, '/')) : '';
		
		// Check whether the saved bucket is still valid.
		if ( ! in_array($saved_bucket, array_keys($buckets)))
		{
			$field_data = $saved_bucket = '';
		}
		
		// Build the UI.
		$ret .= '<div class="eepro-co-uk">';
		$ret .= '<div class="bucketlist-ui">';
		$ret .= '<p class="initial-load">' .$LANG->line('loading') .'</p>';
		$ret .= '</div>';
		$ret .= '<input class="hidden" id="' .$field_name .'" name="' .$field_name .'" type="hidden" value="' .$field_data .'" />';
		$ret .= '</div>';

		return $ret;
	}
	
	
	/**
	 * Displays the fieldtype in an FF Matrix.
	 *
	 * @access	public
	 * @param	string		$cell_name			The cell ID.
	 * @param	string		$cell_data			Previously saved cell data.
	 * @param	array		$cell_settings		The cell settings.
	 * @return	string
	 */
	public function display_cell($cell_name, $cell_data, $cell_settings)
	{
		return $this->display_field($cell_name, $cell_data, $cell_settings);
	}
  
  
	/**
	 * Displays the site-wide fieldtype settings form.
	 *
	 * @access	public
	 * @return	string
	 */
	public function display_site_settings()
	{
		// Initialise a new instance of the SettingsDisplay class.
		$sd = new Fieldframe_SettingsDisplay();
		
		// Open the settings block.
		$ret = $sd->block('site_settings_heading');
		
		// Retrieve the settings.
		$settings = array_merge($this->default_site_settings, $this->site_settings);
			
		// Create the settings fields.
		$ret .= $sd->row(array(
			$sd->label('access_key_id'),
			$sd->text('access_key_id', $settings['access_key_id'])
			));
			
		$ret .= $sd->row(array(
			$sd->label('secret_access_key'),
			$sd->text('secret_access_key', $settings['secret_access_key'])
			));
		
		/*	
		$options = array(
			'y' => 'yes',
			'n' => 'no'
			);
			
		$ret .= $sd->row(array(
			$sd->label('use_ssl'),
			$sd->select('use_ssl', $use_ssl, $options)
			));
		*/
			
		$options = array(
			'300'	=> '5_min',
			'600'	=> '10_min',
			'900'	=> '15_min',
			'1800'	=> '30_min',
			'2700'	=> '45_min',
			'3600'	=> '60_min',
			'5400'	=> '90_min',
			'7200' 	=> '120_min',
			'14400' => '240_min',
			'21600' => '360_min',
			'28800' => '480_min'
			);
			
		$ret .= $sd->row(array(
			$sd->label('cache_duration', 'cache_duration_hint'),
			$sd->select('cache_duration', $settings['cache_duration'], $options)
			));
		
		$options = array(
			'y' => 'yes',
			'n' => 'no'
			);
			
		$ret .= $sd->row(array(
			$sd->label('allow_upload', 'allow_upload_hint'),
			$sd->select('allow_upload', $settings['allow_upload'], $options)
			));
			
		$ret .= $sd->row(array(
			$sd->label('custom_url', 'custom_url_hint'),
			$sd->select('custom_url', $settings['custom_url'], $options)
			));
			
		// Close the settings block.
		$ret .= $sd->block_c();
		
		// Return the settings block.
		return $ret;
	}
	
	
	/**
	 * Custom cell settings for FF Matrix.
	 *
	 * @access	public
	 * @param	array	$cell_settings	Previously-saved cell settings.
	 * @return	string
	 */
	public function display_cell_settings($cell_settings = array())
	{
		return '';		// Don't display anything.
	}
	
	
	/**
	 * Performs house-keeping when upgrading from an earlier version.
	 *
	 * @access	public
	 * @param	string|bool		$from		The previous version, or FALSE, if this is the initial installation.
	 */
	public function update($from = FALSE)
	{
		global $DB;
		
		/**
		 * No messing about. Just blat the lot, and start again with
		 * a clean database cache.
		 */
		
		$sql[] = 'DROP TABLE IF EXISTS exp_bucketlist_buckets';
		$sql[] = 'DROP TABLE IF EXISTS exp_bucketlist_files';		// Pre-0.8.0 hangover.
		$sql[] = 'DROP TABLE IF EXISTS exp_bucketlist_items';
			
		$sql[] = "CREATE TABLE IF NOT EXISTS exp_bucketlist_buckets (
			bucket_id int(10) unsigned NOT NULL auto_increment,
			site_id int(2) unsigned NOT NULL default 1,
			bucket_name varchar(255) NOT NULL,
			bucket_cache_date int(10) unsigned NOT NULL default 0,
			CONSTRAINT pk_buckets PRIMARY KEY(bucket_id),
			CONSTRAINT fk_bucket_site_id FOREIGN KEY(site_id) REFERENCES exp_site(site_id))";
		
		$sql[] = "CREATE TABLE IF NOT EXISTS exp_bucketlist_items (
			item_id int(10) unsigned NOT NULL auto_increment,
			site_id int(2) unsigned NOT NULL default 1,
			bucket_id int(10) unsigned NOT NULL,
			item_path varchar(255) NOT NULL,
			item_name varchar(255) NOT NULL,
			item_size int(10) unsigned NOT NULL,
			item_extension varchar(10) NOT NULL,
			item_is_folder char(1) NOT NULL default 'n',
			item_last_updated int(10) unsigned NOT NULL,
			CONSTRAINT pk_items PRIMARY KEY(item_id),
			CONSTRAINT fk_item_site_id FOREIGN KEY(site_id) REFERENCES exp_site(site_id),
			CONSTRAINT fk_item_bucket_id FOREIGN KEY(bucket_id) REFERENCES exp_bucketlist_buckets(bucket_id))";
		
		foreach ($sql AS $query)
		{
			$DB->query($query);
		}
	}
	
	
	/**
	 * Outputs the basic file information (the URL to the file).
	 *
	 * @access	public
	 * @param	array		$params				Array of key / value pairs of the tag parameters.
	 * @param	string		$tagdata			Content between the opening and closing tags, if it's a tag pair.
	 * @param	string		$field_data			The field data.
	 * @param	array		$field_settings		The field settings.
	 * @return	string
	 */
	public function display_tag($params, $tagdata, $field_data, $field_settings)
	{
		$out = '';
		
		if ($field_data)
		{
			$bucket	= substr($field_data, 0, strpos($field_data, '/'));
			$file	= substr($field_data, strlen($bucket) + 1);
			
			$out .= $this->site_settings['use_ssl'] == 'y' ? 'https://' : 'http://';
			$out .= urlencode($bucket) .($this->site_settings['custom_url'] == 'y' ? '/' : '.s3.amazonaws.com/') .urlencode($file);
		}
		
		return $out;
	}
	
	
	/**
	 * Outputs the file name.
	 *
	 * @access	public
	 * @param	array		$params				Array of key / value pairs of the tag parameters.
	 * @param	string		$tagdata			Content between the opening and closing tags, if it's a tag pair.
	 * @param	string		$field_data			The field data.
	 * @param	array		$field_settings		The field settings.
	 * @return	string
	 */
	public function file_name($params, $tagdata, $field_data, $field_settings)
	{
		$out = '';
		
		if ($field_data)
		{
			$out = pathinfo($field_data, PATHINFO_BASENAME);
		}
		
		return $out;
	}
	
	
	/**
	 * Outputs the file size.
	 *
	 * @access	public
	 * @param	array		$params				Array of key / value pairs of the tag parameters.
	 * @param	string		$tagdata			Content between the opening and closing tags, if it's a tag pair.
	 * @param	string		$field_data			The field data.
	 * @param	array		$field_settings		The field settings.
	 * @return	string
	 */
	public function file_size($params, $tagdata, $field_data, $field_settings)
	{
		global $DB;
		
		// Default parameters.
		$params = array_merge(array('format' => 'auto'), $params);
		
		$out = '';
		
		if ($field_data)
		{
			$db_item = $DB->query("SELECT item_size FROM exp_bucketlist_items
				WHERE item_path = '{$field_data}'");
				
			if ($db_item->num_rows == 1)
			{
				$file_size = intval($db_item->row['item_size']);
				
				switch ($params['format'])
				{
					case 'bytes':
						$out = $file_size;
						break;
						
					case 'kilobytes':
						$out = round($file_size / 1024, 2);
						break;
						
					case 'megabytes':
						$out = round($file_size / 1048576, 2);		// 1024 * 1024
						break;
						
					case 'auto':
					default:
						if ($file_size < 1048567)
						{
							$out = round($file_size / 1024, 2) .'<abbr title="Kilobytes">KB</abbr>';
						}
						else
						{
							$out = round($file_size / 1048567, 2) .'<abbr title="Megabytes">MB</abbr>';
						}
						break;
				}
			}
		}
		
		return $out;
	}
	
}

/* End of file	: ft.bucketlist.php */
/* Location		: /system/extensions/fieldtypes/bucketlist/ft.bucketlist.php */