<?php

require_once 'resources/S3.php';

class Bucketlist extends Fieldframe_Fieldtype {
  
	/**
	 * Basic fieldtype information.
	 *
	 * @access	public
	 * @var 		array
	 */
	public $info = array(
	  'name'      => 'BucketList',
	  'version'   => '0.6.0',
	  'desc'      => 'Enables selection of files stored on Amazon S3.',
	  'docs_url'  => 'http://experienceinternet.co.uk/bucketlist/',
	  'versions_xml_url' => 'http://experienceinternet.co.uk/addon-versions.xml'
	  );

	/**
	 * Fieldtype requirements.
	 *
	 * @access	public
	 * @var 		array
	 */
	public $requirements = array(
	  'ff'        => '1.3.4',
	  'cp_jquery' => '1.1'
	  );
  
	/**
	 * Fieldtype extension hooks.
	 *
	 * @access	public
	 * @var 		array
	 */
	public $hooks = array('show_full_control_panel_end');
  
	/**
	 * Default site settings.
	 *
	 * @access	public
	 * @var 		array
	 */
	public $default_site_settings = array(
	  'cache_duration' => '60',
	  'use_ssl' => 'n'
	  );


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

		return (isset($SESS->cache['sl'])
		  && isset($SESS->cache['sl']['bucketlist']));
	}
  
  
	/**
	 * Creates the Session cache, if it doesn't exist.
	 *
	 * @access  private
	 */
	private function create_session_cache()
	{
		global $SESS;

		if ( ! isset($SESS->cache['sl']))
		{
			$SESS->cache['sl'] = array();
		}

		if ( ! isset($SESS->cache['sl']['bucketlist']))
		{
			$SESS->cache['sl']['bucketlist'] = array();
		}
	}


	/**
	 * Loads the buckets from the database. Takes into account the cache duration.
	 *
	 * @access  private
	 * @return  array
	 */
	private function load_buckets_from_db()
	{
		global $DB, $PREFS;

		// Initialise a few variables.
		$db_cache_limit = time() - (intval($this->site_settings['cache_duration']) * 1000);
		$site_id = $PREFS->ini('site_id');

		// Check the DB cache.
		$db_cache = $DB->query("SELECT `id`, `site_id`, `bucket_name`, `cache_date`
			FROM `exp_bucketlist_buckets`
			WHERE `site_id` = '{$site_id}'
			AND `cache_date` > {$db_cache_limit}");

		return $db_cache;
	}
  
  
  /**
   * Loads the buckets from Amazon.
   *
   * @access  private
   * @return  bool    A boolean value indicating the success of the operation.
   */
  private function load_buckets_from_amazon()
  {
		global $DB, $PREFS;

		$s3 = new S3($this->site_settings['access_key_id'],
			$this->site_settings['secret_access_key'],
			($this->site_settings['use_ssl'] === 'y'));

		$amazon_buckets = @ $s3->listBuckets();

		/*
		$amazon_buckets = array();

		for ($i = 0; $i < 10; $i++)
		{
			$amazon_buckets[] = 'bucket_' . chr(65 + $i);
		}
		*/

		if (is_array($amazon_buckets) && count($amazon_buckets) > 0)
		{
			// Retrieve the site ID.
			$site_id = $PREFS->ini('site_id');
			
			// Delete the old database cache.
			$DB->query("DELETE FROM `exp_bucketlist_buckets`");
			
			// Write the new buckets to the database cache.
			$cache_date = time();
			$sql = 'INSERT INTO `exp_bucketlist_buckets` (`site_id`, `bucket_name`, `cache_date`) VALUES';
			
			foreach ($amazon_buckets AS $amazon_bucket)
			{
				// Construct the query.
				$sql .= " ({$site_id}, '{$amazon_bucket}', {$cache_date}),";
			}
			
			$sql = rtrim($sql, ',');
			$DB->query($sql);
			
			return TRUE;
		}
		else
		{
			return FALSE;
		}
  }



	/**
	 * Loads the list of buckets from Amazon, or the cache.
	 *
	 * @access  private
	 * @return  array
	 */
	private function load_buckets()
	{
		global $SESS;

		// Initialise the buckets array.
		$buckets = array();
		
		// Check the session cache.
		if ( ! $this->session_cache_exists())
		{
			// Load the buckets from the database.
			$db_buckets = $this->load_buckets_from_db();
			
			if ($db_buckets->num_rows > 0)
			{
				// Parse the database results.
				foreach ($db_buckets->result AS $db_bucket)
				{
					$buckets[] = array(
						'bucket_id'   => $db_bucket['id'],
						'bucket_name' => $db_bucket['bucket_name']
						);
				}
			}
			else
			{
				// Load the buckets from Amazon, and save them to the database.
				if ($this->load_buckets_from_amazon())
				{
					// Call this method again, to load the database cache, and save it to the
					// Session cache. This means an extra database call, but it's a lot neater.
					return $this->load_buckets();
				}
			}
			
			// Save the buckets to the Session cache.
			$this->create_session_cache();
			$SESS->cache['sl']['bucketlist'] = $buckets;
		}

		// Return the Session cache.
		return $SESS->cache['sl']['bucketlist'];
	}
  
  
	/**
	 * Loads the files for the specified bucket from the database.
	 *
	 * @access  private
	 * @param   string    $bucket_name   The 'parent' bucket.
	 * @return  array
	 */
	private function load_files_from_db($bucket_name = '')
	{
		global $DB, $PREFS;

		// Initialise a few variables.
		$db_cache_limit = time() - (intval($this->site_settings['cache_duration']) * 1000);
		$site_id = $PREFS->ini('site_id');
		
		// Check the DB cache.
		$db_cache = $DB->query("SELECT `id`, `site_id`, `bucket_name`, `file_name`, `cache_date`
			FROM `exp_bucketlist_files`
			WHERE `site_id` = '{$site_id}'
			AND `bucket_name` = '{$bucket_name}'
			AND `cache_date` > {$db_cache_limit}");

		return $db_cache;
	}
	
	
	/**
	 * Loads the files for the specified bucket from Amazon.
	 *
	 * @access  private
	 * @param   string    $bucket_name    The 'parent' bucket.
	 * @return  bool      A boolean value indicating the success of the operation.
	 */
	private function load_files_from_amazon($bucket_name = '')
	{
		global $DB, $PREFS;
		
		if ( ! $bucket_name)
		{
			return array();
		}
		
		$s3 = new S3($this->site_settings['access_key_id'],
			$this->site_settings['secret_access_key'],
			($this->site_settings['use_ssl'] === 'y'));

		$amazon_files = @ $s3->getBucket($bucket_name);

		/*
		$amazon_files = array();

		for ($i = 65; $i < 90; $i++)
		{
			$amazon_files[] = 'File-' .chr($i);
		}
		*/

		if (is_array($amazon_files) && count($amazon_files) > 0)
		{
			// Retrieve the site ID.
			$site_id = $PREFS->ini('site_id');

			// Delete the old database cache.
			$DB->query("DELETE FROM `exp_bucketlist_files` WHERE `bucket_name` = '{$bucket_name}'");

			// Write the new buckets to the database cache.
			$cache_date = time();
			$sql = "INSERT INTO `exp_bucketlist_files`
				(`site_id`, `bucket_name`, `file_name`, `file_size`, `file_last_updated`, `cache_date`)
				VALUES";

			foreach ($amazon_files AS $amazon_file)
			{
				$name = $amazon_file['name'];
				$time = $amazon_file['time'];
				$size = $amazon_file['size'];

				// Construct the query.
				$sql .= " ({$site_id}, '{$bucket_name}', '{$name}', {$size}, {$time}, {$cache_date}),";
			}

			$sql = rtrim($sql, ',');
			$DB->query($sql);

			return TRUE;
		}
		else
		{
			return FALSE;
		}
	}
	
	
	/**
	 * Loads the files for the specified bucket. This is only ever called via AJAX,
	 * so we don't bother with the Session cache.
	 *
	 * @access  private
	 * @param   string       $bucket_name     The name of the 'parent' bucket.
	 * @return  array
	 */
	private function load_files($bucket_name = '')
	{
		global $SESS;
	
		// Initialise the files array.
		$files = array();
		
		if ( ! $bucket_name)
		{
			return $files;
		}

		// Load the files from the database.
		$db_files = $this->load_files_from_db($bucket_name);

		if ($db_files->num_rows > 0)
		{
			// Parse the database results.
			foreach ($db_files->result AS $db_file)
			{
				$files[] = array(
					'file_id'   => $db_file['id'],
					'file_name' => $db_file['file_name']
					);
			}
		}
		else
		{
			// Load the files from Amazon, and save them to the database.
			if ($this->load_files_from_amazon($bucket_name))
			{
				/**
				 * Call this method again, to load the database cache. This means
				 * an extra database call, but it's a lot neater.
				 */
				
				return $this->load_files($bucket_name);
			}
		}

		return $files;
	}


	/**
	 * Build the file 'select' options.
	 *
	 * @access  private
	 * @param   string    $bucket_name      The 'parent' bucket name.
	 * @param   string    $active_file      The name of the previously selected file (optional).
	 * @return  An HTML string containing the 'select' options.
	 */
	private function build_file_options($bucket_name = '', $active_file = '')
	{
		// Initialise the return string.
		$ret = '';
		
		// Confirm that we have the required information.
		if ( ! $this->check_amazon_credentials())
		{
			return $ret;
		}

		// Load the files.
		if ($files = $this->load_files($bucket_name))
		{
			foreach ($files AS $file)
			{
				$ret .= '<option value="' .$file['file_name']. '"';
				$ret .= ($file['file_name'] == $active_file) ? ' selected' : '';
				$ret .= '>' .$file['file_name']. '</option>';
			}
		}

		return $ret;
	}
	
	
	
	/**
	 * ----------------------------------------------------------------
	 * PUBLIC METHODS
	 * ----------------------------------------------------------------
	 */
  
	/**
	 * Handles AJAX requests. Called from the show_full_control_panel_start hook.
	 *
	 * @access	public
	 * @param		string		$out		The HTML content of the page.
	 * @return	string
	 */
	public function show_full_control_panel_end($out)
	{
		global $IN, $OUT;
		
		$out = $this->get_last_call($out);
		
		// We're only interested in AJAX requests.
		if ($IN->GBL('ajax_request', 'GET') === 'y')
		{
			$OUT->out_type = 'html';
			$out = $this->build_file_options($IN->GBL('bucket_id', 'GET'));
		}
		
		return $out;
	}
    
  
	/**
	 * Displays the SL S3 fieldtype in the publish / edit form.
	 *
	 * @access	public
	 * @param		string		$field_name				The field ID.
	 * @param		string		$field_data				Previously saved field data.
	 * @param		array			$field_settings		The field settings.
	 * @return	string
	 */
	public function display_field($field_name, $field_data, $field_settings)
	{
		global $IN, $LANG;
		
		// Check that this isn't an AJAX request.
		if ($IN->GBL('ajax_request', 'GET') == 'y')
		{
			return FALSE;
		}
		
		// Retrieve the correct language file.
		$LANG->fetch_language_file('bucketlist');
		
		// Initialise the return string.
		$ret = '';
		
		// Include custom JS and CSS.
		$this->include_js('js/cp.js');
		$this->include_css('css/cp.css');
		
		// Check the AWS credentials.
		if ( ! $this->check_amazon_credentials())
		{
			$ret .= '<div id="eepro-co-uk"><p class="error">' .$LANG->line('missing_credentials'). '</p></div>';
			return $ret;
		}
		
		// Load the buckets.
		if ( ! $buckets = $this->load_buckets())
		{
			$ret .= '<div id="eepro-co-uk"><p class="error">' .$LANG->line('no_available_buckets'). '</p></div>';
			return $ret;
		}
		
		// Check if we have previously-saved bucket and file information.
		if (is_array($field_data))
		{
			$saved_bucket = $field_data[0];
			$saved_file = count($field_data) == 2 ? $field_data[1] : '';
		}
		else
		{
			$saved_bucket = $saved_file = '';
		}
		
		// Check the bucket list against the field settings.
		$available_buckets = array();
		$available_bucket_names = array();
		foreach ($buckets AS $bucket)
		{
			if (in_array($bucket['bucket_name'], $field_settings['field_buckets']))
			{
				$available_bucket_names[] = $bucket['bucket_name'];
				$available_buckets[] = $bucket;
			}
		}
		
		// Are there any buckets available?
		if (count($available_buckets) == 0)
		{
			$ret .= '<div id="eepro-co-uk"><p class="error">' .$LANG->line('no_available_buckets'). '</p></div>';
			return $ret;
		}
		
		// Check whether the saved bucket is still valid.
		if ( ! in_array($saved_bucket, $available_bucket_names))
		{
			$saved_bucket = '';
		}
		
		// Build the UI.
		$ret .= '<div id="eepro-co-uk">';
		
		// Current file.
		if ($saved_bucket != '' && $saved_file != '')
		{
			$ret .= '<div class="saved-file">';
			$ret .= '<p>' .$saved_bucket. '/' .$saved_file. ' (<span class="action remove">Remove</span>)</p>';
			$ret .= '</div>';
		}
		
		$ret .= '<table cellspacing="0" cellpadding="0"';
		
		if ($saved_bucket != '' && $saved_file != '')
		{
			$ret .= ' class="hidden"';
		}
		
		$ret .= '>';
		$ret .= '<tr>';
		
		// Buckets.
		$ret .= '<td>';
		$ret .= '<div class="buckets">';
		$ret .= '<label for="bucket-' .$field_name. '">' .$LANG->line('buckets'). '</label>';
		$ret .= '<select id="bucket-' .$field_name. '" name="' .$field_name. '[0]" size="4" class="multiple">';
		
		foreach ($available_buckets AS $bucket)
		{
			$ret .= '<option value="' .$bucket['bucket_name']. '"';
			if (count($available_buckets) == 1 OR $bucket['bucket_name'] == $saved_bucket)
			{
				$ret .= ' selected';
			}
			$ret .= '>' .$bucket['bucket_name']. '</option>';
		}
		
		$ret .= '</select>';
		$ret .= '</div>';
		$ret .= '</td>';
		
		// Spacer.
		$ret .= '<td width="25"></td>';
		
		// Files.
		$ret .= '<td>';
		$ret .= '<div class="files';
		if (count($available_buckets) != 1 && $saved_bucket == '')
		{
			$ret .= ' hidden';
		}
		$ret .= '">';
		$ret .= '<label for="file-' .$field_name. '">' .$LANG->line('files'). '</label>';
		$ret .= '<select id="file-' .$field_name. '" name="' .$field_name. '[1]" size="4" class="multiple">';
		
		if ($saved_bucket != '')
		{
			// If we have a saved bucket, load the associated files.
			$ret .= $this->build_file_options($saved_bucket, $saved_file);
		}
		elseif (count($available_buckets) == 1)
		{
			// If only one bucket exists, load the files.
			$ret .= $this->build_file_options($available_buckets[0]['bucket_name'], $saved_file);
		}
		
		$ret .= '</select>';
		$ret .= '</div>';
		
		// Loading and error messages.
		$ret .= '<p class="loading hidden"><span>' .$LANG->line('loading_files'). '</span></p>';
		$ret .= '<p class="error hidden"><span>' .$LANG->line('error_loading_files'). '</span></p>';
		
		if (count($available_buckets) != 1 && $saved_bucket == '')
		{
			$ret .= '<p class="info"><span>' .$LANG->line('select_bucket_to_load_files'). '</span></p>';
		}
		
		$ret .= '</td>';
		$ret .= '</tr>';
		$ret .= '</table>';
		$ret .= '</div>';

		return $ret;
	}
  
  
	/**
	 * Displays the site-wide fieldtype settings form:
	 * - AWS Key ID
	 * - AWS Secret Key
	 * - Use SSL?
	 * - Custom base URL
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
		
		// Do we have existing settings?
		$access_key_id = isset($this->site_settings['access_key_id'])
			? $this->site_settings['access_key_id']
			: '';
			
		$secret_access_key = isset($this->site_settings['secret_access_key'])
			? $this->site_settings['secret_access_key']
			: '';
			
		$use_ssl = isset($this->site_settings['use_ssl'])
			? $this->site_settings['use_ssl']
			: $this->default_site_settings['use_ssl'];
			
		$cache_duration = isset($this->site_settings['cache_duration'])
			? $this->site_settings['cache_duration']
			: $this->default_site_settings['cache_duration'];
		
		/*
		$custom_url = isset($this->site_settings['custom_url'])
			? $this->site_settings['custom_url']
			: '';
		*/
			
		// Create the settings fields.
		$ret .= $sd->row(array(
			$sd->label('access_key_id'),
			$sd->text('access_key_id', $access_key_id)
			));
			
		$ret .= $sd->row(array(
			$sd->label('secret_access_key'),
			$sd->text('secret_access_key', $secret_access_key)
			));
			
		$options = array(
			'y' => 'yes',
			'n' => 'no'
			);
			
		$ret .= $sd->row(array(
			$sd->label('use_ssl'),
			$sd->select('use_ssl', $use_ssl, $options)
			));
			
		$options = array(
			'5'		=> '5_min',
			'10'	=> '10_min',
			'15'	=> '15_min',
			'30'	=> '30_min',
			'45'	=> '45_min',
			'60'	=> '60_min',
			'90'	=> '90_min',
			'120' => '120_min',
			'240' => '240_min',
			'360' => '360_min',
			'480' => '480_min'
			);
			
		$ret .= $sd->row(array(
			$sd->label('cache_duration', 'cache_duration_hint'),
			$sd->select('cache_duration', $cache_duration, $options)
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
	 * Displays the custom field fieldtype settings form.
	 *
	 * @access	public
	 * @param		array		$field_settings		Any previously saved field settings.
	 * @return	array
	 */
	public function display_field_settings($field_settings)
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
			if ( ! $buckets = $this->load_buckets())
			{
				$ret .= $sd->info_row('no_buckets');
			}
			else
			{
				$ret .= $sd->info_row('buckets_info');
				
				$options = array();
				$bucket_names = array();
				$attributes = array(
					'size' => 8,
					'width' => '100%'
					);
				
				foreach ($buckets AS $bucket)
				{
					$bucket_names[] = $bucket['bucket_name'];
					$options[$bucket['bucket_name']] = $bucket['bucket_name'];
				}
				
				$selected = isset($field_settings['field_buckets']) ? $field_settings['field_buckets'] : $bucket_names;
				
				$ret .= $sd->row(array(
					$sd->label('buckets_label'),
					$sd->multiselect(
						'field_buckets[]',
						$selected,
						$options,
						$attributes
						)
					));
			}
		}
		
		// Close the settings block.
		$ret .= $sd->block_c();
		
		return array('cell2' => $ret);
	}
	
	
	/**
	 * Performs house-keeping when upgrading from an earlier version.
	 *
	 * @access	public
	 * @param		string|bool			$from			The previous version, or FALSE, if this is the initial installation.
	 */
	public function update($from = FALSE)
	{
		global $DB;
	
		$sql[] = "CREATE TABLE IF NOT EXISTS `exp_bucketlist_buckets` (
		 `id` int(10) unsigned NOT NULL auto_increment,
		 `site_id` int(2) unsigned NOT NULL default 1,
		 `bucket_name` varchar(255) NOT NULL,
		 `cache_date` int(10) unsigned NOT NULL default 0,
		 PRIMARY KEY(`id`)
		 )";
			
		$sql[] = "CREATE TABLE IF NOT EXISTS `exp_bucketlist_files` (
			`id` int(10) unsigned NOT NULL auto_increment,
			`site_id` int(2) unsigned NOT NULL default 1,
			`bucket_name` varchar(255) NOT NULL,
			`file_name` varchar(255) NOT NULL,
			`file_size` int(10) unsigned NOT NULL,
			`file_last_updated` int(10) unsigned NOT NULL,
			`cache_date` int(10) unsigned NOT NULL default 0,
			PRIMARY KEY(`id`)
			)";
			
		foreach ($sql AS $query)
		{
			$DB->query($query);
		}
	}
	
	
	/**
	 * Outputs the basic field information (the URL to the file).
	 *
	 * @access	public
	 * @param		array			$params						Array of key / value pairs of the tag parameters.
	 * @param		string		$tagdata					Content between the opening and closing tags, if it's a tag pair.
	 * @param		string		$field_data				The field data.
	 * @param		array			$field_settings		The field settings.
	 * @return	string
	 */
	public function display_tag($params, $tagdata, $field_data, $field_settings)
	{
		$out = '';
		
		if (is_array($field_data) && count($field_data) == 2)
		{
			$out = $this->site_settings['use_ssl'] == 'y' ? 'https://' : 'http://';
			$out .= $field_data[0] .'.s3.amazonaws.com/'. $field_data[1];
		}
		
		return $out;
	}
	
	
	/**
	 * Outputs the file name.
	 *
	 * @access	public
	 * @param		array			$params						Array of key / value pairs of the tag parameters.
	 * @param		string		$tagdata					Content between the opening and closing tags, if it's a tag pair.
	 * @param		string		$field_data				The field data.
	 * @param		array			$field_settings		The field settings.
	 * @return	string
	 */
	public function file_name($params, $tagdata, $field_data, $field_settings)
	{
		$out = '';
		
		if (is_array($field_data) && count($field_data) == 2)
		{
			$out = $field_data[1];
		}
		
		return $out;
	}
	
	
	/**
	 * Outputs the file size.
	 *
	 * @access	public
	 * @param		array			$params						Array of key / value pairs of the tag parameters.
	 * @param		string		$tagdata					Content between the opening and closing tags, if it's a tag pair.
	 * @param		string		$field_data				The field data.
	 * @param		array			$field_settings		The field settings.
	 * @return	string
	 */
	public function file_size($params, $tagdata, $field_data, $field_settings)
	{
		global $DB;
		
		$out = '';
		
		if (is_array($field_data) && count($field_data) == 2)
		{
			$file_name = $field_data[1];
			$db_file_info = $DB->query("
				SELECT `file_size` FROM `exp_bucketlist_files`
				WHERE `file_name` = '{$file_name}'
				");
				
			if ($db_file_info->num_rows == 1)
			{
				$file_size = intval($db_file_info->row['file_size']);
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