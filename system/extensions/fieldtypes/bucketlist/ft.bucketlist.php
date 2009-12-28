<?php

/**
 * Fieldtype extension enabling integration of Amazon S3 with your ExpressionEngine website.
 *
 * @package   	BucketList
 * @version   	0.9.0
 * @author    	Stephen Lewis <addons@eepro.co.uk>
 * @copyright 	Copyright (c) 2009, Stephen Lewis
 * @license		@todo commercial license gumpf
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
		'version'			=> '0.9.0',
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
	public $hooks = array('show_full_control_panel_end');
  
	/**
	 * Default site settings.
	 *
	 * @access	public
	 * @var 	array
	 */
	public $default_site_settings = array(
		'access_key_id'		=> '',
		'secret_access_key'	=> '',
		'cache_duration' 	=> '3600',		// 60 minutes
		'use_ssl' 			=> 'n'
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
	 * Returns a list of items contained within the specified bucket. The
	 * session cache is checked, followed by the database cache, before
	 * Amazon S3 is queried.
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
			$db_items = $DB->query("SELECT
					items.item_extension,
					items.item_name,
					items.item_path
				FROM exp_bucketlist_items AS items
				WHERE items.site_id = '{$this->site_id}'
				AND items.bucket_id = '{$bucket_id}'
				ORDER BY items.item_name ASC");
				
			foreach ($db_items->result AS $db_item)
			{
				$items[] = array(
					'item_extension' => $db_item['item_extension'],
					'item_name' => $db_item['item_name'],
					'item_path' => $db_item['item_path']);
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
			
			if (is_array($s3_items))
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
					$item_last_updated 	= $s3_item['time'];
					$item_extension		= pathinfo($s3_item['name'], PATHINFO_EXTENSION);
					
					$item_name = pathinfo($s3_item['name'], PATHINFO_BASENAME);
					$item_path = $bucket_name .'/' .$s3_item['name'];
					$item_size = $s3_item['size'];
					
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
					$sql .= "('{$this->site_id}', '{$s3_bucket}', '{$cache_date}'), ";
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
	 * @param 		string		$parent_dir		The parent directory path.
	 * @return 		string
	 */
	private function build_items_ui($parent_dir = '')
	{
		global $LANG;
		
		if ( ! $parent_dir)
		{
			return $this->build_buckets_ui();
		}
		
		$LANG->fetch_language_file($this->lower_class);
		
		// Extract the bucket name.
		$bucket_name = substr($parent_dir, 0, strpos($parent_dir, '/'));
		
		// Retrieve the items residing in the bucket.
		$items = $this->get_items($bucket_name);
		
		// Separate into files and folders.
		$folders 			= array();
		$files 				= array();
		$parent_dir_pattern	= $this->clean_string_for_regexp($parent_dir);
		
		$folder_pattern	= '/^' .$parent_dir_pattern .'[^\.]{1}[^\/\.]*?\/{1}$/';
		$file_pattern	= '/^' .$parent_dir_pattern .'[^\.]{1}[^\/]*?\.{1}.*$/';
		
		foreach ($items AS $item)
		{
			if (preg_match($folder_pattern, $item['item_path']))
			{
				$folders[] = $item;
			}
			
			if (preg_match($file_pattern, $item['item_path']))
			{
				$files[] = $item;
			}
		}
		
		if ($folders OR $files)
		{
			// Open the list of files and folders.
			$ret = "<ul class='bucketlist-tree' style='display : none;'>";
			
			// Add the folders to the list.
			foreach ($folders AS $f)
			{
				$ret .= "<li class='directory collapsed'><a href='#' rel='{$f['item_path']}'>{$f['item_name']}</a></li>";
			}
		
			// Add the files to the list.
			foreach ($files AS $f)
			{
				$ret .= "<li class='file ext_{$f['item_extension']}'><a href='#' rel='{$f['item_path']}'>{$f['item_name']}</a></li>";
			}
		
			// Close the list of files and folders.
			$ret .= '</ul>';
		}
		else
		{
			$ret = '<ul class="bucketlist-tree" style="display : none;"><li class="empty">' .$LANG->line('no_items') .'</li></ul>';
		}
		return $ret;
	}
	
	
	/**
	 * Temporarily retired method to display the fieldtype field settings form.
	 * Refer to the get_available_buckets method for more details on why there
	 * are no longer any field settings.
	 *
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
	 * Handles AJAX requests. Called from the show_full_control_panel_start hook.
	 *
	 * @access		public
	 * @param		string		$out		The HTML content of the page.
	 * @return		string
	 */
	public function show_full_control_panel_end($out)
	{
		global $IN, $OUT;
		
		$out = $this->get_last_call($out);
		
		if ($IN->GBL('ajax', 'GET') == 'y' && $IN->GBL('addon_id', 'GET') == $this->lower_class)
		{
			$OUT->out_type = 'html';
			$out = $this->build_items_ui(urldecode($IN->GBL('dir', 'GET')));
		}
		
		return $out;
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
		global $FNS, $IN, $LANG, $REGX;
		
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
		
		// Include some inline JS, so we can write out the callback URL.
		$ajax_script_url = $REGX->prep_query_string(BASE .'&ajax=y&addon_id=' .$this->lower_class);

		$inline_js = "ajaxScriptURL = '{$ajax_script_url}';";
		$this->insert_js($inline_js);
		
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
		
		/*
		$ret .= $sd->row(array(
			$sd->label('custom_url', 'custom_url_hint'),
			$sd->text('custom_url', $custom_url)
			));
		*/
			
		// Close the settings block.
		$ret .= $sd->block_c();
		
		// Return the settings block.
		return $ret;
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
		
		if ($from !== FALSE && $from < '0.8.0')
		{
			$sql[] = "DROP TABLE IF EXISTS exp_bucketlist_buckets";
			$sql[] = "DROP TABLE IF EXISTS exp_bucketlist_files";
		}
			
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
	 * --------------------------------------------------------------------------
	 * @todo The output methods all need to be updated to handle folders.
	 * --------------------------------------------------------------------------
	 */
	
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
			$out .= urlencode($bucket) .'.s3.amazonaws.com/' .urlencode($file);
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

?>