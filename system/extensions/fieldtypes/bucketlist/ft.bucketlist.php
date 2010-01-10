<?php

/**
 * Fieldtype extension enabling integration of Amazon S3 with your ExpressionEngine website.
 *
 * @package   	BucketList
 * @version   	1.1.0b3
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
		'version'			=> '1.1.0b3',
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
	 * Checks that the S3 credentials have been set. Makes not attempt to check their validity.
	 *
	 * @access  private
	 * @return  bool
	 */
	function check_s3_credentials()
	{
		return (isset($this->site_settings['access_key_id'])
			&& $this->site_settings['access_key_id'] !== ''
			&& isset($this->site_settings['secret_access_key'])
			&& $this->site_settings['secret_access_key'] !== '');
	}
	
	
	/**
	 * Checks whether the specified item exists on the S3 server.
	 *
	 * @access	private
	 * @param	string		$item_name		The full item path and name, including the bucket.
	 * @return	bool
	 */
	function item_exists_on_s3($item_name = '')
	{
		// Clearly not, muppet.
		if ( ! $item_name)
		{
			return FALSE;
		}
		
		// Separate out the bucket name.
		if ( ! preg_match('/^([0-9a-z]{1}[0-9a-z\.\_\-]{2,254})\/{1}(.*)$/', $item_name, $matches))
		{
			return FALSE;
		}
		
		// Make the call.
		$s3 = new S3($this->site_settings['access_key_id'], $this->site_settings['secret_access_key'], FALSE);
		return @$s3->getObjectInfo($matches[1], $matches[2], FALSE);
		
	}


	
	
	
	/**
	 * Parses a single 'item' DB record, and returns an array containing the
	 * item information.
	 *
	 * @access	private
	 * @param 	array 		$db_item		The DB record.
	 * @return 	array
	 */
	function parse_item_db_result($db_item = array())
	{
		if ( ! $db_item OR ! is_array($db_item))
		{
			return array();
		}
		
		// Do we have the required information?
		$required_fields 	= array('item_extension', 'item_is_folder', 'item_name', 'item_path', 'item_size');
		$item 				= array();
		$missing_field		= FALSE;
		
		foreach ($required_fields AS $field_id)
		{
			if ( ! array_key_exists($field_id, $db_item))
			{
				$missing_field = TRUE;
				break;
			}
			
			$item[$field_id] = $db_item[$field_id];
		}
		
		if ($missing_field)
		{
			return array();
		}
		else
		{
			// Bit of cleaning up, and we'll be done.
			$item['item_is_folder'] = strtolower($item['item_is_folder']);
			$item['item_size']		= intval($item['item_size']);
			
			return $item;
		}
	}
	
	
	/**
	 * Parses a single Amazon S3 item, representing a single item of content in a bucket.
	 *
	 * @access	private
	 * @param	array		$s3_item	The S3 item to parse.
	 * @return	array
	 */
	function parse_item_s3_result($s3_item = array())
	{
		// Steady butt.
		if ( ! $s3_item OR ! is_array($s3_item))
		{
			return array();
		}
		
		// Do we have the required information?
		$required_fields 	= array('name', 'time', 'size', 'hash');
		$item 				= array();
		$missing_field		= FALSE;
		
		foreach ($required_fields AS $field_id)
		{
			if ( ! array_key_exists($field_id, $s3_item) OR (is_string($s3_item[$field_id]) && $s3_item[$field_id] == ''))
			{
				$missing_field = TRUE;
				break;
			}
		}
		
		if ($missing_field)
		{
			return array();
		}
		
		// Extract the information we require.
		$item = array(
			'item_extension'	=> pathinfo($s3_item['name'], PATHINFO_EXTENSION),
			'item_is_folder'	=> (substr($s3_item['name'], -1) == '/') ? 'y' : 'n',
			'item_name'			=> pathinfo($s3_item['name'], PATHINFO_BASENAME),
			'item_path'			=> $s3_item['name'],
			'item_size'			=> intval($s3_item['size'])
		);
		
		return $item;
	}
	
	
	/**
	 * Parses a single 'bucket' DB record, and returns an array containing the
	 * bucket information.
	 *
	 * @access	private
	 * @param 	array 		$db_bucket		The DB record.
	 * @return 	array
	 */
	function parse_bucket_db_result($db_bucket = array())
	{
		if ( ! $db_bucket OR ! is_array($db_bucket))
		{
			return array();
		}
		
		// Do we have the required information?
		$required_fields 	= array('bucket_id', 'bucket_items_cache_date', 'bucket_name', 'site_id');
		$bucket 			= array();
		$missing_field		= FALSE;
		
		foreach ($required_fields AS $field_id)
		{
			if ( ! array_key_exists($field_id, $db_bucket))
			{
				$missing_field = TRUE;
				break;
			}
			
			$bucket[$field_id] = $db_bucket[$field_id];
		}
		
		return ($missing_field ? array() : $bucket);
	}
	
	
	/**
	 * Retrieves all the buckets from the database.
	 *
	 * @access	private
	 * @return 	array
	 */
	function load_all_buckets_from_db()
	{
		global $DB;
		
		$db_buckets = $DB->query("SELECT
				bucket_id, bucket_items_cache_date, bucket_name, site_id
			FROM exp_bucketlist_buckets
			WHERE site_id = '{$this->site_id}'");
			
		if ($db_buckets->num_rows == 0)
		{
			return array();
		}
		
		// Initialise the return array.
		$buckets = array();
		
		foreach ($db_buckets->result AS $db_bucket)
		{
			if ($bucket = $this->parse_bucket_db_result($db_bucket))
			{
				$buckets[] = $bucket;
			}
		}
		
		return $buckets;
	}
	
	
	/**
	 * Retrieves a bucket from the database, given its name.
	 *
	 * @access	private
	 * @param 	string		$bucket_name		The name of the bucket.
	 * @return 	array
	 */
	function load_bucket_from_db($bucket_name = '')
	{
		global $DB;
		
		if ( ! $bucket_name)
		{
			return array();
		}
		
		$db_bucket = $DB->query("SELECT
				bucket_id, bucket_items_cache_date, bucket_name, site_id
			FROM exp_bucketlist_buckets
			WHERE bucket_name = '" .$DB->escape_str($bucket_name) ."'
			AND site_id = '{$this->site_id}'
			LIMIT 1");
			
		return ($this->parse_bucket_db_result($db_bucket->row));
	}
	
	
	/**
	 * Retrieve a bucket's contents from the database.
	 *
	 * @access	private
	 * @param 	string		$bucket_name		The name of the bucket.
	 * @return 	array
	 */
	function load_bucket_items_from_db($bucket_name = '')
	{
		global $DB;
		
		// NOTE: DOES NOT check whether the items cache has expired.
		
		// Talk sense man.
		if ( ! $bucket_name)
		{
			return array();
		}
		
		// Load the items from the database.
		$db_items = $DB->query("SELECT
				item_id, item_path, item_name, item_size, item_extension, item_is_folder,
				buckets.bucket_id, buckets.bucket_name
			FROM exp_bucketlist_items AS items
			INNER JOIN exp_bucketlist_buckets AS buckets
			ON buckets.bucket_id = items.bucket_id
			WHERE buckets.bucket_name = '" .$DB->escape_str($bucket_name) ."'
			AND items.site_id = '{$this->site_id}'
			ORDER BY item_name ASC");
			
		if ($db_items->num_rows < 1)
		{
			return array();
		}
		
		// Parse the data into arrays ('folders' and 'files').
		$folders = $files = array();
		
		foreach ($db_items->result AS $db_item)
		{
			$item = $this->parse_item_db_result($db_item);
			
			if ($item)
			{
				$item['item_is_folder'] == 'y' ? $folders[] = $item : $files[] = $item;
			}
		}
		
		// Do we have any items?
		$items = array();
		
		if ($folders OR $files)
		{
			$items['folders'] 	= $folders;
			$items['files']		= $files;
		}
		
		// Return the data.
		return $items;
	}
	
	
	/**
	 * Checks whether the stored buckets still exist, and whether any new
	 * buckets have been created in the interim. Does not check the contents.
	 *
	 * @access	private
	 * @return 	bool
	 */
	function update_buckets_from_s3()
	{
		global $DB;
		
		// Retrieve all the buckets from Amazon.
		$s3 = new S3($this->site_settings['access_key_id'], $this->site_settings['secret_access_key'], FALSE);
		
		$s3_buckets = @$s3->listBuckets();
		
		// Do we have any results?
		if ( ! $s3_buckets)
		{
			return FALSE;
		}
		
		/**
		 * Delete any obsolete buckets, and their associated items.
		 */
		
		// @todo: data cleansing. Should never be an issue, but still...
		$valid_bucket_names = "'" .implode("', '", $s3_buckets) ."'";
		
		// Retrieve the obsolete buckets.
		$db_obsolete_buckets = $DB->query("SELECT bucket_id
			FROM exp_bucketlist_buckets
			WHERE bucket_name NOT IN({$valid_bucket_names})");
		
		if ($db_obsolete_buckets->num_rows > 0)
		{
			// Create an array of obsolete bucket IDs.
			$obsolete_bucket_ids = array();
			
			foreach ($db_obsolete_buckets->result AS $db_obb)
			{
				$obsolete_bucket_ids[] = $db_obb['bucket_id'];
			}
			
			$obsolete_bucket_ids = "'" .implode("', '", $obsolete_bucket_ids) ."'";
			
			// Delete the associated items.
			$DB->query("DELETE FROM exp_bucketlist_items
				WHERE bucket_id IN ({$obsolete_bucket_ids})");
			
			// Delete the obsolete buckets.
			$DB->query("DELETE FROM exp_bucketlist_buckets
				WHERE bucket_id IN ({$obsolete_bucket_ids})");
		}
		
		/**
		 * Add any missing buckets to the database.
		 */
		
		// Retrieve all the buckets from the database.
		$existing_buckets = $this->load_all_buckets_from_db();
		
		/**
		 * We've deleted any obsolete buckets, so if the number
		 * of existing buckets equals the number of S3 buckets,
		 * we're golden.
		 */
		
		if (count($existing_buckets) != count($s3_buckets))
		{
			// Create an array of the existing bucket names.
			$existing_bucket_names = array();
			foreach ($existing_buckets AS $eb)
			{
				$existing_bucket_names[] = $eb['bucket_name'];
			}
			
			// Determine the missing items.
			$missing_bucket_names = array_diff($s3_buckets, $existing_bucket_names);
			$missing_buckets = array();
			
			$old_cache = strtotime('19 February 1973');		// Olden times.
			
			foreach ($missing_bucket_names AS $missing)
			{
				$missing_buckets[] = "{$this->site_id}, '{$missing}', {$old_cache}";
			}
			
			// Build the SQL.
			$sql = "INSERT INTO exp_bucketlist_buckets (
					site_id, bucket_name, bucket_items_cache_date
				) VALUES (" .implode('), (', $missing_buckets) .")";
				
			$DB->query($sql);
		}
		
		return TRUE;
	}
	
	
	/**
	 * Attempts to retrieve a bucket's contents from Amazon, and save them
	 * to the database.
	 *
	 * @access	private
	 * @param 	string		$bucket_name		The name of the bucket.
	 * @return 	bool
	 */
	function update_bucket_items_from_s3($bucket_name = '')
	{
		global $DB;
		
		// NOTE: the cache date is not checked. We just assume this call is required.
		
		// Leave it ahhht, you slag.
		if ( ! $bucket_name)
		{
			return FALSE;
		}
		
		// Is this a valid bucket?
		if ( ! $bucket = $this->load_bucket_from_db($bucket_name))
		{
			return FALSE;
		}
		
		// Make the call to Amazon.
		$s3 = new S3($this->site_settings['access_key_id'], $this->site_settings['secret_access_key'], FALSE);
		
		if ( ! $s3_items = @$s3->getBucket($bucket_name))
		{
			return FALSE;
		}
		
		// Parse the data returned from Amazon.
		$new_items = array();
		
		foreach ($s3_items AS $s3_item)
		{
			if ($item = $this->parse_item_s3_result($s3_item))
			{
				/**
				 * Paranoia with the string escaping, but better than the alternative. Field list:
				 * bucket_id, item_extension, item_is_folder, item_name, item_path, item_size, site_id
				 */
				
				$new_items[] = "'" .$DB->escape_str($bucket['bucket_id']) ."'"
					.", '" .$DB->escape_str($item['item_extension']) ."'"
					.", '" .$DB->escape_str($item['item_is_folder']) ."'"
					.", '" .$DB->escape_str($item['item_name']) ."'"
					.", '" .$DB->escape_str($item['item_path']) ."'"
					.", '" .$DB->escape_str($item['item_size']) ."'"
					.", {$this->site_id}";
			}
		}
		
		// Delete any existing bucket items from the database.
		$DB->query("DELETE FROM exp_bucketlist_items
			WHERE bucket_id = {$bucket['bucket_id']}
			AND site_id = {$this->site_id}");
		
		// Add the new items to the database.
		$sql = "INSERT INTO exp_bucketlist_items (
				bucket_id, item_extension, item_is_folder, item_name, item_path, item_size, site_id
			) VALUES (" .implode('), (', $new_items) .")";
			
		$DB->query($sql);
		
		// Update the bucket_items_cache_date column in exp_bucketlist_buckets.
		$DB->query($DB->update_string(
			'exp_bucketlist_buckets',
			array('bucket_items_cache_date' => time()),
			"bucket_id = {$bucket['bucket_id']}"
		));
		
		return TRUE;
		
	}
	
	
	/**
	 * Loads a bucket's contents.
	 *
	 * @access	private
	 * @param 	string		$bucket_name		The name of the bucket.
	 * @return 	array
	 */
	function load_bucket_items($bucket_name = '')
	{
		global $DB, $SESS;
		
		// Be reasonable.
		if ( ! $bucket_name)
		{
			return array();
		}
		
		// Shorthand.
		$session_cache =& $SESS->cache[$this->namespace][$this->lower_class];
		
		$items = array();
		
		// Check the Session cache.
		if (array_key_exists($bucket_name, $session_cache['items']))
		{
			$items = $session_cache['items'][$bucket_name];
		}
		
		// Nothing in the Sessions cache? Check the database.
		if ( ! $items)
		{
			// Is this even a valid bucket?
			if ($bucket = $this->load_bucket_from_db($bucket_name))
			{
				/**
				 * If the bucket_items_cache_date is valid, load the items
				 * from the database.
				 *
				 * Otherwise, update the items from S3, and then load them.
				 */
				
				$cache_expiry_date = $bucket['bucket_items_cache_date'] + intval($this->site_settings['cache_duration']);
				if ($cache_expiry_date < time())
				{
					$this->update_bucket_items_from_s3($bucket_name);
				}
			
				$items = $this->load_bucket_items_from_db($bucket_name);
			}
		}
		
		/**
		 * Even if we don't have any items at this point, there's nothing
		 * else we can do. Just set the Session cache, and move on.
		 */
		
		$session_cache['items'][$bucket_name] = $items;
		
		return $items;
	}
	
	
	
	/**
	 * --------------------------------------------------------------
	 * USER INTERFACE METHODS
	 * --------------------------------------------------------------
	 */

	/**
	 * Builds the 'root' HTML. That is, the buckets.
	 *
	 * @access	private
	 * @return 	string
	 */
	function build_root_ui()
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
		
		/**
		 * Note that we're explicitly loading the buckets from the database.
		 *
		 * This is because the display_field method, called when the field is
		 * first, um, displayed, runs update_buckets_from_s3, to make sure we
		 * have an up-to-date list of buckets.
		 *
		 * REMEMBER:
		 * The cache date is irrelevant as far as buckets are concerned. Whenever
		 * the field is display, we check that our list of buckets is still valid.
		 *
		 * The bucket items are the things that get cached for the period set by
		 * the user.
		 */
		
		if ( ! $buckets = $this->load_all_buckets_from_db())
		{
			$html = '<ul class="bucketlist-tree"><li class="empty">' .$LANG->line('no_buckets') .'</li></ul>';
		}
		else
		{
			$html = '<ul class="bucketlist-tree">';
		
			foreach ($buckets AS $bucket)
			{
				$html .= '<li class="directory bucket collapsed">';
				
				/**
				 * Note the addition of a forward slash after the bucket name.
				 * This saves us a lot of hassle, as it now means that we can
				 * treat paths with and without 'folders' the same way when
				 * they are returned to us.
				 */
				
				$html .= '<a href="#" rel="' .rawurlencode($bucket['bucket_name'] .'/') .'">' .$bucket['bucket_name'] .'</a></li>';
			}
		
			$html .= '</ul>';
		}
		
		return $html;
	}
	
	
	/**
	 * Builds the branch HTML.
	 *
	 * @access	private
	 * @param	string		$tree_path		The path from the root of the tree.
	 * @return	string
	 */
	function build_branch_ui($tree_path = '')
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
		
		
		// Initialise the return HTML.
		$html = '';
		
		
		// Extract the bucket name from the tree path.
		$bucket_name = substr($tree_path, 0, strpos($tree_path, '/'));
		
		
		// Extract the item path (the full tree path, minus the bucket).
		$item_path = substr($tree_path, strlen($bucket_name) + 1);
		
		
		// Retrieve the bucket items.
		if ($items = $this->load_bucket_items($bucket_name))
		{
			// Merge the files and folders, so we can process them in a single loop.
			$files_and_folders = array_merge($items['folders'], $items['files']);
			
			$files_html = $folders_html = '';
			
			// The pattern to find the files and folders under the current item path.
			$pattern = '/^' .preg_quote(stripslashes($item_path), '/') .'([^\/]+)\/?$/';
			
			foreach ($files_and_folders AS $f)
			{
				if (preg_match($pattern, $f['item_path'], $matches))
				{
					/**
					 * We want to return the full tree path: the bucket name, plus
					 * the item path.
					 *
					 * We also URL encode the path, in case it contains quotes and the like.
					 */
					
					$f['item_path'] = rawurlencode($bucket_name .'/' .$f['item_path']);
					
					$item_name = rtrim($matches[1], '/');
					
					// Add items to our folders or files lists.
					if ($f['item_is_folder'] == 'y')
					{
						$folders_html .= "<li class='directory collapsed'>
							<a href='#' rel='{$f['item_path']}'>{$item_name}</a></li>";
					}
					else
					{
						$files_html .= "<li class='file ext_{$f['item_extension']}'>
							<a href='#' rel='{$f['item_path']}'>{$item_name}</a></li>";
					}
				}
			}
			
			$html .= $folders_html .$files_html;
		}
		
		// If we have no items to display, and uploading is not allowed, display an 'empty' message.
		if ( ! $html && $this->site_settings['allow_upload'] != 'y')
		{
			$html .= '<li class="empty">' .$LANG->line('no_items') .'</li></ul>';
		}
		
		// Include upload link?
		if ($this->site_settings['allow_upload'] == 'y')
		{
			$html = '<li class="upload"><a href="#">' .$LANG->line('upload_here') .'</a></li>' .$html;
		}
		
		// Wrap everything in a list.
		$html = '<ul class="bucketlist-tree" style="display : none;">' .$html .'</ul>';
		
		return $html;
		
	}
	
	
	/**
	 * Retrieves the requested branch, and returns it.
	 *
	 * @access	private
	 * @param 	string		$tree_path		The path from the root of the tree.
	 * @return	void
	 */
	function output_branch_ui($tree_path = '')
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
		
		exit($this->build_branch_ui($tree_path));
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
		global $DB, $FNS, $IN, $LANG, $PREFS, $SESS;
		
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
		
		/**
		 * The path has been on a round trip from this class, contained in
		 * a rel attribute, and then an input:hidden form element.
		 *
		 * During this entire glorious journey, the JS has not meddled with
		 * the encoding one iota, just so that we can easily decode it here.
		 */
		
		$full_path 	= rawurldecode($IN->GBL('path', 'POST'));
		$upload_id	= $IN->GBL('upload_id', 'POST');
		
		/**
		 * The $full_path contains the bucket and the folder elements.
		 * We need them separate.
		 * 
		 * The following regular expression also contains a little bit
		 * of validation for the bucket name. It's not 100% strict
		 * though, as there's no way we should ever be passed a non-
		 * existent bucket name, never mind an entirely invalid one.
		 */
		
		if (preg_match('/^([0-9a-z]{1}[0-9a-z\.\_\-]{2,254})\/{1}(.*)$/', $full_path, $matches))
		{
			$bucket	= $matches[1];
			$path 	= $matches[2];
		}
		else
		{
			$bucket = $path = '';
		}
		
		
		if ( ! $bucket OR ! $file)
		{
			$status = 'failure';
			$message = $LANG->line('upload_failure_missing_info');
		}
		else
		{
			// Remove extraneous slashes from the path.
			$path = rtrim($path, '/');
			
			// The destination.
			$uri = ($path == '') ? $file['name'] : $path .'/' .$file['name'];
			
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
				
				//Prepare ourselves for the upcoming database action.
				$now 			= time();
				$item_name 		= $file['name'];
				$item_path 		= $bucket .'/' .$uri;
				$item_size 		= $file['size'];
				$item_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
				
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
					WHERE item_name = '" .$DB->escape_str($item_name) ."'
					AND item_path = '" .$DB->escape_str($item_path) ."'
					AND bucket_name = '" .$DB->escape_str($bucket) ."'");
					
				if ($db_existing_item->num_rows == 1)
				{
					$list_item = '';
				}
				else
				{
					$db_bucket = $DB->query("SELECT bucket_id
						FROM exp_bucketlist_buckets
						WHERE bucket_name = '" .$DB->escape_str($bucket) ."'");

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
						$list_item = '<li class="file ext_' .strtolower($item_extension) .'">
							<a href="#" rel="' .rawurlencode($item_path) .'">' .$item_name .'</a></li>';
						
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
						
						/**
						 * The cache is now out of date, dagnammit.
						 * Clear the Session cache for this bucket, and fob everything off onto load_items.
						 */
						
						$SESS->cache[$this->namespace][$this->lower_class]['items'][$bucket] = array();
						$this->load_items($bucket);
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
	public function sessions_start(&$session)
	{
		global $IN;
		
		// Initialise the cache.
		$session->cache[$this->namespace] = array();
		$session->cache[$this->namespace][$this->lower_class] = array();
		$session->cache[$this->namespace][$this->lower_class]['buckets'] = array();
		$session->cache[$this->namespace][$this->lower_class]['items'] = array();
		$session->cache[$this->namespace][$this->lower_class]['buckets_updated_from_s3'] = FALSE;
		
		if ($IN->GBL('ajax', 'GET') == 'y' && $IN->GBL('addon_id', 'GET') == $this->lower_class)
		{
			// We're either being summoned by the file tree, or the uploader. Which is it?
			$request = $IN->GBL('request', 'GET');
			
			switch ($request)
			{
				case 'tree':
					$this->output_branch_ui(urldecode($IN->GBL('dir', 'GET')));
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
	 * Displays the field.
	 *
	 * @access	public
	 * @param	string		$field_name			The field ID.
	 * @param	string		$field_data			Previously-saved field data.
	 * @param 	array 		$field_settings		The field settings.
	 * @return	string
	 */
	public function display_field($field_name, $field_data, $field_settings)
	{
		global $IN, $LANG, $SESS;
		
		// We have no truck with AJAX requests. They are handled by sessions_start.
		if ($IN->GBL('ajax', 'GET') == 'y')
		{
			return '';
		}
		
		// Retrieve the correct language file.
		$LANG->fetch_language_file($this->lower_class);
		
		/**
		 * Add some JavaScript and CSS to the header.
		 */
		
		$this->include_js('js/cp.js');
		$this->include_js('js/jquery.bucketlist.js');
		$this->include_css('css/cp.css');
		
		// Language strings, for use in the JS.
		$upload_failure = str_replace(array('"', '"'), '', $LANG->line('upload_failure_unknown'));
		$confirm_exit	= addslashes($LANG->line('confirm_exit'));
		
		$js_language = "var languageStrings = {
			uploadFailureGeneric : '{$upload_failure}',
			confirmExit : '{$confirm_exit}'
		};";
		
		$this->insert_js($js_language);
		
		
		/**
		 * Now on with the real work.
		 */
		
		// Open the .eepro-co-uk wrapper element.
		$html = '<div class="eepro-co-uk">';
		
		// Can't do much without the S3 credentials.
		if ( ! $this->check_s3_credentials())
		{
			$html .= '<p class="alert">' .$LANG->line('missing_credentials'). '</p>';
		}
		else
		{
			// If we have a saved field, does it still exist on the server?
			if ( ! $this->item_exists_on_s3($field_data))
			{
				/**
				 * Let's not try to get too clever here. We could try to check
				 * whether the bucket even exists anymore, and refresh all the
				 * bucket items, as things have clearly changed.
				 *
				 * However, that is all handled when the user 'opens' a bucket
				 * anyway, so let's just reset the field data so nothing auto-
				 * displays, and leave it at that.
				 */
				
				$field_data = '';
			}
			
			// Update the buckets cache from S3. Only need to do this once.
			if ( ! $SESS->cache[$this->namespace][$this->lower_class]['buckets_updated_from_s3'])
			{
				$this->update_buckets_from_s3();
				$SESS->cache[$this->namespace][$this->lower_class]['buckets_updated_from_s3'] = TRUE;
			}
			
			// Open the UI wrapper.
			$html .= '<div class="bucketlist-ui">';

			// Retrieve the tree root UI (i.e. the buckets).
			$html .= $this->build_root_ui();
			
			// Close the UI wrapper.
			$html .= '</div>';
			
			// Output a hidden field containing the field's value.
			$html .= '<input class="hidden" id="' .$field_name .'" name="' .$field_name
				.'" type="hidden" value="' .rawurlencode($field_data) .'" />';
		
		}
		
		// Close the .eepro-co-uk wrapper element.
		$html .= '</div>';
		
		return $html;
		
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
			bucket_items_cache_date int(10) unsigned NOT NULL default 0,
			CONSTRAINT pk_buckets PRIMARY KEY(bucket_id),
			CONSTRAINT fk_bucket_site_id FOREIGN KEY(site_id) REFERENCES exp_sites(site_id),
			CONSTRAINT uk_bucket_name UNIQUE (bucket_name))";
		
		$sql[] = "CREATE TABLE IF NOT EXISTS exp_bucketlist_items (
			item_id int(10) unsigned NOT NULL auto_increment,
			site_id int(2) unsigned NOT NULL default 1,
			bucket_id int(10) unsigned NOT NULL,
			item_path varchar(1000) NOT NULL,
			item_name varchar(255) NOT NULL,
			item_is_folder char(1) NOT NULL default 'n',
			item_size int(10) unsigned NOT NULL,
			item_extension varchar(10) NOT NULL,
			CONSTRAINT pk_items PRIMARY KEY(item_id),
			CONSTRAINT fk_item_site_id FOREIGN KEY(site_id) REFERENCES exp_sites(site_id),
			CONSTRAINT fk_item_bucket_id FOREIGN KEY(bucket_id) REFERENCES exp_bucketlist_buckets(bucket_id),
			CONSTRAINT uk_item_path UNIQUE (item_path))";
		
		foreach ($sql AS $query)
		{
			$DB->query($query);
		}
	}
	
	
	/**
	 * Modifies the field's POST data before it's saved to the database.
	 *
	 * @access	public
	 * @param	string		$field_data			The POST data.
	 * @param	array		$field_settings		The field settings.
	 * @param 	mixed		$entry_id			Not used.
	 * @return	string
	 */
	public function save_field($field_data = '', $field_settings = array(), $entry_id = '')
	{
		return rawurldecode($field_data);
	}
	
	
	/**
	 * Modifies the cell's POST data before it's saved to the database.
	 *
	 * @access	public
	 * @param	string		$cell_data			The POST data.
	 * @param	array		$cell_settings		The cell settings.
	 * @param 	mixed		$entry_id			Not used.
	 * @return	string
	 */
	public function save_cell($cell_data = '', $cell_settings = array(), $entry_id = '')
	{
		return $this->save_field($cell_data, $cell_settings, $entry_id);
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