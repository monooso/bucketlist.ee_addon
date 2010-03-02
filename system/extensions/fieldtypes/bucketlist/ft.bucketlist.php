<?php

if ( ! defined('EXT'))
{
	exit('Invalid file request');
}

/**
 * Seamlessly integrate Amazon S3 with your ExpressionEngine website.
 *
 * @package   	BucketList
 * @version   	1.1.1
 * @author    	Stephen Lewis <addons@experienceinternet.co.uk>
 * @copyright 	Copyright (c) 2009, Stephen Lewis
 * @link      	http://experienceinternet.co.uk/bucketlist/
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
		'version'			=> '1.1.1',
		'desc'				=> 'Seamlessly integrate Amazon S3 with your ExpressionEngine site.',
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
		'custom_url'		=> 'n'
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
	 * Demo mode doesn't actually upload the files to Amazon S3.
	 *
	 * @access  private
	 * @var   	bool
	 */
	private $demo = FALSE;


	/**
	 * ----------------------------------------------------------------
	 * PRIVATE METHODS
	 * ----------------------------------------------------------------
	 */
	
	/**
	 * Duplicate of Fieldframe_Main->_array_ascii_to_entities. Reproduced here, as the
	 * method is technically private.
	 *
	 * @see		http://pixelandtonic.com/fieldframe/
	 * @access	private
	 * @param 	mixed 		$vals		The value to convert.
	 * @return 	string
	 */
	private function _array_ascii_to_entities($vals)
	{
		if (is_array($vals))
		{
			foreach ($vals as &$val)
			{
				$val = $this->_array_ascii_to_entities($val);
			}
		}
		else
		{
			global $REGX;
			$vals = $REGX->ascii_to_entities($vals);
		}

		return $vals;
	}
	
	
	/**
	 * Duplicate of Fieldframe_Main->_array_entities_to_ascii. Reproduced here, as the
	 * method is technically private.
	 *
	 * @see		http://pixelandtonic.com/fieldframe/
	 * @access	private
	 * @param 	mixed 		$vals		The value to convert.
	 * @return 	string
	 */
	private function _array_entities_to_ascii($vals)
	{
		if (is_array($vals))
		{
			foreach ($vals as &$val)
			{
				$val = $this->_array_entities_to_ascii($val);
			}
		}
		else
		{
			global $REGX;
			$vals = $REGX->entities_to_ascii($vals);
		}
		
		return $vals;
	}
	
	
	/**
	 * Duplicate of the Fieldframe_Main->_serialize(). Reproduced here, as the method
	 * is technically private.
	 *
	 * @see 	http://pixelandtonic.com/fieldframe/
	 * @access	private
	 * @param 	array 		$vals		The array to serialise.
	 * @return 	string
	 */
	private function _serialize($vals = array())
	{
		global $PREFS;

		if ($PREFS->ini('auto_convert_high_ascii') == 'y')
		{
			$vals = $this->_array_ascii_to_entities($vals);
		}

     	return addslashes(serialize($vals));
	}
	
	
	/**
	 * Duplicate of Fieldframe_Main->_unserialize(). Reproduced here, as the method
	 * is technically private.
	 *
	 * @see		http://pixelandtonic.com/fieldframe/
	 * @access	private
	 * @param 	string 		$vals		The string to unserialise.
	 * @param 	bool		$convert	Convert high ASCII values, if the PREF is set to 'y'?
	 * @return 	array
	 */
	private function _unserialize($vals, $convert = TRUE)
	{
		global $PREFS, $REGX;
		
		if (($tmp_vals = @unserialize($vals)) !== FALSE)
		{
			$vals = $REGX->array_stripslashes($tmp_vals);
			
			if ($convert && $PREFS->ini('auto_convert_high_ascii') == 'y')
			{
				$vals = $this->_array_entities_to_ascii($vals);
			}
		}
		
		return $vals;
	}
	
	
	/**
	 * Checks that the S3 credentials have been set. Makes not attempt to check their validity.
	 *
	 * @access  private
	 * @return  bool
	 */
	function _check_s3_credentials()
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
	function _item_exists_on_s3($item_name = '')
	{
		// Clearly not, muppet.
		if ( ! $item_name)
		{
			return FALSE;
		}
		
		// Separate out the bucket name.
		$bucket_and_path = $this->_split_bucket_and_path_string($item_name);
		
		if ( ! $bucket_and_path['bucket'])
		{
			return FALSE;
		}
		
		// Make the call.
		$s3 = new S3($this->site_settings['access_key_id'], $this->site_settings['secret_access_key'], FALSE);
		return @$s3->getObjectInfo($bucket_and_path['bucket'], $bucket_and_path['item_path'], FALSE);
		
	}
	
	
	/**
	 * Validates the structure of an 'item' array. Returns a valid item array, with any extraneous
	 * information stripped out, or FALSE.
	 *
	 * @access	private
	 * @param 	array 			$item		The item to validate.
	 * @return 	array|bool
	 */
	function _validate_item($item = array())
	{
		if ( ! $item OR ! is_array($item))
		{
			return FALSE;
		}
		
		$default_item = array(
			'item_extension'	=> '',
			'item_is_folder'	=> '',
			'item_name'			=> '',
			'item_path'			=> '',
			'item_size'			=> ''
		);
		
		$item = array_merge($default_item, $item);
		
		/**
		 * Item extension is optional in this first check, as folders don't have one.
		 */
		
		$required_fields 	= array('item_is_folder', 'item_name', 'item_path', 'item_size');
		$valid_item 		= array();
		$missing_field		= FALSE;
		
		foreach ($required_fields AS $field_id)
		{
			if ($field_id != 'item_size' && ( ! is_string($item[$field_id]) OR $item[$field_id] == ''))
			{
				$missing_field = TRUE;
				break;
			}
			
			$valid_item[$field_id] = $item[$field_id];
		}
		
		// One last check. Files need extension.
		if (strtolower($valid_item['item_is_folder']) != 'y' && ( ! is_string($item['item_extension']) OR $item['item_extension'] == ''))
		{
			$missing_field = TRUE;
		}
		else
		{
			$valid_item['item_extension'] = strtolower($item['item_extension']);
		}
		
		if ($missing_field)
		{
			return FALSE;
		}
		else
		{
			// Bit of cleaning up, and we'll be done.
			$valid_item['item_is_folder'] 	= strtolower($valid_item['item_is_folder']);
			$valid_item['item_size']		= intval($valid_item['item_size']);
			
			return $valid_item;
		}
	}
	
	
	/**
	 * Parses a single Amazon S3 item, representing a single item of content in a bucket.
	 *
	 * @access	private
	 * @param	array		$s3_item	The S3 item to parse.
	 * @return	array
	 */
	function _parse_item_s3_result($s3_item = array())
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
	 * Validates the structure of a 'bucket' array. Returns a valid item array, with any extraneous
	 * information stripped out, or FALSE.
	 *
	 * @access	private
	 * @param 	array 			$bucket		The bucket to validate.
	 * @return 	array|bool
	 */
	function _validate_bucket($bucket = array())
	{
		if ( ! $bucket OR ! is_array($bucket))
		{
			return FALSE;
		}
		
		// Do we have the required information?
		$required_fields 	= array('bucket_id', 'bucket_items_cache_date', 'bucket_name', 'site_id');
		$valid_bucket 		= array();
		$missing_field		= FALSE;
		
		foreach ($required_fields AS $field_id)
		{
			if ( ! array_key_exists($field_id, $bucket))
			{
				$missing_field = TRUE;
				break;
			}
			
			$valid_bucket[$field_id] = $bucket[$field_id];
		}
		
		return ($missing_field ? FALSE : $valid_bucket);
	}
	
	
	/**
	 * Retrieves an item based on the saved field data.
	 *
	 * @access	private
	 * @param	string			$field_data		The saved field data (a full item path, including bucket).
	 * @return	array|bool
	 */
	function _load_item_using_field_data($field_data = '')
	{
		global $DB;
		
		if ( ! $field_data)
		{
			return FALSE;
		}
		
		// Trim the bucket from the start of the field data.
		if ($bucket_and_path = $this->_split_bucket_and_path_string($field_data))
		{
			$db_item = $DB->query("SELECT item_extension, item_is_folder, item_name, item_path, item_size
				FROM exp_bucketlist_items AS items
				INNER JOIN exp_bucketlist_buckets AS buckets
				ON buckets.bucket_id = items.bucket_id
				WHERE buckets.bucket_name = '" .$DB->escape_str($bucket_and_path['bucket']) ."'
				AND items.item_path = '" .$DB->escape_str($bucket_and_path['item_path']) ."'
				LIMIT 1");
			
			if ($db_item->num_rows == 1 && $item = $this->_validate_item($db_item->row))
			{
				return $item;
			}
		}
		
		return FALSE;
	}
	
	
	/**
	 * Retrieves all the buckets from the database, and filter them against the available buckets.
	 *
	 * @access	private
	 * @param 	mixed 		$filter		An array containing the available buckets, or FALSE to return all.
	 * @return 	array
	 */
	function _load_all_buckets_from_db($filter = FALSE)
	{
		global $DB;
		
		$sql = "SELECT
				bucket_id, bucket_items_cache_date, bucket_name, site_id
			FROM exp_bucketlist_buckets
			WHERE site_id = '{$this->site_id}'";
			
		$sql .= is_array($filter) ? " AND bucket_name IN('" .implode("', '", $filter) ."')" : '';
		$sql .= ' ORDER BY bucket_name ASC';
		
		$db_buckets = $DB->query($sql);
			
		if ($db_buckets->num_rows == 0)
		{
			return array();
		}
		
		// Initialise the return array.
		$buckets = array();
		
		foreach ($db_buckets->result AS $db_bucket)
		{
			if ($bucket = $this->_validate_bucket($db_bucket))
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
	 * @return 	array|bool
	 */
	function _load_bucket_from_db($bucket_name = '')
	{
		global $DB;
		
		if ( ! $bucket_name)
		{
			return FALSE;
		}
		
		$db_bucket = $DB->query("SELECT
				bucket_id, bucket_items_cache_date, bucket_name, site_id
			FROM exp_bucketlist_buckets
			WHERE bucket_name = '" .$DB->escape_str($bucket_name) ."'
			AND site_id = '{$this->site_id}'
			LIMIT 1");
			
		return ($this->_validate_bucket($db_bucket->row));
	}
	
	
	/**
	 * Add a bucket item to the database, if it doesn't already exist. If the item was added,
	 * the method returns 1. If the item was already present, the method returns 0. If an error
	 * occurred, the method returns FALSE.
	 *
	 * In other words use === when evaluating the response.
	 *
	 * @access	private
	 * @param	array 		$item			A bucket item.
	 * @param 	string		$bucket_name	The bucket to which to add the items.
	 * @return	int|bool
	 */
	function _add_bucket_item_to_db($item = array(), $bucket_name = '')
	{
		global $DB;
		
		// No trainers mate.
		if ( ! $item OR ! $bucket_name OR ! is_array($item))
		{
			return FALSE;
		}
		
		// Is this a valid bucket?
		if ( ! $bucket = $this->_load_bucket_from_db($bucket_name))
		{
			return FALSE;
		}
		
		if ( ! $valid_item = $this->_validate_item($item))
		{
			return FALSE;
		}
		
		// Does this item already exist in the database?
		$db_item = $DB->query("SELECT item_id
			FROM exp_bucketlist_items
			WHERE bucket_id = '" .$DB->escape_str($bucket['bucket_id']) ."'
			AND item_name = '" .$DB->escape_str($valid_item['item_name']) ."'
			AND item_path = '" .$DB->escape_str($valid_item['item_path']) ."'
			LIMIT 1");
			
		if ($db_item->num_rows == 1)
		{
			return 0;
		}

		// Add a couple of extra bits to the $valid_item array.
		$valid_item['bucket_id'] = $DB->escape_str($bucket['bucket_id']);
		$valid_item['site_id'] = $this->site_id;
		
		// Add the item to the database.
		$DB->query($DB->insert_string('exp_bucketlist_items', $valid_item));
		
		return $DB->affected_rows;
		
	}
	
	
	/**
	 * Retrieve a bucket's contents from the database.
	 *
	 * @access	private
	 * @param 	string		$bucket_name		The name of the bucket.
	 * @return 	array
	 */
	function _load_bucket_items_from_db($bucket_name = '')
	{
		global $DB;
		
		// NOTE: DOES NOT check whether the items cache has expired.
		
		// Talk sense man.
		if ( ! $bucket_name)
		{
			return FALSE;
		}
		
		// Is this a valid bucket name?
		if ( ! $bucket = $this->_load_bucket_from_db($bucket_name))
		{
			return FALSE;
		}
		
		// Load the items from the database.
		$db_items = $DB->query("SELECT
				item_id, item_path, item_name, item_size, item_extension, item_is_folder
			FROM exp_bucketlist_items
			WHERE bucket_id = '" .$DB->escape_str($bucket['bucket_id']) ."'
			AND site_id = '{$this->site_id}'
			ORDER BY item_name ASC");
			
		if ($db_items->num_rows == 0)
		{
			return array();
		}
		
		// Parse the data into arrays ('folders' and 'files').
		$folders = $files = array();
		
		foreach ($db_items->result AS $db_item)
		{
			$item = $this->_validate_item($db_item);
			
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
	function _update_buckets_from_s3()
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
		$existing_buckets = $this->_load_all_buckets_from_db();
		
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
	function _update_bucket_items_from_s3($bucket_name = '')
	{
		global $DB;
		
		// NOTE: the cache date is not checked. We just assume this call is required.
		
		// Leave it ahhht, you slag.
		if ( ! $bucket_name)
		{
			return FALSE;
		}
		
		// Is this a valid bucket?
		if ( ! $bucket = $this->_load_bucket_from_db($bucket_name))
		{
			return FALSE;
		}
		
		// Make the call to Amazon.
		$s3 = new S3($this->site_settings['access_key_id'], $this->site_settings['secret_access_key'], FALSE);
		
		if ( ! $s3_items = @$s3->getBucket($bucket_name))
		{
			return FALSE;
		}
		
		// Delete any existing bucket items from the database.
		$DB->query("DELETE FROM exp_bucketlist_items
			WHERE bucket_id = {$bucket['bucket_id']}
			AND site_id = {$this->site_id}");
		
		// Parse the data returned from Amazon.
		$new_items = array();
		
		// The basic SQL query.
		$base_insert_sql = 'INSERT INTO exp_bucketlist_items (
				bucket_id, item_extension, item_is_folder, item_name, item_path, item_size, site_id
			) VALUES (%s)';
		
		foreach ($s3_items AS $s3_item)
		{
			/**
			 * Every 1500 items, we write to the database. This prevents MySQL max_allowed_packet
			 * errors when dealing with extremely large buckets.
			 */
			
			if (count($new_items) >= 1500)
			{
				$DB->query(sprintf($base_insert_sql, implode('), (', $new_items)));
				$new_items = array();
			}
			
			if ($item = $this->_parse_item_s3_result($s3_item))
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
		
		// Stragglers?
		if (count($new_items) > 0)
		{
			$DB->query(sprintf($base_insert_sql, implode('), (', $new_items)));
		}
		
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
	function _load_bucket_items($bucket_name = '')
	{
		global $DB, $SESS;
		
		// Be reasonable.
		if ( ! $bucket_name)
		{
			return FALSE;
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
			if ($bucket = $this->_load_bucket_from_db($bucket_name))
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
					$this->_update_bucket_items_from_s3($bucket_name);
				}
			
				$items = $this->_load_bucket_items_from_db($bucket_name);
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
	 * Splits a 'bucket and item path' string into two separate strings.
	 *
	 * IMPORTANT NOTE:
	 * If the item path is the bucket root (/), the method still expects the
	 * slash to be included. If it's not, an empty value is returned for both
	 * the bucket and path.
	 *
	 * @access	private
	 * @param	string		$full_path			The full 'bucket and item path' string.
	 * @param	bool		$strip_slashes		Strips and forward slashes from the end of the item path.
	 * @return	array|bool
	 */
	function _split_bucket_and_path_string($full_path = '', $strip_slashes = FALSE)
	{
		$bucket_and_path = array('bucket' => '', 'item_path' => '');
		
		if ( ! $full_path)
		{
			return FALSE;
		}
		
		/**
		 * The following regular expression also contains a little bit
		 * of validation for the bucket name. It's not 100% strict
		 * though, as there's no way we should ever be passed a non-
		 * existent bucket name, never mind an entirely invalid one.
		 */
		
		if (preg_match('/^([0-9a-z]{1}[0-9a-z\.\_\-]{2,254})\/{1}(.*)$/', $full_path, $matches))
		{
			if ($matches[1] OR $matches[2])
			{
				$bucket_and_path['bucket'] 		= $matches[1];
				$bucket_and_path['item_path']	= $strip_slashes ? rtrim($matches[2], '/') : $matches[2];
			}
		}
		
		return $bucket_and_path;
	}
	
	
	/**
	 * Forwards the specified file from the $_FILES array to S3
	 *
	 * @access	private
	 * @param	string		$field_id		The ID of the file field.
	 * @param	string		$bucket_name	The name of the destination bucket.
	 * @param 	string		$item_path 		The path to the item from the bucket root.
	 * @return	bool
	 */
	function _upload_file_to_s3($field_id = '', $bucket_name = '', $item_path = '')
	{
		// Idiot check.
		if ( ! $field_id OR ! isset($_FILES[$field_id]) OR ! $bucket_name)
		{
			return FALSE;
		}
		
		// If we're in demonstration mode, just return TRUE.
		if ($this->demo)
		{
			return TRUE;
		}
		
		$file = $_FILES[$field_id];
		
		// Strip trailing slashes from the end of $item_path, just in case.
		$item_path = rtrim($item_path, '/');
			
		// The destination.
		$uri = $item_path ? $item_path .'/' .$file['name'] : $file['name'];
			
		// Retrieve the Amazon account credentials.
		$access_key = $this->site_settings['access_key_id'];
		$secret_key = $this->site_settings['secret_access_key'];
		
		// Create the S3 instance.
		$s3 = new S3($access_key, $secret_key, FALSE);

		// Generate the input array for our file.
		$input = $s3->inputFile($file['tmp_name']);	
		
		// Upload the file.
		return $s3->putObject($input, $bucket_name, $uri, S3::ACL_PUBLIC_READ);
		
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
	 * @param 	array 		$settings		Field or cell settings.
	 * @return 	string
	 */
	function _build_root_ui($settings = array())
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
		
		$available_buckets = isset($settings['available_buckets']) ? $settings['available_buckets'] : array();
		
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
		
		if ( ! $buckets = $this->_load_all_buckets_from_db($available_buckets))
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
	function _build_branch_ui($tree_path = '')
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
		if ($items = $this->_load_bucket_items($bucket_name))
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
					$f['item_extension'] = strtolower($f['item_extension']);
					
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
	function _output_branch_ui($tree_path = '')
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
		
		exit($this->_build_branch_ui($tree_path));
	}
	
	
	/**
	 * Outputs the upload response HTML.
	 *
	 * @access	private
	 * @param	string		$message_data	Message data: status, message, upload_id, list_item.
	 * @return	string
	 */
	function _output_upload_response($message_data = array())
	{
		global $LANG, $PREFS;
		
		// Fine, be like that, see what I care.
		if ( ! is_array($message_data))
		{
			$message_data = array();
		}
		
		// Ever the optimist.
		$default_data = array(
			'status'		=>	'failure',
			'message'		=>	$LANG->line('upload_failure'),
			'upload_id'		=> '',
			'list_item'		=> '');
			
		$message_data = array_merge($default_data, $message_data);
		
		// Tidy see.
		foreach ($message_data AS $field)
		{
			$message_data[$field] = htmlspecialchars($message_data[$field], ENT_COMPAT, 'UTF-8');
		}

		/**
		 * Create and return the HTML document. Why, you may ask,
		 * do we not respond with XML, or perhaps even JSON?
		 *
		 * Simple, Internet Explorer and can't handle XML. JSON
		 * is even more problematic.
		 */

		$html = <<<_HTML_
<html>
<head>
	<title>Amazon S3 Response</title>
</head>
<body>
<p id="status">{$message_data['status']}</p>
<p id="message">{$message_data['message']}</p>
<p id="uploadId">{$message_data['upload_id']}</p>
<ul id="listItem">{$message_data['list_item']}</ul>
</body>
</html>
_HTML_;

		// Output the return document.
		header('Content-type: text/html; charset=' .$PREFS->ini('charset'));
		exit($html);

	}
	
	
	/**
	 * Forwards the just-uploaded file to Amazon S3, and writes out a
	 * response document based on the success or failure of the operation.
	 *
	 * @access	private
	 * @return	void
	 */
	function _process_upload()
	{
		global $IN, $LANG, $SESS;
		
		
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
		
		
		// Retrieve the upload ID.
		$upload_id = $IN->GBL('upload_id', 'POST');
		
		
		// Paranoia. Get out early.
		if ($this->site_settings['allow_upload'] != 'y')
		{
			$status = 'failure';
			$message = $LANG->line('upload_failure');
			
			$this->_output_upload_response(array(
				'status'	=> $status,
				'message'	=> $message,
				'upload_id'	=> $upload_id,
				'list_item'	=> ''
			));
			
			return FALSE;
		}
		
		
		/**
		 * The path has been on a round trip from this class, contained in
		 * a rel attribute, and then an input:hidden form element.
		 *
		 * During this entire glorious journey, the JS has not meddled with
		 * the encoding one iota, just so that we can easily decode it here.
		 */
		
		$full_path 	= rawurldecode($IN->GBL('path', 'POST'));
		$bucket_and_path = $this->_split_bucket_and_path_string($full_path, TRUE);
		
		
		// Upload the file to S3.
		if ( ! $bucket_and_path['bucket']
			OR ! $this->_upload_file_to_s3('file', $bucket_and_path['bucket'], $bucket_and_path['item_path']))
		{
			$status = 'failure';
			$message = $LANG->line('upload_failure');
			
			$this->_output_upload_response(array(
				'status'		=> $status,
				'message'		=> $message,
				'upload_id'		=> $upload_id,
				'list_item'		=> ''
			));
			
			return FALSE;
		}
		
		// Shortcut to the uploaded file (which we now know exists).
		$file = $_FILES['file'];
		
		// All good so far.
		$status = 'success';
		$message = $LANG->line('upload_success') .$file['name'];
		
		// Construct the URI.
		$uri = $bucket_and_path['item_path'] ? $bucket_and_path['item_path'] .'/' .$file['name'] : $file['name'];
		
		// Extract some file information.
		$item_info = array(
			'item_extension' 	=> pathinfo($file['name'], PATHINFO_EXTENSION),
			'item_is_folder'	=> 'n',
			'item_name' 		=> $file['name'],
			'item_path' 		=> $uri,
			'item_size' 		=> $file['size']
		);
		
		
		// Add our item to the database.
		$database_result = $this->_add_bucket_item_to_db($item_info, $bucket_and_path['bucket']);
		
		/**
		 * Whether the operation failed, or no items were added, we do
		 * the same thing at the moment. If this changes in the future,
		 * we'll need to do a strict === check here.
		 */
		
		if ( ! $database_result)
		{
			$list_item = '';
		}
		else
		{
			// Create the HTML for the new list item.
			$list_item = '<li class="file ext_' .strtolower($item_info['item_extension']) .'">
				<a href="#" rel="' .rawurlencode($bucket_and_path['bucket'] .'/'
				.$item_info['item_path']) .'">' .$item_info['item_name'] .'</a></li>';
				
			// The Session cache is now out of date. Just load the items from the database.
			$SESS->cache[$this->namespace][$this->lower_class]['items'][$bucket_and_path['bucket']]
				= $this->_load_bucket_items_from_db($bucket_and_path['bucket']);
			
		}
		
		
		// Output the return document.
		$this->_output_upload_response(array(
			'status'		=> $status,
			'message'		=> $message,
			'upload_id'		=> $upload_id,
			'list_item'		=> $list_item
		));
	}
	
	
	/**
	 * Forces an update of the fieldtype. Used during beta testing, when the
	 * version number updates are not recognised by FieldFrame.
	 *
	 * @access	private
	 * @return	void
	 */
	function _force_update()
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
			CONSTRAINT uk_site_id_bucket_name UNIQUE (site_id, bucket_name))";
		
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
			CONSTRAINT fk_item_bucket_id FOREIGN KEY(bucket_id) REFERENCES exp_bucketlist_buckets(bucket_id))";
		
		foreach ($sql AS $query)
		{
			$DB->query($query);
		}
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
		global $DB, $IN, $PREFS;
		
		$this->site_id 		= $DB->escape_str($PREFS->ini('site_id'));
		$this->class 		= get_class($this);
		$this->lower_class 	= strtolower($this->class);
		$this->namespace	= 'sl';
		
		
		/**
		 * If this is a beta version, force the update method to run
		 * on the FieldFrame settings page.
		 */
		
		if (strpos($this->info['version'], 'b')
			&& $IN->GBL('P', 'GET') == 'extension_settings'
			&& $IN->GBL('name', 'GET') == 'fieldframe')
		{
			$this->_force_update();
		}
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
		if ( ! array_key_exists($this->namespace, $session->cache))
		{
			$session->cache[$this->namespace] = array();
		}
		
		$session->cache[$this->namespace][$this->lower_class] = array();
		$session->cache[$this->namespace][$this->lower_class]['buckets'] = array();
		$session->cache[$this->namespace][$this->lower_class]['items'] = array();
		$session->cache[$this->namespace][$this->lower_class]['buckets_updated_from_s3'] = FALSE;
		
		if ($IN->GBL('ajax', 'POST') == 'y' && $IN->GBL('addon_id', 'POST') == $this->lower_class)
		{
			// We're either being summoned by the file tree, or the uploader. Which is it?
			$request = $IN->GBL('request', 'POST');
			
			switch ($request)
			{
				case 'tree':
					$this->_output_branch_ui(urldecode($IN->GBL('dir', 'POST')));
					break;
					
				case 'upload':
					$this->_process_upload();
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
		if ($IN->GBL('ajax', 'POST') == 'y')
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
		$upload_failure = str_replace(array('"', '"'), '', $LANG->line('upload_failure'));
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
		if ( ! $this->_check_s3_credentials())
		{
			$html .= '<p class="alert">' .$LANG->line('missing_credentials'). '</p>';
		}
		else
		{
			// If we have a saved field, does it still exist on the server?
			if ( ! $this->_item_exists_on_s3($field_data))
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
				$this->_update_buckets_from_s3();
				$SESS->cache[$this->namespace][$this->lower_class]['buckets_updated_from_s3'] = TRUE;
			}
			
			// Open the UI wrapper.
			$html .= '<div class="bucketlist-ui">';

			// Retrieve the tree root UI (i.e. the buckets).
			$html .= $this->_build_root_ui($field_settings);
			
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
	 * Adds custom settings to the "Edit field" form.
	 *
	 * @access	public
	 * @param	array 		$field_settings		Previously saved field settings.
	 * @param 	bool 		$is_cell			Is this being called from the display_cell_settings method?
	 * @return	array
	 */
	public function display_field_settings($field_settings = array(), $is_cell = FALSE)
	{
		global $LANG;
		
		$SD = new Fieldframe_SettingsDisplay();
		
		$html = '<div class="bucketlist-settings '
			.($is_cell ? 'cell' : '')
			.'">';
			
		$html .= '<label class="defaultBold">' .$LANG->line('available_buckets') .'</label>';
		
		$saved_buckets = isset($field_settings['available_buckets'])
			? $field_settings['available_buckets']
			: array();
		
		// Update the buckets cache from S3.
		$this->_update_buckets_from_s3();
		
		// Load the buckets from the database.
		if ( ! $buckets = $this->_load_all_buckets_from_db())
		{
			$html .= '<p>' .$LANG->line('no_buckets') .'</p>';
		}
		else
		{
			foreach ($buckets AS $bucket)
			{
				$checked = in_array($bucket['bucket_name'], $saved_buckets) ? 'checked="checked"' : '';
				
				$html .= '<label>'
					.'<input '. $checked .' name="available_buckets[]" type="checkbox" value="' .$bucket['bucket_name'] .'">'
					.$bucket['bucket_name']
					.'</label>';
			}
		}
		
		$html .= '</div>';
		
		return array('cell2' => $html);
	}
	
	
	/**
	 * Adds custom FF Matrix cell settings.
	 *
	 * @access	public
	 * @param	array		$cell_settings		Previously saved cell settings.
	 * @return	string
	 */
	public function display_cell_settings($cell_settings = array())
	{
		$settings = $this->display_field_settings($cell_settings, TRUE);
		return isset($settings['cell2']) ? $settings['cell2'] : '';
	}
	
	
	/**
	 * Performs house-keeping when upgrading from an earlier version.
	 *
	 * @access	public
	 * @param	string|bool		$from		The previous version, or FALSE, if this is the initial installation.
	 */
	public function update($from = FALSE)
	{
		global $DB, $REGX;
		
		$this->_force_update();
		
		if ($from && $from < '1.1')
		{
			$update_fields = $update_matrices = FALSE;
			
			// Determine the BucketList fieldtype ID.
			$db_bucketlist_ft = $DB->query("SELECT fieldtype_id
				FROM exp_ff_fieldtypes
				WHERE class = '{$this->lower_class}'
				LIMIT 1");
				
			// Determine the FF Matrix fieldtype ID.
			$db_matrix_ft = $DB->query("SELECT fieldtype_id
				FROM exp_ff_fieldtypes
				WHERE class = 'ff_matrix'
				LIMIT 1");
				
			if ($db_bucketlist_ft->num_rows === 1)
			{
				// Retrieve all the BucketList fields.
				$db_fields = $DB->query("SELECT field_id, ff_settings
					FROM exp_weblog_fields
					WHERE field_type = 'ftype_id_" .$db_bucketlist_ft->row['fieldtype_id'] ."'");
					
				$update_fields = ($db_fields->num_rows > 0);
			}
				
			// Retrieve all the BucketList cells.
			if ($db_matrix_ft->num_rows === 1)
			{
				$db_matrices = $DB->query("SELECT field_id, ff_settings
					FROM exp_weblog_fields
					WHERE field_type = 'ftype_id_" .$db_matrix_ft->row['fieldtype_id'] ."'");
					
				$update_matrices = ($db_matrices->num_rows > 0);
			}
			
			if ($db_fields OR $db_matrices)
			{
				$this->_update_buckets_from_s3();

				$buckets 		= $this->_load_all_buckets_from_db();
				$field_buckets 	= array();

				foreach ($buckets AS $bucket)
				{
					$field_buckets[] = $bucket['bucket_name'];
				}

				$field_settings = $this->_serialize(array('available_buckets' => $field_buckets));
				
				// Update the fields.
				if ($update_fields)
				{
					foreach ($db_fields->result AS $db_field)
					{
						$DB->query($DB->update_string(
							'exp_weblog_fields',
							array('ff_settings' => $field_settings),
							"field_id = '{$db_field['field_id']}'" 
						));
					}
				}
				
				// Update the matrices.
				if ($update_matrices)
				{
					foreach($db_matrices->result AS $db_matrix)
					{
						$update_matrix = FALSE;
						$matrix_settings = $this->_unserialize($db_matrix['ff_settings']);
						
						// Update all the BucketList cell types.
						if ( ! isset($matrix_settings['cols']))
						{
							continue;
						}
						
						foreach ($matrix_settings['cols'] AS $col_key => $col_val)
						{
							if (isset($col_val['type']) && $col_val['type'] == $this->lower_class)
							{
								$update_matrix = TRUE;
								$col_val['settings'] = array('available_buckets' => $field_buckets);
								$matrix_settings['cols'][$col_key] = $col_val;
							}
						}
						
						if ($update_matrix)
						{
							$DB->query($DB->update_string(
								'exp_weblog_fields',
								array('ff_settings' => $this->_serialize($matrix_settings)),
								"field_id = '{$db_matrix['field_id']}'" 
							));
						}
					}
				}
			}
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
	 * @param	array		$params				Array of tag parameters as key / value pairs.
	 * @param	string		$tagdata			Content between the opening and closing tags (not used).
	 * @param	string		$field_data			The field data.
	 * @param	array		$field_settings		The field settings.
	 * @return	string
	 */
	public function display_tag($params, $tagdata, $field_data, $field_settings)
	{
		$out = '';
		$bucket_and_path = $this->_split_bucket_and_path_string($field_data);
			
		if ($bucket_and_path['bucket'] && $bucket_and_path['item_path'])
		{
			$out .= $this->site_settings['use_ssl'] == 'y' ? 'https://' : 'http://';
			$out .= urlencode($bucket_and_path['bucket'])
				.($this->site_settings['custom_url'] == 'y' ? '/' : '.s3.amazonaws.com/') .urlencode($bucket_and_path['item_path']);
		}
		
		return $out;
	}
	
	
	/**
	 * Outputs the file name.
	 *
	 * @access	public
	 * @param	array		$params				Array of tag parameters as key / value pairs.
	 * @param	string		$tagdata			Content between the opening and closing tags (not used).
	 * @param	string		$field_data			The field data.
	 * @param	array		$field_settings		The field settings.
	 * @return	string
	 */
	public function file_name($params, $tagdata, $field_data, $field_settings)
	{
		$out = '';
		
		if ($item = $this->_load_item_using_field_data($field_data))
		{
			$out = $item['item_name'];
		}
		
		return $out;
	}
	
	
	/**
	 * Outputs the file size.
	 *
	 * @access	public
	 * @param	array		$params				Array of tag parameters as key / value pairs.
	 * @param	string		$tagdata			Content between the opening and closing tags (not used).
	 * @param	string		$field_data			The field data.
	 * @param	array		$field_settings		The field settings.
	 * @return	string
	 */
	public function file_size($params, $tagdata, $field_data, $field_settings)
	{
		// Default parameters.
		$params = array_merge(array('format' => 'auto'), $params);
		
		$out = '';
		
		if ($item = $this->_load_item_using_field_data($field_data))
		{
			switch ($params['format'])
			{
				case 'bytes':
					$out = $item['item_size'];
					break;
				
				case 'kilobytes':
					$out = round($item['item_size'] / 1024, 2);
					break;
				
				case 'megabytes':
					$out = round($item['item_size'] / 1048576, 2);		// 1024 * 1024
					break;
				
				case 'auto':
				default:
					if ($item['item_size'] < 1048567)
					{
						$out = round($item['item_size'] / 1024, 2) .'<abbr title="Kilobytes">KB</abbr>';
					}
					else
					{
						$out = round($item['item_size'] / 1048567, 2) .'<abbr title="Megabytes">MB</abbr>';
					}
					break;
			}
		}
		
		return $out;
	}
	
	
}

/* End of file	: ft.bucketlist.php */
/* Location		: /system/extensions/fieldtypes/bucketlist/ft.bucketlist.php */